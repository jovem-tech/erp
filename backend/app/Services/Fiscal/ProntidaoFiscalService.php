<?php

namespace App\Services\Fiscal;

use App\Services\Company\CompanyProfileService;
use App\Services\Fiscal\CertificadoA1;
use App\Support\Documento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quanto falta para conseguir emitir nota.
 *
 * A partir de 01/01/2027 o MEI emite documento fiscal em toda operação,
 * inclusive para pessoa física (LC 214/2025 + Resolução CGSN 190/2026). A NFS-e
 * exige identificar o tomador, e no levantamento que originou a `041` a base
 * tinha 1.323 de 1.323 clientes sem `cpf_cnpj`.
 *
 * Esse número não se resolve com integração nem com certificado: entra pela
 * porta do cadastro, um cliente de cada vez. Então ele precisa estar visível,
 * do mesmo jeito que a `038` só achou as 2.187 OS com CMV zerado porque alguém
 * contou.
 */
class ProntidaoFiscalService
{
    /**
     * Campos da empresa sem os quais a NFS-e não sai. `empresa_endereco` (linha
     * única) fica de fora de propósito: serve aos PDFs, não ao XML.
     *
     * @var array<string, string>
     */
    private const CAMPOS_EMPRESA = [
        'empresa_razao_social' => 'Razão social',
        'empresa_cnpj' => 'CNPJ',
        'empresa_logradouro' => 'Logradouro',
        'empresa_numero' => 'Número',
        'empresa_bairro' => 'Bairro',
        'empresa_cidade' => 'Cidade',
        'empresa_uf' => 'UF',
        'empresa_cep' => 'CEP',
        'empresa_codigo_ibge' => 'Código IBGE do município',
        'empresa_inscricao_municipal' => 'Inscrição municipal',
        'empresa_cnae' => 'CNAE',
    ];

