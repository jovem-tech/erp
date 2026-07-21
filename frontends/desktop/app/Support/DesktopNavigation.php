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
                    $section['items']
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
                    [
                        'label' => 'Aparelhos / Equip.',
                        'route' => 'equipments.index',
                        'module' => 'equipamentos',
                        'icon' => 'bi-laptop',
                    ],
                    [
                        'label' => 'Serviços',
                        'route' => 'servicos.index',
                        'module' => 'servicos',
                        'icon' => 'bi-gear-fill',
                    ],
                    [
                        'label' => 'Estoque de Peças',
                        'route' => 'estoque.index',
                        'module' => 'estoque',
                        'icon' => 'bi-box-seam',
                    ],
                ],
            ],
            [
                'label' => 'Financeiro',
                'items' => [
                    [
                        'label' => 'Lançamentos',
                        'route' => 'financeiro.index',
                        'module' => 'financeiro',
                        'icon' => 'bi-cash-coin',
                    ],
                    [
                        'label' => 'Contas e Saldos',
                        'route' => 'financeiro.contas.index',
                        'module' => 'contas_saldos',
                        'icon' => 'bi-wallet2',
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
                    [
                        'label' => 'Ferramentas',
                        'icon' => 'bi-sliders2',
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
                        'label' => 'Fluxo de Trabalho OS',
                        'route' => 'knowledge.os-flow.index',
                        'module' => 'conhecimento',
                        'icon' => 'bi-diagram-3-fill',
                    ],
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
