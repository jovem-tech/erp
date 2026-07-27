# Especificação: player de criação da OS mobile

**Branch de feature:** `codex/mobile-navigation`
**Status:** concluída

## Objetivo

Durante a abertura de uma nova ordem de serviço no PWA, substituir
temporariamente a navegação inferior global por controles próprios do wizard,
sem alterar a navegação das demais telas.

## História de usuário

Como operador abrindo uma OS, quero controlar o fluxo pelas ações fixas
`Início`, `Voltar`, `Próximo`, `Salvar` e `Cancelar`, para avançar entre as
etapas sem precisar procurar botões no fim do formulário.

## Requisitos funcionais

- **FR-001** — O player deve existir exclusivamente no pathname `/os/novo`.
- **FR-002** — Qualquer outra rota, inclusive `/os/{id}/editar`, deve preservar
  a Bottom Nav global.
- **FR-003** — `Voltar` deve retornar uma etapa e ficar indisponível na primeira.
- **FR-004** — `Próximo` deve avançar somente quando a etapa atual estiver
  válida e deve ficar indisponível na revisão.
- **FR-005** — `Salvar` deve permanecer visível, porém desabilitado, até que
  cliente, equipamento, checklist aplicável, relato, técnico responsável e
  prazo estejam válidos e todos os cards da revisão tenham sido confirmados.
- **FR-006** — `Salvar` deve reutilizar a criação idempotente já existente e
  impedir submissões concorrentes.
- **FR-007** — `Início` deve sair para a área de trabalho e `Cancelar` deve sair
  para a lista de OS.
- **FR-008** — Ao sair com dados preenchidos, o sistema deve solicitar
  confirmação de descarte.
- **FR-009** — Os botões internos duplicados do wizard e o botão superior
  `Voltar` não devem ser exibidos durante a criação.

## Critérios de aceite

- Há exatamente cinco ações no player em `/os/novo`.
- `Salvar` não pode ser acionado com obrigatoriedades pendentes.
- A edição e as demais telas continuam mostrando `Início`, `OS`, `Nova OS`,
  `Orçamentos` e `Perfil`.
- O layout permanece utilizável em `390 x 844`, sem cobrir os campos finais.

## Fora de escopo

- alterar contratos da API;
- alterar banco de dados;
- transformar a Bottom Nav da edição em player;
- persistir rascunho da OS no servidor.
