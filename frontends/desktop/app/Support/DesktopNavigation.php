<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

class DesktopNavigation
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function sections(): array
    {
        $sections = [];

        foreach (self::definition() as $section) {
            $items = array_values(array_filter(
                array_map(
                    static fn (array $item): ?array => self::filterItem($item),
                    array_filter(
                        $section['items'],
                        static fn (array $item): bool => empty($item['hidden'])
                    )
                )
            ));

            if ($items !== []) {
                $sections[] = [
                    'label' => $section['label'],
                    'items' => $items,
                ];
            }
        }

        return $sections;
    }

    public static function firstAllowedRouteName(): string
    {
        foreach (self::definition() as $section) {
            foreach ($section['items'] as $item) {
                $filtered = self::filterItem($item);
                if ($filtered === null) {
                    continue;
                }

                if (isset($filtered['route'])) {
                    return (string) $filtered['route'];
                }

                foreach ($filtered['children'] ?? [] as $child) {
                    if (isset($child['route'])) {
                        return (string) $child['route'];
                    }
                }
            }
        }

        return self::routeExists('profile.show') ? 'profile.show' : 'dashboard';
    }

    /**
     * Todas as paginas favoritaveis, achatadas (sem secoes) e ja filtradas pela
     * permissao do usuario, indexadas pelo nome da rota.
     *
     * Diferente de sections(), inclui os itens marcados como `hidden`: eles
     * ficam fora da sidebar por decisao de navegacao (o PDV e' alcancado pelo
     * botao "Nova venda", devolucoes pelo "Mais acoes" da listagem de vendas),
     * mas continuam sendo paginas legitimas que o usuario pode querer fixar nos
     * favoritos.
     *
     * @return array<string, array<string, string>>
     */
    public static function favoritableItems(): array
    {
        $items = [];

        foreach (self::definition() as $section) {
            foreach ($section['items'] as $item) {
                self::collectFavoritable($item, (string) $section['label'], $items);
            }
        }

        return $items;
    }

    /**
     * @return array<string, string>|null
     */
    public static function findFavoritable(mixed $routeName): ?array
    {
        if (! is_string($routeName) || $routeName === '') {
            return null;
        }

        return self::favoritableItems()[$routeName] ?? null;
    }

    /**
     * Nome da rota da requisicao atual, quando ela e' uma pagina favoritavel.
     * Usado pela navbar para decidir se mostra a estrela e em que estado.
     */
    public static function currentFavoritableRoute(): ?string
    {
        $current = Route::currentRouteName();

        return self::findFavoritable($current) !== null ? (string) $current : null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, string>>  $items
     */
    private static function collectFavoritable(array $item, string $sectionLabel, array &$items): void
    {
        if (isset($item['children']) && is_array($item['children'])) {
            foreach ($item['children'] as $child) {
                self::collectFavoritable($child, $sectionLabel, $items);
            }

            return;
        }

        // filterItem() e' a unica fonte de verdade sobre RBAC + rota registrada.
        // Reusa-la aqui garante que favoritos e sidebar nunca divirjam sobre o
        // que este usuario pode enxergar.
        $allowed = self::filterItem($item);

        if ($allowed === null) {
            return;
        }

        $items[(string) $allowed['route']] = [
            'label' => (string) ($allowed['label'] ?? ''),
            'route' => (string) $allowed['route'],
            'icon' => (string) ($allowed['icon'] ?? 'bi-dot'),
            'section' => $sectionLabel,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function definition(): array
    {
        return [
            [
                'label' => 'Visão Geral',
                'items' => [
                    [
                        'label' => 'Dashboard',
                        'route' => 'dashboard',
                        'module' => 'dashboard',
                        'icon' => 'bi-grid-1x2-fill',
                    ],
                    // Agenda logo abaixo do Dashboard: as duas telas de "o que
                    // preciso saber agora" ficam juntas, e a agenda e a segunda
                    // parada da rotina do dia.
                    [
                        'label' => 'Agenda',
                        'route' => 'agenda.index',
                        'module' => 'agenda',
                        'icon' => 'bi-calendar-week',
                    ],
                ],
            ],
            [
                'label' => 'Atendimento',
                'items' => [
                    [
                        'label' => 'Ordens de Serviço',
                        'route' => 'orders.index',
                        'module' => 'os',
                        'icon' => 'bi-clipboard-check-fill',
                    ],
                    [
                        'label' => 'Orçamentos',
                        'route' => 'orcamentos.index',
                        'module' => 'orcamentos',
                        'icon' => 'bi-receipt',
                    ],
                    // Vendas de balcão — specs/027-vendas-balcao-pdv. "Nova
                    // venda (PDV)" e "Devoluções" não têm entrada visível no
                    // menu: o PDV é acessado pelo botão "Nova venda" desta
                    // listagem, e devoluções pelo "Mais ações" dela.
                    // `hidden` mantém as duas fora da sidebar sem tirá-las do
                    // cálculo de firstAllowedRouteName() — sem isso, um
                    // usuário só com permissão de uma delas (ex.: só
                    // "vendas:criar") ficaria sem destino de fallback ao
                    // esbarrar num redirecionamento de permissão.
                    [
                        'label' => 'Vendas',
                        'route' => 'vendas.index',
                        'module' => 'vendas',
                        'icon' => 'bi-cart-check',
                    ],
                    [
                        'label' => 'Nova venda (PDV)',
                        'route' => 'vendas.create',
                        'module' => 'vendas',
                        'action' => 'criar',
                        'icon' => 'bi-upc-scan',
                        'hidden' => true,
                    ],
                    [
                        'label' => 'Devoluções',
                        'route' => 'devolucoes.index',
                        'module' => 'vendas',
                        'icon' => 'bi-arrow-return-left',
                        'hidden' => true,
                    ],
                    // Turno de caixa — specs/028-caixa-sessoes. Acessado pelo
                    // botão "Caixa" em Financeiro > Contas e Saldos.
                    [
                        'label' => 'Caixa',
                        'route' => 'caixa.index',
                        'module' => 'caixa',
                        'icon' => 'bi-cash-stack',
                        'hidden' => true,
                    ],
                ],
            ],
            [
                'label' => 'Cadastros',
                'items' => [
                    [
                        'label' => 'Clientes',
                        'route' => 'clients.index',
                        'module' => 'clientes',
                        'icon' => 'bi-people',
                    ],
                    [
                        'label' => 'Fornecedores',
                        'route' => 'suppliers.index',
                        'module' => 'fornecedores',
                        'icon' => 'bi-truck',
                    ],
                    // Aparelhos/equipamentos não tem entrada visível: o cadastro é
                    // estritamente derivado do cliente, então a listagem é acessada
                    // pelo "Mais ações" de Clientes (e o caminho inverso, pelo "Mais
                    // ações" de Equipamentos). `hidden` mantém o item fora da sidebar
                    // sem tirá-lo do cálculo de firstAllowedRouteName() — sem isso, um
                    // usuário só com "equipamentos:visualizar" ficaria sem destino de
                    // fallback ao esbarrar num redirecionamento de permissão.
                    [
                        'label' => 'Aparelhos / Equip.',
                        'route' => 'equipments.index',
                        'module' => 'equipamentos',
                        'icon' => 'bi-laptop',
                        'hidden' => true,
                    ],
                    [
                        'label' => 'Serviços',
                        'route' => 'servicos.index',
                        'module' => 'servicos',
                        'icon' => 'bi-gear-fill',
                    ],
                    [
                        'label' => 'Estoque',
                        'route' => 'estoque.index',
                        'module' => 'estoque',
                        'icon' => 'bi-box-seam',
                    ],
                ],
            ],
            // Fiscal entre Cadastros e Financeiro. NAO pode vir antes de
            // Cadastros: `firstAllowedRouteName()` percorre esta lista em
            // ordem, e "Prontidão fiscal" (modulo `clientes`) roubaria de
            // `clients.index` o papel de destino de fallback — um usuario so'
            // com `clientes` cairia num relatorio ao esbarrar num
            // redirecionamento de permissao. specs/041-emissao-fiscal-nfse.
            [
                'label' => 'Fiscal',
                'items' => [
                    [
                        'label' => 'Notas pendentes',
                        'route' => 'fiscal.pendentes',
                        'module' => 'os',
                        'icon' => 'bi-receipt',
                    ],
                    [
                        'label' => 'Notas emitidas',
                        'route' => 'fiscal.emitidas',
                        'module' => 'os',
                        'icon' => 'bi-receipt-cutoff',
                    ],
                    [
                        'label' => 'Prontidão fiscal',
                        'route' => 'fiscal.prontidao',
                        'module' => 'clientes',
                        'icon' => 'bi-clipboard-check',
                    ],
                    // POR ULTIMO na secao, e nao por ordem de importancia:
                    // `firstAllowedRouteName()` percorre esta lista em ordem e
                    // usa o primeiro item permitido como destino de fallback
                    // quando um redirecionamento de permissao precisa de
                    // algum lugar para ir. Um relatorio mensal e' pessimo
                    // destino de fallback — mesma armadilha que o comentario
                    // acima documenta para "Prontidão fiscal".
                    [
                        'label' => 'Relatório Mensal das Receitas',
                        'route' => 'fiscal.anexo-x',
                        'module' => 'fiscal',
                        'icon' => 'bi-journal-text',
                    ],
                ],
            ],
            [
                'label' => 'Financeiro',
                'items' => [
                    [
                        'label' => 'Finanças',
                        'icon' => 'bi-cash-stack',
                        'children' => [
                            [
                                'label' => 'Lançamentos',
                                'route' => 'financeiro.index',
                                'module' => 'financeiro',
                                'icon' => 'bi-cash-coin',
                            ],
                            [
                                'label' => 'Despesas',
                                'route' => 'financeiro.despesas-fixas.index',
                                'module' => 'financeiro',
                                'icon' => 'bi-pin-angle',
                            ],
                            [
                                'label' => 'Contas e Saldos',
                                'route' => 'financeiro.contas.index',
                                'module' => 'contas_saldos',
                                'icon' => 'bi-wallet2',
                            ],
                        ],
                    ],
                    [
                        'label' => 'Relatórios',
                        'icon' => 'bi-file-earmark-bar-graph',
                        'children' => [
                            [
                                'label' => 'Fluxo de Caixa',
                                'route' => 'financeiro.relatorios.fluxo-caixa',
                                'module' => 'financeiro',
                                'icon' => 'bi-calendar3-week',
                            ],
                            [
                                'label' => 'DRE por Competência',
                                'route' => 'financeiro.relatorios.dre',
                                'module' => 'financeiro',
                                'icon' => 'bi-graph-up-arrow',
                            ],
                            [
                                'label' => 'DRE de Caixa',
                                'route' => 'financeiro.relatorios.dre-caixa',
                                'module' => 'financeiro',
                                'icon' => 'bi-wallet2',
                            ],
                            [
                                'label' => 'Margem por OS',
                                'route' => 'financeiro.relatorios.margem',
                                'module' => 'financeiro',
                                'icon' => 'bi-graph-up',
                            ],
                        ],
                    ],
                    // Grupo "Ferramentas" não tem entrada visível: as três telas são
                    // exatamente as mesmas do dropdown "Mais ações" de Financeiro >
                    // Lançamentos, então a sidebar só duplicava o atalho. `hidden`
                    // mantém o grupo fora do menu preservando os filhos no cálculo de
                    // firstAllowedRouteName() (ex.: usuário só com "precificacao").
                    [
                        'label' => 'Ferramentas',
                        'icon' => 'bi-sliders2',
                        'hidden' => true,
                        'children' => [
                            [
                                'label' => 'Cartões e Taxas',
                                'route' => 'financeiro.cartoes.index',
                                'module' => 'financeiro',
                                'icon' => 'bi-credit-card-2-front',
                            ],
                            [
                                'label' => 'Configurações Financeiras',
                                'route' => 'financeiro.configuracoes',
                                'module' => 'financeiro',
                                'icon' => 'bi-bar-chart-line',
                            ],
                            [
                                'label' => 'Precificação',
                                'route' => 'financeiro.precificacao.index',
                                'module' => 'precificacao',
                                'icon' => 'bi-calculator',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'label' => 'Conhecimento',
                'items' => [
                    [
                        'label' => 'Base de Defeitos',
                        'route' => 'knowledge.defects.index',
                        'module' => 'conhecimento',
                        'icon' => 'bi-bug-fill',
                    ],
                    [
                        'label' => 'Defeitos Relatados',
                        'route' => 'knowledge.reported-defects.index',
                        'module' => 'conhecimento',
                        'icon' => 'bi-chat-square-text-fill',
                    ],
                ],
            ],
            [
                'label' => 'Processos e Modelos',
                'items' => [
                    [
                        'label' => 'Modelo da Assistência Técnica',
                        'route' => 'knowledge.assistance-model.index',
                        'module' => 'conhecimento',
                        'icon' => 'bi-diagram-2-fill',
                    ],
                    [
                        'label' => 'Checklists',
                        'icon' => 'bi-check2-square',
                        'children' => [
                            [
                                'label' => 'Checklist de Entrada',
                                'route' => 'knowledge.checklists.entrada',
                                'module' => 'conhecimento',
                                'icon' => 'bi-box-arrow-in-down',
                            ],
                            [
                                'label' => 'Checklist de Manutenção',
                                'route' => 'knowledge.checklists.manutencao',
                                'module' => 'conhecimento',
                                'icon' => 'bi-tools',
                            ],
                            [
                                'label' => 'Checklist Controle de Qualidade',
                                'route' => 'knowledge.checklists.controle-qualidade',
                                'module' => 'conhecimento',
                                'icon' => 'bi-patch-check-fill',
                            ],
                            [
                                'label' => 'Checklist de Saída',
                                'route' => 'knowledge.checklists.saida',
                                'module' => 'conhecimento',
                                'icon' => 'bi-box-arrow-up',
                            ],
                        ],
                    ],
                    [
                        'label' => 'Modelos PDF',
                        'route' => 'knowledge.pdf-engine.index',
                        'module' => 'conhecimento',
                        'icon' => 'bi-file-earmark-pdf-fill',
                    ],
                    [
                        'label' => 'Templates WhatsApp',
                        'route' => 'knowledge.whatsapp-templates.index',
                        'module' => 'conhecimento',
                        'icon' => 'bi-whatsapp',
                    ],
                ],
            ],
            [
                'label' => 'Administração',
                'items' => [
                    [
                        'label' => 'Equipe da Assistência',
                        'route' => 'technicians.index',
                        'module' => 'funcionarios',
                        'icon' => 'bi-person-badge',
                    ],
                    [
                        'label' => 'Gerenciador de Arquivos',
                        'route' => 'files.index',
                        'module' => 'arquivos',
                        'action' => 'listar',
                        'icon' => 'bi-folder2-open',
                    ],
                    [
                        'label' => 'Configurações do Sistema',
                        'route' => 'configurations.system.index',
                        'module' => 'configuracoes',
                        'icon' => 'bi-sliders',
                    ],
                    // Cadastro dos status de OS: saiu de "Processos e Modelos" em
                    // 23/08/2026 porque não é material de leitura — edita o catálogo
                    // que o módulo de OS usa em produção. Continua no módulo RBAC
                    // 'conhecimento' de propósito: trocar para 'configuracoes' tiraria
                    // o acesso de quem usa a tela hoje.
                    [
                        'label' => 'Status de OS',
                        'route' => 'knowledge.os-flow.index',
                        'module' => 'conhecimento',
                        'icon' => 'bi-list-check',
                    ],
                    [
                        'label' => 'Acesso e Integrações',
                        'icon' => 'bi-shield-lock',
                        'children' => [
                            [
                                'label' => 'Usuários',
                                'route' => 'users.index',
                                'module' => 'usuarios',
                                'icon' => 'bi-person-lines-fill',
                            ],
                            [
                                'label' => 'Grupos e Permissões',
                                'route' => 'groups.index',
                                'module' => 'grupos',
                                'icon' => 'bi-diagram-3',
                            ],
                            [
                                'label' => 'Integrações',
                                'route' => 'configurations.integrations.index',
                                'module' => 'configuracoes',
                                'icon' => 'bi-plug',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private static function filterItem(array $item): ?array
    {
        if (isset($item['children']) && is_array($item['children'])) {
            $children = array_values(array_filter(
                array_map(
                    static fn (array $child): ?array => self::filterItem($child),
                    $item['children']
                )
            ));

            if ($children === []) {
                return null;
            }

            $item['children'] = $children;

            return $item;
        }

        if (! isset($item['module']) || ! is_string($item['module'])) {
            return null;
        }

        if (! self::routeExists($item['route'] ?? null)) {
            return null;
        }

        $action = isset($item['action']) && is_string($item['action']) ? $item['action'] : 'visualizar';

        return DesktopSession::can($item['module'], $action) ? $item : null;
    }

    private static function routeExists(mixed $routeName): bool
    {
        return is_string($routeName) && $routeName !== '' && Route::has($routeName);
    }
}
