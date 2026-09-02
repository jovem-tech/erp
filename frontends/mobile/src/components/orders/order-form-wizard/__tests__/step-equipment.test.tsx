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
  brands: [
    { id: 10, nome: 'Samsung' },
    // Sem relação de catálogo com nenhum tipo — representa uma marca
    // legada vinculada a um equipamento antigo (ver teste "includeIds").
    { id: 11, nome: 'LG' },
  ],
  models: [{ id: 100, marca_id: 10, nome: 'A015' }],
  catalog_relations: [
    { tipo_id: 1, marca_id: 10, modelo_id: 100 },
    { tipo_id: 2, marca_id: 10, modelo_id: 100 },
  ],
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
    await waitFor(() => expect(screen.getByText('Tipo de equipamento')).toBeInTheDocument());
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
    await waitFor(() => expect(screen.getByText('Tipo de equipamento')).toBeInTheDocument());
  });

  it('pré-preenche por correspondência exata os dados do equipamento do orçamento vinculado', async () => {
    const { getEquipmentFormData } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);
    const onChangePendingNewEquipment = vi.fn();

    render(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        pendingNewEquipment={null}
        pendingNewEquipmentPhotos={[]}
        linkedBudget={{
          id: 77,
          numero: 'ORC-0077',
          status: 'aguardando_resposta',
          equipamento_resumo: 'Notebook Samsung A015',
          equipamento_tipo_avulso: 'Notebook',
          equipamento_marca_avulso: 'Samsung',
          equipamento_modelo_avulso: 'A015',
          equipamento_cor: 'Cinza',
        }}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={onChangePendingNewEquipment}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    expect(await screen.findByText(/Equipamento informado no orçamento ORC-0077/)).toBeInTheDocument();
    await waitFor(() => expect(onChangePendingNewEquipment).toHaveBeenCalledWith({
      tipo_id: 2,
      marca_id: 10,
      modelo_id: 100,
      cor: 'Cinza',
    }));
  });

  it('seleciona sozinho o equipamento cadastrado do orçamento vinculado', async () => {
    const { getEquipmentDetail } = await import('@/lib/orders');
    const onSelectEquipamento = vi.fn();

    render(
      <StepEquipment
        mode="create"
        clienteId={1}
        equipamento={null}
        pendingNewEquipment={null}
        pendingNewEquipmentPhotos={[]}
        linkedBudget={{
          id: 91,
          numero: 'ORC-0091',
          status: 'aprovado',
          cliente_id: 1,
          equipamento_id: 1,
          equipamento_resumo: 'Samsung A015',
        }}
        onSelectEquipamento={onSelectEquipamento}
        onChangePendingNewEquipment={vi.fn()}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    await waitFor(() => expect(getEquipmentDetail).toHaveBeenCalledWith(1));
    await waitFor(() => expect(onSelectEquipamento).toHaveBeenCalledWith(
      expect.objectContaining({ id: 1, cliente_id: 1, resumo_tecnico: 'Samsung A015', tipo_id: 1 })
    ));
  });

  it('avisa quando o equipamento escolhido não é o do orçamento vinculado', async () => {
    render(
      <StepEquipment
        mode="create"
        clienteId={1}
        equipamento={buildEquipment()}
        pendingNewEquipment={null}
        pendingNewEquipmentPhotos={[]}
        linkedBudget={{
          id: 91,
          numero: 'ORC-0091',
          status: 'aprovado',
          cliente_id: 1,
          equipamento_id: 99,
          equipamento_resumo: 'Notebook do orçamento',
        }}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={vi.fn()}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    expect(
      await screen.findByText(/O orçamento ORC-0091 é de outro equipamento deste cliente/)
    ).toBeInTheDocument();
  });

  it('sem relação de catálogo para o tipo do orçamento, só o tipo é pré-preenchido', async () => {
    const { getEquipmentFormData } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue({ ...formData, catalog_relations: [] });
    const onChangePendingNewEquipment = vi.fn();

    render(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        pendingNewEquipment={null}
        pendingNewEquipmentPhotos={[]}
        linkedBudget={{
          id: 78,
          numero: 'ORC-0078',
          status: 'aguardando_resposta',
          equipamento_resumo: 'Notebook Samsung A015',
          equipamento_tipo_avulso: 'Notebook',
          equipamento_marca_avulso: 'Samsung',
          equipamento_modelo_avulso: 'A015',
          equipamento_cor: 'Cinza',
        }}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={onChangePendingNewEquipment}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    expect(await screen.findByText(/Equipamento informado no orçamento ORC-0078/)).toBeInTheDocument();
    await waitFor(() => expect(onChangePendingNewEquipment).toHaveBeenCalledWith({
      tipo_id: 2,
      marca_id: 0,
      modelo_id: 0,
      cor: 'Cinza',
    }));
    expect(screen.getByText(/reconhecida no catálogo/)).toBeInTheDocument();
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
    await waitFor(() => expect(screen.getByText('Tipo de equipamento')).toBeInTheDocument());

    // seleciona tipo "Desktop"
    await user.click(screen.getByRole('combobox', { name: /Tipo de equipamento/ }));
    await user.click(screen.getByRole('option', { name: 'Desktop' }));
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

    await waitFor(() => expect(screen.getByText('Tipo de equipamento')).toBeInTheDocument());
    expect(screen.queryByText('Modalidade')).not.toBeInTheDocument();
  });

  it('reseta marca e modelo ao trocar o tipo de equipamento', async () => {
    const { getEquipmentFormData } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);
    const onChangePendingNewEquipment = vi.fn();

    render(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        pendingNewEquipment={{ tipo_id: 1, marca_id: 10, modelo_id: 100 }}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={onChangePendingNewEquipment}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    await waitFor(() => expect(screen.getByText('Tipo de equipamento')).toBeInTheDocument());

    const user = userEvent.setup();
    const combobox = screen.getByRole('combobox', { name: /Tipo de equipamento/ });
    await user.click(combobox);
    await user.clear(combobox);
    await user.type(combobox, 'Notebook');
    await user.click(screen.getByRole('option', { name: 'Notebook' }));

    expect(onChangePendingNewEquipment).toHaveBeenCalledWith({ tipo_id: 2, marca_id: 0, modelo_id: 0 });
  });

  it('limpa os campos de hardware ao trocar de um tipo desktop para um que não é desktop', async () => {
    const { getEquipmentFormData } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);
    const onChangePendingNewEquipment = vi.fn();

    render(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        pendingNewEquipment={{
          tipo_id: 1,
          marca_id: 10,
          modelo_id: 100,
          desktop_modalidade: 'montado',
          placa_mae: 'Asus Prime',
        }}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={onChangePendingNewEquipment}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    await waitFor(() => expect(screen.getByText('Tipo de equipamento')).toBeInTheDocument());

    const user = userEvent.setup();
    const combobox = screen.getByRole('combobox', { name: /Tipo de equipamento/ });
    await user.click(combobox);
    await user.clear(combobox);
    await user.type(combobox, 'Notebook');
    await user.click(screen.getByRole('option', { name: 'Notebook' }));

    const [call] = onChangePendingNewEquipment.mock.calls;
    expect(call[0]).not.toHaveProperty('desktop_modalidade');
    expect(call[0]).not.toHaveProperty('placa_mae');
  });

  it('mantém visível o vínculo atual fora do catálogo ao editar equipamento legado', async () => {
    const { getEquipmentFormData } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);

    render(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        // LG (id 11) não tem relação de catálogo com nenhum tipo — cenário
        // de equipamento legado cujo vínculo precede o catálogo estrito.
        pendingNewEquipment={{ tipo_id: 1, marca_id: 11, modelo_id: 0 }}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={vi.fn()}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    await waitFor(() => expect(screen.getByRole('combobox', { name: /Marca/ })).toHaveValue('LG'));
    expect(screen.getByText('Vínculo atual fora do catálogo deste tipo.')).toBeInTheDocument();
  });

  it('não grava nada no catálogo enquanto o usuário apenas seleciona itens existentes', async () => {
    const { getEquipmentFormData, createEquipmentBrand, createEquipmentModel } = await import('@/lib/orders');
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
        canCreateCatalog
      />
    );

    await user.click(screen.getByText('Equipamento novo'));
    await waitFor(() => expect(screen.getByText('Marca')).toBeInTheDocument());

    await user.click(screen.getByRole('combobox', { name: /Marca/ }));
    await user.click(screen.getByRole('option', { name: 'Samsung' }));

    expect(createEquipmentBrand).not.toHaveBeenCalled();
    expect(createEquipmentModel).not.toHaveBeenCalled();
  });

  it('grava a marca nova no catálogo imediatamente e a seleciona', async () => {
    const { getEquipmentFormData, createEquipmentBrand } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);
    vi.mocked(createEquipmentBrand).mockResolvedValue({ id: 20, nome: 'Xiaomi', tipo_id: 1 });
    const onChangePendingNewEquipment = vi.fn();

    const user = userEvent.setup();

    render(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        pendingNewEquipment={{ tipo_id: 1, marca_id: 0, modelo_id: 0 }}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={onChangePendingNewEquipment}
        onChangePendingNewEquipmentPhotos={vi.fn()}
        canCreateCatalog
      />
    );

    await user.click(screen.getByText('Equipamento novo'));
    await waitFor(() => expect(screen.getByText('Marca')).toBeInTheDocument());

    const combobox = screen.getByRole('combobox', { name: /Marca/ });
    await user.click(combobox);
    await user.type(combobox, 'Xiaomi');
    await user.click(screen.getByRole('button', { name: '+ Nova marca "Xiaomi"' }));

    const nameInput = screen.getByRole('textbox', { name: 'Nome' });
    expect(nameInput).toHaveValue('Xiaomi');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(createEquipmentBrand).toHaveBeenCalledWith('Xiaomi', 1));
    await waitFor(() =>
      expect(onChangePendingNewEquipment).toHaveBeenCalledWith(
        expect.objectContaining({ marca_id: 20, modelo_id: 0 })
      )
    );
    expect(screen.queryByRole('textbox', { name: 'Nome' })).not.toBeInTheDocument();
  });

  it('desabilita o cadastro de marca sem a permissão equipamentos:criar', async () => {
    const { getEquipmentFormData } = await import('@/lib/orders');
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
        canCreateCatalog={false}
      />
    );

    await user.click(screen.getByText('Equipamento novo'));
    await waitFor(() => expect(screen.getByText('Marca')).toBeInTheDocument());

    const combobox = screen.getByRole('combobox', { name: /Marca/ });
    await user.click(combobox);
    await user.type(combobox, 'Xiaomi');

    const createButton = screen.getByRole('button', { name: '+ Nova marca "Xiaomi"' });
    expect(createButton).toBeDisabled();
    expect(createButton).toHaveAttribute('title', 'Sem permissão para cadastrar marcas.');
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

  it('clicar num chip de cor grava cor, cor_hex e cor_rgb juntos', async () => {
    const { getEquipmentFormData } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);
    const onChangePendingNewEquipment = vi.fn();

    const user = userEvent.setup();

    render(
      <StepEquipment
        mode="create"
        clienteId={null}
        equipamento={null}
        pendingNewEquipment={{ tipo_id: 1, marca_id: 10, modelo_id: 100 }}
        pendingNewEquipmentPhotos={[]}
        onSelectEquipamento={vi.fn()}
        onChangePendingNewEquipment={onChangePendingNewEquipment}
        onChangePendingNewEquipmentPhotos={vi.fn()}
      />
    );

    await waitFor(() => expect(screen.getByText('Cor')).toBeInTheDocument());
    await user.click(screen.getByRole('button', { name: 'Preto' }));

    // Uma única chamada com os três campos juntos — não duas chamadas
    // sucessivas que se sobrescreveriam a partir do mesmo estado-base.
    expect(onChangePendingNewEquipment).toHaveBeenCalledTimes(1);
    expect(onChangePendingNewEquipment).toHaveBeenCalledWith(
      expect.objectContaining({ tipo_id: 1, marca_id: 10, modelo_id: 100, cor: 'Preto', cor_hex: '#1A1A1A', cor_rgb: '26, 26, 26' })
    );
  });
});

describe('isStepEquipmentValid', () => {
  it('é válido com equipamento existente selecionado', () => {
    expect(isStepEquipmentValid(buildEquipment(), null, [])).toBe(true);
  });

  it('é inválido para equipamento novo sem foto', () => {
    expect(isStepEquipmentValid(null, { tipo_id: 1, marca_id: 1, modelo_id: 1, cor: 'Preto' }, [])).toBe(false);
  });

  it('é inválido para equipamento novo sem cor', () => {
    const file = new File(['x'], 'foto.jpg', { type: 'image/jpeg' });
    expect(isStepEquipmentValid(null, { tipo_id: 1, marca_id: 1, modelo_id: 1 }, [file])).toBe(false);
  });

  it('é válido para equipamento novo com tipo/marca/modelo/cor e ao menos 1 foto', () => {
    const file = new File(['x'], 'foto.jpg', { type: 'image/jpeg' });
    expect(isStepEquipmentValid(null, { tipo_id: 1, marca_id: 1, modelo_id: 1, cor: 'Preto' }, [file])).toBe(true);
  });
});
