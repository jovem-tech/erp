<?php

namespace App\Console\Commands;

use App\Models\OrderEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importa o historico legado das OSs para a timeline unificada `os_eventos`.
 *
 * Idempotente: cada linha importada carrega (legacy_tabela, legacy_id, tipo),
 * cobertos por indice UNIQUE — re-execucoes usam insertOrIgnore e nao duplicam
 * nada. Eventos "ao vivo" (legacy_tabela NULL) nunca sao tocados por este
 * comando, nem mesmo com --fresh.
 */
class BackfillOsEventos extends Command
{
    protected $signature = 'os:backfill-eventos
        {--fresh : Apaga os eventos importados do legado antes de reimportar}
        {--os= : Restringe o backfill a uma unica OS (id)}';

    protected $description = 'Importa o historico legado (status, procedimentos, orcamentos, financeiro, cobrancas) para a timeline os_eventos.';

    public function handle(): int
    {
        if (! Schema::hasTable('os_eventos')) {
            $this->error('Tabela os_eventos nao existe. Rode php artisan migrate antes.');

            return self::FAILURE;
        }

        $osId = (int) ($this->option('os') ?? 0);

        if ($this->option('fresh')) {
            $query = DB::table('os_eventos')->whereNotNull('legacy_tabela');
            if ($osId > 0) {
                $query->where('os_id', $osId);
            }
            $deleted = $query->delete();
            $this->info(sprintf('--fresh: %d evento(s) importado(s) do legado removido(s).', $deleted));
        }

        $this->importStatusHistory($osId);
        $this->importProcedureHistory($osId);
        $this->importBudgetStatusHistory($osId);
        $this->importBudgetSends($osId);
        $this->importBudgetApprovals($osId);
        $this->importFinanceiroTitles($osId);
        $this->importFinanceiroMovements($osId);
        $this->importCollectionSchedules($osId);

        $this->info('Backfill concluido.');

        return self::SUCCESS;
    }

