# Analysis 007 - Consistência e Segurança

## Consistência

- o desktop ficou separado do `backend/` como projeto Laravel independente;
- toda chamada HTTP sai dos services e não dos controllers;
- o menu e as rotas usam as permissões efetivas retornadas por `auth/me`;
- a estrutura visual foi reconstruída em Blade sem tocar no legado.

## Segurança

- token Bearer mantido apenas na sessão server-side;
- `401` revoga a sessão local do desktop;
- `403` não derruba a sessão, apenas redireciona com feedback;
- sem acesso direto do desktop ao banco compartilhado;
- fotos e PDFs continuam mediadas pelo backend central.

## Riscos remanescentes

- a edição completa de OS ainda não foi portada para o desktop;
- novos módulos do legado ainda precisam ser migrados progressivamente;
- em produção, a sessão do desktop deve ser revisada para driver apropriado conforme a topologia da VPS.

## Status final

- fase implementada
- testes automatizados verdes
- smoke test funcional concluído
