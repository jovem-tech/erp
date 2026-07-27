import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
  calculateDeliveryDate,
  isStepOperationsValid,
  StepOperations,
} from '@/components/orders/order-form-wizard/steps/step-operations';

vi.mock('@/lib/orders', () => ({
  listTechnicians: vi.fn().mockResolvedValue([{ value: 7, label: 'Técnico A' }]),
  searchLinkableBudgets: vi.fn().mockResolvedValue([]),
}));

describe('StepOperations', () => {
  it('calcula a previsão em dias corridos sem depender do horário do navegador', () => {
    expect(calculateDeliveryDate(7, new Date(2026, 6, 26, 23, 55))).toBe('2026-08-02');
  });

  it('preenche a previsão automaticamente sem exibir a decisão de PDF', async () => {
    const user = userEvent.setup();
    const onChangePrazoEntrega = vi.fn();

    render(
      <StepOperations
        prioridade="normal"
        prazoEntregaDias={null}
        dataPrevisao=""
        tecnicoId={null}
        observacoesInternas=""
        orcamentoVinculado={null}
        canLinkBudget={false}
        onChangePrioridade={vi.fn()}
        onChangePrazoEntrega={onChangePrazoEntrega}
        onChangeTecnico={vi.fn()}
        onChangeObservacoesInternas={vi.fn()}
        onChangeOrcamentoVinculado={vi.fn()}
      />
    );

    await user.selectOptions(screen.getByLabelText('Prazo de entrega (dias corridos) *'), '15');
    expect(onChangePrazoEntrega).toHaveBeenCalledWith(15, calculateDeliveryDate(15));
    expect(screen.queryByRole('checkbox', { name: /Gerar e enviar PDF ao cliente/ })).not.toBeInTheDocument();
  });

  it('exige técnico, prazo e data calculada para avançar', () => {
    expect(isStepOperationsValid(7, null, '')).toBe(false);
    expect(isStepOperationsValid(7, 3, '2026-07-29')).toBe(true);
  });
});
