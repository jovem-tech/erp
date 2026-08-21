# Confirmação de administrador (step-up)

Todas as ações sensíveis que pedem "e-mail + senha de administrador" passam por
`backend/app/Services/Auth/AdminCredentialVerifier`: cancelar baixa de OS,
editar orçamento de OS encerrada, devolução de venda, caixa, excluir
lançamento, estorno de fatura de cartão e gerenciador de arquivos.

## Quem pode autorizar

Sem habilidade específica (`$requiredAbility = null`), duas portas:

1. `usuarios.perfil = 'admin'` — marcador legado, mantido para não exigir
   migração de dados.
2. RBAC `grupos:editar` (`SUPER_ADMIN_ABILITY`) — quem edita grupos de
   permissão pode conceder qualquer permissão a si mesmo, então já é super
   administrador de fato.

A porta 2 existe porque o campo **Perfil** da tela de usuários é apenas o NOME
do grupo, exibido em modo leitura (`users/_index-scripts.blade.php` no
desktop) e nem sequer enviado no formulário. Um usuário de um grupo "Super
Administrador" tem `perfil` diferente de `'admin'` e, só com a regra legada,
era recusado mesmo tendo todas as permissões do sistema.

## Quando o fluxo exige uma habilidade específica

Passar `$requiredAbility` (ex.: `'arquivos:administrar'` em
`FileManagerController`) faz essa habilidade mandar sozinha — nem o perfil
legado nem o super admin entram por cima. Use quando o fluxo souber
exatamente quem pode autorizar.

## Ao mexer aqui

A regra vale para TODOS os fluxos de step-up de uma vez. Há teste dedicado em
`backend/tests/Feature/Auth/AdminCredentialVerifierTest.php` — rode-o junto
com a suíte inteira, porque afrouxar esta classe afrouxa o sistema todo.
