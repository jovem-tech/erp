<?php

namespace App\Services\Fiscal;

use App\Models\AnexoXAjuste;
use App\Models\AnexoXFechamento;
use App\Models\DocumentoFiscal;
use App\Models\Financeiro;
use App\Models\SaleItem;
use App\Services\Company\CompanyProfileService;
use App\Services\Financeiro\ReceitaBrutaSource;
use App\Support\Documento;
use App\Support\PeriodoMensal;
use App\Support\RateioAtividade;
use App\Support\RegimeTributario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ANEXO X — Relatório Mensal das Receitas Brutas (Res. CGSN 140/2018, art. 106).
 *
 * Obrigação acessória do MEI: preencher até o dia 20 do mês subsequente ao da
 * percepção da receita e guardar pelo prazo decadencial.
 *
 * O formulário pede o que nenhum outro relatório do sistema fazia — segregar a
 * receita por ATIVIDADE (revenda de mercadorias × produtos industrializados ×
 * prestação de serviços) e, dentro de cada uma, separar o que teve documento
 * fiscal emitido do que foi dispensado.
 *
 * **Invariante que governa a classe inteira:** o documento fiscal decide a
 * COLUNA, nunca o TOTAL. `I+II=III`, `IV+V=VI`, `VII+VIII=IX`, `III+VI+IX=X`,
 * e X não depende de `documentos_fiscais` — emitir uma nota não pode mudar
 * quanto se faturou. Há teste para isso.
 *
 * **Segunda invariante:** X tem que bater com a receita líquida que o DRE
 * mostra para o mesmo mês e regime. Por isso as linhas vêm todas de
 * `ReceitaBrutaSource`, a mesma fonte que o DRE consome — não de predicados
 * parecidos escritos de novo aqui. Também há teste.
 *
 * As linhas IV/V/VI (indústria) saem sempre zeradas nesta base — assistência
 * técnica não industrializa — mas continuam impressas, porque são linhas do
 * formulário oficial e um Anexo X sem elas não é o Anexo X.
 */
class AnexoXService
{
    public const REGIME_COMPETENCIA = 'competencia';

    public const REGIME_CAIXA = 'caixa';

    /** Limite de receita bruta anual do MEI (LC 123/2006, art. 18-A, § 1º). */
    public const LIMITE_MEI_ANUAL = 81000.00;

    /** Excesso tolerado antes do desenquadramento retroativo. */
    public const LIMITE_MEI_EXCESSO_PCT = 20.0;

    /** @var array<int, string> */
    public const MESES_CURTOS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    public const ATIVIDADE_COMERCIO = 'comercio';

    public const ATIVIDADE_INDUSTRIA = 'industria';

    public const ATIVIDADE_SERVICOS = 'servicos';

    /**
     * Item avulso do PDV entra como MERCADORIA.
     *
     * Item avulso é linha de texto livre, sem vínculo com `pecas` ou
     * `servicos`. Numa assistência técnica isso é cabo, capinha, película —
     * mercadoria. Mão de obra não é vendida no balcão como avulso: nasce em
     * OS, onde existe `valor_mao_obra`.
     *
     * É uma escolha assumida, não um fato do banco. Por isso o total de
     * avulsos sai separado em `origens.avulsos_da_venda`, para conferência. Não
     * virou configuração de propósito: classificação fiscal que o operador não
     * tem como decidir sozinho só convida ao erro. Errar aqui não move a linha
     * X — move III contra IX.
     */
    private const AVULSO_COMO_ATIVIDADE = self::ATIVIDADE_COMERCIO;

    /**
     * Rótulos das dez linhas, na redação da norma.
     *
     * @var array<string, string>
     */
    private const ROTULOS = [
        'i' => 'Revenda de mercadorias com dispensa de emissão de documento fiscal',
        'ii' => 'Revenda de mercadorias com documento fiscal emitido',
        'iii' => 'Total das receitas com revenda de mercadorias (I + II)',
        'iv' => 'Venda de produtos industrializados com dispensa de emissão de documento fiscal',
        'v' => 'Venda de produtos industrializados com documento fiscal emitido',
        'vi' => 'Total das receitas com venda de produtos industrializados (IV + V)',
        'vii' => 'Receita com prestação de serviços com dispensa de emissão de documento fiscal',
        'viii' => 'Receita com prestação de serviços com documento fiscal emitido',
        'ix' => 'Total das receitas com prestação de serviços (VII + VIII)',
        'x' => 'Total geral das receitas brutas no mês (III + VI + IX)',
    ];

    public function __construct(
        private readonly ReceitaBrutaSource $receitaBrutaSource,
        private readonly CompanyProfileService $companyProfileService,
        private readonly AnexoXFechamentoService $fechamentos,
        private readonly AnexoXAjusteService $ajustes
    ) {
    }

    /**
     * @return array<int, string>
     */
    public static function regimes(): array
    {
        return [self::REGIME_COMPETENCIA, self::REGIME_CAIXA];
    }

    public static function normalizarRegime(?string $regime): string
    {
        $regime = strtolower(trim((string) $regime));

        return in_array($regime, self::regimes(), true) ? $regime : self::REGIME_COMPETENCIA;
    }

    /**
     * Relatório do mês: o congelado, se houver fechamento vigente; senão a
     * apuração ao vivo.
     *
     * `$reconferir` recalcula mesmo com o mês fechado, para comparar o que foi
     * declarado com o que os dados de hoje dizem. Não é o padrão porque
     * recalcular o mês inteiro a cada abertura de tela seria caro e, num mês
     * fechado, quase sempre inútil.
     *
     * @return array<string, mixed>
     */
    public function relatorio(string $competencia, string $regime = self::REGIME_COMPETENCIA, bool $reconferir = false, bool $incluirAcumulado = true): array
    {
        $competencia = PeriodoMensal::normalizar($competencia);
        $regime = self::normalizarRegime($regime);

        $vigente = $this->fechamentos->vigente($competencia, $regime);

        if (! $vigente instanceof AnexoXFechamento) {
            $relatorio = $this->apurar($competencia, $regime, $incluirAcumulado);
            $relatorio['origem_dos_valores'] = 'ao_vivo';
            $relatorio['fechamento'] = $this->historicoSemVigente($competencia, $regime);

            return $relatorio;
        }

        $congelado = $vigente->payload();

        // Payload ilegível (registro antigo, JSON truncado) não pode deixar a
        // tela em branco: cai para o vivo e diz que caiu.
        if ($congelado === []) {
            $congelado = $this->apurar($competencia, $regime);
            $congelado['origem_dos_valores'] = 'ao_vivo';
            $congelado['fechamento'] = $this->fechamentos->apresentar($vigente);
            $congelado['fechamento']['payload_ilegivel'] = true;

            return $congelado;
        }

        $congelado['origem_dos_valores'] = 'fechamento';
        $congelado['fechamento'] = $this->fechamentos->apresentar(
            $vigente,
            $reconferir ? $this->apurar($competencia, $regime) : null
        );

        return $congelado;
    }

    /**
     * Apuração ao vivo, sempre — ignora fechamento.
     *
     * @return array<string, mixed>
     */
    public function apurar(string $competencia, string $regime = self::REGIME_COMPETENCIA, bool $incluirAcumulado = true): array
    {
        $competencia = PeriodoMensal::normalizar($competencia);
        $regime = self::normalizarRegime($regime);

        $nucleo = $this->apurarBlocos($competencia, $regime);

        $operacoes = $nucleo['operacoes'];
        $empresa = $this->empresa();
        $regimeTributario = $this->regimeTributario();

        return [
            'competencia' => $competencia,
            'periodo_label' => $nucleo['periodo_label'],
            'regime' => $regime,
            'regime_label' => $this->rotuloRegime($regime),
            'regime_tributario' => $regimeTributario,
            'aviso_regime_tributario' => $this->avisoRegimeTributario($regimeTributario),
            'empresa' => $empresa,
            'linhas' => $nucleo['linhas'],
            'deducoes' => $nucleo['deducoes'],
            'ajustes' => $this->ajustes->apresentar(
                $competencia,
                $regime,
                bloqueado: $this->fechamentos->vigente($competencia, $regime) instanceof AnexoXFechamento
            ),
            'origens' => $this->origens($operacoes),
            'drill_down' => array_values(array_map(fn (array $op): array => $this->apresentarOperacao($op), $operacoes)),
            'sem_documento' => $this->semDocumento($operacoes),
            // O acumulado do ano custa uma varredura de doze meses e NÃO vai
            // para o PDF — é extra de tela. O relatório anual desliga:
            // deixá-lo ligado faria doze relatórios varrerem doze meses cada.
            'acumulado_ano' => $incluirAcumulado
                ? $this->acumuladoAnual($competencia, $regime, $regimeTributario, $empresa)
                : null,
            'origem_dos_valores' => 'ao_vivo',
            'fechamento' => null,
            'gerado_em' => now()->toIso8601String(),
        ];
    }

