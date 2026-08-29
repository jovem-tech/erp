<?php

namespace App\Services\Financeiro;

use App\Models\Configuration;
use App\Models\Financeiro;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Custo da hora produtiva, calculado dos custos fixos REAIS.
 *
 *     custo_hora = custos fixos mensais / (tecnicos x horas produtivas/dia x dias uteis)
 *
 * E o elo que faltava para o ciclo fechar: custo fixo do DRE -> preco do
 * servico -> margem realizada -> DRE. Ate specs/037 o custo-hora era um numero
 * digitado a mao (R$ 40, o default) sem lastro nenhum na operacao.
 *
 * Capacidade e GLOBAL, nao por servico: a oficina tem N tecnicos
 * independentemente do servico cotado. Capacidade por servico permitiria dois
 * custos-hora contraditorios na mesma empresa, e o fixo rateado deixaria de
 * bater com o fixo do DRE — que e justamente a reconciliacao que da sentido ao
 * numero.
 */
class CustoHoraService
{
    public const ORIGEM_CALCULADO = 'calculado';

    public const ORIGEM_MANUAL = 'manual';

    public const MOTIVO_CAPACIDADE = 'CAPACIDADE_NAO_CONFIGURADA';

    public const MOTIVO_SEM_FIXO = 'SEM_CUSTO_FIXO_LANCADO';

    public const MOTIVO_FORA_DA_FAIXA = 'FORA_DA_FAIXA_ESPERADA';

    /**
     * Faixa de sanidade contra o valor manual. Fora dela o calculado ainda e
     * devolvido — mas marcado como nao confiavel, para a tela avisar em vez de
     * trocar o numero em silencio. Substituir sem avisar e o que faz o usuario
     * deixar de confiar no recurso inteiro.
     */
    private const FATOR_MINIMO = 0.5;

    private const FATOR_MAXIMO = 5.0;

    private const CACHE_SEGUNDOS = 600;

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    /**
     * @return array{
     *     valor: float,
     *     origem: string,
     *     confiavel: bool,
     *     motivo: string|null,
     *     base: array<string, float|int>
     * }
     */
    public function resolver(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $config = $this->configuracoes();
        $manual = max(0.0, (float) ($config['precificacao_servico_custo_hora_produtiva'] ?? 0));
        $tecnicos = max(0.0, (float) ($config['precificacao_capacidade_tecnicos'] ?? 0));
        $horasDia = max(0.0, (float) ($config['precificacao_capacidade_horas_dia'] ?? 0));
        $diasMes = max(0.0, (float) ($config['precificacao_capacidade_dias_mes'] ?? 0));
        $meses = max(1, (int) ($config['precificacao_custo_hora_meses_base'] ?? 3));

        $horasTotais = round($tecnicos * $horasDia * $diasMes, 2);

        $base = [
            'tecnicos' => $tecnicos,
            'horas_dia' => $horasDia,
            'dias_mes' => $diasMes,
            'horas_totais' => $horasTotais,
            'meses' => $meses,
            'custos_fixos' => 0.0,
            'manual' => $manual,
        ];

        // Guarda 1: sem capacidade nao ha divisor. Cai no valor manual — que
        // permanece como escape hatch, nao como default silencioso.
        if ($horasTotais <= 0) {
            return $this->memo = $this->resultado($manual, self::ORIGEM_MANUAL, false, self::MOTIVO_CAPACIDADE, $base);
        }

        $custosFixos = $this->custosFixosMedios($meses);
        $base['custos_fixos'] = $custosFixos;

        // Guarda 2: sem custo fixo lancado na janela, NAO devolver zero.
        // Custo-hora zero faz todo servico parecer infinitamente lucrativo — e
        // a saida mais perigosa que este servico consegue produzir.
        if ($custosFixos <= 0) {
            return $this->memo = $this->resultado($manual, self::ORIGEM_MANUAL, false, self::MOTIVO_SEM_FIXO, $base);
        }

        $calculado = round($custosFixos / $horasTotais, 2);

        // Guarda 3: resultado absurdo em relacao ao manual. Devolve o
        // calculado assim mesmo (ele pode estar certo e o manual desatualizado),
        // mas pede conferencia.
        $confiavel = true;
        $motivo = null;

        if ($manual > 0) {
            $piso = $manual * self::FATOR_MINIMO;
            $teto = $manual * self::FATOR_MAXIMO;

            if ($calculado < $piso || $calculado > $teto) {
                $confiavel = false;
                $motivo = self::MOTIVO_FORA_DA_FAIXA;
            }
        }

        return $this->memo = $this->resultado($calculado, self::ORIGEM_CALCULADO, $confiavel, $motivo, $base);
    }

