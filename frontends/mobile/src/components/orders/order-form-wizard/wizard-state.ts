import type {
  ClientSearchResult,
  ClientUpdatePayload,
  CreateOrderPayload,
  DeliveryLeadDays,
  EntryChecklistAnswerPayload,
  EntryChecklistModel,
  EntryChecklistPayload,
  EntryChecklistResponseStatus,
  EquipmentSearchResult,
  EquipmentUpdatePayload,
  LinkableBudget,
  NovoClientePayload,
  NovoEquipamentoPayload,
  OrderDetail,
  OrderPriority,
  UpdateOrderPayload,
} from '@/lib/types';

export type WizardMode = 'create' | 'edit';

export interface ChecklistAnswerState {
  status: EntryChecklistResponseStatus;
  observacao: string;
}

export interface WizardFormState {
  cliente: ClientSearchResult | null;
  pendingNewClient: NovoClientePayload | null;
  pendingClientUpdate: ClientUpdatePayload | null;

  equipamento: EquipmentSearchResult | null;
  pendingNewEquipment: NovoEquipamentoPayload | null;
  pendingEquipmentUpdate: EquipmentUpdatePayload | null;
  pendingNewEquipmentPhotos: File[];

  checklistModel: EntryChecklistModel | null;
  checklistAnswers: Record<number, ChecklistAnswerState>;
  checklistObservacoesEstado: string;

  relatoCliente: string;
  acessorios: string;

  prioridade: OrderPriority;
  prazoEntregaDias: DeliveryLeadDays | null;
  dataPrevisao: string;
  tecnicoId: number | null;
  tecnicoLabel: string | null;
  observacoesInternas: string;

  fotos: File[];

  enviarPdfCliente: boolean;
  orcamentoVinculado: LinkableBudget | null;
}

export function createInitialWizardState(): WizardFormState {
  return {
    cliente: null,
    pendingNewClient: null,
    pendingClientUpdate: null,
    equipamento: null,
    pendingNewEquipment: null,
    pendingEquipmentUpdate: null,
    pendingNewEquipmentPhotos: [],
    checklistModel: null,
    checklistAnswers: {},
    checklistObservacoesEstado: '',
    relatoCliente: '',
    acessorios: '',
    prioridade: 'normal',
    prazoEntregaDias: null,
    dataPrevisao: '',
    tecnicoId: null,
    tecnicoLabel: null,
    observacoesInternas: '',
    fotos: [],
    enviarPdfCliente: false,
    orcamentoVinculado: null,
  };
}

export function createWizardStateFromOrder(order: OrderDetail): WizardFormState {
  const base = createInitialWizardState();

  return {
    ...base,
    cliente: order.cliente
      ? {
          id: order.cliente.id,
          tipo_pessoa: '',
          nome_razao: order.cliente.nome_razao,
          cpf_cnpj: order.cliente.cpf_cnpj,
          nome_contato: order.cliente.nome_contato,
          orders_count: 0,
          equipments_count: 0,
          email: order.cliente.email,
          telefone1: order.cliente.telefone1,
          telefone_contato: order.cliente.telefone_contato,
          cidade: order.cliente.cidade,
          uf: order.cliente.uf,
          status_cadastro: '',
        }
      : null,
    equipamento: order.equipamento
      ? {
          id: order.equipamento.id,
          cliente_id: order.equipamento.cliente_id,
          cliente_nome: order.cliente_nome,
          tipo_id: order.equipamento.tipo_id,
          tipo_nome: order.equipamento_tipo_nome ?? '',
          marca_nome: '',
          modelo_nome: '',
          resumo_tecnico: order.equipamento.resumo_tecnico,
          numero_serie: order.equipamento.numero_serie,
          imei: order.equipamento.imei,
          desktop_modalidade: order.equipamento.desktop_modalidade,
          status_operacional: '',
          orders_count: 0,
          primary_photo_id: null,
          primary_photo_url: null,
        }
      : null,
    relatoCliente: order.relato_cliente ?? '',
    acessorios: order.acessorios ?? '',
    prioridade: (order.prioridade as OrderPriority) || 'normal',
    dataPrevisao: order.data_previsao ?? '',
    tecnicoId: order.tecnico?.id ?? null,
    tecnicoLabel: order.tecnico?.nome ?? null,
    observacoesInternas: order.observacoes_internas ?? '',
  };
}

