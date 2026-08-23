<?php

namespace App\Services\Integrations\Inter;

use App\Models\FinanceiroConta;
use App\Services\Financeiro\FinanceiroContaService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Saldo e extrato da conta PJ no Banco Inter (somente leitura).
 *
 * Esta e' a primeira coisa a subir da integracao de proposito: valida todo o
 * caminho de mTLS + OAuth2 num contexto onde um bug NAO custa dinheiro — nada
 * e' gravado, nada e' liquidado.
 *
 * O valor aqui nao e' mostrar o saldo, e' o eixo de conciliacao que ele cria:
 *
 *     saldo interno (financeiro_conta_movimentos + financeiro_movimentos)
 *     x saldo real do banco
 *     = divergencia
 *
 * E o extrato pega o que a cobranca sozinha nunca pegaria: recebimento por
 * chave Pix, TED, deposito — dinheiro que entrou sem passar por uma `cob`.
 */
class InterBankingService
{
    /** Diferenca abaixo disto e' arredondamento, nao divergencia. */
    private const TOLERANCIA = 0.01;

    public function __construct(
        private readonly InterClient $client,
        private readonly InterCredentials $credentials,
        private readonly FinanceiroContaService $contas,
    ) {
    }

    /**
     * Saldo atual da conta no banco.
     *
     * @return array<string, mixed>
     *
     * @throws InterException
     */
    public function saldo(bool $forcarAtualizacao = false): array
    {
        $chave = 'inter:saldo:'.sha1($this->credentials->ambiente().'|'.$this->credentials->contaCorrente());
        $ttl = max(0, (int) config('inter.banking.saldo_cache_segundos', 600));

        if ($forcarAtualizacao) {
            Cache::forget($chave);
        }

        $consultar = function (): array {
            $bruto = $this->client->get(
                (string) config('inter.banking.saldo_path', 'banking/v2/saldo'),
                (array) config('inter.escopos.banking', ['extrato.read'])
            );

            return [
                'disponivel' => $this->extrairDisponivel($bruto),
                'consultado_em' => CarbonImmutable::now()->toIso8601String(),
                'bruto' => $bruto,
            ];
        };

        if ($ttl === 0) {
            return $consultar();
        }

        return Cache::remember($chave, $ttl, $consultar);
    }

    /**
     * Extrato por periodo.
     *
     * @return array<string, mixed>
     *
     * @throws InterException
     */
    public function extrato(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        [$de, $ate] = $this->normalizarPeriodo($de, $ate);

        $bruto = $this->client->get(
            (string) config('inter.banking.extrato_path', 'banking/v2/extrato'),
            (array) config('inter.escopos.banking', ['extrato.read']),
            [
                'dataInicio' => $de->toDateString(),
                'dataFim' => $ate->toDateString(),
            ]
        );

        $transacoes = $this->extrairTransacoes($bruto);

        return [
            'periodo' => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'total' => count($transacoes),
            'transacoes' => $transacoes,
        ];
    }

    /**
     * Compara o saldo interno com o do banco.
     *
     * NUNCA gera ajuste. `financeiro_conta_movimentos` tem `tipo='ajuste'`
     * exatamente para isso, mas o ajuste e' decisao humana, com autor
     * registrado — um sistema que "conserta" o proprio saldo sozinho apaga a
     * evidencia do erro que causou a diferenca.
     *
     * @return array<string, mixed>
     *
     * @throws InterException
     */
    public function conciliacao(FinanceiroConta $conta, bool $forcarAtualizacao = false): array
    {
        $saldo = $this->saldo($forcarAtualizacao);

        $interno = round($this->contas->balanceOf((int) $conta->id), 2);
        $banco = round((float) $saldo['disponivel'], 2);
        $divergencia = round($banco - $interno, 2);
        $divergente = abs($divergencia) > self::TOLERANCIA;

        if ($divergente) {
            Log::channel('pagamentos')->warning('[INTER] Divergencia entre saldo interno e saldo do banco.', [
                'conta_id' => (int) $conta->id,
                'conta_nome' => (string) $conta->nome,
                'saldo_interno' => $interno,
                'saldo_banco' => $banco,
                'divergencia' => $divergencia,
            ]);
        }

        return [
            'conta' => [
                'id' => (int) $conta->id,
                'nome' => (string) $conta->nome,
                'provider' => (string) ($conta->integracao_provider ?? ''),
            ],
            'saldo_interno' => $interno,
            'saldo_banco' => $banco,
            // Positivo = o banco tem mais do que o sistema registrou (entrada
            // nao lancada). Negativo = o sistema registrou mais do que entrou.
            'divergencia' => $divergencia,
            'divergente' => $divergente,
            'consultado_em' => $saldo['consultado_em'],
            'ajuste_automatico' => false,
        ];
    }

    /**
     * Contas internas marcadas como vinculadas ao Inter.
     *
     * @return \Illuminate\Support\Collection<int, FinanceiroConta>
     */
    public function contasVinculadas()
    {
        return FinanceiroConta::query()
            ->where('integracao_provider', 'inter')
            ->where('ativo', true)
            ->orderBy('nome')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $bruto
     */
    private function extrairDisponivel(array $bruto): float
    {
        // O Inter devolve `disponivel`; os fallbacks cobrem variacao de
        // nomenclatura entre versoes da API sem quebrar a tela.
        foreach (['disponivel', 'saldoDisponivel', 'valor', 'saldo'] as $campo) {
            if (isset($bruto[$campo]) && is_numeric($bruto[$campo])) {
                return round((float) $bruto[$campo], 2);
            }
        }

        throw InterException::local(
            'Resposta de saldo do Banco Inter sem campo reconhecido.',
            ['campos' => array_keys($bruto)]
        );
    }

    /**
     * @param  array<string, mixed>  $bruto
     * @return array<int, array<string, mixed>>
     */
    private function extrairTransacoes(array $bruto): array
    {
        foreach (['transacoes', 'movimentacoes', 'items', 'data'] as $campo) {
            if (isset($bruto[$campo]) && is_array($bruto[$campo])) {
                return array_values($bruto[$campo]);
            }
        }

        // Lista na raiz.
        return array_is_list($bruto) ? $bruto : [];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function normalizarPeriodo(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $de = $de->startOfDay();
        $ate = $ate->startOfDay();

        if ($de->greaterThan($ate)) {
            [$de, $ate] = [$ate, $de];
        }

        $limite = max(1, (int) config('inter.banking.janela_maxima_dias', 90));

        // Validado ANTES de chamar: o erro fica nosso e explicito, em vez de um
        // 400 generico do banco que ninguem sabe interpretar.
        if ($de->diffInDays($ate) > $limite) {
            throw InterException::local(sprintf(
                'Periodo de %s a %s excede o limite de %d dias do extrato.',
                $de->toDateString(),
                $ate->toDateString(),
                $limite
            ));
        }

        return [$de, $ate];
    }
}
