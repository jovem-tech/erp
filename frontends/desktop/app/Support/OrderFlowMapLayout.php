<?php

namespace App\Support;

/**
 * Geometria do Mapa da OS (`/os/{id}/mapa` e a aba "Mapa de status" do modal),
 * calculada a partir do catalogo VIVO de status.
 *
 * Antes de 2026-09-09 o desenho era um SVG estatico gerado por
 * `scripts/python/diagrama_fluxo_os_organizado.py`: raias, cards e setas com
 * coordenadas escritas a mao. Criar, renomear, reordenar ou desativar um
 * status na tela "Status de OS" nao mudava nada no mapa, e regenerar exigia um
 * dev roteando coordenadas no Python. Esta classe substitui aquele artefato.
 *
 * Classe pura de proposito: sem I/O, sem banco, sem sessao — recebe o catalogo
 * (o mesmo `status_disponiveis` que a API ja devolve) e devolve a geometria.
 * Isso a torna testavel isolada e mantem a regra de negocio no backend.
 *
 * As ARESTAS nao sao calculadas aqui. Elas dependem da OS (trajeto percorrido,
 * rota provavel, proximas etapas) e sao desenhadas em runtime por
 * `orders-map.js`, que le as caixas dos cards com getBBox(). Foi isso que
 * consertou o bug de cronologia: antes a seta precisava PREEXISTIR no SVG, e um
 * salto real fora do catalogo (ex.: aguardando_reparo -> reparo_concluido)
 * simplesmente nao aparecia.
 */
class OrderFlowMapLayout
{
    /** Faixa horizontal: fases de progresso do fluxo. */
    private const BAND_FLOW = 0;

    /**
     * Faixa horizontal dos desfechos: saidas do fluxo (sem reparo, cancelado)
     * e encerramento, lado a lado.
     *
     * Antes eram duas faixas empilhadas, cada uma com uma ou duas raias
     * encostadas na margem esquerda: dois tercos do desenho ficavam vazios e
     * as setas precisavam de desvios enormes para chegar la embaixo.
     */
    private const BAND_OUTCOME = 1;

    private const CARD_W = 168;
    private const CARD_H = 62;
    private const CARD_GAP = 16;
    private const CARD_LINE_H = 18;

    private const LANE_PAD_X = 22;
    private const LANE_HEADER_H = 34;
    private const LANE_PAD_BOTTOM = 18;
    private const LANE_GAP_X = 34;
    private const LANE_GAP_Y = 54;

    private const MARGIN_X = 40;

    /**
     * Folga acima da primeira linha de raias e abaixo da ultima. Maior que a
     * lateral de proposito: e por ai que passa o canal horizontal que o
     * roteador de setas usa para contornar raias inteiras (uma seta de
     * Execucao ate Concluido sobe, atravessa por cima da Qualidade e desce).
     * Com 40 a seta encostava na borda do desenho.
     */
    private const MARGIN_TOP = 72;
    private const MARGIN_BOTTOM = 72;

    /** Porta unica de encerramento, desenhada no topo da faixa de encerramento. */
    private const PORT_H = 50;

    /** Maximo de caracteres por linha do rotulo do card antes de quebrar. */
    private const LABEL_WRAP = 18;

    /**
     * Largura-alvo do desenho. Nao e um limite rigido: define quantas raias
     * cabem lado a lado antes de quebrar a linha. Com o catalogo atual (7
     * macrofases de fluxo) tudo cabe numa faixa unica, que e como a
     * cronologia se le melhor — esquerda para a direita. Se o usuario criar
     * muitas macrofases, quebra sozinho em vez de esticar sem fim.
     */
    private const TARGET_W = 1800;

    /** Largura de uma raia: um card com respiro dos dois lados. */
    private static function laneWidth(): int
    {
        return self::LANE_PAD_X * 2 + self::CARD_W;
    }

