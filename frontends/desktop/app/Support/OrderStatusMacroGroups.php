<?php

namespace App\Support;

/**
 * Vocabulário compartilhado das macrofases (`os_status.grupo_macro`) e dos
 * estados de fluxo (`os_status.estado_fluxo_padrao`) do catálogo de status de OS.
 *
 * Vive aqui, e não dentro de um controller, porque várias telas leem o mesmo
 * catálogo e precisam chamar as fases pelo mesmo nome: o cadastro de status
 * (`OrderStatusFlowController`), o Modelo da Assistência Técnica
 * (`AssistanceModelController`), o modal "Alterar status da OS" e o Mapa da OS.
 * Quando isso era método privado de um dos dois primeiros, o outro repetia os
 * rótulos por conta própria e eles divergiam.
 *
 * Em 2026-09-09 esta classe virou a fonte ÚNICA de ordem, rótulo e cor das
 * macrofases: antes existiam quatro cópias divergentes do mesmo vocabulário
 * (esta classe, o `MACRO_PHASES` de `orders-status-modal.js`, a paleta CSS de
 * `_status_modal.blade.php` e as `LANES` do gerador Python do mapa), com três
 * ordens diferentes. `toPayload()` é o que atravessa para o JS — nenhuma tela
 * deve voltar a declarar essa lista por conta própria.
 */
class OrderStatusMacroGroups
{
    /**
     * Ordem cronológica oficial das macrofases do fluxo — a mesma nas três
     * telas (Mapa da OS, modal "Alterar status" e cadastro Status de OS).
     *
     * É declarada aqui de propósito e NÃO derivada de `os_status.ordem_fluxo`:
     * no banco `interrupcao` tem ordem 120-140, ou seja, cairia depois de
     * Execução/Qualidade — decisão explícita do usuário (2026-08-10) é que
     * "Em espera" vem logo depois de Orçamento, porque a espera acontece
     * antes de a bancada encostar no equipamento.
     *
     * @return list<string>
     */
    public static function order(): array
    {
        return ['recepcao', 'diagnostico', 'orcamento', 'interrupcao', 'execucao', 'qualidade', 'concluido'];
    }

    /**
     * Saídas do fluxo: a OS termina sem seguir para Concluído. Espelha
     * `OrderStatus::FLOW_EXIT_MACRO_GROUPS` do backend (que é a fonte da
     * verdade da regra); aqui a lista existe só para posicionar essas fases
     * DEPOIS das fases de progresso na leitura das telas.
     *
     * @return list<string>
     */
    public static function exitOrder(): array
    {
        return ['finalizado_sem_reparo', 'cancelado'];
    }

    /**
     * Encerramento (`grupo_macro = 'encerrado'`) não é fase de progresso nem
     * saída comum: só a baixa da OS aplica esses status
     * (`OrderClosureService::close()`), por isso fica isolado no fim, atrás da
     * porta de baixa no mapa. Ver skill sistema-erp-os-fluxo-fechamento.
     */
    public const CLOSURE_GROUP = 'encerrado';

    /**
     * Posição de ordenação de uma macrofase. Fases do fluxo primeiro, depois
     * as saídas, depois o encerramento; qualquer macrofase desconhecida (o
     * campo `grupo_macro` é texto livre no cadastro) cai no fim, ordenada
     * alfabeticamente para o resultado ser determinístico — nunca some.
     */
    public static function orderIndex(string $grupoMacro): int
    {
        $code = mb_strtolower(trim($grupoMacro));

        $flow = array_search($code, self::order(), true);
        if ($flow !== false) {
            return 100 + $flow;
        }

        $exit = array_search($code, self::exitOrder(), true);
        if ($exit !== false) {
            return 200 + $exit;
        }

        if ($code === self::CLOSURE_GROUP) {
            return 300;
        }

        return 400;
    }

