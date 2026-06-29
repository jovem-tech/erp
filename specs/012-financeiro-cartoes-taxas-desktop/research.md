# Research: Financeiro - Cartões e Taxas no Desktop

## Referência funcional

- O legado `sistema-hml/financeiro/cartoes` organiza o módulo em abas, com formulários curtos, tabelas operacionais e simulador de faturamento líquido.
- O desktop novo já possui base de layout, topbar, sidebar e helper global para Select2.

## Decisões técnicas

- Reutilizar o backend central como fonte de verdade.
- Reutilizar o shell atual do desktop sem mexer no banco.
- Centralizar o comportamento de selects no helper compartilhado do desktop.
- Manter o JS da página isolado em um arquivo específico do módulo.

## Riscos observados

- Selects não inicializados após troca de abas.
- Filtro por provedor em taxas online sem reconfiguração do Select2.
- Simulador com combinação sem taxa ativa retornando erro silencioso.
- Estados de edição que não limpam o formulário antes do próximo cadastro.

## Mitigações

- Chamar `window.DesktopUi.refreshSelect2()` ao trocar abas e ao limpar/preencher formulários.
- Manter o endpoint do simulador em contrato JSON claro.
- Exibir feedback com SweetAlert2 em falha ou sucesso.
- Adicionar testes de renderização e de contrato para o simulador.