    public function __construct(
        private readonly CompanyProfileService $companyProfileService,
        private readonly CertificadoA1 $certificado
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function verificar(): array
    {
        $areas = [
            'certificado' => $this->verificarCertificado(),
            'empresa' => $this->verificarEmpresa(),
            'clientes' => $this->verificarClientes(),
            'servicos' => $this->verificarServicos(),
            'pecas' => $this->verificarPecas(),
        ];

        $pendencias = array_sum(array_map(
            static fn (array $area): int => (int) $area['pendencias'],
            $areas
        ));

        return [
            'areas' => $areas,
            'pendencias_totais' => $pendencias,
            'pronto' => $pendencias === 0,
        ];
    }

    /**
     * Certificado A1.
     *
     * Ausente NÃO conta como pendência: sem certificado a emissão de serviço
     * continua funcionando pelo modo assistido (portal gov.br). Contar como
     * pendência faria o relatório cobrar algo que, hoje, é opcional.
     *
     * Instalado **e quebrado** (vencido, senha errada, ilegível) aí sim conta:
     * nesse caso alguém pagou pelo certificado e acha que está emitindo.
     *
     * @return array<string, mixed>
     */
    private function verificarCertificado(): array
    {
        if (! $this->certificado->existe()) {
            return [
                'instalado' => false,
                'usavel' => false,
                'problemas' => [],
                'titular' => null,
                'expira_em' => null,
                'dias_ate_vencimento' => null,
                'pendencias' => 0,
                'total' => 0,
                'prontos' => 0,
                'percentual_pronto' => 100.0,
            ];
        }

        $problemas = $this->certificado->problemas();
        $expiraEm = $this->certificado->expiraEm();

        return [
            'instalado' => true,
            'usavel' => $problemas === [],
            'problemas' => $problemas,
            'titular' => $this->certificado->nomeTitular(),
            'documento_titular' => $this->certificado->documentoTitular(),
            'expira_em' => $expiraEm?->toDateString(),
            'dias_ate_vencimento' => $this->certificado->diasAteVencimento(),
            'pendencias' => $problemas === [] ? 0 : 1,
            'total' => 1,
            'prontos' => $problemas === [] ? 1 : 0,
            'percentual_pronto' => $problemas === [] ? 100.0 : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verificarEmpresa(): array
    {
        $settings = $this->companyProfileService->payload()['settings'] ?? [];

        $faltando = [];

        foreach (self::CAMPOS_EMPRESA as $chave => $rotulo) {
            if (trim((string) ($settings[$chave] ?? '')) === '') {
                $faltando[] = $rotulo;
            }
        }

        $total = count(self::CAMPOS_EMPRESA);
        $prontos = $total - count($faltando);

        return [
            'total' => $total,
            'prontos' => $prontos,
            'pendencias' => count($faltando),
            'campos_faltando' => $faltando,
            'percentual_pronto' => round($prontos / $total * 100, 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verificarClientes(): array
    {
        $total = (int) DB::table('clientes')->count();

        $semDocumento = (int) DB::table('clientes')
            ->where(static function ($query): void {
                $query->whereNull('cpf_cnpj')->orWhere('cpf_cnpj', '');
            })
            ->count();

        // O dígito verificador não dá para conferir em SQL, então os documentos
        // preenchidos voltam para o PHP. São poucos por definição: o problema
        // desta base é o oposto — quase nada preenchido. Se algum dia esta
        // consulta pesar, o caminho é materializar a checagem numa coluna.
        $invalidos = 0;

        DB::table('clientes')
            ->select('cpf_cnpj')
            ->whereNotNull('cpf_cnpj')
            ->where('cpf_cnpj', '!=', '')
            ->orderBy('id')
            ->chunk(1000, static function ($linhas) use (&$invalidos): void {
                foreach ($linhas as $linha) {
                    if (! Documento::valido((string) $linha->cpf_cnpj)) {
                        $invalidos++;
                    }
                }
            });

        $prontos = $total - $semDocumento - $invalidos;

        return [
            'total' => $total,
            'sem_documento' => $semDocumento,
            'documento_invalido' => $invalidos,
            'prontos' => $prontos,
            'pendencias' => $semDocumento + $invalidos,
            'percentual_pronto' => $total > 0 ? round($prontos / $total * 100, 1) : 100.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function verificarServicos(): array
    {
        return $this->contarPendenciaSimples('servicos', 'codigo_tributacao_nacional', 'sem_codigo_tributacao');
    }

    /**
     * @return array<string, mixed>
     */
    private function verificarPecas(): array
    {
        return $this->contarPendenciaSimples('pecas', 'ncm', 'sem_ncm');
    }

    /**
     * Conta linhas ativas sem a coluna fiscal preenchida.
     *
     * Só conta o que está ativo: peça encerrada e serviço encerrado não vão
     * para nota nenhuma, e inflá-los no relatório faria o número parecer pior
     * do que é — e um número que exagera deixa de ser usado.
     *
     * @return array<string, mixed>
     */
    private function contarPendenciaSimples(string $tabela, string $coluna, string $rotulo): array
    {
        if (! Schema::hasTable($tabela) || ! Schema::hasColumn($tabela, $coluna)) {
            return [
                'total' => 0,
                $rotulo => 0,
                'prontos' => 0,
                'pendencias' => 0,
                'percentual_pronto' => 100.0,
            ];
        }

        $base = DB::table($tabela)->where('status', 'ativo');

        $total = (int) (clone $base)->count();

        $semDado = (int) (clone $base)
            ->where(static function ($query) use ($coluna): void {
                $query->whereNull($coluna)->orWhere($coluna, '');
            })
            ->count();

        $prontos = $total - $semDado;

        return [
            'total' => $total,
            $rotulo => $semDado,
            'prontos' => $prontos,
            'pendencias' => $semDado,
            'percentual_pronto' => $total > 0 ? round($prontos / $total * 100, 1) : 100.0,
        ];
    }
}
