# Correcao de drift de schema: colunas de ajuste percentual ausentes em orcamentos

## Contexto

- versao: `3.13.1.0`
- data: `2026-07-06`
- ambiente-alvo: `Ubuntu VPS` (aplicado em `192.168.1.100`, ambiente dev)
- area afetada: `Orçamentos > Novo/Editar` — salvar orçamento (`Salvar sem enviar` e `Salvar e enviar para aprovação`)

## Problema observado

Ao clicar em "Salvar e enviar para aprovação" (ou "Salvar sem enviar"), o desktop
mostrava o toast genérico:

> Ocorreu um erro inesperado. Tente novamente em instantes.

No log do backend (`storage/logs/laravel-2026-07-06.log`), o `INSERT` em
`orcamentos` falhava com:

```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'desconto_tipo' in 'field list'
```

## Causa raiz

- a migration `database/migrations/2026_07_03_000001_add_adjustment_modes_to_orcamentos_tables.php`
  (feature de "ajustes percentuais" do orçamento) adiciona `desconto_tipo`,
  `desconto_percentual`, `acrescimo_tipo` e `acrescimo_percentual` em
  `orcamentos` e `orcamento_itens`;
- `php artisan migrate:status` mostrava essa migration como **`[6] Ran`** na
  tabela de controle (`laravel_migrations` — nome customizado neste projeto
  porque a tabela `migrations` já existe, usada pelo sistema legado CI4);
- mas `Schema::getColumnListing('orcamentos')` confirmava que as 4 colunas
  simplesmente **não existiam** nesta base (`sistema_hml` em 192.168.1.100), e
  o mesmo valia para `orcamento_itens`;
- ou seja: a migration foi executada com sucesso em algum momento/ambiente
  (por isso está marcada como `Ran`), mas esta cópia específica do banco não
  recebeu essa alteração de schema — a mesma classe de problema já documentada
  em `documentacao/07-novas-implementacoes/2026-07-05-deploy-producao-contabo-subdominios-e-dados-reais.md`
  (18 colunas aditivas faltantes na reconciliação VPS × legado).

## Correção aplicada

- executado, via `php artisan tinker` (autorizado explicitamente pelo
  usuário, por ser alteração de schema em banco compartilhado), o mesmo
  bloco `Schema::table(...)` que a migration já faz — com guards
  `Schema::hasColumn(...)`, então é idempotente e seguro de repetir;
- as 4 colunas foram adicionadas em `orcamentos` e as mesmas 4 em
  `orcamento_itens`, com os mesmos tipos/defaults/posições da migration
  original (`string(20) default 'valor'` para os `_tipo`, `decimal(8,4)
  nullable` para os `_percentual`);
- nenhum arquivo de código foi alterado — a migration em si já estava
  correta, só o estado do banco estava dessincronizado da tabela de
  controle.

## Impactos

- Aditivo puro: colunas novas com default, nenhuma coluna/tabela removida ou
  alterada, nenhum dado existente tocado.
- Não afeta `laravel_migrations` (a tracking table não foi alterada — a
  migration continua marcada como `Ran`, o que já era o caso).
- Vale conferir se a mesma cópia de banco usada em produção (VPS Contabo) tem
  esse mesmo drift; este fix cobriu apenas o ambiente de dev (192.168.1.100).

## Validação

- Confirmado via `Schema::getColumnListing` que as 8 colunas (4 por tabela)
  passaram a existir;
- `INSERT` de teste em `orcamentos` com `desconto_tipo`, `desconto_percentual`,
  `acrescimo_tipo`, `acrescimo_percentual` preenchidos, executado dentro de
  uma transação com `rollBack()` — confirmou que o schema agora aceita a
  gravação sem erro, sem persistir nenhum dado de teste.