    private function importStatusHistory(int $osId): void
    {
        if (! Schema::hasTable('os_status_historico')) {
            $this->warn('os_status_historico: tabela ausente, pulando.');

            return;
        }

        $inserted = 0;
        $read = 0;

        DB::table('os_status_historico')
            ->when($osId > 0, fn ($q) => $q->where('os_id', $osId))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$inserted, &$read): void {
                $batch = [];
                foreach ($rows as $row) {
                    $read++;
                    $observacao = trim((string) ($row->observacao ?? ''));
                    $isCreation = $row->status_anterior === null || trim((string) $row->status_anterior) === '';
                    $isBudgetSync = str_starts_with($observacao, 'Status sincronizado automaticamente pelo orçamento');

                    if ($isCreation) {
                        $tipo = OrderEvent::TIPO_OS_CRIADA;
                        $titulo = 'OS criada';
                        $origem = OrderEvent::ORIGEM_USUARIO;
                    } elseif ($isBudgetSync) {
                        $tipo = OrderEvent::TIPO_STATUS_SINCRONIZADO_ORCAMENTO;
                        $titulo = 'Status sincronizado pelo orçamento';
                        $origem = OrderEvent::ORIGEM_AUTOMACAO;
                    } else {
                        $tipo = OrderEvent::TIPO_STATUS_ALTERADO;
                        $titulo = 'Status alterado';
                        $origem = OrderEvent::ORIGEM_USUARIO;
                    }

                    $descricao = $isCreation
                        ? ($observacao !== '' ? $observacao : 'OS aberta')
                        : sprintf('%s → %s', (string) $row->status_anterior, (string) $row->status_novo)
                            . ($observacao !== '' ? ' — ' . $observacao : '');

                    $batch[] = [
                        'os_id' => (int) $row->os_id,
                        'categoria' => OrderEvent::CATEGORIA_STATUS,
                        'tipo' => $tipo,
                        'titulo' => $titulo,
                        'descricao' => $descricao,
                        'dados' => json_encode([
                            'status_anterior' => $row->status_anterior,
                            'status_novo' => $row->status_novo,
                            'estado_fluxo' => $row->estado_fluxo,
                            'legacy_origem' => $row->legacy_origem ?? null,
                        ], JSON_UNESCAPED_UNICODE),
                        'usuario_id' => $row->usuario_id !== null ? (int) $row->usuario_id : null,
                        'origem' => $origem,
                        'legacy_tabela' => 'os_status_historico',
                        'legacy_id' => (int) $row->id,
                        'created_at' => $row->created_at,
                    ];
                }
                $inserted += DB::table('os_eventos')->insertOrIgnore($batch);
            });

        $this->info(sprintf('os_status_historico: %d lida(s), %d importada(s).', $read, $inserted));
    }

    private function importProcedureHistory(int $osId): void
    {
        if (! Schema::hasTable('os_procedimentos_historico')) {
            $this->warn('os_procedimentos_historico: tabela ausente, pulando.');

            return;
        }

        $inserted = 0;
        $read = 0;

        DB::table('os_procedimentos_historico')
            ->when($osId > 0, fn ($q) => $q->where('os_id', $osId))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$inserted, &$read): void {
                $batch = [];
                foreach ($rows as $row) {
                    $read++;
                    $batch[] = [
                        'os_id' => (int) $row->os_id,
                        'categoria' => OrderEvent::CATEGORIA_REGISTRO,
                        'tipo' => OrderEvent::TIPO_PROCEDIMENTO_REGISTRADO,
                        'titulo' => 'Procedimento registrado',
                        'descricao' => trim((string) $row->descricao) !== '' ? trim((string) $row->descricao) : null,
                        'dados' => json_encode(['procedimento_historico_id' => (int) $row->id], JSON_UNESCAPED_UNICODE),
                        'usuario_id' => $row->usuario_id !== null ? (int) $row->usuario_id : null,
                        'origem' => OrderEvent::ORIGEM_USUARIO,
                        'legacy_tabela' => 'os_procedimentos_historico',
                        'legacy_id' => (int) $row->id,
                        'created_at' => $row->created_at,
                    ];
                }
                $inserted += DB::table('os_eventos')->insertOrIgnore($batch);
            });

        $this->info(sprintf('os_procedimentos_historico: %d lida(s), %d importada(s).', $read, $inserted));
    }

    private function importBudgetStatusHistory(int $osId): void
    {
        if (! Schema::hasTable('orcamento_status_historico') || ! Schema::hasTable('orcamentos')) {
            $this->warn('orcamento_status_historico: tabela ausente, pulando.');

            return;
        }

        $inserted = 0;
        $read = 0;

        DB::table('orcamento_status_historico as h')
            ->join('orcamentos as o', 'o.id', '=', 'h.orcamento_id')
            ->whereNotNull('o.os_id')
            ->when($osId > 0, fn ($q) => $q->where('o.os_id', $osId))
            // Decisoes do cliente ja sao importadas de orcamento_aprovacoes
            // (fonte canonica) — pular evita evento duplicado na timeline.
            ->where('h.origem', '!=', 'cliente')
            ->select('h.*', 'o.os_id', 'o.numero as orcamento_numero')
            ->orderBy('h.id')
            ->chunkById(500, function ($rows) use (&$inserted, &$read): void {
                $batch = [];
                foreach ($rows as $row) {
                    $read++;
                    $isCreation = $row->status_anterior === null || trim((string) $row->status_anterior) === '';

                    $batch[] = [
                        'os_id' => (int) $row->os_id,
                        'categoria' => OrderEvent::CATEGORIA_ORCAMENTO,
                        'tipo' => $isCreation ? OrderEvent::TIPO_ORCAMENTO_CRIADO : OrderEvent::TIPO_ORCAMENTO_ATUALIZADO,
                        'titulo' => $isCreation ? 'Orçamento criado' : 'Orçamento atualizado',
                        'descricao' => sprintf(
                            'Orçamento %s%s',
                            (string) $row->orcamento_numero,
                            trim((string) ($row->observacao ?? '')) !== '' ? ' — ' . trim((string) $row->observacao) : ''
                        ),
                        'dados' => json_encode([
                            'orcamento_id' => (int) $row->orcamento_id,
                            'numero' => (string) $row->orcamento_numero,
                            'status_anterior' => $row->status_anterior,
                            'status_novo' => $row->status_novo,
                        ], JSON_UNESCAPED_UNICODE),
                        'usuario_id' => $row->alterado_por !== null ? (int) $row->alterado_por : null,
                        'origem' => OrderEvent::ORIGEM_USUARIO,
                        'legacy_tabela' => 'orcamento_status_historico',
                        'legacy_id' => (int) $row->id,
                        'created_at' => $row->created_at,
                    ];
                }
                $inserted += DB::table('os_eventos')->insertOrIgnore($batch);
            }, 'h.id', 'id');

        $this->info(sprintf('orcamento_status_historico: %d lida(s), %d importada(s).', $read, $inserted));
    }

    private function importBudgetSends(int $osId): void
    {
        if (! Schema::hasTable('orcamento_envios') || ! Schema::hasTable('orcamentos')) {
            $this->warn('orcamento_envios: tabela ausente, pulando.');

            return;
        }

        $inserted = 0;
        $read = 0;

        DB::table('orcamento_envios as e')
            ->join('orcamentos as o', 'o.id', '=', 'e.orcamento_id')
            ->whereNotNull('o.os_id')
            ->when($osId > 0, fn ($q) => $q->where('o.os_id', $osId))
            ->select('e.*', 'o.os_id', 'o.numero as orcamento_numero')
            ->orderBy('e.id')
            ->chunkById(500, function ($rows) use (&$inserted, &$read): void {
                $batch = [];
                foreach ($rows as $row) {
                    $read++;
                    $enviadoOk = trim((string) ($row->status ?? '')) === 'enviado';
                    $timestamp = $row->enviado_em ?? $row->created_at;

                    if ($enviadoOk) {
                        $batch[] = [
                            'os_id' => (int) $row->os_id,
                            'categoria' => OrderEvent::CATEGORIA_MENSAGEM,
                            'tipo' => OrderEvent::TIPO_WHATSAPP_ENVIADO,
                            'titulo' => 'Orçamento enviado por WhatsApp',
                            'descricao' => sprintf('Proposta do orçamento %s enviada ao cliente.', (string) $row->orcamento_numero),
                            'dados' => json_encode([
                                'origin' => 'orcamento_aprovacao',
                                'orcamento_id' => (int) $row->orcamento_id,
                                'envio_id' => (int) $row->id,
                                'canal' => (string) $row->canal,
                                'destino' => (string) $row->destino,
                            ], JSON_UNESCAPED_UNICODE),
                            'usuario_id' => $row->enviado_por !== null ? (int) $row->enviado_por : null,
                            'origem' => OrderEvent::ORIGEM_USUARIO,
                            'legacy_tabela' => 'orcamento_envios',
                            'legacy_id' => (int) $row->id,
                            'created_at' => $timestamp,
                        ];
                    }

                    if (trim((string) ($row->documento_path ?? '')) !== '') {
                        $batch[] = [
                            'os_id' => (int) $row->os_id,
                            'categoria' => OrderEvent::CATEGORIA_DOCUMENTO,
                            'tipo' => OrderEvent::TIPO_ORCAMENTO_PDF_GERADO,
                            'titulo' => 'PDF do orçamento gerado',
                            'descricao' => sprintf('PDF do orçamento %s gerado para envio.', (string) $row->orcamento_numero),
                            'dados' => json_encode([
                                'orcamento_id' => (int) $row->orcamento_id,
                                'envio_id' => (int) $row->id,
                                'arquivo' => (string) $row->documento_path,
                            ], JSON_UNESCAPED_UNICODE),
                            'usuario_id' => $row->enviado_por !== null ? (int) $row->enviado_por : null,
                            'origem' => OrderEvent::ORIGEM_USUARIO,
                            'legacy_tabela' => 'orcamento_envios',
                            'legacy_id' => (int) $row->id,
                            'created_at' => $row->created_at ?? $timestamp,
                        ];
                    }
                }
                $inserted += DB::table('os_eventos')->insertOrIgnore($batch);
            }, 'e.id', 'id');

        $this->info(sprintf('orcamento_envios: %d lida(s), %d evento(s) importado(s).', $read, $inserted));
    }

    private function importBudgetApprovals(int $osId): void
    {
        if (! Schema::hasTable('orcamento_aprovacoes') || ! Schema::hasTable('orcamentos')) {
            $this->warn('orcamento_aprovacoes: tabela ausente, pulando.');

            return;
        }

        $inserted = 0;
        $read = 0;

        DB::table('orcamento_aprovacoes as a')
            ->join('orcamentos as o', 'o.id', '=', 'a.orcamento_id')
            ->whereNotNull('o.os_id')
            ->when($osId > 0, fn ($q) => $q->where('o.os_id', $osId))
            ->select('a.*', 'o.os_id', 'o.numero as orcamento_numero')
            ->orderBy('a.id')
            ->chunkById(500, function ($rows) use (&$inserted, &$read): void {
                $batch = [];
                foreach ($rows as $row) {
                    $read++;
                    $aprovado = trim((string) ($row->acao ?? '')) === 'aprovado';

                    $batch[] = [
                        'os_id' => (int) $row->os_id,
                        'categoria' => OrderEvent::CATEGORIA_ORCAMENTO,
                        'tipo' => $aprovado ? OrderEvent::TIPO_ORCAMENTO_APROVADO : OrderEvent::TIPO_ORCAMENTO_RECUSADO,
                        'titulo' => $aprovado ? 'Orçamento aprovado pelo cliente' : 'Orçamento recusado pelo cliente',
                        'descricao' => sprintf(
                            'Cliente %s o orçamento %s.%s',
                            $aprovado ? 'aprovou' : 'recusou',
                            (string) $row->orcamento_numero,
                            trim((string) ($row->resposta_cliente ?? '')) !== '' ? ' Resposta: ' . trim((string) $row->resposta_cliente) : ''
                        ),
                        'dados' => json_encode([
                            'orcamento_id' => (int) $row->orcamento_id,
                            'numero' => (string) $row->orcamento_numero,
                            'resposta_cliente' => $row->resposta_cliente,
                            'ip_origem' => $row->ip_origem,
                            'user_agent' => $row->user_agent,
                        ], JSON_UNESCAPED_UNICODE),
                        'usuario_id' => $row->usuario_id !== null ? (int) $row->usuario_id : null,
                        'origem' => OrderEvent::ORIGEM_CLIENTE,
                        'legacy_tabela' => 'orcamento_aprovacoes',
                        'legacy_id' => (int) $row->id,
                        'created_at' => $row->created_at,
                    ];
                }
                $inserted += DB::table('os_eventos')->insertOrIgnore($batch);
            }, 'a.id', 'id');

        $this->info(sprintf('orcamento_aprovacoes: %d lida(s), %d importada(s).', $read, $inserted));
    }

    private function importFinanceiroTitles(int $osId): void
    {
        if (! Schema::hasTable('financeiro')) {
            $this->warn('financeiro: tabela ausente, pulando.');

            return;
        }

        $inserted = 0;
        $read = 0;

        DB::table('financeiro')
            ->whereNotNull('os_id')
            ->where('os_id', '>', 0)
            ->when($osId > 0, fn ($q) => $q->where('os_id', $osId))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$inserted, &$read): void {
                $batch = [];
                foreach ($rows as $row) {
                    $read++;
                    $batch[] = [
                        'os_id' => (int) $row->os_id,
                        'categoria' => OrderEvent::CATEGORIA_FINANCEIRO,
                        'tipo' => OrderEvent::TIPO_TITULO_CRIADO,
                        'titulo' => 'Título financeiro criado',
                        'descricao' => trim((string) ($row->descricao ?? '')) !== '' ? trim((string) $row->descricao) : null,
                        'dados' => json_encode([
                            'financeiro_id' => (int) $row->id,
                            'tipo' => (string) $row->tipo,
                            'categoria' => (string) ($row->categoria ?? ''),
                            'valor' => round((float) $row->valor, 2),
                            'origem_tipo' => $row->origem_tipo ?? null,
                            'status_atual' => (string) ($row->status ?? ''),
                        ], JSON_UNESCAPED_UNICODE),
                        'usuario_id' => null,
                        'origem' => OrderEvent::ORIGEM_USUARIO,
                        'legacy_tabela' => 'financeiro',
                        'legacy_id' => (int) $row->id,
                        'created_at' => $row->created_at,
                    ];
                }
                $inserted += DB::table('os_eventos')->insertOrIgnore($batch);
            });

        $this->info(sprintf('financeiro (os_id): %d lida(s), %d importada(s).', $read, $inserted));
    }

    private function importFinanceiroMovements(int $osId): void
    {
        if (! Schema::hasTable('financeiro_movimentos') || ! Schema::hasTable('financeiro')) {
            $this->warn('financeiro_movimentos: tabela ausente, pulando.');

            return;
        }

        $inserted = 0;
        $read = 0;

        DB::table('financeiro_movimentos as m')
            ->join('financeiro as f', 'f.id', '=', 'm.financeiro_id')
            ->whereNotNull('f.os_id')
            ->where('f.os_id', '>', 0)
            ->when($osId > 0, fn ($q) => $q->where('f.os_id', $osId))
            ->select('m.*', 'f.os_id', 'f.tipo as financeiro_tipo')
            ->orderBy('m.id')
            ->chunkById(500, function ($rows) use (&$inserted, &$read): void {
                $batch = [];
                foreach ($rows as $row) {
                    $read++;
                    $isEntrada = trim((string) ($row->tipo_movimento ?? '')) === 'entrada';

                    $batch[] = [
                        'os_id' => (int) $row->os_id,
                        'categoria' => OrderEvent::CATEGORIA_FINANCEIRO,
                        'tipo' => OrderEvent::TIPO_MOVIMENTO_REGISTRADO,
                        'titulo' => $isEntrada ? 'Recebimento registrado' : 'Pagamento registrado',
                        'descricao' => sprintf(
                            'R$ %s (%s).',
                            number_format((float) $row->valor_movimento, 2, ',', '.'),
                            trim((string) ($row->forma_pagamento ?? '')) !== '' ? (string) $row->forma_pagamento : 'forma não informada'
                        ),
                        'dados' => json_encode([
                            'financeiro_id' => (int) $row->financeiro_id,
                            'movimento_id' => (int) $row->id,
                            'valor' => round((float) $row->valor_movimento, 2),
                            'forma_pagamento' => $row->forma_pagamento,
                            'data_movimento' => $row->data_movimento,
                        ], JSON_UNESCAPED_UNICODE),
                        'usuario_id' => null,
                        'origem' => OrderEvent::ORIGEM_USUARIO,
                        'legacy_tabela' => 'financeiro_movimentos',
                        'legacy_id' => (int) $row->id,
                        'created_at' => $row->created_at ?? $row->data_movimento,
                    ];
                }
                $inserted += DB::table('os_eventos')->insertOrIgnore($batch);
            }, 'm.id', 'id');

        $this->info(sprintf('financeiro_movimentos (os_id): %d lida(s), %d importada(s).', $read, $inserted));
    }

    private function importCollectionSchedules(int $osId): void
    {
        if (! Schema::hasTable('os_cobranca_agendamentos')) {
            $this->warn('os_cobranca_agendamentos: tabela ausente, pulando.');

            return;
        }

        $inserted = 0;
        $read = 0;

        DB::table('os_cobranca_agendamentos')
            ->when($osId > 0, fn ($q) => $q->where('os_id', $osId))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$inserted, &$read): void {
                $batch = [];
                foreach ($rows as $row) {
                    $read++;

                    $batch[] = [
                        'os_id' => (int) $row->os_id,
                        'categoria' => OrderEvent::CATEGORIA_FINANCEIRO,
                        'tipo' => OrderEvent::TIPO_COBRANCA_AGENDADA,
                        'titulo' => 'Cobrança automática agendada',
                        'descricao' => sprintf('Cobrança D+%d agendada por %s.', (int) $row->prazo_dias, (string) $row->canal),
                        'dados' => json_encode([
                            'agendamento_id' => (int) $row->id,
                            'prazo_dias' => (int) $row->prazo_dias,
                            'canal' => (string) $row->canal,
                            'status_atual' => (string) $row->status,
                        ], JSON_UNESCAPED_UNICODE),
                        'usuario_id' => null,
                        'origem' => OrderEvent::ORIGEM_SISTEMA,
                        'legacy_tabela' => 'os_cobranca_agendamentos',
                        'legacy_id' => (int) $row->id,
                        'created_at' => $row->created_at,
                    ];

                    if ($row->enviado_em !== null) {
                        $batch[] = [
                            'os_id' => (int) $row->os_id,
                            'categoria' => OrderEvent::CATEGORIA_MENSAGEM,
                            'tipo' => OrderEvent::TIPO_COBRANCA_ENVIADA,
                            'titulo' => 'Cobrança automática enviada',
                            'descricao' => sprintf('Lembrete de saldo pendente (D+%d) enviado por %s.', (int) $row->prazo_dias, (string) $row->canal),
                            'dados' => json_encode([
                                'agendamento_id' => (int) $row->id,
                                'prazo_dias' => (int) $row->prazo_dias,
                            ], JSON_UNESCAPED_UNICODE),
                            'usuario_id' => null,
                            'origem' => OrderEvent::ORIGEM_AUTOMACAO,
                            'legacy_tabela' => 'os_cobranca_agendamentos',
                            'legacy_id' => (int) $row->id,
                            'created_at' => $row->enviado_em,
                        ];
                    }
                }
                $inserted += DB::table('os_eventos')->insertOrIgnore($batch);
            });

        $this->info(sprintf('os_cobranca_agendamentos: %d lida(s), %d evento(s) importado(s).', $read, $inserted));
    }
}
