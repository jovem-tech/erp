<?php

namespace App\Services\Fiscal;

use App\Models\AnexoXAjuste;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Lança, cancela e lista os ajustes manuais do Anexo X.
 *
 * Não sabe apurar nada — devolve somas por linha e listas prontas, e quem monta
 * o relatório é `AnexoXService`. A dependência é de mão única de propósito,
 * como a do `AnexoXFechamentoService`: um caminho de volta fecharia um ciclo
 * que o container teria que desatar na mão.
 */
class AnexoXAjusteService
{
    /**
     * @return Collection<int, AnexoXAjuste>
     */
    public function doMes(string $competencia, string $regime, bool $somenteVigentes = false): Collection
    {
        $query = AnexoXAjuste::query()
            ->doMes($competencia, $regime)
            ->with(['autor', 'autorCancelamento'])
            ->orderBy('id');

        if ($somenteVigentes) {
            $query->vigentes();
        }

        return $query->get();
    }

    /**
     * Todos os ajustes vigentes de um ano, indexados por `competencia|regime`.
     *
     * Uma query só para os doze meses e os dois regimes: a tela do ano precisa
     * disso 24 vezes, e buscar mês a mês seria N+1 na tela mais cara do módulo.
     *
     * @return array<string, Collection<int, AnexoXAjuste>>
     */
    public function vigentesDoAno(int $ano): array
    {
        return AnexoXAjuste::query()
            ->vigentes()
            ->where('competencia', 'like', sprintf('%04d-%%', $ano))
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AnexoXAjuste $ajuste): string => $ajuste->competencia.'|'.$ajuste->regime)
            ->all();
    }

    /**
     * Soma dos ajustes vigentes por linha do formulário.
     *
     * @param  Collection<int, AnexoXAjuste>|null  $ajustes  já carregados, para não repetir query no laço do ano
     * @return array<string, float>
     */
    public function somasPorLinha(string $competencia, string $regime, ?Collection $ajustes = null): array
    {
        $ajustes ??= $this->doMes($competencia, $regime, somenteVigentes: true);

        $somas = [];

        foreach ($ajustes as $ajuste) {
            if ($ajuste->cancelado()) {
                continue;
            }

            $linha = (string) $ajuste->linha;
            $somas[$linha] = round(($somas[$linha] ?? 0.0) + (float) $ajuste->valor, 2);
        }

        return $somas;
    }

    public function lancar(
        string $competencia,
        string $regime,
        string $linha,
        float $valor,
        string $motivo,
        int $usuarioId
    ): AnexoXAjuste {
        return AnexoXAjuste::query()->create([
            'competencia' => $competencia,
            'regime' => $regime,
            'linha' => strtolower(trim($linha)),
            'valor' => round($valor, 2),
            'motivo' => trim($motivo),
            'criado_em' => now(),
            'criado_por' => $usuarioId,
            'app_versao' => (string) config('app.version'),
        ]);
    }

    /**
     * Cancela um ajuste. Não apaga: o registro continua listado, riscado.
     */
    public function cancelar(AnexoXAjuste $ajuste, int $usuarioId, string $motivo): AnexoXAjuste
    {
        if ($ajuste->cancelado()) {
            return $ajuste;
        }

        $ajuste->forceFill([
            'cancelado_em' => now(),
            'cancelado_por' => $usuarioId,
            'motivo_cancelamento' => trim($motivo),
        ])->save();

        return $ajuste->refresh();
    }

    /**
     * Bloco `ajustes` do payload do relatório.
     *
     * @param  Collection<int, AnexoXAjuste>|null  $ajustes
     * @return array<string, mixed>
     */
    public function apresentar(string $competencia, string $regime, bool $bloqueado, ?Collection $ajustes = null): array
    {
        $ajustes ??= $this->doMes($competencia, $regime);

        $porLinha = [];
        $total = 0.0;
        $quantidade = 0;

        foreach ($ajustes as $ajuste) {
            $porLinha[(string) $ajuste->linha][] = $this->apresentarLancamento($ajuste);

            if (! $ajuste->cancelado()) {
                $total += (float) $ajuste->valor;
                $quantidade++;
            }
        }

        return [
            'total' => round($total, 2),
            'quantidade' => $quantidade,
            'bloqueado' => $bloqueado,
            'por_linha' => $porLinha,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function apresentarLancamento(AnexoXAjuste $ajuste): array
    {
        return [
            'id' => (int) $ajuste->id,
            'linha' => (string) $ajuste->linha,
            'valor' => round((float) $ajuste->valor, 2),
            'motivo' => (string) $ajuste->motivo,
            'criado_por' => $this->apresentarUsuario($ajuste->autor, (int) $ajuste->criado_por),
            'criado_em' => $ajuste->criado_em?->toIso8601String(),
            'cancelado_em' => $ajuste->cancelado_em?->toIso8601String(),
            'cancelado_por' => $this->apresentarUsuario($ajuste->autorCancelamento, (int) $ajuste->cancelado_por),
            'motivo_cancelamento' => $ajuste->motivo_cancelamento,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function apresentarUsuario(?User $usuario, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return ['id' => $id, 'nome' => (string) ($usuario->nome ?? 'Usuário '.$id)];
    }
}
