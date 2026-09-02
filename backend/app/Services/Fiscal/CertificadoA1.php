<?php

namespace App\Services\Fiscal;

use App\Models\Configuration;
use App\Support\SecretSettings;
use Carbon\CarbonImmutable;

/**
 * Certificado digital A1 (ICP-Brasil) usado para assinar a DPS e falar com o
 * Ambiente Nacional da NFS-e por mTLS.
 *
 * A1 é arquivo `.pfx`/`.p12`, não token: é o formato que permite emissão
 * automática. O A3 é cartão/token físico e exigiria alguém plugar o
 * dispositivo a cada lote.
 *
 * Espelha `InterCredentials`, inclusive na decisão de segurança: caminho e
 * senha vêm do `.env`, o conteúdo nunca vai para o banco — ver o cabeçalho de
 * `config/fiscal.php` para o porquê.
 *
 * O método que responde "está usável?" devolve a LISTA do que falta, e não um
 * booleano: "não configurado" e "vencido" pedem ações diferentes, e um `false`
 * sozinho obrigaria quem chama a adivinhar qual é o caso.
 */
class CertificadoA1
{
    /**
     * @var array{cert: string, pkey: string, extracerts?: array<int, string>}|null
     */
    private ?array $lido = null;

    private bool $tentouLer = false;

    public const CHAVE_SENHA = 'fiscal_certificado_senha';

    public function caminho(): string
    {
        return $this->resolverCaminho((string) config('fiscal.certificado.pfx_path', ''));
    }

    /**
     * Senha do `.pfx`.
     *
     * Precedência: o que foi enviado pela tela (cifrado em repouso na tabela
     * `configuracoes`, mesmo mecanismo dos segredos do Inter e do SMTP) tem
     * prioridade sobre o `.env`.
     *
     * Guardar aqui em vez de no `.env` é mais seguro, não menos: no `.env` a
     * senha fica em texto puro. O que continua **fora** do banco é o `.pfx` —
     * ele é a chave privada, e chave privada não vai para dump de banco.
     */
    public function senha(): string
    {
        try {
            $doBanco = (string) (Configuration::query()
                ->where('chave', self::CHAVE_SENHA)
                ->value('valor') ?? '');
        } catch (\Throwable) {
            // Banco fora, ou contexto sem conexao (comando de console cedo
            // demais, teste unitario). Ler a senha nao pode derrubar quem
            // chama: cai para o `.env`, que e' o comportamento anterior.
            $doBanco = '';
        }

        if ($doBanco !== '') {
            return SecretSettings::decrypt(self::CHAVE_SENHA, $doBanco, [self::CHAVE_SENHA]);
        }

        return (string) config('fiscal.certificado.senha', '');
    }

    public function existe(): bool
    {
        $caminho = $this->caminho();

        return $caminho !== '' && is_file($caminho) && is_readable($caminho);
    }

    /**
     * Lista o que impede o certificado de ser usado. Vazio = usável.
     *
     * @return array<int, string>
     */
    public function problemas(): array
    {
        $problemas = [];
        $caminho = $this->caminho();

        if ($caminho === '') {
            return ['FISCAL_CERT_PFX_PATH não configurado.'];
        }

        if (! is_file($caminho)) {
            return ['Certificado não encontrado em '.$caminho];
        }

        if (! is_readable($caminho)) {
            // Armadilha conhecida deste servidor: arquivo sob storage/ criado
            // por outro usuário. Em runtime quem lê é o www-data.
            return ['Certificado existe mas não é legível (confira o dono do arquivo): '.$caminho];
        }

        if ($this->senha() === '') {
            $problemas[] = 'FISCAL_CERT_SENHA não informada.';
        }

        if ($this->ler() === null) {
            $problemas[] = 'Não foi possível abrir o .pfx — senha incorreta ou arquivo corrompido.';

            return $problemas;
        }

        $expiraEm = $this->expiraEm();

        if ($expiraEm === null) {
            $problemas[] = 'Certificado aberto, mas sem data de validade legível.';
        } elseif ($expiraEm->isPast()) {
            $problemas[] = 'Certificado venceu em '.$expiraEm->format('d/m/Y').'.';
        }

        return $problemas;
    }

