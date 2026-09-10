<?php

namespace App\Services\Fiscal;

use App\Models\Configuration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Em qual ambiente a NFS-e e' emitida — e quem pode virar essa chave.
 *
 * Vivia so' no `.env`, o que contradizia o desenho do resto do modulo fiscal:
 * o sistema e' vendido, quem compra nao tem terminal, e o proprio
 * `config/fiscal.php` ja' registra que trocar certificado tem de ser pela
 * tela. Trocar de ambiente e' ainda mais operacional que isso — e' a diferenca
 * entre testar e emitir documento com obrigacao tributaria.
 *
 * Mas justamente por isso NAO e' um campo comum de configuracao:
 *
 *  - ligar producao exige `fiscal:administrar`, permissao mais estreita que a
 *    de emitir (`fiscal:criar`) ou cancelar (`fiscal:excluir`);
 *  - e' recusado enquanto o certificado ou o cadastro fiscal da empresa nao
 *    estiverem prontos — ligar producao para descobrir na primeira nota que
 *    falta o codigo IBGE e' trocar um erro barato por um caro;
 *  - toda mudanca vai para a tabela `logs` com usuario, IP e horario, alem do
 *    canal `fiscal`. Quem ligou producao e quando e' pergunta que aparece
 *    depois de a nota errada existir.
 *
 * Precedencia: o valor da tela (tabela `configuracoes`) vence o `.env`, mesmo
 * padrao da senha do certificado em `CertificadoA1::senha()`. O `.env` segue
 * valendo como padrao de instalacao — e nasce em homologacao.
 */
class AmbienteFiscal
{
    public const CHAVE = 'fiscal_nfse_ambiente';

    public const PRODUCAO = 1;

    public const HOMOLOGACAO = 2;

    private ?int $memo = null;

    public function __construct(
        private readonly CertificadoA1 $certificado,
        private readonly \App\Services\Company\CompanyProfileService $empresa,
    ) {}

    public function atual(): int
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $doBanco = null;

        try {
            $valor = Configuration::query()->where('chave', self::CHAVE)->value('valor');
            $doBanco = ($valor === null || trim((string) $valor) === '') ? null : (int) $valor;
        } catch (Throwable) {
            // Banco fora, ou contexto sem conexao (console cedo demais, teste
            // unitario). Ler o ambiente nao pode derrubar quem chama.
            $doBanco = null;
        }

        $resolvido = $doBanco ?? (int) config('fiscal.nfse.ambiente', self::HOMOLOGACAO);

        // Qualquer valor estranho vira homologacao. O default seguro nao pode
        // depender de o dado no banco estar sao.
        return $this->memo = in_array($resolvido, [self::PRODUCAO, self::HOMOLOGACAO], true)
            ? $resolvido
            : self::HOMOLOGACAO;
    }

    public function ehProducao(): bool
    {
        return $this->atual() === self::PRODUCAO;
    }

    public function rotulo(): string
    {
        return $this->ehProducao() ? 'Produção' : 'Homologação';
    }

    public function esquecerCache(): void
    {
        $this->memo = null;
    }

    /**
     * O que impede ligar producao agora.
     *
     * @return array<int, string>
     */
    public function impedimentos(): array
    {
        $problemas = [];

        foreach ($this->certificado->problemas() as $problema) {
            $problemas[] = $problema;
        }

        $settings = (array) ($this->empresa->payload()['settings'] ?? []);

        $obrigatorios = [
            'empresa_cnpj' => 'CNPJ da empresa',
            'empresa_codigo_ibge' => 'código IBGE do município',
            'empresa_codigo_tributacao_nacional' => 'código de tributação nacional',
        ];

        $faltando = [];

        foreach ($obrigatorios as $chave => $rotulo) {
            if (trim((string) ($settings[$chave] ?? '')) === '') {
                $faltando[] = $rotulo;
            }
        }

        if ($faltando !== []) {
            $problemas[] = 'Cadastro fiscal incompleto: falta '.implode(', ', $faltando).'.';
        }

        return $problemas;
    }

    /**
     * Troca o ambiente, com guarda e rastro.
     *
     * @throws ValidationException
     */
    public function definir(
        int $ambiente,
        ?int $usuarioId = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): int {
        if (! in_array($ambiente, [self::PRODUCAO, self::HOMOLOGACAO], true)) {
            throw ValidationException::withMessages([
                'ambiente' => 'Ambiente inválido: use 1 (produção) ou 2 (homologação).',
            ]);
        }

        $anterior = $this->atual();

        if ($ambiente === self::PRODUCAO) {
            $impedimentos = $this->impedimentos();

            if ($impedimentos !== []) {
                // Recusar aqui e' barato. Deixar ligar e descobrir na primeira
                // emissao custa um numero de DPS queimado e um susto.
                throw ValidationException::withMessages([
                    'ambiente' => 'Não é possível ligar a produção: '.implode(' ', $impedimentos),
                ]);
            }
        }

        Configuration::query()->updateOrInsert(
            ['chave' => self::CHAVE],
            ['valor' => (string) $ambiente, 'tipo' => 'texto', 'updated_at' => now(), 'created_at' => now()]
        );

        $this->esquecerCache();

        $this->registrarNoHistorico($anterior, $ambiente, $usuarioId, $ip, $userAgent);

        return $ambiente;
    }

    private function registrarNoHistorico(
        int $de,
        int $para,
        ?int $usuarioId,
        ?string $ip,
        ?string $userAgent
    ): void {
        $descricao = sprintf(
            'Ambiente de emissão fiscal alterado de %s para %s.',
            $de === self::PRODUCAO ? 'PRODUÇÃO' : 'homologação',
            $para === self::PRODUCAO ? 'PRODUÇÃO' : 'homologação'
        );

        // Canal fiscal: retencao longa, independente do LOG_LEVEL global.
        Log::channel('fiscal')->warning('[NFSE] '.$descricao, [
            'de' => $de,
            'para' => $para,
            'usuario_id' => $usuarioId,
            'ip' => $ip,
        ]);

        // Tabela `logs`: e' onde o sistema ja' guarda "quem fez o que", com IP.
        // Falhar aqui nao pode desfazer a troca — mas tem de aparecer no log.
        try {
            DB::table('logs')->insert([
                'usuario_id' => $usuarioId,
                'acao' => 'fiscal_ambiente_alterado',
                'descricao' => $descricao,
                'ip' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::channel('fiscal')->error('[NFSE] Não foi possível gravar a troca de ambiente em `logs`.', [
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
