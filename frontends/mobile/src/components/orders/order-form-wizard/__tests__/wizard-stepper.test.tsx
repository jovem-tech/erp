import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { WizardStepper } from '@/components/orders/order-form-wizard/wizard-stepper';

const steps = [
  { key: 'cliente', label: 'Cliente' },
  { key: 'equipamento', label: 'Equipamento' },
  { key: 'revisao', label: 'Revisão' },
];

describe('WizardStepper', () => {
  it('marca a etapa atual como ativa e as futuras como pendentes', () => {
    render(<WizardStepper steps={steps} currentIndex={0} maxVisitedIndex={0} onNavigate={vi.fn()} />);

    const tabs = screen.getAllByRole('tab');
    expect(tabs[0]).toHaveClass('wizard-step--active');
    expect(tabs[1]).toHaveClass('wizard-step--pending');
    expect(tabs[1]).toBeDisabled();
  });

  it('permite navegar para uma etapa já visitada, mas não para uma etapa futura', async () => {
    const user = userEvent.setup();
    const onNavigate = vi.fn();

    render(<WizardStepper steps={steps} currentIndex={1} maxVisitedIndex={1} onNavigate={onNavigate} />);

    await user.click(screen.getByText('Cliente'));
    expect(onNavigate).toHaveBeenCalledWith(0);

    onNavigate.mockClear();
    await user.click(screen.getByText('Revisão'));
    expect(onNavigate).not.toHaveBeenCalled();
  });
});