    /**
     * Paleta de FLUXO das macrofases — a que o usuário definiu em 2026-08-10
     * para o fluxograma do modal, agora compartilhada com o Mapa da OS.
     *
     * Diferente de accent()/softAccent(), que existem para o donut do
     * dashboard e têm outra exigência (cada matiz usado uma única vez, senão
     * as fatias pequenas ficam indistinguíveis). São dois usos legítimos e
     * distintos da mesma taxonomia — não unificar sem decisão do usuário.
     *
     * ATENÇÃO — estas cores também viram TRAÇO no Mapa da OS: o trajeto
     * percorrido e a próxima etapa sugerida são desenhados na cor do card de
     * destino (ver PHASE_COLORED em orders-map.js). Por isso cada cor precisa
     * funcionar nos dois papéis: preenchimento de card E linha sobre branco.
     *
     * Ajustes de 2026-09-09, pedidos pelo usuário depois de ver o mapa:
     * - `interrupcao` era #FFD400, um amarelo que como linha ficava em 1.43:1
     *   sobre o branco e sumia. Passou a #B8860B (3.25:1), que continua lendo
     *   como amarelo/dourado mas destaca. O texto do card virou branco — o
     *   #3D3200 escuro de antes existia por causa do amarelo claro.
     * - `cancelado` era #CC0000, a mesma cor de `finalizado_sem_reparo`.
     *   Passou a #000000, que separa as duas saídas do fluxo.
     *
     * Ainda abaixo de 3:1 como linha, por decisão explícita do usuário de usar
     * a cor literal do card em vez de escurecer/contornar: `orcamento` 2.24,
     * `diagnostico` 2.34, `qualidade` 2.51. Não "corrigir" sem falar com ele.
     *
     * @return array{color: string, text: string}
     */
    public static function flowAccent(string $grupoMacro): array
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => ['color' => '#10739E', 'text' => '#FFFFFF'],
            'diagnostico' => ['color' => '#F2931E', 'text' => '#FFFFFF'],
            'orcamento' => ['color' => '#66B2FF', 'text' => '#10395B'],
            'interrupcao' => ['color' => '#B8860B', 'text' => '#FFFFFF'],
            'execucao' => ['color' => '#999900', 'text' => '#FFFFFF'],
            'qualidade' => ['color' => '#9999FF', 'text' => '#1F1F5B'],
            'concluido' => ['color' => '#00994D', 'text' => '#FFFFFF'],
            'finalizado_sem_reparo' => ['color' => '#CC0000', 'text' => '#FFFFFF'],
            'cancelado' => ['color' => '#000000', 'text' => '#FFFFFF'],
            self::CLOSURE_GROUP => ['color' => '#7048E8', 'text' => '#FFFFFF'],
            default => ['color' => '#6f5afc', 'text' => '#FFFFFF'],
        };
    }

    /**
     * Ordena os códigos de macrofase que existem de fato no catálogo vivo.
     * Recebe os `grupo_macro` distintos e devolve na ordem oficial, com as
     * desconhecidas no fim em ordem alfabética.
     *
     * @param  iterable<string>  $grupos
     * @return list<string>
     */
    public static function sortGroups(iterable $grupos): array
    {
        // String vazia e um grupo legitimo aqui: `os_status.grupo_macro` e
        // NOT NULL mas aceita '', e um status nessa situacao precisa aparecer
        // no mapa e nas listagens (com o rotulo "Sem grupo macro") em vez de
        // sumir. orderIndex('') cai no bucket das desconhecidas, no fim.
        $codes = [];
        foreach ($grupos as $grupo) {
            $codes[mb_strtolower(trim((string) $grupo))] = true;
        }

        $codes = array_keys($codes);

        usort($codes, static function (string $a, string $b): int {
            return [self::orderIndex($a), $a] <=> [self::orderIndex($b), $b];
        });

        return $codes;
    }

    /**
     * Vocabulário completo para o JS (modal e mapa). Emitido pelas views como
     * `window.__DESKTOP_OS_FLOW_PHASES`; substitui o `MACRO_PHASES`/
     * `EXIT_PHASES`/paleta CSS que viviam duplicados em
     * `orders-status-modal.js` e `_status_modal.blade.php`.
     *
     * `grupos` cobre as macrofases conhecidas; uma fase inventada no cadastro
     * não aparece aqui e o JS resolve pelo fallback (humanizeSlug + cor
     * padrão), do mesmo jeito que o PHP.
     */
    public static function toPayload(): array
    {
        $grupos = [];

        foreach ([...self::order(), ...self::exitOrder(), self::CLOSURE_GROUP] as $code) {
            $grupos[$code] = [
                'codigo' => $code,
                'rotulo' => self::label($code),
                'ordem' => self::orderIndex($code),
                'saida' => in_array($code, self::exitOrder(), true),
                'encerramento' => $code === self::CLOSURE_GROUP,
            ] + self::flowAccent($code);
        }

        return [
            'ordem' => self::order(),
            'saidas' => self::exitOrder(),
            'encerramento' => self::CLOSURE_GROUP,
            'grupos' => $grupos,
            'padrao' => self::flowAccent('__desconhecida__') + ['ordem' => 400],
        ];
    }

    public static function label(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => 'Recepção',
            'diagnostico' => 'Diagnóstico',
            'orcamento' => 'Orçamento',
            'execucao' => 'Execução',
            'qualidade' => 'Qualidade',
            'interrupcao' => 'Em espera',
            'concluido' => 'Concluído',
            'finalizado_sem_reparo' => 'Sem reparo',
            'encerrado' => 'Encerramento',
            'cancelado' => 'Cancelado',
            default => self::humanizeSlug($grupoMacro),
        };
    }

    public static function description(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => 'Entrada, conferência inicial e triagem da OS.',
            'diagnostico' => 'Levantamento técnico, verificação de causa e definição do caminho.',
            'orcamento' => 'Aprovação comercial, retorno do cliente e decisão de continuidade.',
            'execucao' => 'Reparo, testes, validação técnica e acompanhamento da solução.',
            'qualidade' => 'Testes operacionais e finais antes de liberar a OS.',
            'interrupcao' => 'Pausas do fluxo: espera de peça, pagamento ou pendência financeira.',
            'concluido' => 'Reparo concluído, disponível na loja ou garantia concluída.',
            'finalizado_sem_reparo' => 'Sem reparo: irreparável, disponível para retirada ou recusado.',
            'encerrado' => 'Entrega, devolução sem reparo ou descarte do equipamento.',
            'cancelado' => 'Atendimento cancelado.',
            default => 'Fase operacional agrupada por macroprocesso.',
        };
    }

    /**
     * Paleta das 10 macrofases. `execucao`/`concluido` eram dois verdes quase
     * idênticos e `qualidade`/`interrupcao`/`orcamento` eram três tons de
     * dourado — no donut do dashboard, onde essas fatias ficam pequenas e
     * espremidas lado a lado, ficavam indistinguíveis. Cada matiz aqui é
     * usado uma única vez.
     */
    public static function accent(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => '#0ea5e9',
            'diagnostico' => '#6f5afc',
            'orcamento' => '#f59e0b',
            'execucao' => '#0d9488',
            'qualidade' => '#db2777',
            'interrupcao' => '#c2410c',
            'concluido' => '#16a34a',
            'finalizado_sem_reparo' => '#b85450',
            'encerrado' => '#64748b',
            'cancelado' => '#ef4444',
            default => '#6f5afc',
        };
    }

    public static function softAccent(string $grupoMacro): string
    {
        return match (mb_strtolower(trim($grupoMacro))) {
            'recepcao' => 'rgba(14, 165, 233, 0.12)',
            'diagnostico' => 'rgba(111, 90, 252, 0.12)',
            'orcamento' => 'rgba(245, 158, 11, 0.14)',
            'execucao' => 'rgba(13, 148, 136, 0.12)',
            'qualidade' => 'rgba(219, 39, 119, 0.12)',
            'interrupcao' => 'rgba(194, 65, 12, 0.14)',
            'concluido' => 'rgba(22, 163, 74, 0.12)',
            'finalizado_sem_reparo' => 'rgba(184, 84, 80, 0.12)',
            'encerrado' => 'rgba(100, 116, 139, 0.12)',
            'cancelado' => 'rgba(239, 68, 68, 0.12)',
            default => 'rgba(111, 90, 252, 0.12)',
        };
    }

    /**
     * Rótulo de `estado_fluxo_padrao`. O catálogo real usa seis valores —
     * `pronto` e `cancelado` existem no banco e faltavam no match antigo,
     * caindo no humanizeSlug() por acidente.
     */
    public static function flowStateLabel(string $flowState): string
    {
        return match (mb_strtolower(trim($flowState))) {
            'em_atendimento' => 'Em atendimento',
            'em_execucao' => 'Em execução',
            'pausado' => 'Pausado',
            'pronto' => 'Pronto',
            'encerrado' => 'Encerrado',
            'cancelado' => 'Cancelado',
            default => self::humanizeSlug($flowState),
        };
    }

    public static function humanizeSlug(string $value): string
    {
        $value = trim(str_replace(['_', '-'], ' ', $value));

        return $value !== '' ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8') : 'Sem grupo macro';
    }
}
