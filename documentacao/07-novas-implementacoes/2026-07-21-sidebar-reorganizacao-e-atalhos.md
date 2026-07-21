# Reorganização da sidebar e atalhos de submenu

**Data:** 21/07/2026
**Versão:** `5.4.2.0`
**Status:** ativo no ambiente de desenvolvimento LAN; não publicado na VPS de produção

## Objetivo

A barra lateral do desktop agrupava itens sem afinidade real: *Comercial* misturava
Orçamentos/Clientes/Fornecedores com *Equipe da Assistência* (equipe interna);
*Conhecimento* era um único bloco de 10 itens juntando conhecimento de referência,
configuração de processo de OS e modelos de documento; e catálogos (Serviços,
Estoque) ficavam sob *Operacional*. Além disso, sub-páginas muito usadas (relatórios
financeiros, cartões, usuários, integrações) só eram alcançáveis entrando na
página-mãe primeiro.

## O que mudou

Alteração **puramente de apresentação** — só o array `definition()` de
`frontends/desktop/app/Support/DesktopNavigation.php`. Nenhuma rota, permissão,
controller ou módulo RBAC mudou. O renderizador
(`resources/views/layouts/partials/sidebar.blade.php`) já suportava seções, itens
diretos e grupos expansíveis; nada de Blade/CSS foi tocado.

Nova estrutura em 7 seções:

- **Visão Geral:** Dashboard.
- **Atendimento:** Ordens de Serviço, Orçamentos.
- **Cadastros:** Clientes, Fornecedores, Aparelhos/Equip. (movido de Operacional,
  por ser o cadastro dos equipamentos dos clientes), Serviços, Estoque de Peças.
- **Financeiro:** Lançamentos, Contas e Saldos, + grupo **Relatórios** (Fluxo de
  Caixa, DRE por Competência, DRE de Caixa, Margem por OS) e grupo **Ferramentas**
  (Cartões e Taxas, Configurações Financeiras, Precificação).
- **Conhecimento:** Base de Defeitos, Defeitos Relatados.
- **Processos e Modelos:** Fluxo de Trabalho OS, Modelo da Assistência Técnica,
  grupo **Checklists** (Entrada, Manutenção, Controle de Qualidade, Saída),
  Modelos PDF, Templates WhatsApp.
- **Administração:** Equipe da Assistência (saiu de Comercial), Gerenciador de
  Arquivos, Configurações do Sistema, + grupo **Acesso e Integrações** (Usuários,
  Grupos e Permissões, Integrações).

### Atalhos de submenu

Os grupos *Relatórios*, *Ferramentas* e *Acesso e Integrações* levam direto a
sub-páginas que antes só eram acessíveis de dentro da página-mãe (Lançamentos e
Configurações do Sistema). As páginas-mãe continuam como itens próprios e mantêm
seus dropdowns internos — os atalhos são um caminho adicional. Cada atalho tem
`module` próprio, então o filtro `filterItem()` já respeita RBAC: o item some para
quem não tem a permissão e o grupo inteiro some se ficar vazio.

## Notas

- Landing padrão continua o Dashboard (`firstAllowedRouteName()` inalterado); para
  quem não tem Dashboard, o fallback passa a ser Ordens de Serviço.
- Todos os itens de Conhecimento/Processos e Modelos compartilham o módulo
  `conhecimento`; dividi-los em duas seções é só visual.
- Testes: `DesktopFrontendTest` teve o teste do antigo grupo "Pessoas" reescrito
  para validar a nova estrutura (Cadastros + Administração + grupos de atalho) e o
  teste do fluxo de OS ajustado (rótulo "Base de Conhecimento" → "Processos e
  Modelos").
