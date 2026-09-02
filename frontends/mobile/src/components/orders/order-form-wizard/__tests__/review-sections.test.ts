import { describe, expect, it } from 'vitest';
import { buildReviewSections } from '@/components/orders/order-form-wizard';
import { createInitialWizardState } from '@/components/orders/order-form-wizard/wizard-state';
import type { WizardStepInfo } from '@/components/orders/order-form-wizard/wizard-stepper';
import type { EquipmentSearchResult, LinkableBudget } from '@/lib/types';

const steps: WizardStepInfo[] = [
  { key: 'cliente', label: 'Cliente' },
  { key: 'equipamento', label: 'Equipamento' },
  { key: 'detalhes', label: 'Relato' },
  { key: 'atendimento', label: 'Atendimento' },
  { key: 'fotos', label: 'Fotos' },
  { key: 'revisao', label: 'Revisão' },
];

function equipmentSection(state: Parameters<typeof buildReviewSections>[0]) {
  return buildReviewSections(state, steps, 'create', {}).find((section) => section.key === 'equipamento');
}

function rowValue(section: ReturnType<typeof equipmentSection>, label: string): string | undefined {
  return section?.rows.find((row) => row.label === label)?.value;
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

describe('buildReviewSections — card de equipamento', () => {
  it('mostra os nomes resolvidos de tipo/marca/modelo e a cor no cadastro novo', () => {
    const state = {
      ...createInitialWizardState(),
      pendingNewEquipment: { tipo_id: 1, marca_id: 10, modelo_id: 100, cor: 'Preto' },
      pendingNewEquipmentLabels: { tipo: 'Notebook', marca: 'Samsung', modelo: 'Galaxy Book' },
      pendingNewEquipmentPhotos: [new File(['x'], 'foto.jpg', { type: 'image/jpeg' })],
    };

    const section = equipmentSection(state);

    expect(rowValue(section, 'Tipo')).toBe('Notebook');
    expect(rowValue(section, 'Marca')).toBe('Samsung');
    expect(rowValue(section, 'Modelo')).toBe('Galaxy Book');
    expect(rowValue(section, 'Cor')).toBe('Preto');
    expect(rowValue(section, 'Fotos anexadas')).toBe('1');
  });

  it('inclui a linha de senha em desenho apenas quando o tipo de senha é desenho', () => {
    const base = {
      ...createInitialWizardState(),
      pendingNewEquipment: { tipo_id: 1, marca_id: 10, modelo_id: 100, cor: 'Preto' },
      pendingNewEquipmentLabels: { tipo: 'Notebook', marca: 'Samsung', modelo: 'Galaxy Book' },
    };

    expect(rowValue(equipmentSection(base), 'Senha (desenho)')).toBeUndefined();

    const comDesenho = {
      ...base,
      pendingNewEquipment: { ...base.pendingNewEquipment, senha_tipo: 'desenho' as const, senha_desenho: '1-2-3-6-9' },
    };

    expect(rowValue(equipmentSection(comDesenho), 'Senha (desenho)')).toBe('1-2-3-6-9');
  });

  it('mostra a cor na edição local de um equipamento já cadastrado', () => {
    const state = {
      ...createInitialWizardState(),
      equipamento: buildEquipment(),
      pendingEquipmentUpdate: { tipo_id: 3, marca_id: 5, modelo_id: 8, cor: 'Prata', numero_serie: 'SERIE-1' },
    };

    const section = equipmentSection(state);

    expect(rowValue(section, 'Cor')).toBe('Prata');
    expect(rowValue(section, 'Número de série')).toBe('SERIE-1');
  });

  it('equipamento existente sem edição local mantém a linha única de resumo', () => {
    const state = {
      ...createInitialWizardState(),
      equipamento: buildEquipment(),
    };

    const section = equipmentSection(state);

    expect(section?.rows).toHaveLength(1);
    expect(rowValue(section, 'Equipamento')).toBe('Samsung A015');
  });
});

describe('buildReviewSections — card de extras', () => {
  function extrasSection(state: Parameters<typeof buildReviewSections>[0]) {
    return buildReviewSections(state, steps, 'create', {}).find((section) => section.key === 'extras');
  }

  function budget(status: string): LinkableBudget {
    return { id: 91, numero: 'ORC-0091', status, equipamento_resumo: 'Samsung A015' };
  }

  it('anuncia o status inicial quando o orçamento vinculado já está aprovado', () => {
    const section = extrasSection({ ...createInitialWizardState(), orcamentoVinculado: budget('aprovado') });

    expect(rowValue(section, 'Orçamento vinculado')).toBe('ORC-0091');
    expect(rowValue(section, 'Status inicial da OS')).toBe('Aguardando Reparo (orçamento aprovado)');
  });

  it('omite o status inicial quando o orçamento ainda não foi aprovado', () => {
    const section = extrasSection({
      ...createInitialWizardState(),
      orcamentoVinculado: budget('aguardando_resposta'),
    });

    expect(rowValue(section, 'Status inicial da OS')).toBeUndefined();
  });

  it('sem orçamento vinculado, o card informa que nenhum foi escolhido', () => {
    const section = extrasSection(createInitialWizardState());

    expect(rowValue(section, 'Orçamento vinculado')).toBe('Nenhum');
    expect(rowValue(section, 'Status inicial da OS')).toBeUndefined();
  });
});
