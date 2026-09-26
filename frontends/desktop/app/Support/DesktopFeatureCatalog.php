<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Catalogo de "lugares e acoes" do sistema para a busca global: menus, telas,
 * abas, listagens, botoes e atalhos. O usuario digita "simulador de preços"
 * ou "fluxo de caixa" e recebe o link direto, sem ter que lembrar o menu.
 *
 * Duas fontes, mescladas em all():
 *  - automatica: todas as folhas de DesktopNavigation::definition() (inclusive
 *    as `hidden`), enriquecidas com sinonimos de $menuKeywords;
 *  - curada: definition() abaixo, com tudo que NAO esta no menu (abas de uma
 *    tela, ferramentas alcancadas por botao, acoes dentro de um registro,
 *    exportacoes, paginas de ajuda, perfil...).
 *
 * Tudo sai ja filtrado por RBAC (DesktopSession::can) e por rota registrada,
 * pela mesma regra de DesktopNavigation::filterItem(): quem nao pode ver a
 * tela nao a encontra na busca.
 */
class DesktopFeatureCatalog
{
    /**
     * @return array<int, array{key:string,label:string,route:string,params:array<string, mixed>,icon:string,path:array<int, string>,keywords:array<int, string>,hint:string,shortcut:string}>
     */
    public static function all(): array
    {
        $items = [];
        $seen = [];

        foreach (DesktopNavigation::flattenedWithPath() as $entry) {
            $route = $entry['route'];
            $key = 'menu.' . $route;
            $seen[$key] = true;

            $items[] = [
                'key' => $key,
                'label' => $entry['label'],
                'route' => $route,
                'params' => [],
                'icon' => $entry['icon'],
                'path' => $entry['path'],
                'keywords' => self::menuKeywords()[$route] ?? [],
                'hint' => '',
                'shortcut' => '',
            ];
        }

        foreach (self::definition() as $entry) {
            if (isset($seen[$entry['key']])) {
                continue;
            }

            if (! self::allowed($entry)) {
                continue;
            }

            $seen[$entry['key']] = true;

            $items[] = [
                'key' => $entry['key'],
                'label' => $entry['label'],
                'route' => $entry['route'],
                'params' => $entry['params'] ?? [],
                'icon' => $entry['icon'] ?? 'bi-dot',
                'path' => $entry['path'] ?? [],
                'keywords' => $entry['keywords'] ?? [],
                'hint' => $entry['hint'] ?? '',
                'shortcut' => $entry['shortcut'] ?? '',
            ];
        }

        return $items;
    }