    /**
     * O núcleo da apuração de um mês: operações, deduções, blocos e as dez
     * linhas. Sem nada que dependa de tela.
     *
     * Existe para que `apurar()` e `resumoAnual()` passem pelo MESMO código.
     * `apurar()` decora com origens, drill-down, receita sem documento e
     * acumulado do ano; `resumoAnual()` descarta as operações e fica só com as
     * linhas. Sem esse compartilhamento, a tabela do ano poderia mostrar
     * R$ 1.200 e o modal do mesmo mês R$ 1.190 — e ninguém saberia qual dos
     * dois está certo num relatório entregue ao fisco.
     *
     * @return array{operacoes: array<int, array<string, mixed>>, linhas: array<string, array<string, mixed>>, deducoes: array<string, mixed>, periodo_label: string}
     */
    private function apurarBlocos(string $competencia, string $regime, ?Collection $ajustesDoMes = null): array
    {
        [$inicio, $fim, $label] = PeriodoMensal::resolver($competencia);

        $operacoes = $regime === self::REGIME_CAIXA
            ? $this->operacoesCaixa($inicio, $fim)
            : $this->operacoesCompetencia($inicio, $fim);

        $deducoes = $regime === self::REGIME_CAIXA
            ? $this->deducoesCaixa($inicio, $fim)
            : $this->deducoesCompetencia($inicio, $fim);

        $blocos = $this->acumularBlocos($operacoes);
        $blocos = $this->aplicarDeducoes($blocos, $deducoes['por_atividade']);

        // O valor APURADO de cada linha, antes de qualquer ajuste manual. É ele
        // que continua batendo com o DRE, e é o que a tela mostra ao lado do
        // ajuste na tríade Calculado / Ajuste / Declarado.
        $calculados = $this->valoresDeLinha($blocos);

        // O ajuste entra DEPOIS das deduções, e a ordem não é detalhe:
        // `aplicarDeducoes()` faz cascata entre colunas para não deixar linha
        // negativa, e uma devolução de venda QUE ESTÁ no sistema não pode
        // consumir um ajuste lançado para uma venda que NÃO está. Se entrasse
        // antes, a devolução comeria o ajuste em silêncio e a tríade da tela
        // deixaria de fechar.
        $somasDeAjuste = $this->ajustes->somasPorLinha($competencia, $regime, $ajustesDoMes);
        $blocos = $this->aplicarAjustes($blocos, $somasDeAjuste);

        // Desconto só existe por competência: no caixa o que entrou já é
        // líquido dele. Mesma convenção de buildReceitaCaixaBlock().
        $descontos = $regime === self::REGIME_CAIXA
            ? 0.0
            : round(array_sum(array_column($operacoes, 'desconto')), 2);

        return [
            'operacoes' => $operacoes,
            'linhas' => $this->montarLinhas($blocos, $calculados, $somasDeAjuste),
            'deducoes' => [
                'descontos' => $descontos,
                'devolucoes' => $deducoes['total'],
                'por_atividade' => $deducoes['por_atividade'],
            ],
            'periodo_label' => $label,
        ];
    }

    // ---------------------------------------------------------------- receita

    /**
     * Operações do mês no regime de COMPETÊNCIA.
     *
     * @return array<int, array<string, mixed>>
     */
    private function operacoesCompetencia(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $ordens = $this->receitaBrutaSource
            ->queryOrdensReconhecidas($inicio, $fim)
            ->get([
                'os.id',
                'os.numero_os',
                'os.cliente_id',
                'os.valor_total',
                'os.valor_pecas',
                'os.valor_mao_obra',
                'os.desconto',
                DB::raw('COALESCE(os.data_entrega, os.data_conclusao) as data_receita'),
            ]);

        $titulos = $this->receitaBrutaSource->linhasPorCompetencia(
            Financeiro::TIPO_RECEBER,
            Financeiro::GRUPO_DRE_RECEITA_OPERACIONAL,
            $inicio,
            $fim,
            excludeOs: true,
            incluirVendas: true
        );

        $operacoes = [];

        foreach ($ordens as $os) {
            // Base é valor_total - desconto, e NÃO valor_final: as cinco
            // colunas de valor da OS são enviadas pelo cliente e nada as
            // recalcula, então uma OS com valor_final divergente faria o Anexo
            // X e o DRE (que usa valor_total e desconto) discordarem.
            $bruto = round((float) $os->valor_total, 2);
            $desconto = round((float) $os->desconto, 2);
            $liquido = round($bruto - $desconto, 2);

            $operacoes[] = $this->montarOperacao(
                tipo: 'os',
                id: (int) $os->id,
                referencia: (string) $os->numero_os,
                data: (string) $os->data_receita,
                clienteId: (int) ($os->cliente_id ?? 0),
                bruto: $bruto,
                desconto: $desconto,
                liquido: $liquido,
                rateio: $this->rateioDeOs((float) $os->valor_pecas, (float) $os->valor_mao_obra, $liquido),
                osId: (int) $os->id,
                vendaId: 0
            );
        }

        $proporcoes = $this->proporcoesDeVendas(
            $titulos->pluck('venda_id')->filter()->map(fn ($id): int => (int) $id)->all()
        );

        foreach ($titulos as $titulo) {
            $valor = round((float) $titulo->valor, 2);
            $vendaId = (int) ($titulo->venda_id ?? 0);

            $operacoes[] = $this->montarOperacao(
                tipo: $vendaId > 0 ? 'venda' : 'titulo',
                id: (int) $titulo->id,
                referencia: (string) ($titulo->descricao ?? 'Lançamento '.$titulo->id),
                data: (string) ($titulo->data_competencia ?? $titulo->data_vencimento ?? $inicio->toDateString()),
                clienteId: (int) ($titulo->cliente_id ?? 0),
                bruto: $valor,
                desconto: 0.0,
                liquido: $valor,
                rateio: $this->rateioDeVenda($vendaId, $valor, $proporcoes),
                osId: 0,
                vendaId: $vendaId
            );
        }

        return $this->anexarDocumentos($operacoes);
    }

    /**
     * Operações do mês no regime de CAIXA — cada baixa é uma linha.
     *
     * @return array<int, array<string, mixed>>
     */
    private function operacoesCaixa(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $movimentos = $this->receitaBrutaSource
            ->queryMovimentos(Financeiro::TIPO_RECEBER, $inicio, $fim, onlyOperacional: true)
            ->get([
                'financeiro_movimentos.id as movimento_id',
                'financeiro_movimentos.financeiro_id',
                'financeiro_movimentos.data_movimento',
                'financeiro_movimentos.valor_movimento',
                'financeiro.os_id',
                'financeiro.venda_id',
                'financeiro.cliente_id',
                'financeiro.descricao',
            ]);

        $proporcoesVenda = $this->proporcoesDeVendas(
            $movimentos->pluck('venda_id')->filter()->map(fn ($id): int => (int) $id)->all()
        );

        $proporcoesOs = $this->proporcoesDeOs(
            $movimentos->pluck('os_id')->filter()->map(fn ($id): int => (int) $id)->all()
        );

        $operacoes = [];

        foreach ($movimentos as $movimento) {
            $valor = round((float) $movimento->valor_movimento, 2);
            $vendaId = (int) ($movimento->venda_id ?? 0);
            $osId = (int) ($movimento->os_id ?? 0);

            // VENDA ANTES DE OS, e a ordem não é estilo.
            // SalePaymentService::createReceivable() grava os_id no título da
            // VENDA quando ela está vinculada a uma OS. Checar os_id primeiro
            // aplicaria a mistura peça/serviço da OS ao pagamento de uma venda
            // de balcão, que não tem nada a ver com ela.
            if ($vendaId > 0) {
                $rateio = $this->rateioDeVenda($vendaId, $valor, $proporcoesVenda);
            } elseif ($osId > 0 && isset($proporcoesOs[$osId])) {
                $rateio = $this->rateioDeOs(
                    $proporcoesOs[$osId]['pecas'],
                    $proporcoesOs[$osId]['mao_obra'],
                    $valor
                );
            } else {
                $rateio = $this->rateioSemClassificacao($valor);
            }

            $operacoes[] = $this->montarOperacao(
                tipo: 'movimento',
                id: (int) $movimento->movimento_id,
                referencia: (string) ($movimento->descricao ?? 'Baixa '.$movimento->movimento_id),
                data: (string) $movimento->data_movimento,
                clienteId: (int) ($movimento->cliente_id ?? 0),
                bruto: $valor,
                desconto: 0.0,
                liquido: $valor,
                rateio: $rateio,
                osId: $osId,
                vendaId: $vendaId
            );
        }

        return $this->anexarDocumentos($operacoes);
    }

    // --------------------------------------------------------------- deduções

    /**
     * @return array{total: float, por_atividade: array<string, float>}
     */
    private function deducoesCompetencia(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $titulos = $this->receitaBrutaSource->linhasPorCompetencia(
            Financeiro::TIPO_PAGAR,
            null,
            $inicio,
            $fim,
            origemTipo: Financeiro::ORIGEM_TIPO_VENDA_DEVOLUCAO
        );

        return $this->ratearDeducoes($titulos->map(fn ($t): array => [
            'financeiro_id' => (int) $t->id,
            'valor' => round((float) $t->valor, 2),
        ])->all());
    }

    /**
     * @return array{total: float, por_atividade: array<string, float>}
     */
    private function deducoesCaixa(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $linhas = $this->receitaBrutaSource->linhasPorMovimento(
            Financeiro::TIPO_PAGAR,
            null,
            $inicio,
            $fim,
            origemTipo: Financeiro::ORIGEM_TIPO_VENDA_DEVOLUCAO,
            colunasExtras: ['financeiro_movimentos.financeiro_id as financeiro_id']
        );

        return $this->ratearDeducoes($linhas->map(fn ($l): array => [
            'financeiro_id' => (int) $l->financeiro_id,
            'valor' => round((float) $l->valor, 2),
        ])->all());
    }