export function resolveEquipmentTypeId(state: WizardFormState): number | null {
  if (state.equipamento) {
    return state.equipamento.tipo_id;
  }

  if (state.pendingNewEquipment) {
    return state.pendingNewEquipment.tipo_id;
  }

  return null;
}

export function selectClientForWizard(
  state: WizardFormState,
  cliente: ClientSearchResult | null
): WizardFormState {
  if (state.cliente?.id === cliente?.id) {
    return { ...state, cliente };
  }

  return {
    ...state,
    cliente,
    pendingClientUpdate: null,
    equipamento: null,
    pendingNewEquipment: null,
    pendingEquipmentUpdate: null,
    pendingNewEquipmentPhotos: [],
    checklistModel: null,
    checklistAnswers: {},
  };
}

export function selectEquipmentForWizard(
  state: WizardFormState,
  equipamento: EquipmentSearchResult | null
): WizardFormState {
  if (equipamento && state.cliente?.id !== equipamento.cliente_id) {
    return state;
  }

  if (state.equipamento?.id === equipamento?.id) {
    return { ...state, equipamento };
  }

  return { ...state, equipamento, pendingEquipmentUpdate: null };
}

function buildChecklistPayload(state: WizardFormState): EntryChecklistPayload | undefined {
  const items = state.checklistModel?.itens ?? [];

  if (items.length === 0) {
    return undefined;
  }

  const respostas: EntryChecklistAnswerPayload[] = items.map((item) => {
    const answer = state.checklistAnswers[item.id];
    const status: EntryChecklistResponseStatus = answer?.status ?? 'nao_verificado';

    return {
      checklist_item_id: item.id,
      status,
      observacao: status === 'discrepancia' ? answer?.observacao?.trim() || null : null,
    };
  });

  return {
    observacoes_estado: state.checklistObservacoesEstado.trim() || null,
    respostas,
  };
}

export function isChecklistComplete(state: WizardFormState): boolean {
  const items = state.checklistModel?.itens ?? [];

  return items.every((item) => {
    const answer = state.checklistAnswers[item.id];

    if (!answer) {
      return false;
    }

    if (answer.status === 'discrepancia') {
      return answer.observacao.trim() !== '';
    }

    return true;
  });
}

export function isWizardClientComplete(
  cliente: ClientSearchResult | null,
  pendingNewClient: NovoClientePayload | null
): boolean {
  if (cliente) {
    return true;
  }

  return Boolean(pendingNewClient?.nome_razao.trim() && pendingNewClient?.telefone1.trim());
}

export function isWizardEquipmentComplete(
  equipamento: EquipmentSearchResult | null,
  pendingNewEquipment: NovoEquipamentoPayload | null,
  pendingNewEquipmentPhotos: File[]
): boolean {
  if (equipamento) {
    return true;
  }

  return Boolean(
    pendingNewEquipment?.tipo_id &&
      pendingNewEquipment?.marca_id &&
      pendingNewEquipment?.modelo_id &&
      pendingNewEquipmentPhotos.length >= 1
  );
}

export function isWizardDetailsComplete(relatoCliente: string): boolean {
  return relatoCliente.trim().length >= 5;
}

export function isWizardOperationsComplete(
  tecnicoId: number | null,
  prazoEntregaDias: DeliveryLeadDays | null,
  dataPrevisao: string
): boolean {
  return tecnicoId !== null && prazoEntregaDias !== null && dataPrevisao !== '';
}

export function areWizardRequiredFieldsComplete(state: WizardFormState): boolean {
  return (
    (!state.pendingClientUpdate ||
      Boolean(state.pendingClientUpdate.nome_razao.trim() && state.pendingClientUpdate.telefone1.trim())) &&
    isWizardClientComplete(state.cliente, state.pendingNewClient) &&
    (!state.pendingEquipmentUpdate ||
      Boolean(
        state.pendingEquipmentUpdate.tipo_id &&
        state.pendingEquipmentUpdate.marca_id &&
        state.pendingEquipmentUpdate.modelo_id
      )) &&
    isWizardEquipmentComplete(
      state.equipamento,
      state.pendingNewEquipment,
      state.pendingNewEquipmentPhotos
    ) &&
    isChecklistComplete(state) &&
    isWizardDetailsComplete(state.relatoCliente) &&
    isWizardOperationsComplete(state.tecnicoId, state.prazoEntregaDias, state.dataPrevisao)
  );
}

