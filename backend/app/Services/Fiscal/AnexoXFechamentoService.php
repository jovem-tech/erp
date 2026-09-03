<?php

namespace App\Services\Fiscal;

use App\Models\AnexoXFechamento;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Congela, reabre e confere o Anexo X de um mês.
 *
 * Não sabe apurar nada: recebe o relatório já calculado por `AnexoXService`.
 * A dependência é de mão única de propósito — `AnexoXService` consulta este
 * serviço para descobrir se o mês está fechado, e um caminho de volta fecharia
 * um ciclo que o container teria que desatar na mão.
 *
 * **O que o hash é e o que não é.** Ele NÃO congela valor nenhum: quem congela
 * são as colunas `linha_*` e o `payload_json`. O hash é evidência de
 * adulteração — prova que ninguém editou o JSON direto no banco depois. Quem
 * denuncia dado de ORIGEM alterado (uma OS de mês fechado corrigida em
 * novembro) é `divergencias()`, que recalcula ao vivo e compara.
 */
class AnexoXFechamentoService
{
    /**
     * Campos que mudam a cada geração e não podem entrar no hash — senão
     * reconferir o mesmo mês duas vezes acusaria adulteração inexistente.
     *
     * @var array<int, string>
     */
    private const VOLATEIS = ['gerado_em', 'origem_dos_valores', 'fechamento', 'app_versao'];

    public function vigente(string $competencia, string $regime): ?AnexoXFechamento
    {
        return AnexoXFechamento::query()->vigente($competencia, $regime)->first();
    }

    /**
     * Última linha do par, fechada ou reaberta — para a tela mostrar o
     * histórico ("reaberto em ... por ... motivo ...") mesmo sem fechamento
     * vigente.
     */
    public function ultimo(string $competencia, string $regime): ?AnexoXFechamento
    {
        return AnexoXFechamento::query()
            ->where('competencia', $competencia)
            ->where('regime', $regime)
            ->orderByDesc('versao')
            ->first();
    }

    /**
     * Payload canônico: mesma entrada, mesmo hash, em qualquer máquina.
     *
     * Ordena as chaves recursivamente e converte TODO valor numérico para
     * string com duas casas. Sem essa conversão, uma diferença de
     * representação de float entre versões de PHP mudaria o hash de dados
     * idênticos — e um hash que muda sozinho não prova coisa alguma.
     *
     * @param  array<string, mixed>  $relatorio
     * @return array<string, mixed>
     */
    public function canonicalizar(array $relatorio): array
    {
        foreach (self::VOLATEIS as $chave) {
            unset($relatorio[$chave]);
        }

        return $this->canonicalizarValor($relatorio);
    }

