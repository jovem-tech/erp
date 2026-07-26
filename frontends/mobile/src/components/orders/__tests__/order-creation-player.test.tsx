import { useMemo } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
  OrderCreationPlayer,
  OrderCreationPlayerProvider,
  useOrderCreationPlayer,
  type OrderCreationPlayerController,
} from '@/components/orders/order-creation-player';

const mocks = vi.hoisted(() => ({
  push: vi.fn(),
}));

vi.mock('next/navigation', () => ({
  useRouter: () => ({
    push: mocks.push,
  }),
}));

function PlayerHarness({
  controller,
}: {
  controller: OrderCreationPlayerController;
}) {
  const stableController = useMemo(() => controller, [controller]);
  useOrderCreationPlayer(stableController);

  return <OrderCreationPlayer />;
}

describe('OrderCreationPlayer', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('connects the five actions to the wizard controller', async () => {
    const user = userEvent.setup();
    const onBack = vi.fn();
    const onNext = vi.fn();
    const onSave = vi.fn();

    render(
      <OrderCreationPlayerProvider>
        <PlayerHarness
          controller={{
            canGoBack: true,
            canGoNext: true,
            canSave: true,
            busy: false,
            dirty: false,
            onBack,
            onNext,
            onSave,
          }}
        />
      </OrderCreationPlayerProvider>
    );

    const player = await screen.findByRole('navigation', { name: 'Controles da criação da OS' });
    await user.click(within(player).getByRole('button', { name: 'Voltar' }));
    await user.click(within(player).getByRole('button', { name: 'Próximo' }));
    await user.click(within(player).getByRole('button', { name: 'Salvar' }));
    await user.click(within(player).getByRole('button', { name: 'Início' }));

    expect(onBack).toHaveBeenCalledOnce();
    expect(onNext).toHaveBeenCalledOnce();
    expect(onSave).toHaveBeenCalledOnce();
    expect(mocks.push).toHaveBeenCalledWith('/');
  });

  it('confirms data loss before leaving a dirty creation', async () => {
    const user = userEvent.setup();
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);

    render(
      <OrderCreationPlayerProvider>
        <PlayerHarness
          controller={{
            canGoBack: false,
            canGoNext: false,
            canSave: false,
            busy: false,
            dirty: true,
            onBack: vi.fn(),
            onNext: vi.fn(),
            onSave: vi.fn(),
          }}
        />
      </OrderCreationPlayerProvider>
    );

    const cancel = await screen.findByRole('button', { name: 'Cancelar' });
    await user.click(cancel);
    expect(mocks.push).not.toHaveBeenCalled();

    await user.click(cancel);
    expect(confirm).toHaveBeenCalledTimes(2);
    expect(mocks.push).toHaveBeenCalledWith('/os');
  });
});
