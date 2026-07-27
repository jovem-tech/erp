import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StepReview } from '@/components/orders/order-form-wizard/steps/step-review';

describe('StepReview', () => {
  it('marca o card como verificado e mantém o salvamento bloqueado enquanto houver pendência', async () => {
    const user = userEvent.setup();
    const onVerifySection = vi.fn();

    const { container } = render(
      <StepReview
        sections={[
          {
            key: 'cliente',
            title: 'Cliente',
            stepIndex: 0,
            rows: [{ label: 'Cliente', value: 'Maria' }],
            verified: true,
          },
          {
            key: 'equipamento',
            title: 'Equipamento',
            stepIndex: 1,
            rows: [{ label: 'Equipamento', value: 'Notebook' }],
            verified: false,
          },
        ]}
        onEditSection={vi.fn()}
        onVerifySection={onVerifySection}
        onSubmit={vi.fn()}
        busy={false}
        submitLabel="Criar OS"
        errorMessage={null}
        submitDisabled
      />
    );

    expect(container.querySelectorAll('.review-card--verified')).toHaveLength(1);
    expect(screen.getByRole('button', { name: 'Criar OS' })).toBeDisabled();

    await user.click(screen.getByRole('button', { name: 'Verificar' }));
    expect(onVerifySection).toHaveBeenCalledWith('equipamento');
  });
});