    /**
     * Mesma regra de DesktopNavigation::filterItem(): rota registrada e
     * permissao no snapshot da sessao. `module` nulo = tela de todo usuario
     * logado (perfil, notificacoes). `action` aceita alternativas com "|",
     * como o middleware desktop.permission.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function allowed(array $entry): bool
    {
        $route = $entry['route'] ?? null;

        if (! is_string($route) || $route === '' || ! Route::has($route)) {
            return false;
        }

        $module = $entry['module'] ?? null;

        if ($module === null) {
            return true;
        }

        if (! is_string($module) || $module === '') {
            return false;
        }

        $actions = explode('|', (string) ($entry['action'] ?? 'visualizar'));

        foreach ($actions as $action) {
            if (DesktopSession::can($module, trim($action))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sinonimos do dia a dia para os itens que ja estao no menu, indexados
     * pelo nome da rota. O label do menu sozinho nao cobre como as pessoas
     * chamam a tela ("contas a pagar" -> Lançamentos, "técnicos" -> Equipe).
     *
     * @return array<string, array<int, string>>
     */
    private static function menuKeywords(): array
    {
        return [
            'dashboard' => ['início', 'painel', 'home', 'resumo', 'visão geral', 'indicadores'],
            'agenda.index' => ['compromissos', 'calendário', 'tarefas', 'lembretes', 'google agenda'],
            'orders.index' => ['os', 'ordens', 'ordem de serviço', 'listagem de os', 'lista de os', 'consertos', 'reparos', 'atendimentos', 'serviços em andamento'],
            'orcamentos.index' => ['orçamento', 'listagem de orçamentos', 'propostas', 'cotação'],
            'vendas.index' => ['venda', 'vendas de balcão', 'listagem de vendas', 'histórico de vendas'],
            'vendas.create' => ['pdv', 'ponto de venda', 'frente de caixa', 'vender', 'venda rápida', 'balcão'],
            'devolucoes.index' => ['devolução', 'devolver venda', 'estorno', 'troca'],
            'caixa.index' => ['abrir caixa', 'fechar caixa', 'turno', 'sangria', 'suprimento', 'caixa do dia'],
            'clients.index' => ['cliente', 'cadastro de clientes', 'listagem de clientes', 'pessoas'],
            'suppliers.index' => ['fornecedor', 'cadastro de fornecedores', 'compras', 'cnpj'],
            'equipments.catalog.index' => ['catálogo', 'tipos de equipamento', 'marcas', 'modelos', 'cadastro de marcas'],
            'equipments.index' => ['aparelhos', 'equipamentos dos clientes', 'aparelhos dos clientes', 'dispositivos', 'celulares', 'notebooks'],
            'servicos.index' => ['serviço', 'cadastro de serviços', 'tabela de serviços', 'mão de obra', 'tabela de preços de serviços'],
            'estoque.index' => ['peças', 'peça', 'produtos', 'cadastro de peças', 'listagem de peças', 'inventário', 'saldo', 'almoxarifado'],
            'fiscal.pendentes' => ['nota fiscal', 'nfse', 'nfs-e', 'notas a emitir', 'emitir nota'],
            'fiscal.emitidas' => ['nota fiscal', 'nfse', 'notas fiscais emitidas', 'danfse', 'xml'],
            'fiscal.prontidao' => ['clientes sem cpf', 'dados fiscais', 'cadastro incompleto'],
            'fiscal.anexo-x' => ['anexo x', 'receitas do mês', 'faturamento mensal', 'contador', 'simples nacional', 'das'],
            'financeiro.index' => ['financeiro', 'lançamento', 'contas a pagar', 'contas a receber', 'receitas', 'despesas', 'pagamentos', 'recebimentos', 'movimentações financeiras'],
            'financeiro.despesas-fixas.index' => ['despesa fixa', 'despesas recorrentes', 'contas mensais', 'aluguel', 'custos fixos'],
            'financeiro.contas.index' => ['contas bancárias', 'saldos', 'banco', 'carteira', 'saldo em conta', 'transferência entre contas'],
            'financeiro.relatorios.fluxo-caixa' => ['fluxo', 'fluxo de caixa', 'entradas e saídas', 'previsão de caixa', 'projeção', 'relatório financeiro'],
            'financeiro.relatorios.dre' => ['dre', 'demonstrativo de resultado', 'lucro', 'resultado do mês', 'competência'],
            'financeiro.relatorios.dre-caixa' => ['dre', 'demonstrativo de resultado', 'regime de caixa', 'lucro realizado'],
            'financeiro.relatorios.margem' => ['margem', 'lucro por os', 'rentabilidade', 'lucratividade'],
            'financeiro.cartoes.index' => ['cartões', 'taxas de cartão', 'maquininha', 'operadoras', 'bandeiras', 'parcelamento', 'gateway de pagamento'],
            'financeiro.configuracoes' => ['categorias financeiras', 'grupos financeiros', 'formas de pagamento', 'comissões', 'comissionamento', 'chave pix'],
            'financeiro.precificacao.index' => ['precificação', 'preço', 'formar preço', 'markup', 'margem de lucro', 'calcular preço', 'simulador'],
            'knowledge.defects.index' => ['defeitos', 'base de conhecimento', 'procedimentos', 'soluções', 'diagnóstico'],
            'knowledge.reported-defects.index' => ['defeito relatado', 'reclamação do cliente', 'sintomas', 'problema relatado'],
            'knowledge.assistance-model.index' => ['modelo da assistência', 'processo', 'como funciona', 'manual da assistência'],
            'knowledge.checklists.entrada' => ['checklist', 'entrada do aparelho', 'recebimento', 'vistoria de entrada'],
            'knowledge.checklists.manutencao' => ['checklist', 'manutenção', 'reparo', 'procedimento técnico'],
            'knowledge.checklists.controle-qualidade' => ['checklist', 'qualidade', 'qa', 'teste final', 'controle de qualidade'],
            'knowledge.checklists.saida' => ['checklist', 'saída do aparelho', 'entrega', 'vistoria de saída'],
            'knowledge.pdf-engine.index' => ['pdf', 'modelos de impressão', 'layout de impressão', 'documentos', 'modelo de os', 'modelo de orçamento'],
            'knowledge.whatsapp-templates.index' => ['whatsapp', 'mensagens prontas', 'templates', 'modelos de mensagem', 'texto automático'],
            'technicians.index' => ['equipe', 'técnicos', 'funcionários', 'colaboradores', 'atendentes', 'cadastro de técnicos'],
            'files.index' => ['arquivos', 'gerenciador', 'anexos', 'fotos', 'documentos', 'quarentena', 'lixeira'],
            'configurations.system.index' => ['configurações', 'configuração do sistema', 'aparência', 'tema', 'logo', 'dados da empresa', 'segurança', 'sessão', 'preferências'],
            'knowledge.os-flow.index' => ['status', 'status de os', 'fluxo da os', 'etapas', 'situação da os', 'cores dos status'],
            'users.index' => ['usuários', 'usuário', 'logins', 'acessos', 'senha de usuário', 'cadastro de usuários'],
            'groups.index' => ['grupos', 'permissões', 'perfis de acesso', 'papéis', 'rbac', 'quem pode ver'],
            'configurations.integrations.index' => ['integrações', 'whatsapp', 'api', 'gateway', 'e-mail', 'smtp', 'pagamentos', 'google', 'certificado digital', 'nota fiscal'],
        ];
    }

