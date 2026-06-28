# 2026-06-24 - Formulário de novo cliente com paridade visual no desktop do sistema-erp

## Resumo

O formulário de criação de clientes do `frontends/desktop` foi reorganizado para seguir o mesmo padrão visual do `sistema-hml/clientes/novo`, com a leitura operacional em blocos bem definidos.

## O que mudou

- o topo do formulário passou a exibir um card de introdução operacional, com a mesma intenção visual do legado;
- os campos foram reorganizados em grupos:
  - `Tipo de pessoa`, `CPF / CNPJ` e `RG / IE` no bloco inicial;
  - `DADOS PESSOAIS` com nome, telefones, e-mail e preferência de contato;
  - `CONTATO ADICIONAL (opcional)` com nome e telefone do contato auxiliar;
  - `ENDEREÇO` com CEP, endereço, número, complemento, bairro, cidade, UF e referência;
- o campo `status_cadastro` permaneceu como contrato com o backend, mas ficou oculto na interface para preservar a paridade visual com o legado;
- o botão de ações final foi mantido no rodapé do formulário, com alinhamento à direita.

## Ajustes técnicos

- `frontends/desktop/resources/views/clients/form.blade.php`
  - recebeu a nova composição visual e a ordenação operacional dos campos;
- `frontends/desktop/public/assets/css/desktop.css`
  - recebeu estilos para o card de introdução, as seções operacionais e o bloco de ações;
- `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`
  - passou a validar a presença dos grupos visuais do formulário e a ausência do rótulo de situação cadastral na tela de criação.

## Versão

- `shared/version.php` atualizado para `3.0.8` após a reorganização visual do formulário.

## Validação recomendada

- abrir `http://127.0.0.1:8080/clientes/novo`;
- confirmar a leitura visual por blocos;
- confirmar que o formulário continua enviando os mesmos campos para a API;
- executar `php artisan test --filter=DesktopFrontendTest --stop-on-failure`.