export function isWizardDirty(state: WizardFormState): boolean {
  return Boolean(
      state.cliente ||
      state.pendingNewClient ||
      state.pendingClientUpdate ||
      state.equipamento ||
      state.pendingNewEquipment ||
      state.pendingEquipmentUpdate ||
      state.pendingNewEquipmentPhotos.length > 0 ||
      Object.keys(state.checklistAnswers).length > 0 ||
      state.checklistObservacoesEstado.trim() ||
      state.relatoCliente.trim() ||
      state.acessorios.trim() ||
      state.prioridade !== 'normal' ||
      state.prazoEntregaDias ||
      state.dataPrevisao ||
      state.tecnicoId ||
      state.observacoesInternas.trim() ||
      state.fotos.length > 0 ||
      state.enviarPdfCliente ||
      state.orcamentoVinculado
  );
}

export function buildOrderPayload(state: WizardFormState, mode: 'create', idempotencyKey: string): CreateOrderPayload;
export function buildOrderPayload(state: WizardFormState, mode: 'edit', idempotencyKey?: string): UpdateOrderPayload;
export function buildOrderPayload(
  state: WizardFormState,
  mode: WizardMode,
  idempotencyKey: string = ''
): CreateOrderPayload | UpdateOrderPayload {
  const checklistPayload = buildChecklistPayload(state);

  if (mode === 'create') {
    if (state.prazoEntregaDias === null) {
      throw new Error('Selecione o prazo de entrega antes de criar a OS.');
    }

    const payload: CreateOrderPayload = {
      idempotency_key: idempotencyKey,
      relato_cliente: state.relatoCliente.trim(),
      prazo_entrega_dias: state.prazoEntregaDias,
      enviar_pdf_cliente: state.enviarPdfCliente,
    };

    if (state.cliente) {
      payload.cliente_id = state.cliente.id;
      if (state.pendingClientUpdate) {
        payload.cliente_atualizacao = state.pendingClientUpdate;
      }
    } else if (state.pendingNewClient) {
      payload.novo_cliente = state.pendingNewClient;
    }

    if (state.equipamento) {
      payload.equipamento_id = state.equipamento.id;
      if (state.pendingEquipmentUpdate) {
        payload.equipamento_atualizacao = state.pendingEquipmentUpdate;
      }
    } else if (state.pendingNewEquipment) {
      payload.novo_equipamento = state.pendingNewEquipment;
    }

    if (state.orcamentoVinculado) {
      payload.orcamento_id = state.orcamentoVinculado.id;
    }

    if (state.tecnicoId) {
      payload.tecnico_id = state.tecnicoId;
    }

    if (state.prioridade) {
      payload.prioridade = state.prioridade;
    }

    if (state.acessorios.trim()) {
      payload.acessorios = state.acessorios.trim();
    }

    if (state.observacoesInternas.trim()) {
      payload.observacoes_internas = state.observacoesInternas.trim();
    }

    if (state.dataPrevisao) {
      payload.data_previsao = state.dataPrevisao;
    }

    if (checklistPayload) {
      payload.checklist_entrada = checklistPayload;
    }

    return payload;
  }

  const payload: UpdateOrderPayload = {};

  if (state.cliente) {
    payload.cliente_id = state.cliente.id;
  }

  if (state.equipamento) {
    payload.equipamento_id = state.equipamento.id;
  }

  if (state.tecnicoId) {
    payload.tecnico_id = state.tecnicoId;
  }

  if (state.prioridade) {
    payload.prioridade = state.prioridade;
  }

  if (state.prazoEntregaDias) {
    payload.prazo_entrega_dias = state.prazoEntregaDias;
  }

  if (state.relatoCliente.trim()) {
    payload.relato_cliente = state.relatoCliente.trim();
  }

  if (state.acessorios.trim()) {
    payload.acessorios = state.acessorios.trim();
  }

  if (state.observacoesInternas.trim()) {
    payload.observacoes_internas = state.observacoesInternas.trim();
  }

  if (state.dataPrevisao) {
    payload.data_previsao = state.dataPrevisao;
  }

  if (checklistPayload) {
    payload.checklist_entrada = checklistPayload;
  }

  return payload;
}