    /** Quantas raias cabem lado a lado dentro de TARGET_W. */
    private static function lanesPerRow(): int
    {
        $step = self::laneWidth() + self::LANE_GAP_X;

        return max(1, (int) floor((self::TARGET_W - 2 * self::MARGIN_X + self::LANE_GAP_X) / $step));
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses  catalogo `status_disponiveis`
     * @return array{
     *     width: int,
     *     height: int,
     *     lanes: list<array<string, mixed>>,
     *     cards: array<string, array<string, mixed>>,
     *     port: array<string, mixed>|null
     * }
     */
    public static function build(array $statuses): array
    {
        $byGroup = self::groupStatuses($statuses);

        $bands = [self::BAND_FLOW => [], self::BAND_OUTCOME => []];

        foreach ($byGroup as $grupo => $groupStatuses) {
            $bands[self::bandOf($grupo)][$grupo] = $groupStatuses;
        }

        $lanes = [];
        $cards = [];
        $port = null;

        $cursorY = self::MARGIN_TOP;
        $maxRight = 0;

        foreach ([self::BAND_FLOW, self::BAND_OUTCOME] as $band) {
            if ($bands[$band] === []) {
                continue;
            }

            // A faixa de desfechos reserva no topo uma tira para a porta da
            // baixa, que e desenhada logo acima da raia de encerramento.
            $hasClosure = isset($bands[$band][OrderStatusMacroGroups::CLOSURE_GROUP]);
            if ($hasClosure) {
                $cursorY += self::PORT_H + 18;
            }

            // A faixa de desfechos e' alinhada a DIREITA: assim a raia de
            // encerramento cai logo abaixo de "Concluido", que e' onde o fluxo
            // termina. Alinhada a esquerda (como era), a porta da baixa ficava
            // no canto oposto ao da etapa atual tipica e a seta roxa cruzava o
            // desenho inteiro.
            [$bandLanes, $bandCards, $bandBottom, $bandRight] = self::layoutBand(
                $bands[$band],
                $cursorY,
                $band,
                $band !== self::BAND_FLOW
            );

            // A porta e' a unica entrada dos status de encerramento
            // (OrderClosureService::close(); ver skill
            // sistema-erp-os-fluxo-fechamento), entao fica encostada na raia
            // deles, nao solta num canto.
            if ($hasClosure) {
                foreach ($bandLanes as $lane) {
                    if ($lane['grupo'] === OrderStatusMacroGroups::CLOSURE_GROUP) {
                        $port = [
                            'x' => $lane['x'],
                            'y' => $lane['y'] - self::PORT_H - 18,
                            'w' => $lane['w'],
                            'h' => self::PORT_H,
                        ];

                        break;
                    }
                }
            }

            $lanes = array_merge($lanes, $bandLanes);
            $cards += $bandCards;
            $cursorY = $bandBottom + self::LANE_GAP_Y;
            $maxRight = max($maxRight, $bandRight);
        }

        return [
            'width' => (int) ($maxRight + self::MARGIN_X),
            'height' => (int) ($cursorY - self::LANE_GAP_Y + self::MARGIN_BOTTOM),
            'lanes' => $lanes,
            'cards' => $cards,
            'port' => $port,
        ];
    }

    /**
     * Agrupa por `grupo_macro` na ordem oficial das macrofases e, dentro de
     * cada uma, por `ordem_fluxo` (a ordem que o usuario controla na tela
     * "Status de OS"). Status inativo nunca entra no mapa.
     *
     * @param  array<int, array<string, mixed>>  $statuses
     * @return array<string, list<array<string, mixed>>>
     */
    private static function groupStatuses(array $statuses): array
    {
        $byGroup = [];

        foreach ($statuses as $status) {
            $codigo = trim((string) ($status['codigo'] ?? ''));
            if ($codigo === '') {
                continue;
            }

            // `ativo` pode nao vir no payload; ausencia significa ativo, ja que
            // `status_disponiveis` so lista o catalogo ativo.
            if (array_key_exists('ativo', $status) && ! (bool) $status['ativo']) {
                continue;
            }

            $grupo = mb_strtolower(trim((string) ($status['grupo_macro'] ?? '')));
            $byGroup[$grupo][] = $status;
        }

        foreach ($byGroup as $grupo => $groupStatuses) {
            usort($groupStatuses, static fn (array $a, array $b): int => [
                (int) ($a['ordem_fluxo'] ?? 0), mb_strtolower(trim((string) ($a['nome'] ?? ''))),
            ] <=> [
                (int) ($b['ordem_fluxo'] ?? 0), mb_strtolower(trim((string) ($b['nome'] ?? ''))),
            ]);

            $byGroup[$grupo] = $groupStatuses;
        }

        $ordered = [];
        foreach (OrderStatusMacroGroups::sortGroups(array_keys($byGroup)) as $grupo) {
            $ordered[$grupo] = $byGroup[$grupo];
        }

        return $ordered;
    }

    private static function bandOf(string $grupo): int
    {
        if ($grupo === OrderStatusMacroGroups::CLOSURE_GROUP) {
            return self::BAND_OUTCOME;
        }

        return in_array($grupo, OrderStatusMacroGroups::exitOrder(), true)
            ? self::BAND_OUTCOME
            : self::BAND_FLOW;
    }

    /**
     * Distribui as raias de uma faixa em linhas que cabem em TARGET_W.
     * A altura de cada raia sai da contagem de cards, entao uma macrofase nova
     * (ou com muitos status) se acomoda sozinha, sem coordenada escrita a mao.
     *
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: float, 3: float}
     */
    private static function layoutBand(array $groups, float $top, int $band, bool $alignRight = false): array
    {
        $laneW = self::laneWidth();
        $lanesPerRow = self::lanesPerRow();

        // Alinhamento a direita: desloca a faixa para terminar na mesma coluna
        // em que a faixa do fluxo termina.
        $offset = 0.0;
        if ($alignRight) {
            $inRow = min(count($groups), $lanesPerRow);
            $offset = ($lanesPerRow - $inRow) * ($laneW + self::LANE_GAP_X);
        }

        $lanes = [];
        $cards = [];

        $x = self::MARGIN_X + $offset;
        $rowTop = $top;
        $rowBottom = $top;
        $column = 0;
        $maxRight = 0;

        foreach ($groups as $grupo => $groupStatuses) {
            if ($column >= $lanesPerRow) {
                $column = 0;
                $x = self::MARGIN_X + $offset;
                $rowTop = $rowBottom + self::LANE_GAP_Y;
            }

            $laneH = self::LANE_HEADER_H
                + count($groupStatuses) * self::CARD_H
                + max(0, count($groupStatuses) - 1) * self::CARD_GAP
                + self::LANE_PAD_BOTTOM;

            $accent = OrderStatusMacroGroups::flowAccent($grupo);

            $lanes[] = [
                'grupo' => $grupo,
                'titulo' => OrderStatusMacroGroups::label($grupo),
                'x' => (int) $x,
                'y' => (int) $rowTop,
                'w' => (int) $laneW,
                'h' => (int) $laneH,
                'color' => $accent['color'],
                'text' => $accent['text'],
                'band' => $band,
            ];

            $cardY = $rowTop + self::LANE_HEADER_H;

            foreach ($groupStatuses as $status) {
                $codigo = trim((string) ($status['codigo'] ?? ''));
                $nome = trim((string) ($status['nome'] ?? '')) !== '' ? trim((string) $status['nome']) : $codigo;
                $lines = self::wrapLabel($nome);

                $cards[$codigo] = [
                    'codigo' => $codigo,
                    'nome_completo' => $nome,
                    'grupo' => $grupo,
                    'x' => (int) ($x + self::LANE_PAD_X),
                    'y' => (int) $cardY,
                    'w' => (int) self::CARD_W,
                    'h' => (int) self::CARD_H,
                    'lines' => $lines,
                    'kind' => $grupo === OrderStatusMacroGroups::CLOSURE_GROUP ? 'closure' : 'step',
                    'color' => $accent['color'],
                    'text' => $accent['text'],
                    'pausa' => (bool) ($status['status_pausa'] ?? false),
                ];

                $cardY += self::CARD_H + self::CARD_GAP;
            }

            $maxRight = max($maxRight, $x + $laneW);
            $rowBottom = max($rowBottom, $rowTop + $laneH);

            $x += $laneW + self::LANE_GAP_X;
            $column++;
        }

        return [$lanes, $cards, $rowBottom, $maxRight];
    }

    /**
     * Quebra o nome do status em linhas que cabem no card. Substitui o
     * `lines: [...]` que era escrito a mao para cada status no gerador Python
     * — por isso um status novo (ou renomeado) agora se acomoda sozinho.
     *
     * @return list<string>
     */
    public static function wrapLabel(string $label): array
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');

        if ($label === '') {
            return ['—'];
        }

        $lines = [];
        $current = '';

        foreach (explode(' ', $label) as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if (mb_strlen($candidate) <= self::LABEL_WRAP || $current === '') {
                $current = $candidate;

                continue;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        // Cards tem altura fixa: acima de 3 linhas o texto vazaria, entao o
        // resto vira reticencias (o nome completo fica no <title> do card).
        if (count($lines) > 3) {
            $lines = array_slice($lines, 0, 3);
            $lines[2] = mb_substr($lines[2], 0, self::LABEL_WRAP - 1).'…';
        }

        return $lines;
    }

    /** Altura de uma linha de texto do card — usada pelo template do SVG. */
    public static function lineHeight(): int
    {
        return self::CARD_LINE_H;
    }
}
