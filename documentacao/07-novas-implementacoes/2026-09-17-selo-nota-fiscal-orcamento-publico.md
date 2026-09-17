# Selo de emissão de NFS-e no orçamento público, condicionado ao limite do MEI

## Contexto

- versão: `pendente de bump` (implementado em 2026-09-17 sobre a 5.88.0.0)
- data: `2026-09-17`
- ambiente-alvo: `dev 192.168.1.100` (validado ponta a ponta, inclusive contra os
  números reais da empresa) → `VPS`
- migration: `2026_09_17_000001_add_emite_nota_fiscal_to_orcamentos` (aplicada
  no dev com `--path`, por causa do erro de grant do `sistema_erp_chat` que
  bloqueia `artisan migrate` sem escopo — ver
  `documentacao/07-novas-implementacoes/historico-de-versoes.md` e a nota em
  memória sobre a migration do chat)

A faixa de confiança da landing pública de orçamento (`opcoes.blade.php`, ver
"Landing de venda" em `2026-09-15-orcamento-em-niveis-manutencao.md`) mostrava
garantia, entrega e parcelamento, mas não dizia se a assistência emite nota
fiscal de serviço — um diferencial real para o cliente decidir. O problema não
é só exibir o texto: **uma empresa MEI tem teto anual de faturamento (R$
81.000, LC 123/2006, art. 18-A) e, perto ou acima dele, prometer NFS-e deixa
de ser uma garantia**. O selo não pode depender só de uma marcação manual —
tem que respeitar o mesmo limite que o Anexo X já apura.

## O que foi entregue

### Um campo, sem dimensão por nível

`orcamentos.emite_nota_fiscal` (boolean, default `false`) — ao contrário de
`garantia_dias`/`entrega_domicilio`/`parcelas_sem_juros`, **não** ganhou
override em `orcamento_nivel_condicoes`: emissão de nota não muda conforme a
opção de manutenção escolhida (Básica/Avançada/Completa), então não faz
sentido variar por nível. `Budget::CONVERTED_EDITABLE_FIELDS` inclui o campo,
então continua editável direto num orçamento já `convertido`, sem exigir nova
revisão.

### O limite do MEI, computado uma vez só

`AnexoXService::limiteAnualAtingido(?string $competencia = null): bool` —
novo método, mesma fonte que o relatório Anexo X usa
(`acumuladoAnual()`, que por sua vez lê de `ReceitaBrutaSource`, a mesma base
do DRE). Não existe um segundo cálculo de "quanto a empresa faturou" só para
este selo — divergiria do relatório oficial mais cedo ou mais tarde.
Fora do regime MEI, ou qualquer falha na apuração, o método devolve `false`
("não atingiu") por design: um selo de tela a menos é sempre preferível a
quebrar a página pública por causa de um extra.

### A decisão fica no backend, não na view

`BudgetApprovalService::publicBudgetPayload()` combina as duas condições:

```php
$mostrarSeloNotaFiscal = (bool) $budget->emite_nota_fiscal && ! $this->anexoXService->limiteAnualAtingido();
```

e expõe só o resultado (`mostrar_selo_nota_fiscal`) no payload da página
pública. `opcoes.blade.php` lê essa chave e, se verdadeira, acrescenta um
quarto item na faixa de confiança: *"Emissão de nota fiscal de serviço
(NFS-e) em qualquer opção escolhida."*, com um ícone de recibo novo
(`'receipt'`) no closure `$icon()` compartilhado do `show.blade.php`.

### O checkbox ajuda a decidir, não só a marcar

