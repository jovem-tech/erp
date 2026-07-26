import { describe, expect, it, vi } from 'vitest';
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
  searchEquipments: vi.fn().mockResolvedValue([]),
  createEquipmentBrand: vi.fn(),
  createEquipmentModel: vi.fn(),
}));

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

  it('cadastro rápido de marca chama a API e seleciona a marca criada', async () => {
    const { getEquipmentFormData, createEquipmentBrand } = await import('@/lib/orders');
    vi.mocked(getEquipmentFormData).mockResolvedValue(formData);
    vi.mocked(createEquipmentBrand).mockResolvedValue({ id: 99, nome: 'Motorola' });

    const user = userEvent.setup();
    const onChangePendingNewEquipment = vi.fn();

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
      />
    );

    await user.click(screen.getByText('Equipamento novo'));
    await waitFor(() => expect(screen.getByText('+ Nova marca')).toBeInTheDocument());

    await user.click(screen.getByText('+ Nova marca'));
    await user.type(screen.getByPlaceholderText('Nome da marca'), 'Motorola');
    await user.click(screen.getByText('Salvar'));

    await waitFor(() => expect(createEquipmentBrand).toHaveBeenCalledWith('Motorola', 1));
    await waitFor(() =>
      expect(onChangePendingNewEquipment).toHaveBeenCalledWith(expect.objectContaining({ marca_id: 99 }))
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
