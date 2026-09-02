import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  apiCreateOrder,
  apiEntryChecklistModel,
  apiEquipmentFormData,
  apiLookupCep,
  apiSearchAvulsoBudgetContacts,
  apiSearchClients,
  apiSearchEquipments,
  apiSearchLinkableBudgets,
  apiSearchReportedDefects,
  apiTechnicians,
  apiUpdateOrder,
} from '@/lib/api';
import type { CreateOrderPayload, UpdateOrderPayload } from '@/lib/types';
import { writeStoredSession } from '@/lib/session';

function jsonResponse(body: unknown, init: { ok?: boolean; status?: number } = {}): Response {
  return {
    ok: init.ok ?? true,
    status: init.status ?? 200,
    headers: new Headers({ 'content-type': 'application/json' }),
    json: async () => body,
  } as Response;
}

function storeFakeSession(): void {
  writeStoredSession({
    accessToken: 'token-abc',
    tokenType: 'Bearer',
    expiresAt: new Date(Date.now() + 60 * 60 * 1000).toISOString(),
    user: {
      id: 1,
      nome: 'Técnico',
      email: 'tecnico@example.com',
      perfil: 'tecnico',
      grupo_id: 1,
      foto: '',
      ativo: true,
      ultimo_acesso: null,
    },
  });
}

