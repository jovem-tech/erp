---
name: sistema-erp-autenticacao-step-up
description: Padrao de "step-up authentication" (reautenticacao com credenciais de administrador) para acoes sensiveis, sem depender do perfil de quem esta logado. Use sempre que uma acao so pode ser executada por um administrador mas o botao/tela precisa ficar visivel/acessivel a qualquer usuario com acesso ao modulo (ex.: cancelar uma baixa de OS, estornar um lancamento, excluir um registro critico, alterar uma configuracao sensivel).
---

# Sistema ERP — Step-up Authentication (confirmação de admin para ações sensíveis)

## Problema que este padrão resolve

Um usuário **administrador** pode estar com a sessão ativa num computador
compartilhado, ou logado no painel, e um usuário **não-administrador** (técnico,
atendente) pode se aproximar e tentar executar uma ação que só compete a um
admin. Confiar apenas em "o usuário logado tem perfil admin?" não é suficiente
quando a intenção é: **qualquer pessoa com acesso ao módulo pode iniciar/ver a
ação, mas só se concretiza mediante confirmação explícita de usuário+senha de
um administrador**, mesmo que o administrador não seja quem está com a sessão
aberta.

Esse é o padrão usado por sistemas maduros (ex.: "digite a senha do gerente
para autorizar este desconto"). Neste projeto foi implementado pela primeira
vez em **2026-07-08** para o "Cancelar baixa" de OS
(`sistema-erp-os-fluxo-fechamento`) e deve ser **reaproveitado** (não
reinventado) em qualquer nova ação com o mesmo requisito.

## Quando usar este padrão

Use step-up authentication quando **todas** as condições abaixo forem verdadeiras:

1. A ação é sensível/destrutiva o suficiente para exigir aprovação de um admin
   (reversão financeira, exclusão de dados, mudança de configuração crítica).
2. O botão/gatilho da ação deve ficar **visível/acessível a usuários sem perfil
   admin** — ou seja, a regra de visibilidade é mais permissiva que a regra de
   execução.
3. A verificação deve poder ser satisfeita por **qualquer** administrador do
   sistema, não só "se o usuário logado for admin" (ex.: técnico chama o
   admin, que digita a própria senha, sem precisar deslogar o técnico e logar
   como admin).

Se a ação já é só visível para admins (permissão do módulo já restringe a
admin) **e** quem clica sempre é o próprio admin logado, este padrão é
overkill — basta o gate de permissão normal (`RbacAuthorizationService` /
`$this->authorize()`).

## Desenho da solução (backend)

1. **Form Request dedicado** exigindo `admin_email` (email) + `admin_password`
   (string) — nunca reaproveitar o form request da ação em si; a verificação de
   admin é uma preocupação separada.
2. **Autorização da rota continua sendo a permissão de acesso ao módulo**
   (visualização, não edição/admin) — o gate real de "isso só pode ser feito
   por admin" acontece na verificação de credenciais, não no
   `$this->authorize()` do controller.
3. **Verificação inline, dentro do mesmo request**, sem criar nova sessão/token:
   - Buscar `User::where('email', $email)->first()`.
   - Confirmar: usuário existe, `ativo = true`, `perfil === 'admin'` (ou o
     papel exigido pela ação), `Hash::check($senhaEnviada, $user->senha)`.
   - Nenhum desses passos deve alterar a sessão/token do usuário que está
     efetivamente logado — é só uma checagem de credenciais de terceiros.
4. **Rate limiting da verificação** (não da ação em si), mesmo padrão do login
   (`AuthController::login()`): chave única por `{email do admin}|{ip}`, ex.
   5 tentativas / 60s de bloqueio, usando `RateLimiter` (`tooManyAttempts`,
   `hit`, `availableIn`, `clear`).
5. **Falha de verificação retorna HTTP 422 (nunca 401)** — ver seção
   "Armadilha crítica: 401 vs 422" abaixo. Isso vale para qualquer frontend
   deste projeto (desktop, mobile) que trate 401 como "sessão expirada, faça
   logout".
6. **Sucesso**: chamar o service da ação passando **dois** atores distintos —
   quem clicou (autor/ator da ação, para auditoria de "quem fez") e o admin
   verificado (para registrar "quem autorizou"). Nunca confundir os dois.
7. **Nunca persistir a senha do admin** em log, sessão old-input, ou resposta
   — ver seção "Higiene de dados sensíveis" abaixo.

## Armadilha crítica: 401 vs 422

Neste projeto, o desktop (`ApiClient::parseResponse()`) trata **qualquer**
resposta HTTP 401 da API como "a sessão do usuário atual expirou" e força
logout automático (`DesktopSession::forget()`). Um step-up de admin está
verificando as credenciais de uma **pessoa diferente** da sessão ativa — se a
verificação usar 401 para "senha do admin errada", o usuário que clicou (não o
admin!) é deslogado por engano ao simplesmente errar a senha alheia. **Toda
falha de verificação de step-up deve retornar 422** (erro de validação/negócio),
nunca 401. Confirme esse comportamento no frontend específico que for consumir
o endpoint antes de assumir que "422 é seguro em todo lugar" — o princípio é
"nunca reusar o código HTTP que o frontend associa à própria sessão para uma
verificação de credenciais de terceiros".

## Higiene de dados sensíveis

- Backend: se o form request/validação nativa do Laravel puder flashar campos
  para a sessão em caso de erro, usar `Exceptions::dontFlash('admin_password')`
  (ou o nome do campo usado) em `bootstrap/app.php`.
- Frontend (desktop): no catch de erro do controller, **não chamar
  `withInput()`** — ou, se o handler global de exceção já usa `withInput()`,
  excluir explicitamente o campo de senha do admin do `except()`.
- Nunca logar a senha em texto plano — logar só e-mail do admin, id do usuário
  que clicou, IP, e o resultado (sucesso/falha), nunca a senha enviada.

## Referência de implementação completa

Ver `references/exemplo-cancelar-baixa.md` para o exemplo concreto e testado
(arquivos exatos, nomes de classes, rota, JS do modal) usado no "Cancelar
baixa" de OS — use como template ao implementar este padrão em qualquer nova
ação (estorno de lançamento, exclusão de registro crítico, etc.).

**Segunda implementação (2026-07-10): "Revelar senha do equipamento".** Apos o
hardening v4.0.0.0 mascarar `senha_acesso` em todos os payloads da API, este
padrao foi replicado para a consulta da senha de desbloqueio do aparelho:
`RevealEquipmentPasswordRequest` + `EquipmentController::revealPassword`
(backend, `POST equipments/{id}/reveal-password`, rate limit
`equipment-password-reveal-admin-auth:{email}|{ip}`, log de auditoria por
revelacao) → `EquipmentService::revealPassword` + rota
`POST /equipamentos/{id}/revelar-senha` (desktop) →
`_reveal_password_modal.blade.php` + `equipments-reveal-password-modal.js`
(tela de detalhe do equipamento; o modal apaga a senha do DOM ao fechar).
Este endpoint e' o UNICO caminho que expoe `senha_acesso`.

## Checklist ao implementar este padrão numa nova ação

- [ ] Form request dedicado só com `admin_email`/`admin_password` (não misturar
      com os campos da ação em si).
- [ ] `$this->authorize()` do controller usa a permissão de **visualização/acesso
      ao módulo**, não uma permissão de admin — a visibilidade do botão é mais
      ampla que a execução.
- [ ] Verificação: `ativo=true` + `perfil==='admin'` + `Hash::check()`, tudo
      inline, sem criar sessão/token novo.
- [ ] Rate limiting dedicado (chave própria, não reaproveitar a do login).
- [ ] Falha retorna **422**, nunca 401 — confirmado contra o comportamento real
      do `ApiClient`/interceptor do frontend consumidor.
- [ ] Service da ação recebe os dois atores (quem clicou vs. quem autorizou)
      separadamente, e registra ambos na auditoria (histórico/log).
- [ ] `dontFlash`/exclusão de `except()` para o campo de senha do admin.
- [ ] Modal/UI reaproveita o padrão de `_cancel_closure_modal.blade.php` +
      `orders-cancel-closure-modal.js` (form isolado, erro inline, sem redirect
      em caso de falha) em vez de criar um mecanismo novo do zero.
- [ ] Testado via headless: senha errada não navega e não desloga a sessão
      atual; senha certa executa a ação.

## Workflow de decisão

- Nova ação sensível com botão visível a não-admins → ler este skill inteiro
  antes de codar; usar `references/exemplo-cancelar-baixa.md` como template.
- Se a ação envolver o fluxo de fechamento de OS especificamente, combinar com
  `sistema-erp-os-fluxo-fechamento`.