    /**
     * Reparte cada devolução entre as atividades do que foi devolvido.
     *
     * @param  array<int, array{financeiro_id: int, valor: float}>  $linhas
     * @return array{total: float, por_atividade: array<string, float>}
     */
    private function ratearDeducoes(array $linhas): array
    {
        $porAtividade = [
            self::ATIVIDADE_COMERCIO => 0.0,
            self::ATIVIDADE_INDUSTRIA => 0.0,
            self::ATIVIDADE_SERVICOS => 0.0,
        ];

        if ($linhas === []) {
            return ['total' => 0.0, 'por_atividade' => $porAtividade];
        }

        $proporcoes = $this->proporcoesDeDevolucoes(array_column($linhas, 'financeiro_id'));
        $total = 0.0;

        foreach ($linhas as $linha) {
            $valor = $linha['valor'];
            $total += $valor;

            $proporcao = $proporcoes[$linha['financeiro_id']] ?? null;

            if ($proporcao === null) {
                // Devolução sem itens localizáveis: mercadoria. É o que a
                // devolução de balcão quase sempre é, e assumir serviço
                // inflaria a coluna de serviços com estorno de peça.
                $porAtividade[self::ATIVIDADE_COMERCIO] += $valor;

                continue;
            }

            $rateio = RateioAtividade::dividir($proporcao['mercadoria'], $proporcao['servico'], $valor);
            $porAtividade[self::ATIVIDADE_COMERCIO] += $rateio['mercadoria'];
            $porAtividade[self::ATIVIDADE_SERVICOS] += $rateio['servico'];
        }

        return [
            'total' => round($total, 2),
            'por_atividade' => array_map(fn (float $v): float => round($v, 2), $porAtividade),
        ];
    }

    /**
     * Deduz a devolução da atividade devolvida, começando pela coluna
     * "com dispensa".
     *
     * **Por que sai de "com dispensa" e não de "com documento emitido":** a
     * devolução NÃO cancela o documento fiscal já emitido. A nota continua
     * existindo e continuando a documentar a operação original — abater dela
     * faria a coluna II/VIII deixar de bater com a relação de documentos que o
     * próprio sistema imprime.
     *
     * **Guarda contra linha negativa:** o que exceder a coluna "com dispensa"
     * escorre para "com documento" da mesma atividade e, se ainda sobrar, para
     * a outra atividade. Nenhuma linha do formulário sai negativa por
     * distribuição — e X não muda, porque o total deduzido é o mesmo.
     *
     * Se as devoluções superarem TODA a receita do mês, X sai negativo mesmo.
     * A tela avisa e o PDF imprime: maquiar formulário entregue ao fisco é pior
     * que exibir um número feio.
     *
     * @param  array<string, array{com: float, sem: float}>  $blocos
     * @param  array<string, float>  $deducoes
     * @return array<string, array{com: float, sem: float}>
     */
    private function aplicarDeducoes(array $blocos, array $deducoes): array
    {
        $ordemDeEscape = [
            self::ATIVIDADE_COMERCIO => [self::ATIVIDADE_SERVICOS, self::ATIVIDADE_INDUSTRIA],
            self::ATIVIDADE_SERVICOS => [self::ATIVIDADE_COMERCIO, self::ATIVIDADE_INDUSTRIA],
            self::ATIVIDADE_INDUSTRIA => [self::ATIVIDADE_COMERCIO, self::ATIVIDADE_SERVICOS],
        ];

        foreach ($deducoes as $atividade => $valor) {
            $restante = round((float) $valor, 2);

            if ($restante <= 0.0) {
                continue;
            }

            foreach ([$atividade, ...($ordemDeEscape[$atividade] ?? [])] as $alvo) {
                foreach (['sem', 'com'] as $coluna) {
                    if ($restante <= 0.0) {
                        break 2;
                    }

                    $disponivel = max(0.0, $blocos[$alvo][$coluna]);
                    $abate = min($disponivel, $restante);

                    $blocos[$alvo][$coluna] = round($blocos[$alvo][$coluna] - $abate, 2);
                    $restante = round($restante - $abate, 2);
                }
            }

            // Devolução maior que toda a receita do mês: o saldo fica onde
            // nasceu, e a linha sai negativa de propósito.
            if ($restante > 0.0) {
                $blocos[$atividade]['sem'] = round($blocos[$atividade]['sem'] - $restante, 2);
            }
        }

        return $blocos;
    }

    // ---------------------------------------------------------- classificação

    /**
     * @return array{comercio: float, industria: float, servicos: float, alertas: array<int, string>}
     */
    private function rateioDeOs(float $pecas, float $maoObra, float $liquido): array
    {
        if ($pecas + $maoObra <= 0.0) {
            // OS sem quebra de valores: o CNAE da casa é serviço.
            return $this->rateio(0.0, $liquido);
        }

        $rateio = RateioAtividade::dividir($pecas, $maoObra, $liquido);

        return $this->rateio($rateio['mercadoria'], $rateio['servico']);
    }

    /**
     * @param  array<int, array{mercadoria: float, servico: float}>  $proporcoes
     * @return array{comercio: float, industria: float, servicos: float, alertas: array<int, string>}
     */
    private function rateioDeVenda(int $vendaId, float $valor, array $proporcoes): array
    {
        if ($vendaId <= 0) {
            return $this->rateioSemClassificacao($valor);
        }

        $proporcao = $proporcoes[$vendaId] ?? null;

        if ($proporcao === null) {
            // Venda sem itens legíveis: balcão é comércio.
            return $this->rateio($valor, 0.0);
        }

        $rateio = RateioAtividade::dividir($proporcao['mercadoria'], $proporcao['servico'], $valor);

        return $this->rateio($rateio['mercadoria'], $rateio['servico']);
    }

    /**
     * Título de receita operacional lançado à mão, sem venda e sem OS.
     *
     * Não há atividade para ler. Cai em serviço e sai CONTADO em
     * `origens.sem_classificacao` — visível, não silencioso. Se esse número
     * crescer, o caminho é categorizar melhor no Financeiro, não adivinhar
     * aqui.
     *
     * @return array{comercio: float, industria: float, servicos: float, alertas: array<int, string>}
     */
    private function rateioSemClassificacao(float $valor): array
    {
        $rateio = $this->rateio(0.0, $valor);
        $rateio['alertas'][] = 'sem_classificacao_de_atividade';

        return $rateio;
    }

    /**
     * @param  array<int, string>  $alertas
     * @return array{comercio: float, industria: float, servicos: float, alertas: array<int, string>}
     */
    private function rateio(float $comercio, float $servicos, array $alertas = []): array
    {
        return [
            self::ATIVIDADE_COMERCIO => round($comercio, 2),
            // Assistência técnica não industrializa. A linha existe porque o
            // formulário oficial a tem, não porque o sistema a preenche.
            self::ATIVIDADE_INDUSTRIA => 0.0,
            self::ATIVIDADE_SERVICOS => round($servicos, 2),
            'alertas' => $alertas,
        ];
    }

    /**
     * Proporção mercadoria:serviço de cada venda, pelos itens.
     *
     * @param  array<int, int>  $vendaIds
     * @return array<int, array{mercadoria: float, servico: float}>
     */
    private function proporcoesDeVendas(array $vendaIds): array
    {
        $vendaIds = array_values(array_unique(array_filter($vendaIds)));

        if ($vendaIds === []) {
            return [];
        }

        // Tudo que não é serviço é mercadoria — inclui `avulso`, ver
        // AVULSO_COMO_ATIVIDADE.
        $linhas = DB::table('venda_itens')
            ->whereIn('venda_id', $vendaIds)
            ->selectRaw('venda_id, tipo_item, COALESCE(SUM(total), 0) as total')
            ->groupBy('venda_id', 'tipo_item')
            ->get();

        $proporcoes = [];

        foreach ($linhas as $linha) {
            $vendaId = (int) $linha->venda_id;
            $proporcoes[$vendaId] ??= ['mercadoria' => 0.0, 'servico' => 0.0];

            $balde = $linha->tipo_item === SaleItem::TYPE_SERVICE ? 'servico' : 'mercadoria';
            $proporcoes[$vendaId][$balde] += round((float) $linha->total, 2);
        }

        return $proporcoes;
    }

    /**
     * @param  array<int, int>  $osIds
     * @return array<int, array{pecas: float, mao_obra: float}>
     */
    private function proporcoesDeOs(array $osIds): array
    {
        $osIds = array_values(array_unique(array_filter($osIds)));

        if ($osIds === []) {
            return [];
        }

        return DB::table('os')
            ->whereIn('id', $osIds)
            ->get(['id', 'valor_pecas', 'valor_mao_obra'])
            ->mapWithKeys(fn ($os): array => [(int) $os->id => [
                'pecas' => round((float) $os->valor_pecas, 2),
                'mao_obra' => round((float) $os->valor_mao_obra, 2),
            ]])
            ->all();
    }

    /**
     * Proporção mercadoria:serviço de cada devolução, pelo item devolvido.
     *
     * `valor_reembolsado` já vem com o rateio do desconto geral da venda
     * aplicado (ver a migration de `venda_devolucao_itens`), então é ele — e
     * não `valor_total` — que reparte o valor do título.
     *
     * @param  array<int, int>  $financeiroIds
     * @return array<int, array{mercadoria: float, servico: float}>
     */
    private function proporcoesDeDevolucoes(array $financeiroIds): array
    {
        $financeiroIds = array_values(array_unique(array_filter($financeiroIds)));

        if ($financeiroIds === []) {
            return [];
        }

        $linhas = DB::table('venda_devolucoes as d')
            ->join('venda_devolucao_itens as di', 'di.venda_devolucao_id', '=', 'd.id')
            ->join('venda_itens as vi', 'vi.id', '=', 'di.venda_item_id')
            ->whereIn('d.financeiro_id', $financeiroIds)
            ->selectRaw('d.financeiro_id, vi.tipo_item, COALESCE(SUM(di.valor_reembolsado), 0) as total')
            ->groupBy('d.financeiro_id', 'vi.tipo_item')
            ->get();

        $proporcoes = [];

        foreach ($linhas as $linha) {
            $id = (int) $linha->financeiro_id;
            $proporcoes[$id] ??= ['mercadoria' => 0.0, 'servico' => 0.0];

            $balde = $linha->tipo_item === SaleItem::TYPE_SERVICE ? 'servico' : 'mercadoria';
            $proporcoes[$id][$balde] += round((float) $linha->total, 2);
        }

        return $proporcoes;
    }

