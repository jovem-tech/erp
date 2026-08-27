# Tarefas — Proteção de trabalho não salvo

- [x] `window.erpRegisterUnsavedWork` + `beforeunload` único no `desktop.js`
- [x] Liberar o loader de página quando o usuário decide ficar
- [x] Sonda quebrada tratada como "nada a perder"
- [x] Sonda do PDV, excluindo a venda em envio
- [x] Esc do PDV passa a confirmar antes de descartar o carrinho
- [x] `UnsavedWorkGuardTest` (5)

## Verificação manual pendente (no navegador)

- [ ] Carrinho com itens: sidebar, botão de início, "+ Novo", F1, F5 e fechar a aba pedem confirmação
- [ ] Cancelar o diálogo: a página continua utilizável e **o loader não fica preso**
- [ ] Finalizar a venda NÃO pede confirmação
- [ ] Carrinho vazio não pede confirmação em saída nenhuma
- [ ] Esc com itens: pergunta; Enter confirma; Esc de novo cancela sem apagar
- [ ] Esc em tela cheia continua saindo da tela cheia primeiro
- [ ] Esc com o modal de pagamento aberto continua sem apagar o carrinho

## Decisão pendente

- [ ] Wizard de nova OS (`/os/criar`): mesma proteção? Precisa definir "sujo" sem gerar falso alarme com o Select2
- [ ] `session_security_settings.warn_on_close`: dar dono ou remover do banco
