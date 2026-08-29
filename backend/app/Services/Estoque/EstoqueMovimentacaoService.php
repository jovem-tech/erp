<?php

namespace App\Services\Estoque;

use App\Models\Movimentacao;
use App\Models\Peca;
use Illuminate\Support\Facades\DB;

/**
 * Motor unico de movimentacao de estoque.
 *
 * Ate aqui o sistema tinha DUAS implementacoes de baixa que nao se falavam:
 *
 *  - `EstoqueController::storeMovement()`, que lia o saldo, calculava em PHP e
 *    gravava de volta — sem lock, e truncando em zero silenciosamente;
 *  - `SaleStockService`, correta, cujo proprio docblock explicava que NAO
 *    reusava a primeira justamente por causa da corrida.
 *
 * Uma terceira porta (baixa de peca na OS) tornaria o problema permanente. Este
 * servico e a generalizacao da implementacao que ja estava certa: toda escrita
 * no razao passa por aqui.
 *
 * Invariantes:
 *  1. Sempre dentro de transacao — participa da que ja estiver aberta.
 *  2. `lockForUpdate()` ORDENADO POR ID: e o que evita deadlock entre dois
 *     caixas com carrinhos que se cruzam.
 *  3. Saldo alterado so por expressao atomica, nunca por read-modify-write.
 *  4. Quantidade sempre positiva; o sinal vem do tipo do movimento.
 */
class EstoqueMovimentacaoService
{
    public const TIPO_SAIDA = 'saida';

    public const TIPO_ENTRADA = 'entrada';

    /**
     * Registra um lote de movimentos na mesma transacao e sob o mesmo lock.
     *
     * @param array<int, array<string, mixed>> $linhas  peca_id, quantidade e vinculos opcionais
     * @param array<string, mixed> $contexto            tipo, motivo, responsavel_id
     * @return array{divergente: bool, faltas: array<int, array<string, mixed>>, total: float}
     *
     * @throws SaldoInsuficienteException
     */
    public function registrarLote(array $linhas, array $contexto, bool $permitirNegativo = false): array
    {
        $tipo = (string) ($contexto['tipo'] ?? self::TIPO_SAIDA);
        $demanda = $this->agregarPorPeca($linhas);

        if ($demanda === []) {
            return ['divergente' => false, 'faltas' => [], 'total' => 0.0];
        }

        return DB::transaction(function () use ($demanda, $linhas, $contexto, $tipo, $permitirNegativo): array {
            // orderBy('id') e OBRIGATORIO: garante ordem deterministica de lock.
            $pecas = Peca::query()
                ->whereIn('id', array_keys($demanda))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $faltas = $tipo === self::TIPO_SAIDA
                ? $this->conferirFaltas($demanda, $pecas)
                : [];

            if ($faltas !== [] && ! $permitirNegativo) {
                throw new SaldoInsuficienteException($faltas);
            }

            $divergente = $faltas !== [];
            $agora = now();
            $total = 0.0;

            foreach ($linhas as $linha) {
                $pecaId = (int) ($linha['peca_id'] ?? 0);
                $quantidade = round((float) ($linha['quantidade'] ?? 0), 4);

                if ($pecaId <= 0 || $quantidade <= 0 || ! $pecas->has($pecaId)) {
                    continue;
                }

                Movimentacao::query()->create([
                    'peca_id' => $pecaId,
                    'os_id' => $linha['os_id'] ?? null,
                    'venda_id' => $linha['venda_id'] ?? null,
                    'venda_item_id' => $linha['venda_item_id'] ?? null,
                    // Qual lancamento financeiro gerou o movimento (specs/039).
                    'financeiro_id' => $linha['financeiro_id'] ?? null,
                    'tipo' => $tipo,
                    'quantidade' => $quantidade,
                    // Custo congelado. So a entrada conhece o seu: na saida o
                    // custo e o medio, que ainda e da 036 Bloco B.
                    'custo_unitario' => $linha['custo_unitario'] ?? null,
                    'motivo' => (string) ($linha['motivo'] ?? $contexto['motivo'] ?? ''),
                    'responsavel_id' => $contexto['responsavel_id'] ?? null,
                    'created_at' => $agora,
                ]);

                $total += $quantidade;
            }

            $operador = $tipo === self::TIPO_SAIDA ? '-' : '+';

            foreach ($demanda as $pecaId => $quantidade) {
                if (! $pecas->has($pecaId)) {
                    continue;
                }

                // Decremento/incremento atomico. O saldo PODE ficar negativo
                // quando o operador confirmou sem estoque: e o sinal honesto de
                // que o inventario precisa de acerto.
                Peca::query()
                    ->whereKey($pecaId)
                    ->update([
                        'quantidade_atual' => DB::raw(
                            'quantidade_atual '.$operador.' '.$this->quantidadeSql($quantidade)
                        ),
                        'updated_at' => $agora,
                    ]);
            }

            return [
                'divergente' => $divergente,
                'faltas' => $faltas,
                'total' => round($total, 4),
            ];
        });
    }

