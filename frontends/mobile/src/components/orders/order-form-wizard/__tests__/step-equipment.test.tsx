import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StepEquipment, isStepEquipmentValid } from '@/components/orders/order-form-wizard/steps/step-equipment';
import type { EquipmentFormData, EquipmentSearchResult } from '@/lib/types';

const formData: EquipmentFormData = {
  types: [
    { id: 1, nome: 'Desktop', slug: 'desktop', family: 'desktop' },
    { id: 2, nome: 'Notebook', slug: 'notebook', family: 'notebook' },
  ],
  brands: [{ id: 10, nome: 'Samsung' }],
  models: [{ id: 100, marca_id: 10, nome: 'A015' }],
  desktop_defaults: null,
  password_modes: [
    { value: 'desenho', label: 'Desenho' },
    { value: 'texto', label: 'Texto' },
  ],
  max_photos: 4,
};

vi.mock('@/lib/orders', () => ({
  getEquipmentFormData: vi.fn(),
  getEquipmentDetail: vi.fn().mockResolvedValue({
    id: 1,
    cliente_id: 1,
    tipo_id: 1,
    tipo_nome: 'Desktop',
    marca_id: 10,
    marca_nome: 'Samsung',
    modelo_id: 100,
    modelo_nome: 'A015',
    resumo_tecnico: 'Samsung A015',
    numero_serie: 'SERIE-1',
    imei: '',
    desktop_modalidade: 'montado',
    status_operacional: 'ativo',
    status: 'ativo',
    primary_photo_id: null,
    primary_photo_url: null,
    photos: [],
  }),
  searchEquipments: vi.fn().mockResolvedValue([]),
  createEquipmentBrand: vi.fn(),
  createEquipmentModel: vi.fn(),
}));

vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>();

  return {
    ...actual,
    fetchAttachmentBlob: vi.fn(),
  };
});

function buildEquipment(): EquipmentSearchResult {
  return {
    id: 1,
    cliente_id: 1,
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
  };
}

