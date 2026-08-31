import { describe, expect, it } from 'vitest';
import {
  areWizardRequiredFieldsComplete,
  buildOrderPayload,
  createInitialWizardState,
  isChecklistComplete,
  isWizardDirty,
  isWizardEquipmentComplete,
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
      prazoEntregaDias: 3 as const,
      dataPrevisao: '2026-07-29',
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
      prazoEntregaDias: 3 as const,
      dataPrevisao: '2026-07-29',
    };

    const payload = buildOrderPayload(state, 'create', 'uuid-456');

    expect(payload).toMatchObject({
      novo_cliente: { nome_razao: 'Maria Souza', telefone1: '11988887777' },
      novo_equipamento: { tipo_id: 3, marca_id: 5, modelo_id: 8 },
    });
    expect(payload).not.toHaveProperty('cliente_id');
    expect(payload).not.toHaveProperty('equipamento_id');
  });

  it('inclui alterações locais de cliente e equipamento somente no payload final da OS', () => {
    const state = {
      ...createInitialWizardState(),
      cliente: buildClient(),
      pendingClientUpdate: {
        tipo_pessoa: 'fisica',
        nome_razao: 'João Atualizado',
        telefone1: '11911112222',
        status_cadastro: 'completo',
      },
      equipamento: buildEquipment(),
      pendingEquipmentUpdate: {
        tipo_id: 3,
        marca_id: 5,
        modelo_id: 8,
        numero_serie: 'SERIE-EDITADA',
      },
      relatoCliente: 'Tela quebrada',
      prazoEntregaDias: 7 as const,
      dataPrevisao: '2026-08-02',
    };

    const payload = buildOrderPayload(state, 'create', 'uuid-atomic');

    expect(payload).toMatchObject({
      cliente_id: 10,
      cliente_atualizacao: {
        nome_razao: 'João Atualizado',
      },
      equipamento_id: 20,
      equipamento_atualizacao: {
        numero_serie: 'SERIE-EDITADA',
      },
      prazo_entrega_dias: 7,
      data_previsao: '2026-08-02',
    });
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
      prazoEntregaDias: 3 as const,
      dataPrevisao: '2026-07-29',
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

  it('preserva cor no equipamento novo e acessórios na OS', () => {
    const state = {
      ...createInitialWizardState(),
      pendingNewClient: { nome_razao: 'Maria Souza', telefone1: '11988887777' },
      pendingNewEquipment: { tipo_id: 3, marca_id: 5, modelo_id: 8, cor: 'Preto' },
      relatoCliente: 'Não liga',
      acessorios: 'Carregador, Capa',
      prazoEntregaDias: 3 as const,
      dataPrevisao: '2026-07-29',
    };

    const payload = buildOrderPayload(state, 'create', 'uuid-999');

    expect(payload.novo_equipamento).toMatchObject({ cor: 'Preto' });
    expect(payload.acessorios).toBe('Carregador, Capa');
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
      orcamentoVinculado: {
        id: 99,
        numero: 'ORC-0099',
        status: 'aguardando_resposta',
      },
    };
    expect(isChecklistComplete(state)).toBe(true);
  });
});

describe('isWizardEquipmentComplete', () => {
  it('equipamento já existente é sempre completo, sem checar cor', () => {
    expect(isWizardEquipmentComplete(buildEquipment(), null, [])).toBe(true);
  });

  it('equipamento novo sem cor é incompleto mesmo com tipo/marca/modelo/foto', () => {
    const file = new File(['x'], 'foto.jpg', { type: 'image/jpeg' });
    expect(isWizardEquipmentComplete(null, { tipo_id: 1, marca_id: 1, modelo_id: 1 }, [file])).toBe(false);
  });

  it('equipamento novo com cor preenchida e ao menos 1 foto é completo', () => {
    const file = new File(['x'], 'foto.jpg', { type: 'image/jpeg' });
    expect(
      isWizardEquipmentComplete(null, { tipo_id: 1, marca_id: 1, modelo_id: 1, cor: 'Preto' }, [file])
    ).toBe(true);
  });

  it('cor só com espaços em branco não conta como preenchida', () => {
    const file = new File(['x'], 'foto.jpg', { type: 'image/jpeg' });
    expect(
      isWizardEquipmentComplete(null, { tipo_id: 1, marca_id: 1, modelo_id: 1, cor: '   ' }, [file])
    ).toBe(false);
  });
});

describe('areWizardRequiredFieldsComplete — edição local de equipamento existente', () => {
  it('bloqueia o salvamento quando pendingEquipmentUpdate não tem cor', () => {
    const state = {
      ...createInitialWizardState(),
      cliente: buildClient(),
      equipamento: buildEquipment(),
      pendingEquipmentUpdate: { tipo_id: 3, marca_id: 5, modelo_id: 8 },
      relatoCliente: 'Tela quebrada',
      acessorios: 'Carregador',
      tecnicoId: 7,
      prazoEntregaDias: 3 as const,
      dataPrevisao: '2026-07-29',
    };

    expect(areWizardRequiredFieldsComplete(state)).toBe(false);
    expect(areWizardRequiredFieldsComplete({
      ...state,
      pendingEquipmentUpdate: { ...state.pendingEquipmentUpdate, cor: 'Preto' },
    })).toBe(true);
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
    expect(nextState.orcamentoVinculado).toBeNull();
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

describe('estado do player de criação', () => {
  it('só libera o salvamento quando todos os campos obrigatórios estão completos', () => {
    const incomplete = createInitialWizardState();
    expect(areWizardRequiredFieldsComplete(incomplete)).toBe(false);

    const complete = {
      ...incomplete,
      cliente: buildClient(),
      equipamento: buildEquipment(),
      relatoCliente: 'Tela quebrada',
      acessorios: 'Carregador',
      tecnicoId: 7,
      prazoEntregaDias: 3 as const,
      dataPrevisao: '2026-07-29',
    };

    expect(areWizardRequiredFieldsComplete(complete)).toBe(true);
    expect(areWizardRequiredFieldsComplete({ ...complete, acessorios: '' })).toBe(false);
  });

  it('detecta dados preenchidos para proteger o cancelamento', () => {
    expect(isWizardDirty(createInitialWizardState())).toBe(false);
    expect(isWizardDirty({ ...createInitialWizardState(), relatoCliente: 'Não liga' })).toBe(true);
  });
});