    public function estaUsavel(): bool
    {
        return $this->problemas() === [];
    }

    /**
     * Quando o certificado expira.
     *
     * Null quando não deu para ler — e o chamador trata isso como "não sei",
     * nunca como "está válido". Arquivo ilegível derruba a emissão do mesmo
     * jeito que um certificado vencido.
     */
    public function expiraEm(): ?CarbonImmutable
    {
        $lido = $this->ler();

        if ($lido === null) {
            return null;
        }

        $dados = @openssl_x509_parse($lido['cert']);

        if (! is_array($dados) || ! isset($dados['validTo_time_t'])) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $dados['validTo_time_t']);
    }

    public function diasAteVencimento(): ?int
    {
        $expiraEm = $this->expiraEm();

        return $expiraEm === null ? null : (int) CarbonImmutable::now()->diffInDays($expiraEm, false);
    }

    /**
     * CNPJ (ou CPF) do titular, extraído do próprio certificado.
     *
     * Serve para conferir que o certificado instalado é o da empresa
     * cadastrada — trocar de contador e receber o certificado errado é um erro
     * silencioso caro.
     */
    public function documentoTitular(): ?string
    {
        $lido = $this->ler();

        if ($lido === null) {
            return null;
        }

        $dados = @openssl_x509_parse($lido['cert']);

        if (! is_array($dados)) {
            return null;
        }

        // ICP-Brasil põe o CNPJ no CN ("RAZAO SOCIAL:12345678000190") e/ou nas
        // extensões subjectAltName. O CN cobre o caso comum.
        $cn = (string) ($dados['subject']['CN'] ?? '');

        if (preg_match('/(\d{14}|\d{11})\b/', $cn, $encontrado) === 1) {
            return $encontrado[1];
        }

        $alt = (string) ($dados['extensions']['subjectAltName'] ?? '');

        return preg_match('/(\d{14})/', $alt, $encontrado) === 1 ? $encontrado[1] : null;
    }

    public function nomeTitular(): ?string
    {
        $lido = $this->ler();

        if ($lido === null) {
            return null;
        }

        $dados = @openssl_x509_parse($lido['cert']);
        $cn = (string) ($dados['subject']['CN'] ?? '');

        if ($cn === '') {
            return null;
        }

        // "RAZAO SOCIAL:12345678000190" → "RAZAO SOCIAL"
        return trim((string) preg_replace('/:\d{11,14}$/', '', $cn));
    }

    /**
     * Certificado e chave privada em PEM, para assinar a DPS.
     *
     * @return array{cert: string, pkey: string}|null
     */
    public function pem(): ?array
    {
        $lido = $this->ler();

        return $lido === null ? null : ['cert' => $lido['cert'], 'pkey' => $lido['pkey']];
    }

    /**
     * Abre o `.pfx` uma vez por instância. `openssl_pkcs12_read` falha tanto
     * por senha errada quanto por arquivo corrompido, e os dois casos viram
     * `null` de propósito: para quem chama, o certificado não está usável.
     *
     * @return array{cert: string, pkey: string, extracerts?: array<int, string>}|null
     */
    private function ler(): ?array
    {
        if ($this->tentouLer) {
            return $this->lido;
        }

        $this->tentouLer = true;

        if (! $this->existe()) {
            return null;
        }

        $conteudo = @file_get_contents($this->caminho());

        if ($conteudo === false || $conteudo === '') {
            return null;
        }

        $saida = [];

        if (! @openssl_pkcs12_read($conteudo, $saida, $this->senha())) {
            return null;
        }

        if (! isset($saida['cert'], $saida['pkey'])) {
            return null;
        }

        $this->lido = $saida;

        return $this->lido;
    }

    /**
     * Esquece o `.pfx` já aberto. Necessário depois de instalar um certificado
     * novo na mesma requisição — sem isso o cache devolveria o anterior.
     */
    public function esquecerCache(): void
    {
        $this->lido = null;
        $this->tentouLer = false;
    }

    private function resolverCaminho(string $caminho): string
    {
        if ($caminho === '') {
            return '';
        }

        return str_starts_with($caminho, '/') ? $caminho : base_path($caminho);
    }
}
