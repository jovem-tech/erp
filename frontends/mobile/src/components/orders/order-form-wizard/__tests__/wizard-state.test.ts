import { describe, expect, it } from 'vitest';
import {
  buildOrderPayload,
  createInitialWizardState,
  isChecklistComplete,
  selectClientForWizard,
  selectEquipmentForWizard,
} from '@/components/orders/order-form-wizard/wizard-state';
import type { ClientSearchResult, EquipmentSearchResult } from '@/lib/types';

function buildClient(overrides: Partial<ClientSearchResult> = {}): ClientSearchResult {
  return {
    id: 10,
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
    ...overrides,
  };
}

function buildEquipment(overrides: Partial<EquipmentSearchResult> = {}): EquipmentSearchResult {
  return {
    id: 20,
    cliente_id: 10,
    cliente_nome: 'João Silva',
    tipo_id: 3,
    tipo_nome: 'Smartphone',
    marca_nome: 'Samsung',
    modelo_nome: 'A015',
    resumo_tecnico: 'Samsung A015',
    numero_serie: '',
    imei: '',
    desktop_modalidade: '',
    status_operacional: '',
    orders_count: 0,
    primary_photo_id: null,
    primary_photo_url: null,
    ...overrides,
  };
}

describe('buildOrderPayload', () => {
  it('criação com cliente e equipamento já existentes', () => {
    const state = {
      ...createInitialWizardState(),
      cliente: buildClient(),
      equipamento: buildEquipment(),
      relatoCliente: 'Tela quebrada',
      enviarPdfCliente: true,
    };

    const payload = buildOrderPayload(state, 'create', 'uuid-123');

    expect(payload).toMatchObject({
      idempotency_key: 'uuid-123',
      cliente_id: 10,
      equipamento_id: 20,
      relato_cliente: 'Tela quebrada',
      enviar_pdf_cliente: true,
    });
    expect(payload).not.toHaveProperty('novo_cliente');
    expect(payload).not.toHaveProperty('novo_equipamento');
  });

  it('criação com cliente e equipamento novos (cadastro diferido)', () => {
    const state = {
      ...createInitialWizardState(),
      pendingNewClient: { nome_razao: 'Maria Souza', telefone1: '11988887777' },
      pendingNewEquipment: { tipo_id: 3, marca_id: 5, modelo_id: 8 },
      relatoCliente: 'Não liga',
    };

    const payload = buildOrderPayload(state, 'create', 'uuid-456');

    expect(payload).toMatchObject({
      novo_cliente: { nome_razao: 'Maria Souza', telefone1: '11988887777' },
      novo_equipamento: { tipo_id: 3, marca_id: 5, modelo_id: 8 },
    });
    expect(payload).not.toHaveProperty('cliente_id');
    expect(payload).not.toHaveProperty('equipamento_id');
  });

  it('edição não inclui idempotency_key/novo_cliente/novo_equipamento/orcamento_id mesmo que o state os tenha', () => {
    const state = {
      ...createInitialWizardState(),
      cliente: buildClient(),
      equipamento: buildEquipment(),
      pendingNewClient: { nome_razao: 'Não deveria aparecer', telefone1: '0' },
      orcamentoVinculado: { id: 99, numero: 'ORC-1', cliente_nome: '', valor_total: 0, status: '' },
      relatoCliente: 'Atualizado',
    };

    const payload = buildOrderPayload(state, 'edit', 'uuid-ignorado');

    expect(payload).toMatchObject({ cliente_id: 10, equipamento_id: 20, relato_cliente: 'Atualizado' });
    expect(payload).not.toHaveProperty('idempotency_key');
    expect(payload).not.toHaveProperty('novo_cliente');
    expect(payload).not.toHaveProperty('novo_equipamento');
    expect(payload).not.toHaveProperty('orcamento_id');
    expect(payload).not.toHaveProperty('enviar_pdf_cliente');
  });

  it('serializa o checklist de entrada preenchido', () => {
    const state = {
      ...createInitialWizardState(),
      cliente: buildClient(),
      equipamento: buildEquipment(),
      relatoCliente: 'Teste',
      checklistModel: {
        id: 1,
        checklist_tipo_id: 1,
        tipo_equipamento_id: 3,
        nome: 'Entrada padrão',
        descricao: '',
        itens: [
          { id: 100, descricao: 'Tela', ordem: 1 },
          { id: 101, descricao: 'Bateria', ordem: 2 },
        ],
      },
      checklistAnswers: {
        100: { status: 'discrepancia' as const, observacao: 'Risco na tela' },
        101: { status: 'ok' as const, observacao: '' },
      },
    };

    const payload = buildOrderPayload(state, 'create', 'uuid-789');

    expect(payload.checklist_entrada).toEqual({
      observacoes_estado: null,
      respostas: [
        { checklist_item_id: 100, status: 'discrepancia', observacao: 'Risco na tela' },
        { checklist_item_id: 101, status: 'ok', observacao: null },
      ],
    });
  });
});

describe('isChecklistComplete', () => {
  const model = {
    id: 1,
    checklist_tipo_id: 1,
    tipo_equipamento_id: 3,
    nome: 'Entrada padrão',
    descricao: '',
    itens: [{ id: 100, descricao: 'Tela', ordem: 1 }],
  };

  it('retorna false enquanto o item não tem resposta', () => {
    const state = { ...createInitialWizardState(), checklistModel: model };
    expect(isChecklistComplete(state)).toBe(false);
  });

  it('retorna false para discrepância sem observação', () => {
    const state = {
      ...createInitialWizardState(),
      checklistModel: model,
      checklistAnswers: { 100: { status: 'discrepancia' as const, observacao: '' } },
    };
    expect(isChecklistComplete(state)).toBe(false);
  });

  it('retorna true quando todos os itens estão respondidos corretamente', () => {
    const state = {
      ...createInitialWizardState(),
      checklistModel: model,
      checklistAnswers: { 100: { status: 'ok' as const, observacao: '' } },
    };
    expect(isChecklistComplete(state)).toBe(true);
  });
});

describe('consistência entre cliente e equipamento', () => {
  it('limpa equipamento, fotos pendentes e checklist ao trocar o cliente', () => {
    const state = {
      ...createInitialWizardState(),
      cliente: buildClient(),
      equipamento: buildEquipment(),
      pendingNewEquipmentPhotos: [new File(['foto'], 'equipamento.jpg', { type: 'image/jpeg' })],
      checklistModel: {
        id: 1,
        checklist_tipo_id: 1,
        tipo_equipamento_id: 3,
        nome: 'Entrada',
        descricao: '',
        itens: [{ id: 100, descricao: 'Tela', ordem: 1 }],
      },
      checklistAnswers: { 100: { status: 'ok' as const, observacao: '' } },
    };

    const nextState = selectClientForWizard(state, buildClient({ id: 11, nome_razao: 'Maria' }));

    expect(nextState.equipamento).toBeNull();
    expect(nextState.pendingNewEquipmentPhotos).toEqual([]);
    expect(nextState.checklistModel).toBeNull();
    expect(nextState.checklistAnswers).toEqual({});
  });

  it('recusa no estado local um equipamento pertencente a outro cliente', () => {
    const state = {
      ...createInitialWizardState(),
      cliente: buildClient({ id: 10 }),
    };

    expect(selectEquipmentForWizard(state, buildEquipment({ cliente_id: 99 }))).toBe(state);
    expect(selectEquipmentForWizard(state, buildEquipment({ cliente_id: 10 })).equipamento?.id).toBe(20);
  });
});
