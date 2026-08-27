# Tarefas — Atalhos F1..F4 do "+ Novo"

- [x] `data-desktop-quick-create` e `<kbd>` nos quatro itens do dropdown
- [x] `initQuickCreateShortcuts()` no `desktop.js`, com as travas de modificador, dono de teclas, modal e permissão
- [x] Aviso ao guard de sessão antes de navegar
- [x] Reivindicação POR TECLA via `data-desktop-fkeys-owner="F2 F3 F4"`; F1 segue valendo no PDV
- [x] CSS da tecla
- [x] `QuickCreateShortcutsTest` (4)

## Verificação manual pendente (no navegador)

- [ ] **F1 realmente não abre a Ajuda do navegador** — é o risco principal
- [ ] F3 não abre a barra de localizar
- [ ] As quatro teclas abrem a tela certa a partir de qualquer página
- [ ] Com um modal aberto, as teclas não fazem nada
- [ ] No PDV: F2/F3/F4 seguem sendo os do PDV, e F1 abre Nova OS
- [ ] Nenhum logout falso após navegar por atalho (guard de sessão)
- [ ] Usuário sem permissão de criar: a tecla não faz nada e o menu não mostra o item