Formulário de orçamento (`frontends/desktop/resources/views/orcamentos/
form.blade.php`), logo abaixo de "Inclui entrega do equipamento": checkbox
"Emite nota fiscal de serviço (NFS-e) para este orçamento", com texto de
ajuda dinâmico — puxa o mesmo resumo do Anexo X (via novo
`mei_limite_nota_fiscal` em `BudgetWorkflowService::formData()`) e mostra,
por exemplo, *"Faltam R$ 73.877,50 para o limite anual do MEI (8,8%
usado)"*, ou avisa que o selo não vai aparecer se a empresa já passou do
teto — sem o operador precisar abrir o relatório fiscal à parte pra decidir
se marca a caixa.

### Bug lateral corrigido: `entrega_domicilio` não se aplicava em orçamento convertido

Ao replicar o padrão de `entrega_domicilio` para o novo campo,
`BudgetWorkflowService::updateConvertedBudget()` mostrou uma lacuna: o campo
está em `CONVERTED_EDITABLE_FIELDS` (passa na validação de "campo
permitido") mas o bloco que aplica os campos operacionais direto (telefone,
relato, prazo, garantia, formas de pagamento...) nunca lia
`entrega_domicilio` — mudar o checkbox num orçamento já convertido
silenciosamente não gravava nada. Corrigido junto com `emite_nota_fiscal`,
para o campo novo não nascer com o mesmo problema.

## Segurança e arquitetura

Nada no fluxo aceita dado do cliente: `emite_nota_fiscal` é marcado pelo
operador no desktop (mesma autorização de `orcamentos:editar` de qualquer
outro campo de condições comerciais) e a checagem do limite roda inteiramente
no backend, sem input externo. A página pública (sem autenticação, aberta via
link de WhatsApp/e-mail) só recebe o booleano já decidido — nunca os números
de faturamento da empresa. Qualquer exceção na apuração do Anexo X é
capturada e vira "selo escondido", nunca um erro 500 na página do cliente.

## Performance

O cálculo roda uma vez por carregamento da página pública
(`publicBudgetPayload()`), reaproveitando o mesmo caminho que o relatório
Anexo X já percorre: meses fechados vêm de `anexo_x_fechamentos` (uma leitura
congelada), só o mês corrente recalcula de verdade. Sem cache dedicado — o
volume de acessos à página pública de um orçamento não justifica a
complexidade extra, e o try/catch já garante que uma apuração cara nunca vira
travamento.

## Validação

Conferido contra os números reais da empresa via tinker
(`AnexoXService::acumuladoAnual()`): acumulado R$ 7.122,50, limite R$
81.000,00, restante R$ 73.877,50, 8,8%, faixa `dentro` — bate exatamente com
o que a tela do Anexo X mostra, confirmando que não há divergência entre as
duas fontes.

- 3 testes novos em `BudgetMaintenanceLevelsTest`: selo aparece (orçamento
  marcado + `AnexoXService` mockado sem limite atingido), selo some no limite
  (mesmo orçamento, mock com limite atingido) e selo some sem marcação (mock
  nem chega a ser chamado — `&&` do PHP faz curto-circuito antes).
- Coluna espelhada em `tests/Concerns/BuildsLegacyErpSchema.php` (a suíte usa
  schema replicado, não migrations reais).
- Regressão: `BudgetMaintenanceLevelsTest` (23 passando, 3 falhas
  pré-existentes do fluxo de aprovação/sessão, não relacionadas),
  `BudgetFlowTest`, `BudgetCommercialTermsTest`, `OrcamentoNiveisTest`
  (desktop) — sem novas falhas.

## O que isto NÃO faz

- Não emite a NFS-e — só anuncia a intenção da assistência. A emissão em si
  já existe em outro fluxo (`documentacao/07-novas-implementacoes/
  2026-09-09-emissao-automatica-nfse-ambiente-nacional.md`), sem ligação
  direta com este orçamento.
- Não aparece no PDF do orçamento, só na landing pública (trust-strip).
- Não tem override por opção de manutenção, de propósito — ver "Um campo,
  sem dimensão por nível" acima.
- Não cacheia o resumo do MEI: cada carregamento do formulário de orçamento e
  da página pública recalcula.
