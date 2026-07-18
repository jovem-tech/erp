# Quickstart: Gestão de contas financeiras

1. Criar as contas Caixa, Inter, TOM e Reserva.
2. Informar a data de início e o saldo existente ao final do dia anterior.
3. Mapear Dinheiro → Caixa, Pix/Transferência/Boleto → Inter e Cartões → TOM.
4. Em novas baixas, revisar a conta preenchida automaticamente.
5. Confirmar créditos da TOM apenas quando efetivamente liberados.
6. Registrar TOM → Inter e Inter → Reserva como transferências internas.
7. No fechamento mensal, comparar o saldo do ERP com a contagem física/extratos; diferenças viram ajustes justificados.

## Validação técnica

```bash
cd backend
php artisan migrate --force
php artisan test --filter=FinanceiroContaTest
php artisan test --filter=FinanceiroTest
php artisan test --filter=FinanceiroReportTest
php artisan test --filter=OrderFlowTest

cd ../frontends/desktop
LOG_CHANNEL=stderr php artisan test --filter=FinanceiroContaTest
php artisan test --filter=FinanceiroReportTest
```
