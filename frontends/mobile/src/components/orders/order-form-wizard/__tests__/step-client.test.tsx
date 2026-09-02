import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StepClient, isStepClientValid } from '@/components/orders/order-form-wizard/steps/step-client';
import type { ClientSearchResult, LinkableBudget, NovoClientePayload } from '@/lib/types';

vi.mock('@/lib/orders', () => ({
  searchClients: vi.fn().mockResolvedValue([]),
  searchAvulsoBudgetContacts: vi.fn().mockResolvedValue([]),
  listApprovedBudgetsForClient: vi.fn().mockResolvedValue([]),
  isApprovedBudget: (budget: { status?: string } | null | undefined) =>
    Boolean(budget && ['aprovado', 'pendente_abertura_os'].includes(budget.status ?? '')),
  lookupCepAddress: vi.fn().mockResolvedValue({
    cep: '01001-000',
    endereco: 'Praça da Sé',
    bairro: 'Sé',
    cidade: 'São Paulo',
    uf: 'SP',
  }),
  getClientDetail: vi.fn().mockResolvedValue({
    id: 1,
    tipo_pessoa: 'fisica',
    nome_razao: 'João Silva',
    telefone1: '11999999999',
    status_cadastro: 'completo',
  }),
}));

function NewClientHarness({ canLinkBudget = false }: { canLinkBudget?: boolean }) {
  const [pendingNewClient, setPendingNewClient] = useState<NovoClientePayload>({
    nome_razao: '',
    telefone1: '',
  });
  const [linkedBudget, setLinkedBudget] = useState<LinkableBudget | null>(null);

  return (
    <StepClient
      mode="create"
      cliente={null}
      pendingNewClient={pendingNewClient}
      onSelectCliente={vi.fn()}
      onChangePendingNewClient={(payload) => {
        if (payload) {
          setPendingNewClient(payload);
        }
      }}
      linkedBudget={linkedBudget}
      onChangeLinkedBudget={setLinkedBudget}
      canLinkBudget={canLinkBudget}
    />
  );
}

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
  beforeEach(() => {
    vi.clearAllMocks();
  });

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
    expect(screen.getByText('Nome / razão social')).toBeInTheDocument();
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
        telefone1: '(11)99999-9999',
      })
    );
  });

  it('aplica as máscaras de celular e telefone fixo durante a digitação', async () => {
    const user = userEvent.setup();
    render(<NewClientHarness />);

    await user.type(screen.getByLabelText('Telefone *'), '22999999999');
    await user.type(screen.getByLabelText('Telefone secundário'), '2226212621');

    expect(screen.getByLabelText('Telefone *')).toHaveValue('(22)99999-9999');
    expect(screen.getByLabelText('Telefone secundário')).toHaveValue('(22)2621-2621');
  });

  it('busca o CEP completo e preenche o endereço automaticamente', async () => {
    const { lookupCepAddress } = await import('@/lib/orders');
    const user = userEvent.setup();
    render(<NewClientHarness />);

    await user.type(screen.getByLabelText('CEP'), '01001000');

    await waitFor(() => expect(lookupCepAddress).toHaveBeenCalledWith('01001-000'));
    expect(await screen.findByDisplayValue('Praça da Sé')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Sé')).toBeInTheDocument();
    expect(screen.getByDisplayValue('São Paulo')).toBeInTheDocument();
    expect(screen.getByDisplayValue('SP')).toBeInTheDocument();
  });

  it('sugere e permite vincular orçamento aberto do cliente novo sem persistir dados', async () => {
    const { searchAvulsoBudgetContacts } = await import('@/lib/orders');
    vi.mocked(searchAvulsoBudgetContacts).mockResolvedValue([{
      id: 77,
      numero: 'ORC-0077',
      cliente_nome_avulso: 'Márcia Souza',
      telefone_contato: '22988887777',
      email_contato: 'marcia@example.com',
      equipamento_resumo: 'Notebook Dell Inspiron',
      total_formatado: '350,00',
      status: 'aguardando_resposta',
      linkable: true,
    }]);
    const user = userEvent.setup();
    render(<NewClientHarness canLinkBudget />);

    await user.type(screen.getByLabelText('Nome / razão social *'), 'Márcia');

    await waitFor(() => expect(searchAvulsoBudgetContacts).toHaveBeenCalledWith('Márcia'));
    await user.click(await screen.findByRole('button', {
      name: 'ORC-0077 · Notebook Dell Inspiron',
    }));

    expect(screen.getByLabelText('Telefone *')).toHaveValue('(22)98888-7777');
    expect(screen.getByLabelText('E-mail')).toHaveValue('marcia@example.com');

    await user.click(screen.getByRole('button', { name: 'Vincular à OS' }));
    expect(await screen.findByText('Orçamento vinculado à OS')).toBeInTheDocument();
  });

  it('lista os orçamentos aprovados do cliente selecionado e vincula o escolhido', async () => {
    const { listApprovedBudgetsForClient } = await import('@/lib/orders');
    const approvedBudget: LinkableBudget = {
      id: 91,
      numero: 'ORC-0091',
      cliente_id: 1,
      cliente_nome: 'João Silva',
      equipamento_id: 45,
      equipamento_resumo: 'Notebook Acer Aspire',
      total_formatado: '480,00',
      status: 'aprovado',
      status_label: 'Aprovado',
    };
    vi.mocked(listApprovedBudgetsForClient).mockResolvedValue([approvedBudget]);
    const onChangeLinkedBudget = vi.fn();
    const user = userEvent.setup();

    render(
      <StepClient
        mode="create"
        cliente={buildClient()}
        pendingNewClient={null}
        onSelectCliente={vi.fn()}
        onChangePendingNewClient={vi.fn()}
        linkedBudget={null}
        onChangeLinkedBudget={onChangeLinkedBudget}
        canLinkBudget
      />
    );

    await waitFor(() => expect(listApprovedBudgetsForClient).toHaveBeenCalledWith(1));
    expect(await screen.findByText('Orçamentos aprovados deste cliente')).toBeInTheDocument();

    await user.click(screen.getByRole('button', {
      name: 'ORC-0091 · Notebook Acer Aspire · R$ 480,00',
    }));

    expect(onChangeLinkedBudget).toHaveBeenCalledWith(approvedBudget);
  });

  it('avisa que a OS nascerá em Aguardando Reparo quando o orçamento vinculado está aprovado', async () => {
    const { listApprovedBudgetsForClient } = await import('@/lib/orders');
    vi.mocked(listApprovedBudgetsForClient).mockResolvedValue([]);

    render(
      <StepClient
        mode="create"
        cliente={buildClient()}
        pendingNewClient={null}
        onSelectCliente={vi.fn()}
        onChangePendingNewClient={vi.fn()}
        linkedBudget={{
          id: 91,
          numero: 'ORC-0091',
          cliente_id: 1,
          equipamento_id: 45,
          equipamento_resumo: 'Notebook Acer Aspire',
          status: 'aprovado',
        }}
        onChangeLinkedBudget={vi.fn()}
        canLinkBudget
      />
    );

    expect(await screen.findByText('OS a partir do orçamento ORC-0091')).toBeInTheDocument();
    expect(
      screen.getByText('Orçamento já aprovado: a OS será aberta em Aguardando Reparo.')
    ).toBeInTheDocument();
  });

  it('não consulta orçamentos aprovados sem permissão de conversão', async () => {
    const { listApprovedBudgetsForClient } = await import('@/lib/orders');

    render(
      <StepClient
        mode="create"
        cliente={buildClient()}
        pendingNewClient={null}
        onSelectCliente={vi.fn()}
        onChangePendingNewClient={vi.fn()}
      />
    );

    await waitFor(() => expect(listApprovedBudgetsForClient).not.toHaveBeenCalled());
    expect(screen.queryByText('Orçamentos aprovados deste cliente')).not.toBeInTheDocument();
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
    expect(isStepClientValid(null, { nome_razao: 'Maria', telefone1: '(22)9999' })).toBe(false);
  });
});
