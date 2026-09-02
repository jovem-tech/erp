<?php

namespace App\Services\Fiscal;

use App\Models\Configuration;
use App\Support\SecretSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * Instala o certificado A1 enviado pela tela.
 *
 * Existe porque o sistema é vendido: o novo dono precisa instalar o próprio
 * certificado sem acesso a terminal. Antes disso, trocar de certificado exigia
 * `scp`, `chown` e edição de `.env` — o que na prática significa "só o
 * desenvolvedor troca".
 *
 * A divisão de onde cada coisa mora é deliberada:
 *
 *  - O **`.pfx` vai para o disco**, nunca para o banco. Ele é a chave privada,
 *    e chave privada não entra em dump de banco — que é o artefato que mais
 *    circula (réplica, cópia para análise, consulta de alguém com acesso).
 *  - A **senha vai para `configuracoes`, cifrada em repouso** pelo mesmo
 *    `SecretSettings` que já guarda os segredos do Inter e do SMTP. Isso é mais
 *    seguro que o `.env`, onde ela ficava em texto puro.
 *
 * E há um ganho operacional silencioso: quem grava o arquivo é o processo web,
 * ou seja o `www-data`. O dono do arquivo nasce certo. Instalar por `scp` como
 * outro usuário é a armadilha que já mordeu o cache de view e os logs deste
 * servidor.
 */
class CertificadoA1Installer
{
    public function __construct(private readonly CertificadoA1 $certificado) {}

    /**
     * Valida e instala. Nada é gravado se o `.pfx` não abrir com a senha —
     * salvar primeiro e conferir depois deixaria um certificado quebrado no
     * lugar do que funcionava.
     *
     * @return array<string, mixed> estado do certificado recém-instalado
     */
    public function instalar(UploadedFile $arquivo, string $senha): array
    {
        $conteudo = (string) file_get_contents($arquivo->getRealPath());

        if ($conteudo === '') {
            throw ValidationException::withMessages([
                'arquivo' => 'O arquivo enviado está vazio.',
            ]);
        }

        $lido = [];

        if (! @openssl_pkcs12_read($conteudo, $lido, $senha)) {
            throw ValidationException::withMessages([
                'senha' => 'Não foi possível abrir o certificado com esta senha. '
                    .'Confira a senha ou se o arquivo é mesmo um .pfx/.p12 válido.',
            ]);
        }

        if (! isset($lido['cert'], $lido['pkey'])) {
            throw ValidationException::withMessages([
                'arquivo' => 'O arquivo abriu, mas não contém certificado e chave privada juntos.',
            ]);
        }

        $dados = @openssl_x509_parse($lido['cert']);

        if (is_array($dados) && isset($dados['validTo_time_t'])) {
            // Carbon, e nao `time()`: o resto do sistema viaja no tempo em
            // teste, e um `time()` aqui tornaria esta guarda inverificavel.
            $expiraEm = CarbonImmutable::createFromTimestampUTC((int) $dados['validTo_time_t']);

            if ($expiraEm->isPast()) {
                // Recusar aqui evita a pior falha possível: instalar um
                // vencido, ver "certificado configurado" na tela e só
                // descobrir na emissão.
                throw ValidationException::withMessages([
                    'arquivo' => 'Este certificado já venceu em '
                        .$expiraEm->format('d/m/Y').'. Instale o certificado vigente.',
                ]);
            }
        }

        $destino = $this->certificado->caminho();

        if ($destino === '') {
            throw ValidationException::withMessages([
                'arquivo' => 'Caminho de destino do certificado não configurado (FISCAL_CERT_PFX_PATH).',
            ]);
        }

        File::ensureDirectoryExists(dirname($destino), 0700);

        if (File::put($destino, $conteudo) === false) {
            throw ValidationException::withMessages([
                'arquivo' => 'Não foi possível gravar o certificado em '.$destino,
            ]);
        }

        // 0600: o arquivo só interessa ao processo que emite.
        @chmod($destino, 0600);

        Configuration::query()->updateOrInsert(
            ['chave' => CertificadoA1::CHAVE_SENHA],
            [
                'valor' => SecretSettings::encrypt(
                    CertificadoA1::CHAVE_SENHA,
                    $senha,
                    [CertificadoA1::CHAVE_SENHA]
                ),
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->certificado->esquecerCache();

        return $this->estado();
    }

    /**
     * Remove o certificado e esquece a senha.
     */
    public function remover(): array
    {
        $caminho = $this->certificado->caminho();

        if ($caminho !== '' && is_file($caminho)) {
            @unlink($caminho);
        }

        Configuration::query()->where('chave', CertificadoA1::CHAVE_SENHA)->delete();

        $this->certificado->esquecerCache();

        return $this->estado();
    }

    /**
     * @return array<string, mixed>
     */
    public function estado(): array
    {
        $problemas = $this->certificado->problemas();
        $expiraEm = $this->certificado->expiraEm();

        return [
            'instalado' => $this->certificado->existe(),
            'usavel' => $problemas === [],
            'problemas' => $problemas,
            'titular' => $this->certificado->nomeTitular(),
            'documento_titular' => $this->certificado->documentoTitular(),
            'expira_em' => $expiraEm?->toDateString(),
            'dias_ate_vencimento' => $this->certificado->diasAteVencimento(),
        ];
    }
}