    /**
     * Tudo que nao esta no menu. `path` e' o caminho humano ate a tela (vira o
     * subtitulo do resultado); `hint` explica como chegar quando a acao vive
     * dentro de um registro e o link so' consegue levar ate a listagem.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function definition(): array
    {
        return [
            // ---- "+ Novo" e atalhos de teclado (navbar) ----
            [
                'key' => 'novo.os',
                'label' => 'Nova OS',
                'route' => 'orders.create',
                'module' => 'os', 'action' => 'criar',
                'icon' => 'bi-clipboard-plus',
                'path' => ['+ Novo', 'Nova OS'],
                'keywords' => ['criar os', 'abrir os', 'nova ordem de serviço', 'cadastrar os', 'registrar atendimento', 'entrada de aparelho'],
                'shortcut' => 'F1',
            ],
            [
                'key' => 'novo.orcamento',
                'label' => 'Novo orçamento',
                'route' => 'orcamentos.create',
                'module' => 'orcamentos', 'action' => 'criar',
                'icon' => 'bi-receipt',
                'path' => ['+ Novo', 'Novo orçamento'],
                'keywords' => ['criar orçamento', 'fazer orçamento', 'orçar', 'proposta', 'cotação'],
                'shortcut' => 'F2',
            ],
            [
                'key' => 'novo.venda',
                'label' => 'Nova venda',
                'route' => 'vendas.create',
                'module' => 'vendas', 'action' => 'criar',
                'icon' => 'bi-upc-scan',
                'path' => ['+ Novo', 'Nova venda'],
                'keywords' => ['pdv', 'vender', 'venda de balcão', 'frente de caixa', 'registrar venda'],
                'shortcut' => 'F3',
            ],
            [
                'key' => 'novo.lancamento',
                'label' => 'Novo lançamento',
                'route' => 'financeiro.create',
                'module' => 'financeiro', 'action' => 'criar',
                'icon' => 'bi-cash-coin',
                'path' => ['+ Novo', 'Novo lançamento'],
                'keywords' => ['lançar despesa', 'lançar receita', 'registrar pagamento', 'registrar recebimento', 'conta a pagar', 'conta a receber'],
                'shortcut' => 'F4',
            ],
            [
                'key' => 'novo.cliente',
                'label' => 'Novo cliente',
                'route' => 'clients.create',
                'module' => 'clientes', 'action' => 'criar',
                'icon' => 'bi-person-plus',
                'path' => ['Cadastros', 'Clientes', 'Novo cliente'],
                'keywords' => ['cadastrar cliente', 'criar cliente', 'adicionar cliente'],
            ],
            [
                'key' => 'novo.fornecedor',
                'label' => 'Novo fornecedor',
                'route' => 'suppliers.create',
                'module' => 'fornecedores', 'action' => 'criar',
                'icon' => 'bi-truck',
                'path' => ['Cadastros', 'Fornecedores', 'Novo fornecedor'],
                'keywords' => ['cadastrar fornecedor', 'criar fornecedor'],
            ],
            [
                'key' => 'novo.servico',
                'label' => 'Novo serviço',
                'route' => 'servicos.create',
                'module' => 'servicos', 'action' => 'criar',
                'icon' => 'bi-gear',
                'path' => ['Cadastros', 'Serviços', 'Novo serviço'],
                'keywords' => ['cadastrar serviço', 'criar serviço', 'mão de obra'],
            ],
            [
                'key' => 'novo.peca',
                'label' => 'Nova peça',
                'route' => 'estoque.create',
                'module' => 'estoque', 'action' => 'criar',
                'icon' => 'bi-box-seam',
                'path' => ['Cadastros', 'Estoque', 'Nova peça'],
                'keywords' => ['cadastrar peça', 'criar peça', 'novo produto', 'entrada de peça'],
            ],
            [
                'key' => 'novo.aparelho',
                'label' => 'Novo aparelho',
                'route' => 'equipments.create',
                'module' => 'equipamentos', 'action' => 'criar',
                'icon' => 'bi-laptop',
                'path' => ['Cadastros', 'Aparelhos / Equip.', 'Novo aparelho'],
                'keywords' => ['cadastrar aparelho', 'cadastrar equipamento', 'novo equipamento'],
            ],
            [
                'key' => 'novo.defeito',
                'label' => 'Novo defeito na base',
                'route' => 'knowledge.defects.create',
                'module' => 'conhecimento', 'action' => 'criar',
                'icon' => 'bi-bug',
                'path' => ['Conhecimento', 'Base de Defeitos', 'Novo defeito'],
                'keywords' => ['cadastrar defeito', 'novo procedimento', 'nova solução'],
            ],
            [
                'key' => 'novo.defeito-relatado',
                'label' => 'Novo defeito relatado',
                'route' => 'knowledge.reported-defects.create',
                'module' => 'conhecimento', 'action' => 'criar',
                'icon' => 'bi-chat-square-text',
                'path' => ['Conhecimento', 'Defeitos Relatados', 'Novo'],
                'keywords' => ['cadastrar defeito relatado', 'sintoma'],
            ],
            [
                'key' => 'novo.template-whatsapp',
                'label' => 'Novo template de WhatsApp',
                'route' => 'knowledge.whatsapp-templates.create',
                'module' => 'conhecimento', 'action' => 'criar',
                'icon' => 'bi-whatsapp',
                'path' => ['Processos e Modelos', 'Templates WhatsApp', 'Novo'],
                'keywords' => ['criar mensagem', 'nova mensagem pronta', 'modelo de mensagem'],
            ],

            // ---- Abas e ferramentas fora do menu ----
            [
                'key' => 'financeiro.precificacao.simulador',
                'label' => 'Simulador de preços',
                'route' => 'financeiro.precificacao.index',
                'params' => ['tab' => 'simulador'],
                'module' => 'precificacao',
                'icon' => 'bi-calculator',
                'path' => ['Financeiro', 'Ferramentas', 'Precificação', 'aba Simulador'],
                'keywords' => ['simulador de preços de peças', 'simulador de preço', 'simular peça', 'simular serviço', 'simular preço', 'calcular preço', 'quanto cobrar', 'preço de venda', 'markup', 'margem'],
            ],
            [
                'key' => 'financeiro.precificacao.configuracao',
                'label' => 'Regras de precificação',
                'route' => 'financeiro.precificacao.index',
                'params' => ['tab' => 'configuracao'],
                'module' => 'precificacao',
                'icon' => 'bi-sliders2',
                'path' => ['Financeiro', 'Ferramentas', 'Precificação', 'aba Configuração'],
                'keywords' => ['margem padrão', 'markup', 'impostos', 'alíquota', 'custo hora', 'valor da hora técnica', 'configurar precificação'],
            ],
            [
                'key' => 'estoque.a-comprar',
                'label' => 'Peças a comprar',
                'route' => 'estoque.a-comprar',
                'module' => 'estoque',
                'icon' => 'bi-cart-plus',
                'path' => ['Cadastros', 'Estoque', 'A comprar'],
                'keywords' => ['lista de compras', 'estoque mínimo', 'reposição', 'peças em falta', 'comprar peças', 'pedido de compra'],
            ],
            [
                'key' => 'caixa.historico',
                'label' => 'Histórico de caixas',
                'route' => 'caixa.historico',
                'module' => 'caixa',
                'icon' => 'bi-clock-history',
                'path' => ['Financeiro', 'Contas e Saldos', 'Caixa', 'Histórico'],
                'keywords' => ['turnos anteriores', 'fechamentos de caixa', 'caixas fechados', 'relatório de caixa'],
            ],
            [
                'key' => 'financeiro.contas.consolidado',
                'label' => 'Extrato consolidado das contas',
                'route' => 'financeiro.contas.consolidado',
                'module' => 'contas_saldos',
                'icon' => 'bi-journal-richtext',
                'path' => ['Financeiro', 'Contas e Saldos', 'Consolidado'],
                'keywords' => ['extrato geral', 'todas as contas', 'saldo total', 'movimentação bancária'],
            ],
            [
                'key' => 'financeiro.cartoes-credito.faturas',
                'label' => 'Faturas de cartão de crédito',
                'route' => 'financeiro.contas.index',
                'module' => 'contas_saldos',
                'icon' => 'bi-credit-card',
                'path' => ['Financeiro', 'Contas e Saldos', 'cartão de crédito', 'Faturas'],
                'keywords' => ['fatura', 'cartão de crédito', 'pagar fatura', 'prever fatura', 'fechamento da fatura', 'vencimento do cartão'],
                'hint' => 'Em Contas e Saldos, abra a conta do cartão de crédito e use "Faturas".',
            ],
            [
                'key' => 'financeiro.cartoes.simulador',
                'label' => 'Simulador de recebimento no cartão',
                'route' => 'financeiro.cartoes.index',
                'module' => 'financeiro',
                'icon' => 'bi-credit-card-2-front',
                'path' => ['Financeiro', 'Ferramentas', 'Cartões e Taxas', 'Simular recebimento'],
                'keywords' => ['simular cartão', 'quanto vou receber', 'taxa da maquininha', 'parcelado', 'desconto do cartão', 'valor líquido'],
                'hint' => 'Na tela de Cartões e Taxas, use o bloco "Simulador de faturamento líquido".',
            ],
            [
                'key' => 'fiscal.anexo-x.ajustes',
                'label' => 'Ajustes do Relatório Mensal das Receitas',
                'route' => 'fiscal.anexo-x.ajustes',
                'module' => 'fiscal',
                'icon' => 'bi-journal-check',
                'path' => ['Fiscal', 'Relatório Mensal das Receitas', 'Ajustes'],
                'keywords' => ['anexo x', 'ajuste de receita', 'corrigir faturamento', 'lançamento manual fiscal'],
            ],
            [
                'key' => 'fiscal.anexo-x.operacoes',
                'label' => 'Operações do Relatório Mensal das Receitas',
                'route' => 'fiscal.anexo-x.operacoes',
                'module' => 'fiscal',
                'icon' => 'bi-list-ul',
                'path' => ['Fiscal', 'Relatório Mensal das Receitas', 'Operações'],
                'keywords' => ['anexo x', 'operações do mês', 'detalhamento das receitas'],
            ],
            [
                'key' => 'fiscal.anexo-x.pdf',
                'label' => 'PDF do Relatório Mensal das Receitas',
                'route' => 'fiscal.anexo-x.pdf',
                'module' => 'fiscal',
                'icon' => 'bi-file-earmark-pdf',
                'path' => ['Fiscal', 'Relatório Mensal das Receitas', 'Gerar PDF'],
                'keywords' => ['anexo x', 'imprimir relatório mensal', 'relatório para o contador', 'exportar receitas'],
            ],
            [
                'key' => 'fiscal.nota.emitir',
                'label' => 'Emitir nota fiscal da OS',
                'route' => 'fiscal.pendentes',
                'module' => 'os', 'action' => 'editar',
                'icon' => 'bi-receipt',
                'path' => ['Fiscal', 'Notas pendentes', 'botão Emitir'],
                'keywords' => ['emitir nfse', 'emitir nfs-e', 'gerar nota', 'nota de serviço', 'nota fiscal eletrônica'],
                'hint' => 'Abra a OS na lista de Notas pendentes e use "Emitir nota".',
            ],
            [
                'key' => 'fiscal.certificado',
                'label' => 'Certificado digital (A1)',
                'route' => 'configurations.integrations.index',
                'params' => ['tab' => 'fiscal'],
                'module' => 'configuracoes',
                'icon' => 'bi-shield-check',
                'path' => ['Administração', 'Integrações', 'aba Fiscal'],
                'keywords' => ['certificado a1', 'certificado digital', 'pfx', 'senha do certificado', 'ambiente de homologação', 'produção', 'nfse'],
            ],
            [
                'key' => 'knowledge.pdf-templates.legado',
                'label' => 'Modelos PDF (legado)',
                'route' => 'knowledge.pdf-templates.index',
                'module' => 'conhecimento',
                'icon' => 'bi-file-earmark-pdf',
                'path' => ['Processos e Modelos', 'Modelos PDF', 'Legado'],
                'keywords' => ['templates antigos', 'modelos antigos', 'html do pdf'],
            ],
            [
                'key' => 'notifications.index',
                'label' => 'Notificações',
                'route' => 'notifications.index',
                'module' => null,
                'icon' => 'bi-bell',
                'path' => ['Topo', 'Sino', 'Notificações'],
                'keywords' => ['alertas', 'avisos', 'sino', 'central de notificações', 'marcar como lida'],
            ],
            [
                'key' => 'profile.show',
                'label' => 'Meu perfil',
                'route' => 'profile.show',
                'module' => null,
                'icon' => 'bi-person-circle',
                'path' => ['Topo', 'Avatar', 'Meu perfil'],
                'keywords' => ['perfil', 'minha conta', 'meus dados', 'foto de perfil', 'assinatura'],
            ],
            [
                'key' => 'profile.edit',
                'label' => 'Configurações do perfil',
                'route' => 'profile.edit',
                'module' => null,
                'icon' => 'bi-person-gear',
                'path' => ['Topo', 'Avatar', 'Configurações'],
                'keywords' => ['trocar senha', 'alterar senha', 'minha senha', 'foto', 'assinatura digital', 'modo de navegação', 'menu retrátil', 'menu lateral', 'preferências'],
            ],
            [
                'key' => 'logout',
                'label' => 'Sair do sistema',
                'route' => 'profile.show',
                'module' => null,
                'icon' => 'bi-box-arrow-right',
                'path' => ['Topo', 'Avatar', 'Sair'],
                'keywords' => ['logout', 'deslogar', 'encerrar sessão', 'trocar de usuário'],
                'hint' => 'No menu do avatar (canto superior direito), clique em "Sair".',
            ],
            [
                'key' => 'configurations.system.aparencia',
                'label' => 'Aparência do sistema',
                'route' => 'configurations.system.index',
                'params' => ['tab' => 'aparencia'],
                'module' => 'configuracoes',
                'icon' => 'bi-palette',
                'path' => ['Administração', 'Configurações do Sistema', 'aba Aparência'],
                'keywords' => ['tema', 'cores', 'logo', 'logotipo', 'favicon', 'fundo do login', 'marca'],
            ],
            [
                'key' => 'configurations.system.empresa',
                'label' => 'Dados da empresa',
                'route' => 'configurations.system.index',
                'params' => ['tab' => 'empresa'],
                'module' => 'configuracoes',
                'icon' => 'bi-building',
                'path' => ['Administração', 'Configurações do Sistema', 'aba Dados da Empresa'],
                'keywords' => ['razão social', 'cnpj da empresa', 'endereço da empresa', 'telefone da empresa', 'dados institucionais'],
            ],
            [
                'key' => 'configurations.system.sessao',
                'label' => 'Sessão e segurança',
                'route' => 'configurations.system.index',
                'params' => ['tab' => 'sessao'],
                'module' => 'configuracoes',
                'icon' => 'bi-shield-lock',
                'path' => ['Administração', 'Configurações do Sistema', 'aba Sessão e Segurança'],
                'keywords' => ['tempo de sessão', 'expiração', 'manter conectado', 'segurança', 'logout automático'],
            ],
            [
                'key' => 'configurations.system.backups',
                'label' => 'Backups',
                'route' => 'configurations.system.index',
                'params' => ['tab' => 'backups'],
                'module' => 'backups',
                'icon' => 'bi-hdd-stack',
                'path' => ['Administração', 'Configurações do Sistema', 'aba Backup'],
                'keywords' => ['backup', 'cópia de segurança', 'restaurar', 'gerar backup', 'baixar backup'],
            ],
            [
                'key' => 'configurations.integrations.whatsapp',
                'label' => 'Integração WhatsApp',
                'route' => 'configurations.integrations.index',
                'params' => ['tab' => 'whatsapp'],
                'module' => 'configuracoes',
                'icon' => 'bi-whatsapp',
                'path' => ['Administração', 'Integrações', 'aba WhatsApp'],
                'keywords' => ['conectar whatsapp', 'qr code', 'gateway', 'parear celular', 'reiniciar whatsapp'],
            ],
            [
                'key' => 'configurations.integrations.email',
                'label' => 'Integração de e-mail',
                'route' => 'configurations.integrations.index',
                'params' => ['tab' => 'email'],
                'module' => 'configuracoes',
                'icon' => 'bi-envelope',
                'path' => ['Administração', 'Integrações', 'aba E-mail'],
                'keywords' => ['smtp', 'enviar e-mail', 'e-mail de teste', 'servidor de e-mail', 'remetente'],
            ],
            [
                'key' => 'configurations.integrations.payments',
                'label' => 'Integração de pagamentos',
                'route' => 'configurations.integrations.index',
                'params' => ['tab' => 'payments'],
                'module' => 'configuracoes',
                'icon' => 'bi-credit-card',
                'path' => ['Administração', 'Integrações', 'aba Pagamentos'],
                'keywords' => ['gateway de pagamento', 'pix automático', 'link de pagamento', 'maquininha online'],
            ],
            [
                'key' => 'configurations.integrations.agenda-google',
                'label' => 'Integração com Google Agenda',
                'route' => 'configurations.integrations.index',
                'params' => ['tab' => 'agenda-google'],
                'module' => 'configuracoes',
                'icon' => 'bi-google',
                'path' => ['Administração', 'Integrações', 'aba Google Agenda'],
                'keywords' => ['google calendar', 'conectar google', 'sincronizar agenda', 'credenciais google'],
            ],

            // ---- Exportar / importar ----
            [
                'key' => 'estoque.export',
                'label' => 'Exportar estoque (CSV)',
                'route' => 'estoque.export.csv',
                'module' => 'estoque', 'action' => 'exportar',
                'icon' => 'bi-download',
                'path' => ['Cadastros', 'Estoque', 'Mais ações', 'Exportar CSV'],
                'keywords' => ['exportar peças', 'planilha de peças', 'excel', 'baixar estoque'],
            ],
            [
                'key' => 'estoque.import',
                'label' => 'Importar peças em lote',
                'route' => 'estoque.download-template',
                'module' => 'estoque', 'action' => 'importar',
                'icon' => 'bi-upload',
                'path' => ['Cadastros', 'Estoque', 'Mais ações', 'Importar'],
                'keywords' => ['importar estoque', 'planilha modelo', 'modelo de importação', 'csv de peças', 'carga de peças'],
                'hint' => 'Baixa o modelo; para enviar a planilha use "Importar em lote" em Estoque › Mais ações.',
            ],
            [
                'key' => 'servicos.export',
                'label' => 'Exportar serviços (CSV)',
                'route' => 'servicos.export.csv',
                'module' => 'servicos', 'action' => 'exportar',
                'icon' => 'bi-download',
                'path' => ['Cadastros', 'Serviços', 'Mais ações', 'Exportar CSV'],
                'keywords' => ['exportar serviços', 'planilha de serviços', 'tabela de preços'],
            ],
            [
                'key' => 'servicos.import',
                'label' => 'Importar serviços em lote',
                'route' => 'servicos.download-template',
                'module' => 'servicos', 'action' => 'importar',
                'icon' => 'bi-upload',
                'path' => ['Cadastros', 'Serviços', 'Mais ações', 'Importar'],
                'keywords' => ['importar serviços', 'planilha modelo', 'modelo de importação', 'csv de serviços'],
                'hint' => 'Baixa o modelo; para enviar a planilha use "Importar em lote" em Serviços › Mais ações.',
            ],
            [
                'key' => 'equipments.catalog.export',
                'label' => 'Exportar catálogo de equipamentos (CSV)',
                'route' => 'equipments.catalog.export.csv',
                'module' => 'equipamentos', 'action' => 'exportar',
                'icon' => 'bi-download',
                'path' => ['Cadastros', 'Equipamentos', 'Mais ações', 'Exportar CSV'],
                'keywords' => ['exportar marcas', 'exportar modelos', 'planilha de equipamentos'],
            ],
            [
                'key' => 'equipments.catalog.import',
                'label' => 'Importar catálogo de equipamentos',
                'route' => 'equipments.catalog.download-template',
                'module' => 'equipamentos', 'action' => 'importar',
                'icon' => 'bi-upload',
                'path' => ['Cadastros', 'Equipamentos', 'Mais ações', 'Importar'],
                'keywords' => ['importar marcas', 'importar modelos', 'planilha modelo', 'modelo de importação'],
                'hint' => 'Baixa o modelo; para enviar a planilha use "Importar em lote" em Equipamentos › Mais ações.',
            ],

            // ---- Acoes dentro de um registro (o link leva ate a listagem) ----
            [
                'key' => 'orders.baixa',
                'label' => 'Baixa da OS',
                'route' => 'orders.index',
                'module' => 'os', 'action' => 'editar',
                'icon' => 'bi-check2-circle',
                'path' => ['Atendimento', 'Ordens de Serviço', 'abrir OS', 'botão Baixa'],
                'keywords' => ['dar baixa', 'baixar os', 'finalizar os', 'encerrar os', 'concluir os', 'entregar aparelho', 'fechar os', 'receber pagamento da os'],
                'hint' => 'Abra a OS e clique em "Baixa / Adiantamento". Para várias de uma vez, marque-as na lista e use "Dar baixa em lote".',
            ],
            [
                'key' => 'orders.status-lote',
                'label' => 'Mudar status de várias OS',
                'route' => 'orders.index',
                'module' => 'os', 'action' => 'editar',
                'icon' => 'bi-list-check',
                'path' => ['Atendimento', 'Ordens de Serviço', 'selecionar', 'Mais ações'],
                'keywords' => ['status em lote', 'alterar status', 'mudar situação', 'várias os', 'em massa'],
                'hint' => 'Marque as OS na lista e use "Alterar status em lote".',
            ],
            [
                'key' => 'orders.mapa',
                'label' => 'Mapa da OS',
                'route' => 'orders.index',
                'module' => 'os',
                'icon' => 'bi-diagram-3',
                'path' => ['Atendimento', 'Ordens de Serviço', 'abrir OS', 'Mapa'],
                'keywords' => ['mapa', 'linha do tempo', 'visão da os', 'etapas da os', 'onde está a os'],
                'hint' => 'Abra a OS e use "Mapa da OS".',
            ],
            [
                'key' => 'orders.historico',
                'label' => 'Histórico / auditoria da OS',
                'route' => 'orders.index',
                'module' => 'os',
                'icon' => 'bi-clock-history',
                'path' => ['Atendimento', 'Ordens de Serviço', 'abrir OS', 'Histórico'],
                'keywords' => ['auditoria', 'quem alterou', 'log da os', 'histórico de alterações'],
                'hint' => 'Abra a OS e use "Histórico".',
            ],
            [
                'key' => 'orders.documentos',
                'label' => 'Central documental da OS',
                'route' => 'orders.index',
                'module' => 'os',
                'icon' => 'bi-folder2-open',
                'path' => ['Atendimento', 'Ordens de Serviço', 'abrir OS', 'Documentos'],
                'keywords' => ['documentos da os', 'pdf da os', 'termo de garantia', 'comprovante', 'enviar documento', 'assinatura do cliente', 'assinar documento'],
                'hint' => 'Abra a OS e use "Documentos".',
            ],
            [
                'key' => 'orders.imprimir',
                'label' => 'Imprimir OS',
                'route' => 'orders.index',
                'module' => 'os',
                'icon' => 'bi-printer',
                'path' => ['Atendimento', 'Ordens de Serviço', 'abrir OS', 'Imprimir'],
                'keywords' => ['impressão da os', 'via do cliente', 'comprovante de entrada', 'pdf'],
                'hint' => 'Abra a OS e use "Imprimir OS (A4)" ou "Imprimir cupom (80mm)".',
            ],
            [
                'key' => 'orders.fotos',
                'label' => 'Adicionar fotos à OS',
                'route' => 'orders.index',
                'module' => 'os', 'action' => 'editar',
                'icon' => 'bi-camera',
                'path' => ['Atendimento', 'Ordens de Serviço', 'abrir OS', 'Fotos'],
                'keywords' => ['foto', 'fotos da os', 'anexar foto', 'tirar foto', 'câmera', 'webcam', 'colar imagem', 'print', 'imagem do defeito', 'foto do diagnóstico', 'foto da entrega'],
                'hint' => 'Abra a OS e use o quadro "Fotos": Câmera, Computador / galeria, Colar (Ctrl+V) ou arraste as imagens.',
            ],
            [
                'key' => 'orders.checklist-entrada',
                'label' => 'Checklist de entrada na OS',
                'route' => 'orders.create',
                'module' => 'os', 'action' => 'criar',
                'icon' => 'bi-box-arrow-in-down',
                'path' => ['Atendimento', 'Nova OS', 'etapa Checklist'],
                'keywords' => ['vistoria', 'estado do aparelho', 'itens conferidos', 'checklist na entrada'],
                'hint' => 'O checklist é preenchido na etapa correspondente ao criar/editar a OS.',
            ],
            [
                'key' => 'orders.pecas',
                'label' => 'Peças e estoque na OS',
                'route' => 'orders.index',
                'module' => 'os', 'action' => 'editar',
                'icon' => 'bi-box-seam',
                'path' => ['Atendimento', 'Ordens de Serviço', 'abrir OS', 'Peças'],
                'keywords' => ['usar peça na os', 'baixar estoque', 'reservar peça', 'aplicar peças', 'consumo de peças'],
                'hint' => 'Abra a OS e use o bloco de peças para aplicar itens do estoque.',
            ],
            [
                'key' => 'orcamentos.enviar',
                'label' => 'Enviar orçamento ao cliente',
                'route' => 'orcamentos.index',
                'module' => 'orcamentos',
                'icon' => 'bi-send',
                'path' => ['Atendimento', 'Orçamentos', 'abrir orçamento', 'Enviar'],
                'keywords' => ['enviar por whatsapp', 'enviar por e-mail', 'mandar orçamento', 'link de aprovação', 'aprovação do cliente'],
                'hint' => 'Abra o orçamento e use "Enviar para aprovação" ou "Enviar para o cliente consultar".',
            ],
            [
                'key' => 'orcamentos.aprovar',
                'label' => 'Aprovar / recusar orçamento',
                'route' => 'orcamentos.index',
                'module' => 'orcamentos', 'action' => 'editar',
                'icon' => 'bi-hand-thumbs-up',
                'path' => ['Atendimento', 'Orçamentos', 'abrir orçamento', 'Aprovar'],
                'keywords' => ['aprovar orçamento', 'recusar orçamento', 'rejeitar', 'cliente aprovou', 'converter em os', 'gerar os do orçamento'],
                'hint' => 'Abra o orçamento e use "Aprovar orçamento" (ou recusar); aprovado vira OS.',
            ],
            [
                'key' => 'estoque.movimentacoes',
                'label' => 'Movimentações de uma peça',
                'route' => 'estoque.index',
                'module' => 'estoque',
                'icon' => 'bi-arrow-left-right',
                'path' => ['Cadastros', 'Estoque', 'abrir peça', 'Movimentações'],
                'keywords' => ['entrada de estoque', 'saída de estoque', 'ajuste de estoque', 'histórico da peça', 'kardex', 'movimentar estoque'],
                'hint' => 'Na lista de Estoque, abra a peça e use "Movimentações".',
            ],
            [
                'key' => 'estoque.sugerir-preco',
                'label' => 'Sugerir preço de venda da peça',
                'route' => 'estoque.index',
                'module' => 'estoque', 'action' => 'editar',
                'icon' => 'bi-magic',
                'path' => ['Cadastros', 'Estoque', 'editar peça', 'Sugerir preço'],
                'keywords' => ['preço sugerido', 'calcular preço da peça', 'precificar peça', 'margem da peça'],
                'hint' => 'Ao cadastrar a peça, o preço sugerido aparece sozinho abaixo do valor de venda assim que o custo é informado.',
            ],
            [
                'key' => 'servicos.sugerir-preco',
                'label' => 'Sugerir preço do serviço',
                'route' => 'servicos.index',
                'module' => 'servicos', 'action' => 'editar',
                'icon' => 'bi-magic',
                'path' => ['Cadastros', 'Serviços', 'editar serviço', 'Sugerir preço'],
                'keywords' => ['preço sugerido', 'calcular preço do serviço', 'precificar serviço', 'hora técnica'],
                'hint' => 'Ao cadastrar o serviço, o preço sugerido aparece sozinho abaixo do valor assim que o tempo é informado.',
            ],
            [
                'key' => 'financeiro.contas.extrato',
                'label' => 'Extrato de uma conta',
                'route' => 'financeiro.contas.index',
                'module' => 'contas_saldos',
                'icon' => 'bi-journal-text',
                'path' => ['Financeiro', 'Contas e Saldos', 'conta', 'Extrato'],
                'keywords' => ['extrato bancário', 'movimentações da conta', 'saldo da conta', 'conciliação'],
                'hint' => 'Em Contas e Saldos, clique na conta desejada e depois em "Extrato".',
            ],
            [
                'key' => 'financeiro.contas.transferencia',
                'label' => 'Transferência entre contas',
                'route' => 'financeiro.contas.index',
                'module' => 'contas_saldos', 'action' => 'editar',
                'icon' => 'bi-arrow-left-right',
                'path' => ['Financeiro', 'Contas e Saldos', 'Transferir'],
                'keywords' => ['transferir saldo', 'mover dinheiro', 'transferência bancária', 'ajuste de saldo'],
                'hint' => 'Em Contas e Saldos, use "Transferir entre contas".',
            ],
            [
                'key' => 'financeiro.pagar',
                'label' => 'Pagar / receber um lançamento',
                'route' => 'financeiro.index',
                'module' => 'financeiro', 'action' => 'editar',
                'icon' => 'bi-cash',
                'path' => ['Financeiro', 'Lançamentos', 'lançamento', 'Pagar'],
                'keywords' => ['baixar lançamento', 'quitar', 'marcar como pago', 'confirmar recebimento', 'liquidar'],
                'hint' => 'Na lista de Lançamentos, abra o lançamento e use "Registrar baixa".',
            ],
            [
                'key' => 'vendas.cancelar-em-andamento',
                'label' => 'Cancelar a venda em andamento',
                'route' => 'vendas.create',
                'module' => 'vendas', 'action' => 'criar',
                'icon' => 'bi-x-circle',
                'path' => ['Atendimento', 'Vendas', 'Nova venda', 'Cancelar venda'],
                'keywords' => ['cancelar venda', 'desistir da venda', 'descartar venda', 'limpar carrinho', 'zerar pdv', 'começar outra venda'],
                'hint' => 'No PDV, use "Cancelar venda (Esc)" abaixo de "Finalizar venda". Venda já concluída se desfaz com "Devolver".',
            ],
            [
                'key' => 'vendas.devolver',
                'label' => 'Devolver uma venda',
                'route' => 'vendas.index',
                'module' => 'vendas', 'action' => 'criar',
                'icon' => 'bi-arrow-return-left',
                'path' => ['Atendimento', 'Vendas', 'abrir venda', 'Devolver'],
                'keywords' => ['devolução', 'estornar venda', 'troca de produto', 'cancelar venda'],
                'hint' => 'Abra a venda e use "Devolver".',
            ],
            [
                'key' => 'vendas.recibo',
                'label' => 'Recibo da venda',
                'route' => 'vendas.index',
                'module' => 'vendas',
                'icon' => 'bi-receipt',
                'path' => ['Atendimento', 'Vendas', 'abrir venda', 'Recibo'],
                'keywords' => ['imprimir recibo', 'comprovante de venda', 'cupom', 'segunda via'],
                'hint' => 'Abra a venda e use "Cupom 80mm" ou "A4".',
            ],
            [
                'key' => 'caixa.abrir-fechar',
                'label' => 'Abrir / fechar o caixa',
                'route' => 'caixa.index',
                'module' => 'caixa', 'action' => 'criar|editar',
                'icon' => 'bi-cash-stack',
                'path' => ['Financeiro', 'Contas e Saldos', 'Caixa'],
                'keywords' => ['abertura de caixa', 'fechamento de caixa', 'sangria', 'suprimento', 'reforço de caixa', 'conferência do caixa'],
                'hint' => 'Na tela de Caixa, use "Abrir caixa" no início do turno e "Fechar" ao final.',
            ],
            [
                'key' => 'clients.aparelhos',
                'label' => 'Aparelhos de um cliente',
                'route' => 'clients.index',
                'module' => 'clientes',
                'icon' => 'bi-laptop',
                'path' => ['Cadastros', 'Clientes', 'abrir cliente', 'Aparelhos'],
                'keywords' => ['equipamentos do cliente', 'celulares do cliente', 'histórico do cliente', 'os do cliente'],
                'hint' => 'Abra o cliente; os aparelhos e o histórico de OS ficam na ficha dele.',
            ],
            [
                'key' => 'suppliers.cnpj',
                'label' => 'Consultar CNPJ do fornecedor',
                'route' => 'suppliers.create',
                'module' => 'fornecedores', 'action' => 'criar',
                'icon' => 'bi-search',
                'path' => ['Cadastros', 'Fornecedores', 'Novo fornecedor', 'Consultar CNPJ'],
                'keywords' => ['buscar cnpj', 'receita federal', 'preencher pelo cnpj', 'dados do cnpj'],
                'hint' => 'No cadastro do fornecedor, informe um CNPJ válido: os dados públicos são preenchidos automaticamente.',
            ],
            [
                'key' => 'groups.permissoes',
                'label' => 'Permissões de um grupo',
                'route' => 'groups.index',
                'module' => 'grupos',
                'icon' => 'bi-shield-lock',
                'path' => ['Administração', 'Grupos e Permissões', 'grupo', 'Permissões'],
                'keywords' => ['editar permissões', 'liberar acesso', 'bloquear acesso', 'o que o grupo pode ver', 'matriz de permissões'],
                'hint' => 'Na lista de Grupos, clique em "Permissões" no grupo desejado.',
            ],
            [
                'key' => 'equipments.coletor',
                'label' => 'Coletor de dados do equipamento (Windows)',
                'route' => 'equipments.index',
                'module' => 'equipamentos', 'action' => 'criar|editar',
                'icon' => 'bi-usb-plug',
                'path' => ['Cadastros', 'Aparelhos / Equip.', 'aparelho', 'Coletor'],
                'keywords' => ['coletor', 'pareamento', 'baixar coletor', 'informações do computador', 'hardware', 'inventário automático'],
                'hint' => 'No cadastro do aparelho, use o bloco "Coletor de hardware" para gerar o código e importar os dados.',
            ],

            // ---- Ajuda de cada modulo ----
            self::help('dashboard', 'Dashboard', 'dashboard.help', 'dashboard'),
            self::help('orcamentos', 'Orçamentos', 'orcamentos.help', 'orcamentos'),
            self::help('vendas', 'Vendas', 'vendas.help', 'vendas'),
            self::help('devolucoes', 'Devoluções', 'devolucoes.help', 'vendas'),
            self::help('caixa', 'Caixa', 'caixa.help', 'caixa'),
            self::help('fornecedores', 'Fornecedores', 'suppliers.help', 'fornecedores'),
            self::help('equipamentos-catalogo', 'Catálogo de Equipamentos', 'equipments.catalog.help', 'equipamentos'),
            self::help('aparelhos', 'Aparelhos / Equip.', 'equipments.help', 'equipamentos'),
            self::help('servicos', 'Serviços', 'servicos.help', 'servicos'),
            self::help('estoque', 'Estoque', 'estoque.help', 'estoque'),
            self::help('cartoes', 'Cartões e Taxas', 'financeiro.cartoes.help', 'financeiro'),
            self::help('integracoes', 'Integrações', 'configurations.integrations.help', 'configuracoes'),
            self::help('anexo-x', 'Relatório Mensal das Receitas', 'fiscal.anexo-x.ajuda', 'fiscal'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function help(string $key, string $label, string $route, string $module): array
    {
        return [
            'key' => 'ajuda.' . $key,
            'label' => 'Ajuda — ' . $label,
            'route' => $route,
            'module' => $module,
            'icon' => 'bi-question-circle',
            'path' => [$label, 'botão Ajuda'],
            'keywords' => ['ajuda', 'como usar', 'manual', 'dúvidas', 'tutorial', 'documentação', $label],
        ];
    }
}
