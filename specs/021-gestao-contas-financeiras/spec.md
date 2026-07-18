# Feature Specification: Gestão de contas financeiras e disponibilidade de caixa

**Feature Branch**: `develop`

**Created**: 2026-07-18

**Status**: Complete

**Input**: controlar automaticamente quanto existe em Caixa, Banco Inter, adquirente TOM e contas de reserva, separando saldo disponível, recebíveis de cartão, faturamento e transferências internas.

## User Scenarios & Testing

### User Story 1 - Consultar a posição financeira real (Priority: P1)

Como gerente, quero ver o saldo disponível em cada conta e os recebíveis de cartão ainda não liquidados para deixar de somar extratos manualmente.

**Why this priority**: é a necessidade operacional central e evita decisões com base em faturamento confundido com dinheiro disponível.

**Independent Test**: criar Caixa, Inter e TOM com saldos iniciais e confirmar que o painel apresenta saldos individualizados, total disponível, cartão a receber e posição total.

**Acceptance Scenarios**:

1. **Given** contas com saldos iniciais, **When** o painel é aberto, **Then** cada saldo e o total disponível são calculados sem gerar receita ou despesa na DRE.
2. **Given** um recebimento em cartão ainda não creditado, **When** o painel é aberto, **Then** o valor líquido de taxa aparece como “a receber”, não como disponível.
3. **Given** um crédito de cartão confirmado, **When** o painel é atualizado, **Then** o valor líquido deixa de ser previsto e passa ao saldo disponível da conta indicada.

---

### User Story 2 - Alimentar contas a partir das baixas (Priority: P1)

Como operador financeiro, quero indicar a conta em cada baixa para que Pix, dinheiro, cartão e pagamentos atualizem automaticamente a tesouraria.

**Why this priority**: sem integração com as baixas o painel voltaria a depender de lançamento manual duplicado.

**Independent Test**: baixar títulos e uma OS usando formas diferentes e conferir que os valores são atribuídos às contas padrão ou explicitamente selecionadas.

**Acceptance Scenarios**:

1. **Given** Pix mapeado para Inter, **When** uma receita Pix é baixada, **Then** o Inter recebe a entrada automaticamente.
2. **Given** Dinheiro mapeado para Caixa, **When** uma receita em dinheiro é baixada, **Then** o Caixa recebe a entrada automaticamente.
3. **Given** cartão mapeado para TOM, **When** o cliente paga em cartão, **Then** a TOM recebe um crédito previsto pelo valor líquido da taxa.
4. **Given** uma despesa, **When** ela é baixada contra uma conta, **Then** o saldo disponível dessa conta diminui.
5. **Given** contas ativas e nenhuma conta selecionada ou padrão para a forma, **When** a baixa é tentada, **Then** o backend rejeita a operação sem criar efeitos parciais.

---

### User Story 3 - Transferir dinheiro sem duplicar faturamento (Priority: P1)

Como gerente, quero transferir valores entre Caixa, Inter, TOM e Reserva para organizar o caixa sem criar nova receita ou despesa.

**Independent Test**: transferir da TOM para o Inter e do Inter para Reserva, confirmando que os saldos individuais mudam, o total disponível não muda e as DREs permanecem iguais.

**Acceptance Scenarios**:

1. **Given** duas contas ativas, **When** uma transferência válida é registrada, **Then** a saída e a entrada são criadas atomicamente.
2. **Given** a mesma conta como origem e destino, **When** a transferência é enviada, **Then** o backend rejeita a operação.
3. **Given** uma transferência cancelada com motivo, **When** o painel é atualizado, **Then** os dois lados deixam de compor os saldos e a auditoria é preservada.

---

### User Story 4 - Conciliar e fechar o mês (Priority: P2)

Como gerente, quero comparar saldo inicial, entradas, saídas, transferências e saldo final por conta no mês para decidir quanto manter como caixa operacional e quanto transferir à reserva.

**Independent Test**: selecionar um mês com movimentos e conferir a equação de fechamento em cada conta e no consolidado.

**Acceptance Scenarios**:

1. **Given** movimentos no período, **When** o mês é selecionado, **Then** o sistema mostra saldo inicial, entradas, saídas e saldo final por conta.
2. **Given** divergência de extrato ou contagem física, **When** um ajuste com justificativa é lançado, **Then** o saldo é corrigido sem impacto na DRE e com autor/data preservados.
3. **Given** movimentos existentes sem conta financeira, **When** o painel é aberto, **Then** há um alerta de itens não classificados para evitar falsa sensação de conciliação.

### Edge Cases