describe('funções auxiliares do wizard de OS', () => {
  beforeEach(() => {
    window.localStorage.clear();
    storeFakeSession();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('apiSearchClients monta a query string com o termo de busca', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ status: 'success', data: { clients: [] }, error: null })
    );
    vi.stubGlobal('fetch', fetchMock);

    await apiSearchClients({ search: 'joão' });

    const [url] = fetchMock.mock.calls[0];
    expect(String(url)).toContain('/clients?search=jo%C3%A3o');
  });

  it('apiSearchAvulsoBudgetContacts consulta somente o catálogo de contatos avulsos', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ status: 'success', data: { budgets: [] }, error: null })
    );
    vi.stubGlobal('fetch', fetchMock);

    await apiSearchAvulsoBudgetContacts({ q: 'Márcia Souza', per_page: 8 });

    const [url] = fetchMock.mock.calls[0];
    expect(String(url)).toContain('/orcamentos/contatos-avulsos?q=M%C3%A1rcia+Souza&per_page=8');
  });

  it('apiSearchLinkableBudgets filtra por cliente e por orçamentos aprovados', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ status: 'success', data: { budgets: [] }, error: null })
    );
    vi.stubGlobal('fetch', fetchMock);

    await apiSearchLinkableBudgets({ cliente_id: 42, somente_aprovados: true, per_page: 10 });

    const [url] = fetchMock.mock.calls[0];
    expect(String(url)).toContain('/orcamentos/vinculaveis-os?per_page=10&cliente_id=42&somente_aprovados=1');
  });

  it('apiLookupCep envia somente os oito dígitos normalizados', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        status: 'success',
        data: {
          address: {
            cep: '01001-000',
            endereco: 'Praça da Sé',
            bairro: 'Sé',
            cidade: 'São Paulo',
            uf: 'SP',
          },
        },
        error: null,
      })
    );
    vi.stubGlobal('fetch', fetchMock);

    const result = await apiLookupCep('01001-000');

    expect(result.address.cidade).toBe('São Paulo');
    const [url] = fetchMock.mock.calls[0];
    expect(String(url)).toContain('/clients/cep/01001000');
  });

  it('apiEquipmentFormData desembrulha a chave "form" da resposta', async () => {
    const form = { types: [], brands: [], models: [], desktop_defaults: null, password_modes: [], max_photos: 4 };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      jsonResponse({ status: 'success', data: { form }, error: null })
    ));

    const result = await apiEquipmentFormData();

    expect(result).toEqual(form);
  });

  it('apiSearchEquipments restringe a consulta ao cliente e respeita o limite solicitado', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ status: 'success', data: { equipments: [] }, error: null })
    );
    vi.stubGlobal('fetch', fetchMock);

    await apiSearchEquipments({ clientId: 42, search: '', perPage: 50 });

    const [url] = fetchMock.mock.calls[0];
    expect(String(url)).toContain('/equipments?client_id=42&per_page=50');
  });

  it('apiEntryChecklistModel retorna null quando o tipo de equipamento não tem checklist ativo', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      jsonResponse({ status: 'success', data: { modelo: null }, error: null })
    ));

    const result = await apiEntryChecklistModel(42);

    expect(result.modelo).toBeNull();
  });

  it('apiSearchReportedDefects retorna a lista sob a chave "defeitos_relatados"', async () => {
    const defeitos = [{ id: 1, tipo_equipamento_id: 5, tipo_equipamento_nome: 'Smartphone', categoria: 'Tela', subcategoria: '', texto_relato: 'Tela quebrada', icone: '', ordem_exibicao: 1, ativo: true }];
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      jsonResponse({ status: 'success', data: { defeitos_relatados: defeitos }, error: null })
    ));

    const result = await apiSearchReportedDefects({ tipoEquipamentoId: 5 });

    expect(result.defeitos_relatados).toEqual(defeitos);
  });

  it('apiTechnicians filtra apenas quem pode ser atribuído e mapeia para {value,label}', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      jsonResponse({
        status: 'success',
        data: {
          team_members: [
            { nome: 'Ana (técnica)', can_assign_orders: true, order_technician_user_id: 7 },
            { nome: 'Bruno (não atribuível)', can_assign_orders: false, order_technician_user_id: 0 },
          ],
        },
        error: null,
      })
    ));

    const result = await apiTechnicians();

    expect(result.team_members).toEqual([{ value: 7, label: 'Ana (técnica)' }]);
  });

  it('apiCreateOrder monta um POST multipart com o payload e as fotos', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({
        status: 'success',
        data: { order: { id: 1 }, opening_document: null, opening_delivery: null, idempotent_replay: false, warnings: [] },
        error: null,
      })
    );
    vi.stubGlobal('fetch', fetchMock);

    const payload: CreateOrderPayload = {
      idempotency_key: 'uuid-1',
      cliente_id: 10,
      relato_cliente: 'Tela quebrada',
      prazo_entrega_dias: 3,
      enviar_pdf_cliente: false,
      novo_equipamento: undefined,
    };
    const foto = new File(['x'], 'foto.jpg', { type: 'image/jpeg' });
    const fotoEquipamento = new File(['y'], 'equip.jpg', { type: 'image/jpeg' });

    await apiCreateOrder(payload, [foto], [fotoEquipamento]);

    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toContain('/orders');
    expect(init.method).toBe('POST');
    expect(init.body).toBeInstanceOf(FormData);

    const formData = init.body as FormData;
    expect(formData.get('idempotency_key')).toBe('uuid-1');
    expect(formData.get('cliente_id')).toBe('10');
    expect(formData.get('relato_cliente')).toBe('Tela quebrada');
    expect(formData.get('enviar_pdf_cliente')).toBe('0');
    expect(formData.get('fotos[]')).toBe(foto);
    expect(formData.get('novo_equipamento_fotos[]')).toBe(fotoEquipamento);

    // Content-Type não deve ser forçado manualmente — o browser define o boundary do multipart.
    const headers = init.headers as Headers;
    expect(headers.has('Content-Type')).toBe(false);
  });

  it('apiCreateOrder propaga ApiError com o código real em erro 422 de checklist', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(
          { status: 'error', data: null, error: { code: 'ORDER_ENTRY_CHECKLIST_INCOMPLETE', message: 'Checklist incompleto.' } },
          { ok: false, status: 422 }
        )
      )
    );

    const payload: CreateOrderPayload = {
      idempotency_key: 'uuid-2',
      cliente_id: 10,
      equipamento_id: 20,
      relato_cliente: 'Teste',
      prazo_entrega_dias: 3,
      enviar_pdf_cliente: false,
    };

    await expect(apiCreateOrder(payload)).rejects.toMatchObject({
      code: 'ORDER_ENTRY_CHECKLIST_INCOMPLETE',
      status: 422,
    });
  });

  it('apiUpdateOrder sem fotos novas envia PATCH real com JSON', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ status: 'success', data: { order: { id: 5 } }, error: null })
    );
    vi.stubGlobal('fetch', fetchMock);

    const payload: UpdateOrderPayload = { relato_cliente: 'Atualizado' };
    await apiUpdateOrder(5, payload);

    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toContain('/orders/5');
    expect(init.method).toBe('PATCH');
    expect(init.body).toBe(JSON.stringify(payload));
  });

  it('apiUpdateOrder com fotos novas envia POST multipart com _method=PATCH', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse({ status: 'success', data: { order: { id: 5 } }, error: null })
    );
    vi.stubGlobal('fetch', fetchMock);

    const payload: UpdateOrderPayload = { relato_cliente: 'Atualizado' };
    const foto = new File(['x'], 'foto.jpg', { type: 'image/jpeg' });

    await apiUpdateOrder(5, payload, [foto]);

    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toContain('/orders/5');
    expect(init.method).toBe('POST');
    expect(init.body).toBeInstanceOf(FormData);

    const formData = init.body as FormData;
    expect(formData.get('_method')).toBe('PATCH');
    expect(formData.get('relato_cliente')).toBe('Atualizado');
    expect(formData.get('fotos[]')).toBe(foto);
  });
});
