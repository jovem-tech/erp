import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StepDetails, isStepDetailsValid } from '@/components/orders/order-form-wizard/steps/step-details';
import type { ReportedDefect } from '@/lib/types';

vi.mock('@/lib/orders', () => ({
  searchReportedDefects: vi.fn().mockResolvedValue([] as ReportedDefect[]),
}));

function ControlledStepDetails({
  requireAcessorios = true,
  initialAcessorios = '',
}: {
  requireAcessorios?: boolean;
  initialAcessorios?: string;
}) {
  const [relatoCliente, setRelatoCliente] = useState('');
  const [acessorios, setAcessorios] = useState(initialAcessorios);

  return (
    <StepDetails
      tipoEquipamentoId={null}
      relatoCliente={relatoCliente}
      acessorios={acessorios}
      onChangeRelatoCliente={setRelatoCliente}
      onChangeAcessorios={setAcessorios}
      requireAcessorios={requireAcessorios}
    />
  );
}

describe('StepDetails', () => {
  it('marca Relato do cliente e Acessórios como obrigatórios por padrão (modo create)', () => {
    render(<ControlledStepDetails />);

    expect(screen.getByRole('textbox', { name: /Relato do cliente/ })).toHaveAttribute('aria-required', 'true');
    expect(screen.getByRole('textbox', { name: /Acessórios/ })).toHaveAttribute('aria-required', 'true');
  });

  it('no modo edição (requireAcessorios=false) acessórios não é marcado como obrigatório', () => {
    render(<ControlledStepDetails requireAcessorios={false} />);

    expect(screen.getByRole('textbox', { name: /Acessórios/ })).toHaveAttribute('aria-required', 'false');
  });

  it('clicar num chip de acessório adiciona o item ao texto', async () => {
    const user = userEvent.setup();
    render(<ControlledStepDetails />);

    await user.click(screen.getByRole('button', { name: 'Carregador' }));
    expect(screen.getByRole('textbox', { name: /Acessórios/ })).toHaveValue('Carregador');

    await user.click(screen.getByRole('button', { name: 'Capa' }));
    expect(screen.getByRole('textbox', { name: /Acessórios/ })).toHaveValue('Carregador, Capa');
  });

  it('clicar de novo no chip já ativo remove o item', async () => {
    const user = userEvent.setup();
    render(<ControlledStepDetails initialAcessorios="Carregador, Capa" />);

    await user.click(screen.getByRole('button', { name: 'Capa' }));
    expect(screen.getByRole('textbox', { name: /Acessórios/ })).toHaveValue('Carregador');
  });

  it('"Nenhum acessório" é exclusivo: substitui o valor e é desfeito por qualquer chip de item', async () => {
    const user = userEvent.setup();
    render(<ControlledStepDetails />);

    await user.click(screen.getByRole('button', { name: 'Nenhum acessório' }));
    expect(screen.getByRole('textbox', { name: /Acessórios/ })).toHaveValue('Nenhum acessório');
    expect(screen.getByRole('button', { name: 'Nenhum acessório' })).toHaveAttribute('aria-pressed', 'true');

    await user.click(screen.getByRole('button', { name: 'Cabo' }));
    expect(screen.getByRole('textbox', { name: /Acessórios/ })).toHaveValue('Cabo');
    expect(screen.getByRole('button', { name: 'Nenhum acessório' })).toHaveAttribute('aria-pressed', 'false');
  });

  it('isStepDetailsValid exige relato e acessórios quando requireAcessorios é true', () => {
    expect(isStepDetailsValid('Tela quebrada', '')).toBe(false);
    expect(isStepDetailsValid('Tela quebrada', 'Carregador')).toBe(true);
    expect(isStepDetailsValid('Tela quebrada', 'Nenhum acessório')).toBe(true);
  });

  it('isStepDetailsValid ignora acessórios quando requireAcessorios é false', () => {
    expect(isStepDetailsValid('Tela quebrada', '', false)).toBe(true);
  });

  it('carrega e exibe os defeitos comuns do tipo de equipamento', async () => {
    const { searchReportedDefects } = await import('@/lib/orders');
    vi.mocked(searchReportedDefects).mockResolvedValueOnce([
      {
        id: 1,
        tipo_equipamento_id: 3,
        tipo_equipamento_nome: 'Smartphone',
        categoria: 'Tela',
        subcategoria: '',
        texto_relato: 'Tela trincada',
        icone: '',
        ordem_exibicao: 1,
        ativo: true,
      },
    ]);

    const user = userEvent.setup();
    const onChangeRelatoCliente = vi.fn();
    render(
      <StepDetails
        tipoEquipamentoId={3}
        relatoCliente=""
        acessorios=""
        onChangeRelatoCliente={onChangeRelatoCliente}
        onChangeAcessorios={vi.fn()}
      />
    );

    await waitFor(() => expect(screen.getByText('Tela trincada')).toBeInTheDocument());
    await user.click(screen.getByText('Tela trincada'));
    expect(onChangeRelatoCliente).toHaveBeenCalledWith('Tela trincada');
  });
});