- Conta não pode ser desativada com saldo diferente de zero ou recebíveis pendentes.
- Saldo inicial vale para o fechamento do dia anterior à data de início do controle; movimentos anteriores não podem ser atribuídos à conta.
- Cartão cancelado ou baixa estornada deixa de compor saldos porque a posição é derivada do movimento financeiro vigente.
- Taxa de cartão não pode ser abatida duas vezes: a tesouraria usa diretamente `valor_liquido` do cartão.
- Transferência concorrente deve bloquear as contas e validar o saldo da origem dentro da mesma transação.
- Valores monetários usam `DECIMAL`, arredondamento em centavos e nunca `float` no armazenamento.

## Requirements

### Functional Requirements

- **FR-001**: O sistema MUST cadastrar contas dos tipos Caixa, Banco, Adquirente, Reserva, Carteira digital e Outra.
- **FR-002**: Cada conta MUST possuir data de início do controle, saldo inicial auditável, status ativo e indicação se compõe o disponível.
- **FR-003**: O sistema MUST mapear uma conta padrão por forma de pagamento.
- **FR-004**: `financeiro_movimentos` MUST aceitar uma conta financeira opcional para compatibilidade com o histórico anterior.
- **FR-005**: Após existirem contas ativas, novas baixas MUST resolver uma conta explícita ou padrão; ausência MUST falhar antes do commit.
- **FR-006**: Receitas e despesas imediatas MUST compor o saldo pelo valor bruto realizado na data do movimento.
- **FR-007**: Receitas em cartão MUST compor “a receber” pelo valor líquido até a confirmação do crédito e só então compor o disponível.
- **FR-008**: Transferências e ajustes MUST ser externos à DRE e ao faturamento.
- **FR-009**: Transferências MUST criar os dois lados atomicamente e impedir saldo insuficiente na origem.
- **FR-010**: Cancelamentos MUST preservar motivo, autor e horário e retirar os dois lados do cálculo.
- **FR-011**: O painel MUST exibir posição por conta, total disponível, recebíveis, posição total, fechamento mensal e movimentos não classificados.
- **FR-012**: O extrato MUST ser filtrável por período e paginado.
- **FR-013**: O painel e o extrato MUST respeitar `contas_saldos:visualizar`, o cadastro MUST respeitar `contas_saldos:criar` e as demais mutações MUST respeitar `contas_saldos:editar`, de forma independente do módulo `financeiro`.
- **FR-014**: A API e o desktop MUST continuar funcionando sem contas cadastradas, preservando compatibilidade com o fluxo legado.
- **FR-015**: O fechamento de OS MUST continuar sendo o único fluxo autorizado a aplicar status de encerramento e MUST reaproveitar a regra financeira existente.

### Key Entities

- **Conta financeira**: fonte ou destino real do dinheiro, com tipo, data de início e configuração de disponibilidade.
- **Movimento de tesouraria**: saldo inicial, ajuste ou lado de transferência que não representa faturamento.
- **Transferência financeira**: operação atômica entre duas contas, com trilha de cancelamento.
- **Conta padrão por forma de pagamento**: roteamento automático de Pix, dinheiro, cartões, boleto e transferência.
- **Movimento financeiro existente**: baixa de título/OS que passa a referenciar a conta real sem mudar sua semântica de DRE.

## Security and Performance

- O backend central resolve conta, saldo e atomicidade; ids enviados pelo navegador nunca são confiados sem validação.
- Queries monetárias são agregadas no banco e indexadas por conta/data/status.
- Extratos são paginados e o intervalo padrão é mensal.
- Ajustes e cancelamentos exigem justificativa; saldo não é editado diretamente.
- Transferências usam transação e `lockForUpdate` para mitigar corrida e gasto duplicado.
- Nenhuma tabela ou endpoint é público; todas as rotas permanecem sob Sanctum e RBAC.

## Success Criteria

- **SC-001**: O painel reproduz `saldo final = saldo inicial + entradas - saídas + transferências recebidas - transferências enviadas` em todos os testes.
- **SC-002**: Uma venda de cartão de R$ 1.000 com R$ 30 de taxa apresenta R$ 970 a receber e nunca R$ 1.000 disponíveis.
- **SC-003**: Uma transferência altera as contas de origem/destino e mantém o total consolidado e as DREs inalterados.
- **SC-004**: Baixas de Financeiro e OS possuem testes de conta explícita, conta padrão, cartão líquido e ausência de mapeamento.
- **SC-005**: O painel e formulários permanecem utilizáveis nos breakpoints definidos pela constituição.

## Assumptions

- A primeira entrega não importa OFX nem integra APIs do Inter/TOM; a confirmação do crédito e a conciliação são assistidas pelo usuário.
- Dados anteriores à data de início são consolidados no saldo inicial; não haverá inferência retroativa por forma de pagamento.
- A conta Reserva continua pertencendo à empresa. Distribuição para conta pessoal do sócio depende de classificação contábil definida com o contador.
- O mobile não ganha tela de tesouraria nesta entrega, mas continua consumindo os mesmos contratos de baixa compatíveis.