    /**
     * Confere disponibilidade SEM gravar nada.
     *
     * Serve para avisar antes de abrir transacao — o PDV usa para bloquear o
     * botao antes de o operador tentar fechar a venda.
     *
     * @param array<int, array<string, mixed>> $linhas
     * @return array<int, array<string, mixed>>
     */
    public function conferirDisponibilidade(array $linhas): array
    {
        $demanda = $this->agregarPorPeca($linhas);

        if ($demanda === []) {
            return [];
        }

        $pecas = Peca::query()->whereIn('id', array_keys($demanda))->get()->keyBy('id');

        return $this->conferirFaltas($demanda, $pecas);
    }

    /**
     * @param array<int, float> $demanda
     * @param \Illuminate\Support\Collection<int, Peca> $pecas
     * @return array<int, array<string, mixed>>
     */
    private function conferirFaltas(array $demanda, $pecas): array
    {
        $faltas = [];

        foreach ($demanda as $pecaId => $quantidade) {
            $peca = $pecas->get($pecaId);

            if (! $peca instanceof Peca) {
                continue;
            }

            $disponivel = (float) ($peca->quantidade_atual ?? 0);

            if ($disponivel < $quantidade) {
                $faltas[] = [
                    'peca_id' => (int) $peca->id,
                    'codigo' => (string) ($peca->codigo ?? ''),
                    'nome' => (string) ($peca->nome ?? ''),
                    'disponivel' => $disponivel,
                    'solicitado' => $quantidade,
                ];
            }
        }

        return $faltas;
    }

    /**
     * Soma a demanda por peca, com `ksort` para ordem deterministica.
     *
     * Duas linhas da mesma peca no mesmo lote tem de virar UM ajuste de saldo,
     * ou o lock protege a primeira e a segunda corre solta.
     *
     * @param array<int, array<string, mixed>> $linhas
     * @return array<int, float>
     */
    private function agregarPorPeca(array $linhas): array
    {
        $demanda = [];

        foreach ($linhas as $linha) {
            $pecaId = (int) ($linha['peca_id'] ?? 0);
            $quantidade = round((float) ($linha['quantidade'] ?? 0), 4);

            if ($pecaId <= 0 || $quantidade <= 0) {
                continue;
            }

            $demanda[$pecaId] = round(($demanda[$pecaId] ?? 0) + $quantidade, 4);
        }

        ksort($demanda);

        return $demanda;
    }

    /**
     * Formata quantidade para interpolar em SQL cru.
     *
     * `quantidade_atual` e DECIMAL(14,4) e a expressao e `DB::raw`, entao nao ha
     * bind: concatenar float direto seria armadilha dupla — locale pt_BR
     * escreveria "1,5" (virgula quebra o SQL) e valores pequenos virariam
     * notacao cientifica.
     */
    private function quantidadeSql(float $quantidade): string
    {
        return number_format($quantidade, 4, '.', '');
    }
}
