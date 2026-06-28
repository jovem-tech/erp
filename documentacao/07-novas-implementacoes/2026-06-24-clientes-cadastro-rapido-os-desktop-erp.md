# 2026-06-24 - Cadastro rápido de clientes na OS do desktop ERP

## Resumo

O frontend `frontends/desktop` recebeu o cadastro rápido de cliente no fluxo de nova OS, aproximando o comportamento do legado `sistema-hml`.

## O que mudou

- `POST /clientes/rapido` no desktop agora cadastra clientes diretamente pela API central, mantendo a sessão e o contrato de serviços do frontend.
- A tela `frontends/desktop/os/criar` passou a exibir um botão `Novo cliente` quando o usuário tem permissão `clientes:criar`.
- O modal de cadastro rápido adiciona o cliente ao seletor da OS sem sair da tela.
- O seletor de equipamento é limpo após o cadastro rápido para reduzir risco de vínculo operacional incoerente.
- A documentação técnica e o README do desktop foram atualizados para registrar o novo fluxo.

## Testes executados

- renderização da tela de nova OS com modal de cadastro rápido;
- envio do cadastro rápido de cliente com retorno JSON;
- validação dos defaults operacionais enviados ao backend (`tipo_pessoa=fisica`, `status_cadastro=completo`).

## Versão

- `shared/version.php` atualizado para `3.0.7`.