    /**
     * @param  array<string, mixed>  $relatorio
     */
    public function hash(array $relatorio): string
    {
        return hash('sha256', (string) json_encode(
            $this->canonicalizar($relatorio),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * @param  array<string, mixed>  $relatorio  apuração ao vivo do mês
     */
    public function fechar(array $relatorio, int $usuarioId): AnexoXFechamento
    {
        $competencia = (string) $relatorio['competencia'];
        $regime = (string) $relatorio['regime'];

        return DB::transaction(function () use ($relatorio, $competencia, $regime, $usuarioId): AnexoXFechamento {
            // lockForUpdate para dois operadores não gravarem a mesma versão:
            // o unique (competencia, regime, versao) recusaria a segunda, mas
            // com erro de banco em vez de mensagem.
            $ultima = AnexoXFechamento::query()
                ->where('competencia', $competencia)
                ->where('regime', $regime)
                ->lockForUpdate()
                ->orderByDesc('versao')
                ->first();

            $linhas = [];

            foreach (AnexoXFechamento::LINHAS as $linha) {
                $linhas['linha_'.$linha] = round((float) ($relatorio['linhas'][$linha]['valor'] ?? 0), 2);
            }

            return AnexoXFechamento::query()->create(array_merge($linhas, [
                'competencia' => $competencia,
                'regime' => $regime,
                'versao' => (int) ($ultima->versao ?? 0) + 1,
                'status' => AnexoXFechamento::STATUS_FECHADO,
                'deducao_descontos' => round((float) ($relatorio['deducoes']['descontos'] ?? 0), 2),
                'deducao_devolucoes' => round((float) ($relatorio['deducoes']['devolucoes'] ?? 0), 2),
                // O ajuste ja' vai congelado dentro de `payload_json`; estas
                // duas colunas existem so' para a tabela do ano marcar "este
                // mes carrega ajuste" em 24 celulas sem desserializar 24
                // payloads.
                'ajuste_total' => round((float) ($relatorio['ajustes']['total'] ?? 0), 2),
                'ajuste_quantidade' => (int) ($relatorio['ajustes']['quantidade'] ?? 0),
                'acumulado_ano' => round((float) ($relatorio['acumulado_ano']['acumulado'] ?? 0), 2),
                'limite_aplicado' => round((float) ($relatorio['acumulado_ano']['limite'] ?? 0), 2),
                // JSON_PRESERVE_ZERO_FRACTION: sem ele 500.0 vira `500` no JSON e
                // volta como int, e o mesmo campo teria tipo diferente conforme
                // o mês esteja congelado ou não. Não afeta o hash — a
                // canonicalização já converte todo número para string.
                'payload_json' => (string) json_encode(
                    $relatorio,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                ),
                'payload_hash_sha256' => $this->hash($relatorio),
                'app_versao' => (string) config('app.version'),
                'fechado_em' => now(),
                'fechado_por' => $usuarioId,
            ]));
        });
    }

    public function reabrir(string $competencia, string $regime, int $usuarioId, string $motivo): ?AnexoXFechamento
    {
        return DB::transaction(function () use ($competencia, $regime, $usuarioId, $motivo): ?AnexoXFechamento {
            $vigente = AnexoXFechamento::query()
                ->vigente($competencia, $regime)
                ->lockForUpdate()
                ->first();

            if (! $vigente instanceof AnexoXFechamento) {
                return null;
            }

            $vigente->forceFill([
                'status' => AnexoXFechamento::STATUS_REABERTO,
                'reaberto_em' => now(),
                'reaberto_por' => $usuarioId,
                'motivo_reabertura' => $motivo,
            ])->save();

            return $vigente->refresh();
        });
    }

    /**
     * O que mudou entre o que foi declarado e o que os dados de hoje dizem.
     *
     * @param  array<string, mixed>  $aoVivo
     * @return array<int, array<string, mixed>>
     */
    public function divergencias(AnexoXFechamento $fechamento, array $aoVivo): array
    {
        $divergencias = [];
        $congeladas = $fechamento->linhas();

        foreach (AnexoXFechamento::LINHAS as $linha) {
            $atual = round((float) ($aoVivo['linhas'][$linha]['valor'] ?? 0), 2);
            $congelado = $congeladas[$linha];

            if (abs($atual - $congelado) > 0.001) {
                $divergencias[] = [
                    'linha' => $linha,
                    'rotulo' => (string) ($aoVivo['linhas'][$linha]['rotulo'] ?? strtoupper($linha)),
                    'congelado' => $congelado,
                    'atual' => $atual,
                    'diferenca' => round($atual - $congelado, 2),
                ];
            }
        }

        return $divergencias;
    }

    /**
     * Bloco `fechamento` do payload.
     *
     * `$aoVivo` só é passado quando o operador pediu reconferência — recalcular
     * o mês inteiro a cada abertura de tela só para dizer "confere" seria caro
     * e, num mês fechado, inútil na maior parte das vezes.
     *
     * @param  array<string, mixed>|null  $aoVivo
     * @return array<string, mixed>
     */
    public function apresentar(AnexoXFechamento $fechamento, ?array $aoVivo = null): array
    {
        $divergencias = $aoVivo === null ? [] : $this->divergencias($fechamento, $aoVivo);

        $autor = $fechamento->autorFechamento;
        $autorReabertura = $fechamento->autorReabertura;

        return [
            'status' => (string) $fechamento->status,
            'versao' => (int) $fechamento->versao,
            'fechado_em' => $fechamento->fechado_em?->toIso8601String(),
            'fechado_por' => $this->apresentarUsuario($autor, (int) $fechamento->fechado_por),
            'hash' => (string) $fechamento->payload_hash_sha256,
            'app_versao' => $fechamento->app_versao,
            // Recalcula o hash do payload guardado: se não bater, alguém editou
            // o JSON direto no banco.
            'integro' => $this->hash($fechamento->payload()) === (string) $fechamento->payload_hash_sha256,
            'reconferido' => $aoVivo !== null,
            'confere' => $aoVivo !== null && $divergencias === [],
            'divergencias' => $divergencias,
            'reaberto_em' => $fechamento->reaberto_em?->toIso8601String(),
            'reaberto_por' => $this->apresentarUsuario($autorReabertura, (int) $fechamento->reaberto_por),
            'motivo_reabertura' => $fechamento->motivo_reabertura,
        ];
    }

    /**
     * Forma reduzida do fechamento, para a tabela do ano.
     *
     * **Não recalcula o hash.** `apresentar()` faz `json_decode` do payload
     * inteiro — drill-down incluso —, canonicaliza recursivamente e roda um
     * sha256. Fazer isso 24 vezes na carga de uma tela é centenas de KB de CPU
     * que nem aparecem no contador de queries. Por isso `integro` vem `null`,
     * que significa "não conferido aqui": a conferência de integridade é uma
     * ação explícita do menu da linha, sobre um mês só.
     *
     * Requer os autores já carregados (`->with([...])`), senão vira lazy-load
     * de duas queries por fechamento — até 48 no ano.
     *
     * @return array<string, mixed>
     */
    public function apresentarResumido(AnexoXFechamento $fechamento): array
    {
        return [
            'status' => (string) $fechamento->status,
            'versao' => (int) $fechamento->versao,
            'fechado_em' => $fechamento->fechado_em?->toIso8601String(),
            'fechado_por' => $this->apresentarUsuario($fechamento->autorFechamento, (int) $fechamento->fechado_por),
            'hash' => (string) $fechamento->payload_hash_sha256,
            'app_versao' => $fechamento->app_versao,
            'integro' => null,
            'reconferido' => false,
            'confere' => false,
            'divergencias' => [],
            'reaberto_em' => $fechamento->reaberto_em?->toIso8601String(),
            'reaberto_por' => $this->apresentarUsuario($fechamento->autorReabertura, (int) $fechamento->reaberto_por),
            'motivo_reabertura' => $fechamento->motivo_reabertura,
            'ajuste_total' => round((float) $fechamento->ajuste_total, 2),
            'ajuste_quantidade' => (int) $fechamento->ajuste_quantidade,
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

        return [
            'id' => $id,
            'nome' => (string) ($usuario->nome ?? "Usuário ".$id),
        ];
    }

    /**
     * @param  mixed  $valor
     * @return mixed
     */
    private function canonicalizarValor($valor)
    {
        if (is_array($valor)) {
            $canonico = [];

            foreach ($valor as $chave => $item) {
                $canonico[$chave] = $this->canonicalizarValor($item);
            }

            if (! array_is_list($valor)) {
                ksort($canonico);
            }

            return $canonico;
        }

        // Todo número vira string de duas casas: 1500.0, 1500 e "1500.00" são
        // o mesmo valor declarado, e têm que produzir o mesmo hash.
        if (is_float($valor) || is_int($valor)) {
            return number_format((float) $valor, 2, '.', '');
        }

        return $valor;
    }
}