describe('StepEquipment', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('lista ao clicar somente os equipamentos do cliente, com miniatura à esquerda', async () => {
    const { searchEquipments } = await import('@/lib/orders');
    vi.mocked(searchEquipments).mockResolvedValue([buildEquipment()]);

    const user = userEvent.setup();
    const onSelectEquipamento = vi.fn();

    const { container } = render(
      <StepEquipment
        mode="create"
        clienteId={1}
        equipamento={null}
        pendingNewEquipment={null}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={onSelectEquipamento}
        onChangePendingNewEquipment={vi.fn()}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    await user.click(screen.getByPlaceholderText('Marca, modelo, nº de série ou resumo técnico'));

    await waitFor(() => expect(searchEquipments).toHaveBeenCalledWith({
      clientId: 1,
      search: '',
      perPage: 50,
    }));
    await waitFor(() => expect(screen.getByText('Samsung A015')).toBeInTheDocument());
    expect(container.querySelector('.equipment-thumbnail')).toBeInTheDocument();

    await user.click(screen.getByText('Samsung A015'));
    expect(onSelectEquipamento).toHaveBeenCalledWith(buildEquipment());
  });

  it('abre o cadastro novo automaticamente quando o cliente não tem equipamentos', async () => {
    const { getEquipmentFormData, searchEquipments } = await import('@/lib/orders');
    vi.mocked(searchEquipments).mockResolvedValue([]);
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);

    const user = userEvent.setup();
    const onChangePendingNewEquipment = vi.fn();

    render(
      <StepEquipment
        mode="create"
        clienteId={42}
        equipamento={null}
        pendingNewEquipment={null}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={onChangePendingNewEquipment}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    await user.click(screen.getByPlaceholderText('Marca, modelo, nº de série ou resumo técnico'));

    await waitFor(() => expect(screen.getByText(
      /Nenhum equipamento cadastrado para este cliente\. Prossiga com o novo cadastro/
    )).toBeInTheDocument());
    expect(onChangePendingNewEquipment).toHaveBeenCalledWith({ tipo_id: 0, marca_id: 0, modelo_id: 0 });
    await waitFor(() => expect(screen.getByText('Tipo de equipamento *')).toBeInTheDocument());
  });

  it('carrega a foto privada da opção pelo cliente autenticado da API', async () => {
    const { fetchAttachmentBlob } = await import('@/lib/api');
    const { searchEquipments } = await import('@/lib/orders');
    const createObjectUrl = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:equipment-photo');
    const revokeObjectUrl = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    const equipment = {
      ...buildEquipment(),
      primary_photo_id: 9,
      primary_photo_url: 'https://api.example.test/api/v1/equipments/1/photos/9',
    };

    vi.mocked(searchEquipments).mockResolvedValue([equipment]);
    vi.mocked(fetchAttachmentBlob).mockResolvedValue({
      blob: new Blob(['foto'], { type: 'image/jpeg' }),
      contentType: 'image/jpeg',
      filename: 'equipamento.jpg',
    });

    const user = userEvent.setup();
    const { unmount } = render(
      <StepEquipment
        mode="create"
        clienteId={1}
        equipamento={null}
        pendingNewEquipment={null}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={vi.fn()}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    await user.click(screen.getByPlaceholderText('Marca, modelo, nº de série ou resumo técnico'));

    await waitFor(() => expect(fetchAttachmentBlob).toHaveBeenCalledWith('/equipments/1/photos/9'));
    expect(await screen.findByAltText('Foto de Samsung A015')).toHaveAttribute('src', 'blob:equipment-photo');
    expect(createObjectUrl).toHaveBeenCalled();

    unmount();
    expect(revokeObjectUrl).toHaveBeenCalledWith('blob:equipment-photo');
  });

  it('mantém o cadastro novo quando o próprio cliente ainda será cadastrado', async () => {
    const { getEquipmentFormData, searchEquipments } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);

    render(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        pendingNewEquipment={null}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={vi.fn()}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    expect(screen.getByText('Equipamento já cadastrado')).toBeDisabled();
    expect(screen.getByText(/Como o cliente também é novo/)).toBeInTheDocument();
    expect(searchEquipments).not.toHaveBeenCalled();
    await waitFor(() => expect(screen.getByText('Tipo de equipamento *')).toBeInTheDocument());
  });

  it('mostra o bloco de hardware só para tipo "desktop" em modalidade "montado"', async () => {
    const { getEquipmentFormData } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);

    const user = userEvent.setup();
    const onChangePendingNewEquipment = vi.fn();

    const { rerender } = render(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        pendingNewEquipment={null}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={onChangePendingNewEquipment}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    await user.click(screen.getByText('Equipamento novo'));
    await waitFor(() => expect(screen.getByText('Tipo de equipamento *')).toBeInTheDocument());

    // seleciona tipo "Desktop"
    await user.selectOptions(screen.getByLabelText('Tipo de equipamento *'), '1');
    expect(onChangePendingNewEquipment).toHaveBeenCalledWith(expect.objectContaining({ tipo_id: 1 }));

    rerender(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        pendingNewEquipment={{ tipo_id: 1, marca_id: 0, modelo_id: 0 }}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={onChangePendingNewEquipment}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    await waitFor(() => expect(screen.getByText('Modalidade')).toBeInTheDocument());
    expect(screen.queryByText('Placa-mãe')).not.toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText('Modalidade'), 'montado');
    expect(onChangePendingNewEquipment).toHaveBeenCalledWith(expect.objectContaining({ desktop_modalidade: 'montado' }));
  });

  it('não mostra modalidade/hardware para tipo notebook', async () => {
    const { getEquipmentFormData } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);

    render(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        pendingNewEquipment={{ tipo_id: 2, marca_id: 0, modelo_id: 0 }}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={vi.fn()}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    const user = userEvent.setup();
    await user.click(screen.getByText('Equipamento novo'));

    await waitFor(() => expect(screen.getByText('Tipo de equipamento *')).toBeInTheDocument());
    expect(screen.queryByText('Modalidade')).not.toBeInTheDocument();
  });

  it('não grava marca ou modelo no backend durante o rascunho da OS', async () => {
    const { getEquipmentFormData, createEquipmentBrand } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);

    const user = userEvent.setup();

    render(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        pendingNewEquipment={{ tipo_id: 1, marca_id: 0, modelo_id: 0 }}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={vi.fn()}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    await user.click(screen.getByText('Equipamento novo'));
    await waitFor(() => expect(screen.getByText('Marca *')).toBeInTheDocument());

    expect(screen.queryByText('+ Nova marca')).not.toBeInTheDocument();
    expect(screen.queryByText('+ Novo modelo')).not.toBeInTheDocument();
    expect(createEquipmentBrand).not.toHaveBeenCalled();
  });

  it('carrega a edição local do equipamento selecionado ao lado de Trocar', async () => {
    const { getEquipmentFormData } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);
    const user = userEvent.setup();
    const onChangePendingEquipmentUpdate = vi.fn();

    render(
      <StepEquipment
        mode="create"
        clienteId={1}
        equipamento={buildEquipment()}
        pendingNewEquipment={null}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={vi.fn()}
        onChangePendingEquipmentUpdate={onChangePendingEquipmentUpdate}
        onChangePendingNewEquipmentPhotos={vi.fn()}
        canEditExisting
      />
    );

    await user.click(screen.getByRole('button', { name: 'Editar' }));

    expect(await screen.findByText('Editar equipamento selecionado')).toBeInTheDocument();
    expect(onChangePendingEquipmentUpdate).toHaveBeenCalledWith(
      expect.objectContaining({
        tipo_id: 1,
        marca_id: 10,
        modelo_id: 100,
        numero_serie: 'SERIE-1',
      })
    );
  });
});

describe('isStepEquipmentValid', () => {
  it('é válido com equipamento existente selecionado', () => {
    expect(isStepEquipmentValid(buildEquipment(), null, [])).toBe(true);
  });

  it('é inválido para equipamento novo sem foto', () => {
    expect(isStepEquipmentValid(null, { tipo_id: 1, marca_id: 1, modelo_id: 1 }, [])).toBe(false);
  });

  it('é válido para equipamento novo com tipo/marca/modelo e ao menos 1 foto', () => {
    const file = new File(['x'], 'foto.jpg', { type: 'image/jpeg' });
    expect(isStepEquipmentValid(null, { tipo_id: 1, marca_id: 1, modelo_id: 1 }, [file])).toBe(true);
  });
});
