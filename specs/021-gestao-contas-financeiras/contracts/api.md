# API Contract: Contas financeiras

Todas as rotas usam `/api/v1`, autenticação Sanctum e o módulo RBAC independente `contas_saldos`.

- consultas exigem `contas_saldos:visualizar`;
- criação de conta exige `contas_saldos:criar`;
- edição, conciliação, transferência, cancelamento e confirmação de crédito exigem `contas_saldos:editar`.

- `GET /financeiro/contas?mes=YYYY-MM`: posição, fechamento, pendências e defaults.
- `GET /financeiro/contas/relatorios/consolidado?mes=YYYY-MM`: reconciliação patrimonial geral e por conta, incluindo cartão líquido a receber na data final do período.
- `POST /financeiro/contas`: cria conta e saldo inicial.
- `PATCH /financeiro/contas/{conta}`: altera metadados, atividade e defaults.
- `GET /financeiro/contas/{conta}/extrato?inicio=YYYY-MM-DD&fim=YYYY-MM-DD&page=1`: extrato paginado.
- `POST /financeiro/contas/{conta}/ajustes`: ajuste auditável sem DRE.
- `POST /financeiro/contas/transferencias`: transferência atômica.
- `POST /financeiro/contas/transferencias/{transferencia}/cancelar`: cancelamento com motivo.
- `POST /financeiro/contas/cartoes/{movimento}/confirmar`: confirma data de crédito líquido.

Payloads de baixa existentes ganham `conta_financeira_id` nullable para retrocompatibilidade. Quando há contas ativas, a regra de negócio exige conta explícita ou default resolvível.
