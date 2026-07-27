import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StepClient, isStepClientValid } from '@/components/orders/order-form-wizard/steps/step-client';
import type { ClientSearchResult } from '@/lib/types';

vi.mock('@/lib/orders', () => ({
  searchClients: vi.fn().mockResolvedValue([]),
  getClientDetail: vi.fn().mockResolvedValue({
    id: 1,
    tipo_pessoa: 'fisica',
    nome_razao: 'João Silva',
    telefone1: '11999999999',
    status_cadastro: 'completo',
  }),
}));

function buildClient(): ClientSearchResult {
  return {
    id: 1,
    tipo_pessoa: 'fisica',
    nome_razao: 'João Silva',
    cpf_cnpj: '',
    nome_contato: '',
    orders_count: 0,
    equipments_count: 0,
    email: '',
    telefone1: '11999999999',
    telefone_contato: '',
    cidade: '',
    uf: '',
    status_cadastro: 'completo',
  };
}

describe('StepClient', () => {
  it('mostra a busca por padrão e alterna para o cadastro de cliente novo', async () => {
    const user = userEvent.setup();
    const onSelectCliente = vi.fn();
    const onChangePendingNewClient = vi.fn();

    render(
      <StepClient
        mode="create"
        cliente={null}
        pendingNewClient={null}
        onSelectCliente={onSelectCliente}
        onChangePendingNewClient={onChangePendingNewClient}
      />
    );

    expect(screen.getByPlaceholderText('Nome, telefone, e-mail ou CPF/CNPJ')).toBeInTheDocument();

    await user.click(screen.getByText('Cliente novo'));

    expect(onSelectCliente).toHaveBeenCalledWith(null);
    expect(onChangePendingNewClient).toHaveBeenCalledWith({ nome_razao: '', telefone1: '' });
    expect(screen.getByText('Nome / razão social *')).toBeInTheDocument();
  });

  it('no modo edição não oferece a opção de cliente novo', () => {
    render(
      <StepClient
        mode="edit"
        cliente={buildClient()}
        pendingNewClient={null}
        onSelectCliente={vi.fn()}
        onChangePendingNewClient={vi.fn()}
      />
    );

    expect(screen.queryByText('Cliente novo')).not.toBeInTheDocument();
    expect(screen.getByText('João Silva')).toBeInTheDocument();
  });

  it('atualiza um campo do cliente novo preservando os demais', async () => {
    const user = userEvent.setup();
    const onChangePendingNewClient = vi.fn();

    render(
      <StepClient
        mode="create"
        cliente={null}
        pendingNewClient={{ nome_razao: '', telefone1: '11988887777' }}
        onSelectCliente={vi.fn()}
        onChangePendingNewClient={onChangePendingNewClient}
      />
    );

    await user.click(screen.getByText('Cliente novo'));
    await user.type(screen.getByLabelText('Nome / razão social *'), 'M');

    expect(onChangePendingNewClient).toHaveBeenCalledWith({ nome_razao: 'M', telefone1: '11988887777' });
  });

  it('carrega a edição local do cliente existente sem chamar uma API de atualização', async () => {
    const user = userEvent.setup();
    const onChangePendingClientUpdate = vi.fn();

    render(
      <StepClient
        mode="create"
        cliente={buildClient()}
        pendingNewClient={null}
        onSelectCliente={vi.fn()}
        onChangePendingNewClient={vi.fn()}
        onChangePendingClientUpdate={onChangePendingClientUpdate}
        canEditExisting
      />
    );

    await user.click(screen.getByRole('button', { name: 'Editar' }));

    expect(await screen.findByText('Editar cliente selecionado')).toBeInTheDocument();
    expect(onChangePendingClientUpdate).toHaveBeenCalledWith(
      expect.objectContaining({
        nome_razao: 'João Silva',
        telefone1: '11999999999',
      })
    );
  });
});

describe('isStepClientValid', () => {
  it('é válido quando há cliente existente selecionado', () => {
    expect(isStepClientValid(buildClient(), null)).toBe(true);
  });

  it('é válido quando o cliente novo tem nome e telefone', () => {
    expect(isStepClientValid(null, { nome_razao: 'Maria', telefone1: '11999999999' })).toBe(true);
  });

  it('é inválido sem cliente nem dados mínimos do cliente novo', () => {
    expect(isStepClientValid(null, null)).toBe(false);
    expect(isStepClientValid(null, { nome_razao: 'Maria', telefone1: '' })).toBe(false);
  });
});