    /**
     * Valor pronto para entrar no calculo de preco.
     */
    public function valor(): float
    {
        return (float) $this->resolver()['valor'];
    }

    public function esquecerCache(): void
    {
        $this->memo = null;
        Cache::forget($this->chaveCache());
    }

    /**
     * Media dos ultimos N meses FECHADOS.
     *
     * O mes corrente fica de fora de proposito: esta sempre incompleto, e
     * inclui-lo faria o custo-hora despencar no dia 3 e dobrar no dia 28,
     * conforme aluguel e folha vao caindo.
     *
     * Diferente do DRE, aqui a janela tem limite INFERIOR. `groupByCompetencia`
     * soma todo fixo com vencimento ate o fim do periodo — heuristica de
     * "recorrente ainda vigente" que, nesta conta, somaria anos de aluguel num
     * mes so.
     */
    private function custosFixosMedios(int $meses): float
    {
        return (float) Cache::remember(
            $this->chaveCache(),
            self::CACHE_SEGUNDOS,
            function () use ($meses): float {
                $fim = CarbonImmutable::now()->startOfMonth()->subDay()->endOfDay();
                $inicio = CarbonImmutable::now()->startOfMonth()->subMonths($meses)->startOfDay();

                $total = Financeiro::query()
                    ->fixasDre()
                    ->where(function ($query) use ($inicio, $fim): void {
                        $query->whereBetween('data_competencia', [$inicio->toDateString(), $fim->toDateString()])
                            ->orWhere(function ($inner) use ($inicio, $fim): void {
                                $inner->whereNull('data_competencia')
                                    ->whereBetween('data_vencimento', [$inicio->toDateString(), $fim->toDateString()]);
                            });
                    })
                    ->sum('valor');

                return round(((float) $total) / $meses, 2);
            }
        );
    }

    private function chaveCache(): string
    {
        return 'precificacao:custo_hora:'.CarbonImmutable::now()->format('Y-m');
    }

    /**
     * Configuracao COM os defaults aplicados.
     *
     * A tabela `configuracoes` so tem linha para o que o dono ja salvou; ler
     * cru devolveria vazio numa instalacao nova e o custo-hora cairia para
     * zero — a saida que este servico existe para nunca produzir. Os defaults
     * espelham PrecificacaoService::DEFAULTS.
     *
     * @return array<string, string>
     */
    private const PADROES = [
        'precificacao_servico_custo_hora_produtiva' => '40',
        'precificacao_capacidade_tecnicos' => '1',
        'precificacao_capacidade_horas_dia' => '6',
        'precificacao_capacidade_dias_mes' => '22',
        'precificacao_custo_hora_meses_base' => '3',
    ];

    /**
     * @return array<string, string>
     */
    private function configuracoes(): array
    {
        $gravados = Configuration::query()
            ->whereIn('chave', array_keys(self::PADROES))
            ->pluck('valor', 'chave');

        $config = [];

        foreach (self::PADROES as $chave => $padrao) {
            $valor = $gravados[$chave] ?? null;
            $config[$chave] = ($valor === null || $valor === '') ? $padrao : (string) $valor;
        }

        return $config;
    }

    /**
     * @param array<string, float|int> $base
     * @return array<string, mixed>
     */
    private function resultado(float $valor, string $origem, bool $confiavel, ?string $motivo, array $base): array
    {
        return [
            'valor' => round(max(0.0, $valor), 2),
            'origem' => $origem,
            'confiavel' => $confiavel,
            'motivo' => $motivo,
            'base' => $base,
        ];
    }
}