    // ------------------------------------------------------ documento fiscal

    /**
     * Anexa a cada operação os documentos que a cobrem e reparte o valor entre
     * "com dispensa" e "com documento fiscal emitido".
     *
     * @param  array<int, array<string, mixed>>  $operacoes
     * @return array<int, array<string, mixed>>
     */
    private function anexarDocumentos(array $operacoes): array
    {
        $documentos = $this->documentosDasOperacoes($operacoes);
        $clientes = $this->clientesDasOperacoes($operacoes);

        foreach ($operacoes as $indice => $operacao) {
            $chave = $this->chaveDaOperacao($operacao);
            $doDaOperacao = $documentos[$chave] ?? [];

            $cobertura = [
                self::ATIVIDADE_COMERCIO => 0.0,
                self::ATIVIDADE_INDUSTRIA => 0.0,
                self::ATIVIDADE_SERVICOS => 0.0,
            ];

            $alertas = $operacao['alertas'];

            foreach ($doDaOperacao as $documento) {
                if ($documento['status'] === DocumentoFiscal::STATUS_RASCUNHO) {
                    // Rascunho é intenção, não documento. Não cobre — mas é
                    // acionável: alguém montou a nota e esqueceu de emitir.
                    $alertas[] = 'documento_rascunho';

                    continue;
                }

                if ($documento['status'] === DocumentoFiscal::STATUS_CANCELADO) {
                    $alertas[] = 'documento_cancelado';

                    continue;
                }

                // Rejeitado nunca existiu no fisco: não cobre e não alerta.
                if ($documento['status'] !== DocumentoFiscal::STATUS_EMITIDO) {
                    continue;
                }

                if ($documento['diverge_do_xml']) {
                    $alertas[] = 'valor_diverge_do_xml';
                }

                if ($documento['tipo'] === DocumentoFiscal::TIPO_NFSE) {
                    $cobertura[self::ATIVIDADE_SERVICOS] += $documento['valor_servicos'];

                    continue;
                }

                // NF-e / NFC-e cobrem mercadoria. O fallback para valor_total
                // existe porque uma NFC-e de balcão pode vir só com o total.
                $cobertura[self::ATIVIDADE_COMERCIO] += $documento['valor_pecas'] > 0.0
                    ? $documento['valor_pecas']
                    : $documento['valor_total'];
            }

            $blocos = [];

            foreach ([self::ATIVIDADE_COMERCIO, self::ATIVIDADE_INDUSTRIA, self::ATIVIDADE_SERVICOS] as $atividade) {
                $total = round((float) $operacao[$atividade], 2);

                // min() é o que garante I+II = III. Sem ele, um documento
                // maior que a operação (nota englobando duas OS, desconto dado
                // depois de emitir) deixaria a coluna "com dispensa" negativa.
                $comDocumento = round(min($total, round($cobertura[$atividade], 2)), 2);

                if ($cobertura[$atividade] > $total + 0.001) {
                    $alertas[] = 'documento_excedente';
                }

                if ($comDocumento > 0.0 && $comDocumento < $total - 0.001) {
                    $alertas[] = 'documento_parcial';
                }

                $blocos[$atividade] = [
                    'total' => $total,
                    'com_documento' => max(0.0, $comDocumento),
                    'sem_documento' => round($total - max(0.0, $comDocumento), 2),
                ];
            }

            if ($blocos[self::ATIVIDADE_COMERCIO]['sem_documento'] > 0.0 && $blocos[self::ATIVIDADE_COMERCIO]['total'] > 0.0) {
                $alertas[] = 'peca_sem_nfe';
            }

            if ($blocos[self::ATIVIDADE_SERVICOS]['sem_documento'] > 0.0 && $blocos[self::ATIVIDADE_SERVICOS]['total'] > 0.0) {
                $alertas[] = 'servico_sem_nfse';
            }

            $cliente = $clientes[$operacao['cliente_id']] ?? null;
            $tomadorPj = $this->tomadorPessoaJuridica($cliente, $doDaOperacao);

            $semDocumento = round(
                $blocos[self::ATIVIDADE_COMERCIO]['sem_documento']
                + $blocos[self::ATIVIDADE_INDUSTRIA]['sem_documento']
                + $blocos[self::ATIVIDADE_SERVICOS]['sem_documento'],
                2
            );

            if ($tomadorPj && $semDocumento > 0.0) {
                // Venda para PJ é justamente a hipótese em que o MEI NÃO está
                // dispensado de emitir documento fiscal (art. 106, § 1º).
                $alertas[] = 'tomador_pj_sem_documento';
            }

            $operacoes[$indice]['blocos'] = $blocos;
            $operacoes[$indice]['documentos'] = $doDaOperacao;
            $operacoes[$indice]['cliente_nome'] = $cliente['nome'] ?? null;
            $operacoes[$indice]['cliente_documento'] = $cliente['documento'] ?? null;
            $operacoes[$indice]['tomador_pj'] = $tomadorPj;
            $operacoes[$indice]['sem_documento_total'] = $semDocumento;
            $operacoes[$indice]['alertas'] = array_values(array_unique($alertas));
        }

        return $operacoes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $operacoes
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function documentosDasOperacoes(array $operacoes): array
    {
        $osIds = array_values(array_filter(array_column($operacoes, 'os_id')));
        $vendaIds = array_values(array_filter(array_column($operacoes, 'venda_id')));

        if ($osIds === [] && $vendaIds === []) {
            return [];
        }

        $registros = DocumentoFiscal::query()
            ->where(function ($q) use ($osIds, $vendaIds): void {
                if ($osIds !== []) {
                    $q->orWhereIn('os_id', $osIds);
                }

                if ($vendaIds !== []) {
                    $q->orWhereIn('venda_id', $vendaIds);
                }
            })
            ->orderBy('id')
            ->get();

        $porOperacao = [];

        foreach ($registros as $documento) {
            $vendaId = (int) ($documento->venda_id ?? 0);
            $osId = (int) ($documento->os_id ?? 0);

            // Documento com os_id E venda_id pertence à VENDA — mesma
            // precedência do regime de caixa. Contá-lo nos dois cobriria a
            // mesma receita duas vezes.
            $chave = $vendaId > 0 ? 'venda:'.$vendaId : 'os:'.$osId;

            $porOperacao[$chave][] = [
                'id' => (int) $documento->id,
                'tipo' => (string) $documento->tipo,
                'tipo_label' => $this->rotuloTipoDocumento((string) $documento->tipo),
                'status' => (string) $documento->status,
                'numero' => $documento->numero,
                'serie' => $documento->serie,
                'emitido_em' => $documento->emitido_em?->toDateString(),
                'valor_servicos' => round((float) $documento->valor_servicos, 2),
                'valor_pecas' => round((float) $documento->valor_pecas, 2),
                'valor_total' => round((float) $documento->valor_total, 2),
                'tomador_nome' => $documento->tomador_nome,
                'tomador_documento' => $documento->tomador_documento,
                'diverge_do_xml' => $documento->valorDivergeDoXml(),
            ];
        }

        return $porOperacao;
    }

    /**
     * @param  array<int, array<string, mixed>>  $operacoes
     * @return array<int, array{nome: string, documento: string, pj: bool}>
     */
    private function clientesDasOperacoes(array $operacoes): array
    {
        $ids = array_values(array_unique(array_filter(array_column($operacoes, 'cliente_id'))));

        if ($ids === []) {
            return [];
        }

        return DB::table('clientes')
            ->whereIn('id', $ids)
            ->get(['id', 'nome_razao', 'cpf_cnpj', 'tipo_pessoa'])
            ->mapWithKeys(fn ($c): array => [(int) $c->id => [
                'nome' => (string) $c->nome_razao,
                'documento' => (string) ($c->cpf_cnpj ?? ''),
                'pj' => $c->tipo_pessoa === 'juridica' || Documento::ehCnpj((string) ($c->cpf_cnpj ?? '')),
            ]])
            ->all();
    }

    /**
     * @param  array{nome: string, documento: string, pj: bool}|null  $cliente
     * @param  array<int, array<string, mixed>>  $documentos
     */
    private function tomadorPessoaJuridica(?array $cliente, array $documentos): bool
    {
        if ($cliente !== null && $cliente['pj']) {
            return true;
        }

        // Venda avulsa não tem cliente cadastrado; o CNPJ pode ter sido
        // informado só no documento fiscal.
        foreach ($documentos as $documento) {
            if (Documento::ehCnpj((string) ($documento['tomador_documento'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------- montagem

    /**
     * @param  array{comercio: float, industria: float, servicos: float, alertas: array<int, string>}  $rateio
     * @return array<string, mixed>
     */
    private function montarOperacao(
        string $tipo,
        int $id,
        string $referencia,
        string $data,
        int $clienteId,
        float $bruto,
        float $desconto,
        float $liquido,
        array $rateio,
        int $osId,
        int $vendaId
    ): array {
        return [
            'tipo' => $tipo,
            'id' => $id,
            'referencia' => $referencia,
            'data' => substr($data, 0, 10),
            'cliente_id' => $clienteId,
            'bruto' => $bruto,
            'desconto' => $desconto,
            'liquido' => $liquido,
            self::ATIVIDADE_COMERCIO => $rateio[self::ATIVIDADE_COMERCIO],
            self::ATIVIDADE_INDUSTRIA => $rateio[self::ATIVIDADE_INDUSTRIA],
            self::ATIVIDADE_SERVICOS => $rateio[self::ATIVIDADE_SERVICOS],
            'alertas' => $rateio['alertas'],
            'os_id' => $osId,
            'venda_id' => $vendaId,
        ];
    }

    /**
     * @param  array<string, mixed>  $operacao
     */
    private function chaveDaOperacao(array $operacao): string
    {
        return $operacao['venda_id'] > 0
            ? 'venda:'.$operacao['venda_id']
            : 'os:'.$operacao['os_id'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $operacoes
     * @return array<string, array{com: float, sem: float}>
     */
    private function acumularBlocos(array $operacoes): array
    {
        $blocos = [
            self::ATIVIDADE_COMERCIO => ['com' => 0.0, 'sem' => 0.0],
            self::ATIVIDADE_INDUSTRIA => ['com' => 0.0, 'sem' => 0.0],
            self::ATIVIDADE_SERVICOS => ['com' => 0.0, 'sem' => 0.0],
        ];

        foreach ($operacoes as $operacao) {
            foreach ($blocos as $atividade => $_) {
                $blocos[$atividade]['com'] += $operacao['blocos'][$atividade]['com_documento'];
                $blocos[$atividade]['sem'] += $operacao['blocos'][$atividade]['sem_documento'];
            }
        }

        foreach ($blocos as $atividade => $valores) {
            $blocos[$atividade]['com'] = round($valores['com'], 2);
            $blocos[$atividade]['sem'] = round($valores['sem'], 2);
        }

        return $blocos;
    }

    /**
     * @param  array<string, array{com: float, sem: float}>  $blocos
     * @return array<string, array<string, mixed>>
     */
    private function montarLinhas(array $blocos, array $calculados = [], array $somasDeAjuste = []): array
    {
        $valores = $this->valoresDeLinha($blocos);

        $calculadas = ['iii', 'vi', 'ix', 'x'];
        $linhas = [];

        foreach (self::ROTULOS as $chave => $rotulo) {
            $ehCalculada = in_array($chave, $calculadas, true);

            // `valor` significa O DECLARADO — o que vai para o formulário
            // entregue ao fisco. Manter esse significado é o que faz o
            // fechamento congelar o número certo, a reconferência comparar
            // declarado contra declarado e o PDF imprimir toda a receita bruta,
            // tudo sem uma linha de mudança nesses três lugares.
            $linhas[$chave] = [
                'rotulo' => $rotulo,
                'valor' => round($valores[$chave], 2),
                'calculado' => round($calculados[$chave] ?? $valores[$chave], 2),
                'ajuste' => round($somasDeAjuste[$chave] ?? 0.0, 2),
                'ajustavel' => AnexoXAjuste::linhaAjustavel($chave),
                'calculada' => $ehCalculada,
            ];
        }

        return $linhas;
    }

    /**
     * As dez linhas a partir dos blocos — as seis folhas lidas direto e as
     * quatro somas derivadas delas.
     *
     * A aritmética de III/VI/IX/X vive AQUI e em nenhum outro lugar: é o que
     * garante que o ajuste manual, entrando nos blocos, recomponha os totais
     * sozinho e o formulário continue fechando.
     *
     * @param  array<string, array{com: float, sem: float}>  $blocos
     * @return array<string, float>
     */
    private function valoresDeLinha(array $blocos): array
    {
        $valores = [
            'i' => $blocos[self::ATIVIDADE_COMERCIO]['sem'],
            'ii' => $blocos[self::ATIVIDADE_COMERCIO]['com'],
            'iv' => $blocos[self::ATIVIDADE_INDUSTRIA]['sem'],
            'v' => $blocos[self::ATIVIDADE_INDUSTRIA]['com'],
            'vii' => $blocos[self::ATIVIDADE_SERVICOS]['sem'],
            'viii' => $blocos[self::ATIVIDADE_SERVICOS]['com'],
        ];

        $valores['iii'] = round($valores['i'] + $valores['ii'], 2);
        $valores['vi'] = round($valores['iv'] + $valores['v'], 2);
        $valores['ix'] = round($valores['vii'] + $valores['viii'], 2);
        $valores['x'] = round($valores['iii'] + $valores['vi'] + $valores['ix'], 2);

        return array_map(fn (float $v): float => round($v, 2), $valores);
    }

    /**
     * Soma os ajustes manuais aos blocos, na coluna e atividade de cada linha.
     *
     * Nos BLOCOS, e não nas linhas prontas: assim III/VI/IX/X se recompõem
     * sozinhas em `valoresDeLinha()` e a aritmética do formulário continua
     * existindo num lugar só. Somar nas linhas depois exigiria re-somar num
     * segundo lugar — e no dia em que os dois divergissem, o formulário
     * entregue ao fisco não fecharia.
     *
     * @param  array<string, array{com: float, sem: float}>  $blocos
     * @param  array<string, float>  $somas
     * @return array<string, array{com: float, sem: float}>
     */
    private function aplicarAjustes(array $blocos, array $somas): array
    {
        foreach ($somas as $linha => $valor) {
            $destino = AnexoXAjuste::MAPA_DE_BLOCO[$linha] ?? null;

            if ($destino === null || abs((float) $valor) < 0.005) {
                continue;
            }

            [$atividade, $coluna] = $destino;
            $blocos[$atividade][$coluna] = round($blocos[$atividade][$coluna] + (float) $valor, 2);
        }

        return $blocos;
    }

    /**
     * @param  array<int, array<string, mixed>>  $operacoes
     * @return array<string, mixed>
     */
    private function origens(array $operacoes): array
    {
        $origens = [
            'os' => ['comercio' => 0.0, 'servicos' => 0.0, 'quantidade' => 0],
            'vendas' => ['comercio' => 0.0, 'servicos' => 0.0, 'quantidade' => 0],
            'movimentos' => ['comercio' => 0.0, 'servicos' => 0.0, 'quantidade' => 0],
            'sem_classificacao' => ['servicos' => 0.0, 'quantidade' => 0],
        ];

        $mapa = ['os' => 'os', 'venda' => 'vendas', 'titulo' => 'vendas', 'movimento' => 'movimentos'];

        foreach ($operacoes as $operacao) {
            $balde = $mapa[$operacao['tipo']] ?? 'vendas';

            $origens[$balde]['comercio'] += $operacao[self::ATIVIDADE_COMERCIO];
            $origens[$balde]['servicos'] += $operacao[self::ATIVIDADE_SERVICOS];
            $origens[$balde]['quantidade']++;

            if (in_array('sem_classificacao_de_atividade', $operacao['alertas'], true)) {
                $origens['sem_classificacao']['servicos'] += $operacao[self::ATIVIDADE_SERVICOS];
                $origens['sem_classificacao']['quantidade']++;
            }
        }

        foreach ($origens as $chave => $valores) {
            foreach ($valores as $campo => $valor) {
                $origens[$chave][$campo] = $campo === 'quantidade' ? (int) $valor : round((float) $valor, 2);
            }
        }

        // Quanto de I/II veio de item avulso do PDV — a classificação que esta
        // classe assume sem o banco confirmar. Fica exposto para conferência.
        $origens['avulsos_da_venda'] = ['comercio' => $this->totalAvulsoDeVendas($operacoes)];

        return $origens;
    }

    /**
     * @param  array<int, array<string, mixed>>  $operacoes
     */
    private function totalAvulsoDeVendas(array $operacoes): float
    {
        $vendaIds = array_values(array_filter(array_column($operacoes, 'venda_id')));

        if ($vendaIds === []) {
            return 0.0;
        }

        return round((float) DB::table('venda_itens')
            ->whereIn('venda_id', $vendaIds)
            ->where('tipo_item', SaleItem::TYPE_LOOSE)
            ->sum('total'), 2);
    }

    /**
     * Extra de TELA: a receita que caiu nas colunas "com dispensa".
     *
     * Serve para o operador conferir se alguma delas era, na verdade, obrigada
     * a emitir documento fiscal — o caso do tomador pessoa jurídica.
     * NÃO entra no PDF do formulário: o Anexo X é padrão da Receita e não se
     * modifica.
     *
     * @param  array<int, array<string, mixed>>  $operacoes
     * @return array<string, mixed>
     */
    private function semDocumento(array $operacoes): array
    {
        $itens = array_values(array_filter(
            $operacoes,
            fn (array $op): bool => $op['sem_documento_total'] > 0.0
        ));

        $pj = array_values(array_filter($itens, fn (array $op): bool => $op['tomador_pj']));

        return [
            'total' => round(array_sum(array_column($itens, 'sem_documento_total')), 2),
            'quantidade' => count($itens),
            'total_tomador_pj' => round(array_sum(array_column($pj, 'sem_documento_total')), 2),
            'quantidade_tomador_pj' => count($pj),
            'itens' => array_map(fn (array $op): array => $this->apresentarOperacao($op), $itens),
        ];
    }

    /**
     * @param  array<string, mixed>  $operacao
     * @return array<string, mixed>
     */
    private function apresentarOperacao(array $operacao): array
    {
        return [
            'tipo' => $operacao['tipo'],
            'id' => $operacao['id'],
            'referencia' => $operacao['referencia'],
            'data' => $operacao['data'],
            'cliente_id' => $operacao['cliente_id'] > 0 ? $operacao['cliente_id'] : null,
            'cliente_nome' => $operacao['cliente_nome'],
            'cliente_documento' => $operacao['cliente_documento'],
            'tomador_pj' => $operacao['tomador_pj'],
            'bruto' => $operacao['bruto'],
            'desconto' => $operacao['desconto'],
            'liquido' => $operacao['liquido'],
            self::ATIVIDADE_COMERCIO => $operacao['blocos'][self::ATIVIDADE_COMERCIO],
            self::ATIVIDADE_INDUSTRIA => $operacao['blocos'][self::ATIVIDADE_INDUSTRIA],
            self::ATIVIDADE_SERVICOS => $operacao['blocos'][self::ATIVIDADE_SERVICOS],
            'sem_documento_total' => $operacao['sem_documento_total'],
            'documentos' => $operacao['documentos'],
            'alertas' => $operacao['alertas'],
        ];
    }

    // -------------------------------------------------------- acumulado anual

    /**
     * Receita bruta acumulada no ano-calendário contra o limite do MEI.
     *
     * Extra de TELA. Fora do MEI o teto de R$ 81.000 simplesmente não existe, e
     * exibi-lo seria erro ativo — por isso devolve null.
     *
     * Faz UMA passada com o range do ano inteiro, e não doze chamadas de
     * `apurar()`. Os meses já fechados entram pelo valor CONGELADO
     * (`linha_x`), não pelo recalculado: o limite tem que ser conferido contra
     * o que foi efetivamente declarado.
     *
     * @param  array<string, mixed>  $empresa
     * @return array<string, mixed>|null
     */
    public function acumuladoAnual(
        string $competencia,
        string $regime = self::REGIME_COMPETENCIA,
        ?string $regimeTributario = null,
        ?array $empresa = null
    ): ?array {
        $competencia = PeriodoMensal::normalizar($competencia);
        $regime = self::normalizarRegime($regime);
        $regimeTributario ??= $this->regimeTributario();

        if ($regimeTributario !== RegimeTributario::MEI) {
            return null;
        }

        $empresa ??= $this->empresa();
        $ano = (int) substr($competencia, 0, 4);
        $mesFinal = (int) substr($competencia, 5, 2);

        $porMes = [];
        $acumulado = 0.0;
        $mesesFechados = [];

        $congelados = AnexoXFechamento::query()
            ->where('regime', $regime)
            ->where('status', AnexoXFechamento::STATUS_FECHADO)
            ->where('competencia', 'like', $ano.'-%')
            ->orderBy('versao')
            ->get()
            ->keyBy('competencia');

        for ($mes = 1; $mes <= $mesFinal; $mes++) {
            $chave = sprintf('%04d-%02d', $ano, $mes);
            $fechamento = $congelados->get($chave);

            if ($fechamento instanceof AnexoXFechamento) {
                $valor = round((float) $fechamento->linha_x, 2);
                $mesesFechados[] = $chave;
            } else {
                $valor = $this->totalBrutoDoMes($chave, $regime);
            }

            $porMes[$chave] = $valor;
            $acumulado += $valor;
        }

        return $this->montarAcumulado($porMes, $mesesFechados, $ano, $mesFinal, $empresa);
    }

    /**
     * O bloco do acumulado a partir dos totais mensais já conhecidos.
     *
     * Compartilhado por `acumuladoAnual()` (que varre os meses) e por
     * `resumoAnual()` (que já tem os doze totais na mão). Sem esse
     * compartilhamento, o card do acumulado poderia discordar da soma da coluna
     * TOTAL da tabela na mesma tela.
     *
     * @param  array<string, float>  $porMes
     * @param  array<int, string>  $mesesFechados
     * @param  array<string, mixed>  $empresa
     * @return array<string, mixed>
     */
    private function montarAcumulado(array $porMes, array $mesesFechados, int $ano, int $mesFinal, array $empresa): array
    {
        $acumulado = round(array_sum($porMes), 2);
        [$limite, $proporcional, $mesesDeAtividade] = $this->limiteMei($ano, $empresa['data_abertura'] ?? '');
        $limiteExcesso = round($limite * (1 + self::LIMITE_MEI_EXCESSO_PCT / 100), 2);

        return [
            'ano' => $ano,
            'meses_considerados' => $mesFinal,
            'acumulado' => $acumulado,
            'por_mes' => $porMes,
            'meses_fechados' => $mesesFechados,
            'limite' => $limite,
            'limite_proporcional' => $proporcional,
            'meses_de_atividade' => $mesesDeAtividade,
            'limite_excesso_20' => $limiteExcesso,
            'percentual_do_limite' => $limite > 0.0 ? round($acumulado / $limite * 100, 1) : 0.0,
            'restante' => round($limite - $acumulado, 2),
            'faixa' => $this->faixaDoLimite($acumulado, $limite, $limiteExcesso),
            'mensagem' => $this->mensagemDoLimite($acumulado, $limite, $limiteExcesso),
        ];
    }

    /**
     * Total bruto de um mês não fechado, sem montar o relatório inteiro.
     */
    private function totalBrutoDoMes(string $competencia, string $regime): float
    {
        [$inicio, $fim] = PeriodoMensal::resolver($competencia);

        // O ajuste manual e' receita declarada e conta para o limite do MEI
        // como qualquer outra. Sem esta parcela, o card do acumulado
        // discordaria da coluna TOTAL da tabela do ano na mesma tela.
        $ajuste = round(array_sum($this->ajustes->somasPorLinha($competencia, $regime)), 2);

        if ($regime === self::REGIME_CAIXA) {
            $receita = (float) $this->receitaBrutaSource
                ->queryMovimentos(Financeiro::TIPO_RECEBER, $inicio, $fim, onlyOperacional: true)
                ->sum('financeiro_movimentos.valor_movimento');

            $devolucoes = (float) $this->receitaBrutaSource->linhasPorMovimento(
                Financeiro::TIPO_PAGAR,
                null,
                $inicio,
                $fim,
                origemTipo: Financeiro::ORIGEM_TIPO_VENDA_DEVOLUCAO
            )->sum('valor');

            return round($receita - $devolucoes + $ajuste, 2);
        }

        $os = $this->receitaBrutaSource
            ->queryOrdensReconhecidas($inicio, $fim)
            ->selectRaw('COALESCE(SUM(os.valor_total), 0) - COALESCE(SUM(os.desconto), 0) as liquido')
            ->value('liquido');

        $titulos = (float) $this->receitaBrutaSource->linhasPorCompetencia(
            Financeiro::TIPO_RECEBER,
            Financeiro::GRUPO_DRE_RECEITA_OPERACIONAL,
            $inicio,
            $fim,
            excludeOs: true,
            incluirVendas: true
        )->sum('valor');

        $devolucoes = (float) $this->receitaBrutaSource->linhasPorCompetencia(
            Financeiro::TIPO_PAGAR,
            null,
            $inicio,
            $fim,
            origemTipo: Financeiro::ORIGEM_TIPO_VENDA_DEVOLUCAO
        )->sum('valor');

        return round((float) $os + $titulos - $devolucoes + $ajuste, 2);
    }

    /**
     * Limite do MEI no ano, proporcionalizado quando a empresa abriu nele.
     *
     * Sem data de abertura cadastrada, aplica o limite integral. Errar para o
     * lado permissivo é melhor que acusar estouro de limite que não existe.
     *
     * @return array{0: float, 1: bool, 2: int}
     */
    private function limiteMei(int $ano, string $dataAbertura): array
    {
        $dataAbertura = trim($dataAbertura);

        if ($dataAbertura === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAbertura)) {
            return [self::LIMITE_MEI_ANUAL, false, 12];
        }

        $anoAbertura = (int) substr($dataAbertura, 0, 4);

        if ($anoAbertura !== $ano) {
            return [self::LIMITE_MEI_ANUAL, false, 12];
        }

        // Conta o mês de abertura inteiro, inclusive (LC 123/2006, art. 18-A,
        // § 2º: "proporcionalmente ao número de meses ... incluída a fração de
        // mês").
        $mesesDeAtividade = 12 - (int) substr($dataAbertura, 5, 2) + 1;

        return [round(self::LIMITE_MEI_ANUAL / 12 * $mesesDeAtividade, 2), true, $mesesDeAtividade];
    }

    private function faixaDoLimite(float $acumulado, float $limite, float $limiteExcesso): string
    {
        if ($acumulado > $limiteExcesso) {
            return 'excesso_acima_20';
        }

        if ($acumulado > $limite) {
            return 'excesso_ate_20';
        }

        return 'dentro';
    }

    private function mensagemDoLimite(float $acumulado, float $limite, float $limiteExcesso): ?string
    {
        if ($acumulado > $limiteExcesso) {
            return 'Receita acumulada acima de '.$this->moeda($limiteExcesso).' — excesso superior a 20% do limite. '
                .'O desenquadramento do SIMEI retroage ao início do ano-calendário.';
        }

        if ($acumulado > $limite) {
            return 'Receita acumulada acima do limite de '.$this->moeda($limite).', mas dentro dos 20% de excesso. '
                .'O desenquadramento vale a partir de 1º de janeiro do ano seguinte.';
        }

        return null;
    }

    private function moeda(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }

    /**
     * Os doze meses de um ano-calendário, para o PDF anual.
     *
     * Cada mês passa por `relatorio()`, então mês encerrado sai pelo valor
     * CONGELADO — um bloco anual impresso em dezembro tem que reproduzir o que
     * foi declarado em cada mês, não o que os dados de hoje diriam.
     *
     * Sem o acumulado do ano: ele não entra no formulário e custaria uma
     * varredura de doze meses por mês impresso.
     *
     * @return array<int, array<string, mixed>> doze relatórios, de janeiro a dezembro
     */
    public function relatorioAnual(int $ano, string $regime = self::REGIME_COMPETENCIA): array
    {
        $regime = self::normalizarRegime($regime);
        $relatorios = [];

        for ($mes = 1; $mes <= 12; $mes++) {
            $relatorios[] = $this->relatorio(
                sprintf('%04d-%02d', $ano, $mes),
                $regime,
                reconferir: false,
                incluirAcumulado: false
            );
        }

        return $relatorios;
    }

    /**
     * Os doze meses do ano nos DOIS regimes, para a tela do ano.
     *
     * Passa pelo mesmo `apurarBlocos()` que `apurar()` usa — a tabela e o modal
     * do mesmo mês não podem discordar. O que muda é só o que se descarta: aqui
     * as operações são jogadas fora depois de somadas, e não há drill-down,
     * receita sem documento nem acumulado por mês.
     *
     * **Laço de 12 meses, e não uma varredura única do ano.**
     * `ReceitaBrutaSource::linhasPorCompetencia()` faz merge dos títulos
     * `dre_fixo_mensal` com vencimento até o fim do período: chamada mês a mês,
     * um título fixo de janeiro aparece em todos os meses; chamada com o range
     * do ano, aparece uma vez só. A "otimização óbvia" produziria números
     * diferentes de `apurar()`, em silêncio, num relatório fiscal.
     *
     * O que É hasteado do laço: empresa, regime tributário, fechamentos do ano
     * (com os autores) e ajustes do ano. Sem isso seriam ~50 queries a mais.
     *
     * @return array<string, mixed>
     */
    public function resumoAnual(int $ano): array
    {
        $empresa = $this->empresa();
        $regimeTributario = $this->regimeTributario();

        $fechamentos = $this->fechamentosDoAno($ano);
        $ajustesDoAno = $this->ajustes->vigentesDoAno($ano);

        $meses = [];
        $porMes = [self::REGIME_COMPETENCIA => [], self::REGIME_CAIXA => []];
        $mesesFechados = [self::REGIME_COMPETENCIA => [], self::REGIME_CAIXA => []];
        $mostrarIndustria = false;

        $mesCorrente = now()->format('Y-m');

        for ($mes = 1; $mes <= 12; $mes++) {
            $competencia = sprintf('%04d-%02d', $ano, $mes);
            $regimes = [];

            foreach (self::regimes() as $regime) {
                $chave = $competencia.'|'.$regime;
                $fechamento = $fechamentos[$chave] ?? null;

                $regimes[$regime] = $this->resumoDoMes(
                    $competencia,
                    $regime,
                    $fechamento,
                    $ajustesDoAno[$chave] ?? null
                );

                $porMes[$regime][$competencia] = $regimes[$regime]['total'];

                if ($fechamento instanceof AnexoXFechamento) {
                    $mesesFechados[$regime][] = $competencia;
                }

                if (abs($regimes[$regime]['industria']) > 0.005) {
                    $mostrarIndustria = true;
                }
            }

            $meses[] = [
                'competencia' => $competencia,
                'mes' => $mes,
                'mes_label' => self::MESES_CURTOS[$mes - 1],
                'periodo_label' => sprintf('%02d/%04d', $mes, $ano),
                'futuro' => $competencia > $mesCorrente,
                'em_curso' => $competencia === $mesCorrente,
                'regimes' => $regimes,
            ];
        }

        $mesFinal = $ano < (int) now()->format('Y') ? 12 : (int) now()->format('n');
        $mesFinal = $ano > (int) now()->format('Y') ? 0 : $mesFinal;

        return [
            'ano' => $ano,
            'regime_tributario' => $regimeTributario,
            'aviso_regime_tributario' => $this->avisoRegimeTributario($regimeTributario),
            // Achado do "Perguntas e Respostas MEI e Simei" da Receita Federal:
            // o limite e' sobre a receita bruta AUFERIDA no ano-calendario —
            // "auferida" e' o termo do regime de COMPETENCIA. O regime de caixa
            // e' opcao do ME/EPP exercida no PGDAS-D, e o MEI usa DASN-Simei,
            // que nao tem esse mecanismo.
            'regime_que_conta_para_o_limite' => self::REGIME_COMPETENCIA,
            'empresa' => $empresa,
            'rotulos' => self::ROTULOS,
            'mostrar_industria' => $mostrarIndustria,
            'meses' => $meses,
            'acumulado' => [
                self::REGIME_COMPETENCIA => $regimeTributario === RegimeTributario::MEI
                    ? $this->montarAcumulado(
                        array_slice($porMes[self::REGIME_COMPETENCIA], 0, max($mesFinal, 0), true),
                        $mesesFechados[self::REGIME_COMPETENCIA],
                        $ano,
                        $mesFinal,
                        $empresa
                    )
                    : null,
                self::REGIME_CAIXA => $regimeTributario === RegimeTributario::MEI
                    ? $this->montarAcumulado(
                        array_slice($porMes[self::REGIME_CAIXA], 0, max($mesFinal, 0), true),
                        $mesesFechados[self::REGIME_CAIXA],
                        $ano,
                        $mesFinal,
                        $empresa
                    )
                    : null,
            ],
            'totais' => [
                self::REGIME_COMPETENCIA => $this->totaisDoAno($meses, self::REGIME_COMPETENCIA),
                self::REGIME_CAIXA => $this->totaisDoAno($meses, self::REGIME_CAIXA),
            ],
            'grafico' => $this->grafico($meses, $ano, $empresa),
            'gerado_em' => now()->toIso8601String(),
        ];
    }

    /**
     * Um mês num regime, na forma reduzida da tabela do ano.
     *
     * Mês encerrado NÃO apura: vem das colunas congeladas, custo zero. É o que
     * faz um ano com oito meses fechados custar quase metade de um ano aberto —
     * e, mais importante, é o que garante que a tabela mostre o que foi
     * declarado, não o que os dados de hoje diriam.
     *
     * @param  Collection<int, \App\Models\AnexoXAjuste>|null  $ajustesDoMes
     * @return array<string, mixed>
     */
    private function resumoDoMes(
        string $competencia,
        string $regime,
        ?AnexoXFechamento $fechamento,
        ?Collection $ajustesDoMes
    ): array {
        if ($fechamento instanceof AnexoXFechamento) {
            $linhas = [];

            foreach (array_keys(self::ROTULOS) as $chave) {
                $linhas[$chave] = [
                    'valor' => round((float) $fechamento->{'linha_'.$chave}, 2),
                    'calculado' => round((float) $fechamento->{'linha_'.$chave}, 2),
                    'ajuste' => 0.0,
                    'ajustavel' => AnexoXAjuste::linhaAjustavel($chave),
                    'calculada' => in_array($chave, ['iii', 'vi', 'ix', 'x'], true),
                ];
            }

            return $this->montarResumo(
                $linhas,
                [
                    'descontos' => round((float) $fechamento->deducao_descontos, 2),
                    'devolucoes' => round((float) $fechamento->deducao_devolucoes, 2),
                ],
                round((float) $fechamento->ajuste_total, 2),
                (int) $fechamento->ajuste_quantidade,
                'fechamento',
                $this->fechamentos->apresentarResumido($fechamento)
            );
        }

        $nucleo = $this->apurarBlocos($competencia, $regime, $ajustesDoMes);
        $ajuste = $ajustesDoMes ?? collect();

        return $this->montarResumo(
            $nucleo['linhas'],
            $nucleo['deducoes'],
            round((float) $ajuste->sum(fn ($a): float => (float) $a->valor), 2),
            $ajuste->count(),
            'ao_vivo',
            null
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $linhas
     * @param  array<string, mixed>  $deducoes
     * @param  array<string, mixed>|null  $fechamento
     * @return array<string, mixed>
     */
    private function montarResumo(
        array $linhas,
        array $deducoes,
        float $ajusteTotal,
        int $ajusteQuantidade,
        string $origem,
        ?array $fechamento
    ): array {
        $v = static fn (string $chave): float => round((float) ($linhas[$chave]['valor'] ?? 0), 2);

        return [
            'linhas' => $linhas,
            'total' => $v('x'),
            'comercio' => $v('iii'),
            'industria' => $v('vi'),
            'servicos' => $v('ix'),
            // Aritmética fiscal: II+V+VIII e I+IV+VII. Calculada aqui, e não no
            // Blade, para o mobile não ter que reescrevê-la um dia.
            'com_documento' => round($v('ii') + $v('v') + $v('viii'), 2),
            'sem_documento' => round($v('i') + $v('iv') + $v('vii'), 2),
            'ajuste_total' => $ajusteTotal,
            'ajuste_quantidade' => $ajusteQuantidade,
            'deducoes' => [
                'descontos' => round((float) ($deducoes['descontos'] ?? 0), 2),
                'devolucoes' => round((float) ($deducoes['devolucoes'] ?? 0), 2),
            ],
            'origem_dos_valores' => $origem,
            'fechamento' => $fechamento,
        ];
    }

    /**
     * Fechamentos vigentes do ano, indexados por `competencia|regime`, com os
     * autores já carregados.
     *
     * O eager loading não é luxo: `apresentarResumido()` lê `autorFechamento` e
     * `autorReabertura`, e sem ele seriam duas queries por fechamento — até 48
     * no ano.
     *
     * @return array<string, AnexoXFechamento>
     */
    private function fechamentosDoAno(int $ano): array
    {
        return AnexoXFechamento::query()
            ->where('status', AnexoXFechamento::STATUS_FECHADO)
            ->where('competencia', 'like', sprintf('%04d-%%', $ano))
            ->with(['autorFechamento', 'autorReabertura'])
            ->orderBy('versao')
            ->get()
            ->keyBy(fn (AnexoXFechamento $f): string => $f->competencia.'|'.$f->regime)
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $meses
     * @return array<string, float>
     */
    private function totaisDoAno(array $meses, string $regime): array
    {
        $campos = ['total', 'comercio', 'industria', 'servicos', 'com_documento', 'sem_documento', 'ajuste_total'];
        $totais = array_fill_keys($campos, 0.0);

        foreach ($meses as $mes) {
            foreach ($campos as $campo) {
                $totais[$campo] += (float) ($mes['regimes'][$regime][$campo] ?? 0);
            }
        }

        return array_map(fn (float $v): float => round($v, 2), $totais);
    }

    /**
     * Série do gráfico: os DOIS regimes, doze pontos cada.
     *
     * Os dois no mesmo payload para o alternador da tela trocar de leitura sem
     * ida ao servidor — mesmo desenho do gráfico financeiro do dashboard.
     *
     * @param  array<int, array<string, mixed>>  $meses
     * @param  array<string, mixed>  $empresa
     * @return array<string, mixed>
     */
    private function grafico(array $meses, int $ano, array $empresa): array
    {
        $series = [];

        foreach (self::regimes() as $regime) {
            $series[$regime] = ['bruto' => [], 'com_documento' => [], 'sem_documento' => [], 'ajuste' => []];

            foreach ($meses as $mes) {
                $dados = $mes['regimes'][$regime];
                $series[$regime]['bruto'][] = $dados['total'];
                $series[$regime]['com_documento'][] = $dados['com_documento'];
                $series[$regime]['sem_documento'][] = $dados['sem_documento'];
                $series[$regime]['ajuste'][] = $dados['ajuste_total'];
            }
        }

        [$limite, $proporcional, $mesesDeAtividade] = $this->limiteMei($ano, $empresa['data_abertura'] ?? '');

        return [
            'year' => $ano,
            'labels' => self::MESES_CURTOS,
            'mes_atual' => (int) now()->format('n'),
            'ano_corrente' => (int) now()->format('Y'),
            'regimes' => $series,
            'limite' => [
                'anual' => self::LIMITE_MEI_ANUAL,
                'aplicado' => $limite,
                'mensal_medio' => round($limite / 12, 2),
                'proporcional' => $proporcional,
                'meses_de_atividade' => $mesesDeAtividade,
            ],
            'legend' => [
                ['key' => 'competencia', 'label' => 'Competência', 'color' => '#6f5afc', 'type' => 'bar'],
                ['key' => 'caixa', 'label' => 'Caixa', 'color' => '#0ea5e9', 'type' => 'bar'],
                ['key' => 'limite_mensal', 'label' => 'Média mensal do limite', 'color' => '#f59e0b', 'type' => 'dashed'],
            ],
        ];
    }

    // ---------------------------------------------- documentos emitidos (anexo)

    /**
     * Relação dos documentos fiscais emitidos no mês.
     *
     * Vira PDF SEPARADO, nunca uma seção do formulário: o Anexo X é padrão da
     * Receita e não se modifica. A última cláusula do próprio formulário pede
     * que as notas emitidas sejam anexadas ao relatório — anexadas, não
     * embutidas.
     *
     * Critério é a DATA DE EMISSÃO, enquanto as colunas II/VIII do formulário
     * falam da OPERAÇÃO. Uma NFS-e emitida em 03/10 de uma OS entregue em
     * 28/09 conta na coluna VIII de setembro e nesta relação em outubro. A
     * divergência é legítima e está dita em `criterio`.
     *
     * Canceladas aparecem identificadas: omiti-las abriria buraco na sequência
     * numérica, que é o primeiro lugar onde a fiscalização olha.
     *
     * @return array<string, mixed>
     */
    public function documentosEmitidosNoMes(string $competencia): array
    {
        $competencia = PeriodoMensal::normalizar($competencia);
        [$inicio, $fim, $label] = PeriodoMensal::resolver($competencia);

        $registros = DocumentoFiscal::query()
            ->whereIn('status', [DocumentoFiscal::STATUS_EMITIDO, DocumentoFiscal::STATUS_CANCELADO])
            ->whereNotNull('emitido_em')
            ->whereBetween('emitido_em', [$inicio->startOfDay(), $fim->endOfDay()])
            ->orderBy('emitido_em')
            ->orderBy('id')
            ->get();

        $numerosOs = $this->numerosDeOs(
            $registros->pluck('os_id')->filter()->map(fn ($id): int => (int) $id)->all()
        );

        $numerosVenda = $this->numerosDeVenda(
            $registros->pluck('venda_id')->filter()->map(fn ($id): int => (int) $id)->all()
        );

        $documentos = [];
        $totais = ['nfse' => 0.0, 'nfe' => 0.0, 'nfce' => 0.0, 'geral' => 0.0, 'canceladas' => 0.0];
        $quantidadeCanceladas = 0;

        foreach ($registros as $registro) {
            $valor = round((float) $registro->valor_total, 2);
            $cancelado = $registro->status === DocumentoFiscal::STATUS_CANCELADO;

            $origem = null;

            if ((int) ($registro->venda_id ?? 0) > 0) {
                $origem = $numerosVenda[(int) $registro->venda_id] ?? ('Venda '.$registro->venda_id);
            } elseif ((int) ($registro->os_id ?? 0) > 0) {
                $origem = $numerosOs[(int) $registro->os_id] ?? ('OS '.$registro->os_id);
            }

            $documentos[] = [
                'id' => (int) $registro->id,
                'tipo' => (string) $registro->tipo,
                'tipo_label' => $this->rotuloTipoDocumento((string) $registro->tipo),
                'serie' => $registro->serie,
                'numero' => $registro->numero,
                'emitido_em' => $registro->emitido_em?->toDateString(),
                'tomador_nome' => $registro->tomador_nome,
                'tomador_documento' => $registro->tomador_documento,
                'origem' => $origem,
                'valor_servicos' => round((float) $registro->valor_servicos, 2),
                'valor_pecas' => round((float) $registro->valor_pecas, 2),
                'valor_total' => $valor,
                'situacao' => (string) $registro->status,
                'cancelado_em' => $registro->cancelado_em?->toDateString(),
            ];

            if ($cancelado) {
                $totais['canceladas'] += $valor;
                $quantidadeCanceladas++;

                continue;
            }

            $totais[(string) $registro->tipo] = ($totais[(string) $registro->tipo] ?? 0.0) + $valor;
            $totais['geral'] += $valor;
        }

        $totais = array_map(fn (float $v): float => round($v, 2), $totais);
        $totais['quantidade'] = count($documentos);
        $totais['quantidade_canceladas'] = $quantidadeCanceladas;

        return [
            'competencia' => $competencia,
            'periodo_label' => $label,
            'criterio' => 'Data de emissão do documento (documentos_fiscais.emitido_em).',
            'empresa' => $this->empresa(),
            'documentos' => $documentos,
            'totais' => $totais,
            'gerado_em' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function numerosDeOs(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        return DB::table('os')
            ->whereIn('id', $ids)
            ->get(['id', 'numero_os'])
            ->mapWithKeys(fn ($os): array => [(int) $os->id => 'OS '.$os->numero_os])
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function numerosDeVenda(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        return DB::table('vendas')
            ->whereIn('id', $ids)
            ->get(['id', 'numero'])
            ->mapWithKeys(fn ($v): array => [(int) $v->id => 'Venda '.$v->numero])
            ->all();
    }

    // ----------------------------------------------------------------- apoio

    /**
     * @return array<string, mixed>
     */
    private function historicoSemVigente(string $competencia, string $regime): ?array
    {
        $ultimo = $this->fechamentos->ultimo($competencia, $regime);

        if (! $ultimo instanceof AnexoXFechamento) {
            return null;
        }

        // Existe histórico, mas nenhum fechamento vale hoje: o mês foi
        // reaberto. A tela precisa mostrar por quê.
        return $this->fechamentos->apresentar($ultimo);
    }

    /**
     * @return array<string, mixed>
     */
    private function empresa(): array
    {
        $payload = $this->companyProfileService->payload();
        $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];

        return [
            'razao_social' => trim((string) ($settings['empresa_razao_social'] ?? '')),
            'nome_fantasia' => trim((string) ($settings['empresa_nome_fantasia'] ?? '')),
            'cnpj' => Documento::formatar((string) ($settings['empresa_cnpj'] ?? '')),
            'cidade' => trim((string) ($settings['empresa_cidade'] ?? '')),
            'uf' => strtoupper(trim((string) ($settings['empresa_uf'] ?? ''))),
            'data_abertura' => trim((string) ($settings['empresa_data_abertura'] ?? '')),
        ];
    }

    private function regimeTributario(): string
    {
        return RegimeTributario::normalizar(
            DB::table('configuracoes')->where('chave', RegimeTributario::CHAVE)->value('valor')
        );
    }

    private function avisoRegimeTributario(string $regime): ?string
    {
        if ($regime === RegimeTributario::MEI) {
            return null;
        }

        $label = RegimeTributario::catalogo()[$regime]['label'] ?? $regime;

        return 'Sua empresa está configurada como '.$label.'. O Anexo X é obrigação exclusiva do MEI '
            .'(Resolução CGSN nº 140/2018, art. 106). O relatório continua disponível como demonstrativo, '
            .'mas não substitui a escrituração exigida pelo seu regime.';
    }

    private function rotuloRegime(string $regime): string
    {
        return $regime === self::REGIME_CAIXA
            ? 'Caixa (data do recebimento)'
            : 'Competência (data de entrega da OS / data da venda)';
    }

    private function rotuloTipoDocumento(string $tipo): string
    {
        return match ($tipo) {
            DocumentoFiscal::TIPO_NFSE => 'NFS-e',
            DocumentoFiscal::TIPO_NFE => 'NF-e',
            DocumentoFiscal::TIPO_NFCE => 'NFC-e',
            default => strtoupper($tipo),
        };
    }
}
