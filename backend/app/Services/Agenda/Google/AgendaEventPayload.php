<?php

namespace App\Services\Agenda\Google;

use App\Models\AgendaCompromisso;
use Carbon\CarbonImmutable;

/**
 * Traducao entre um compromisso da agenda e o recurso Event da Calendar API.
 *
 * Tambem calcula o hash de conteudo que sustenta o anti-loop do sync
 * bidirecional: so entra no hash o que de fato viaja para o Google. Assim,
 * mudar um campo puramente interno (concluido_por, google_etag) nao dispara
 * push, e um push nosso nao volta como se fosse edicao do usuario.
 */
class AgendaEventPayload
{
    public static function contentHash(AgendaCompromisso $item): string
    {
        return sha1((string) json_encode(self::hashableParts($item)));
    }

    /** @return array<string, mixed> */
    public static function toGoogleEvent(AgendaCompromisso $item): array
    {
        $start = CarbonImmutable::parse($item->inicio_em);
        $end = $item->fim_em !== null
            ? CarbonImmutable::parse($item->fim_em)
            : $start->addHour();

        if ((bool) $item->dia_inteiro) {
            $event = [
                // A Calendar API trata `end.date` como exclusivo: um evento de
                // um dia so termina no dia seguinte. Sem o addDay o evento
                // some do calendario.
                'start' => ['date' => $start->toDateString()],
                'end' => ['date' => $start->max($end)->addDay()->toDateString()],
            ];
        } else {
            if ($end->lessThanOrEqualTo($start)) {
                $end = $start->addHour();
            }

            $event = [
                'start' => ['dateTime' => $start->toRfc3339String(), 'timeZone' => config('app.timezone')],
                'end' => ['dateTime' => $end->toRfc3339String(), 'timeZone' => config('app.timezone')],
            ];
        }

        $event['summary'] = self::summary($item);
        $event['description'] = self::description($item);
        // Concluido/cancelado deixa de ocupar espaco visual no celular sem
        // sumir do historico: continua no calendario, marcado como cancelado.
        $event['status'] = $item->status === AgendaCompromisso::STATUS_PENDENTE
            ? 'confirmed'
            : 'cancelled';

        $reminder = (int) $item->lembrete_minutos;
        $event['reminders'] = $reminder > 0
            ? ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => $reminder]]]
            : ['useDefault' => true];

        // Marca de propriedade: o pull usa isso para distinguir um evento que
        // nasceu aqui de um que o usuario criou pelo celular.
        $event['extendedProperties'] = [
            'private' => [
                'erp_compromisso_id' => (string) $item->id,
                'erp_tipo' => (string) $item->tipo,
            ],
        ];

        return $event;
    }

    /**
     * Titulo com prefixo por tipo: no celular o usuario ve so uma linha, e
     * "Vencimento" ou "Retorno" ali vale mais que o texto completo.
     */
    public static function summary(AgendaCompromisso $item): string
    {
        $prefix = match ((string) $item->tipo) {
            'conta_pagar' => '💸 ',
            'conta_receber' => '💰 ',
            'retorno_pos_servico' => '📞 ',
            'prazo_os' => '🔧 ',
            'cobranca_os' => '📨 ',
            default => '',
        };

        return mb_substr($prefix.trim((string) $item->titulo), 0, 250);
    }

    private static function description(AgendaCompromisso $item): string
    {
        $lines = [];

        $descricao = trim((string) $item->descricao);
        if ($descricao !== '') {
            $lines[] = $descricao;
        }

        if ($item->isManaged()) {
            $lines[] = '';
            $lines[] = '— Gerado automaticamente pelo Sistema ERP. Alterações de data feitas aqui são desfeitas na próxima sincronização.';
        }

        return trim(implode("\n", $lines));
    }

    /** @return array<string, mixed> */
    private static function hashableParts(AgendaCompromisso $item): array
    {
        return [
            'titulo' => self::summary($item),
            'descricao' => self::description($item),
            'inicio' => CarbonImmutable::parse($item->inicio_em)->toIso8601String(),
            'fim' => $item->fim_em !== null ? CarbonImmutable::parse($item->fim_em)->toIso8601String() : null,
            'dia_inteiro' => (bool) $item->dia_inteiro,
            'status' => (string) $item->status,
            'lembrete' => (int) $item->lembrete_minutos,
        ];
    }
}
