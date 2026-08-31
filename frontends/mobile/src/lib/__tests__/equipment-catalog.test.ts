import { describe, expect, it } from 'vitest';
import {
  BRAND_SCOPE_ANCHOR_MODEL_NAME,
  buildCatalogIndex,
  getAllowedBrands,
  getAllowedModels,
  normalizeCatalogLabel,
  normalizeCatalogRelations,
} from '@/lib/equipment-catalog';
import type {
  EquipmentBrandCatalogItem,
  EquipmentCatalogRelation,
  EquipmentModelCatalogItem,
} from '@/lib/types';

const TIPO_NOTEBOOK = 1;
const TIPO_IMPRESSORA = 3;
const TIPO_DESKTOP = 4;

const MARCA_SAMSUNG = 10;
const MARCA_APPLE = 11;
const MARCA_MONTADO = 99;

const MODELO_GALAXY_BOOK = 100;
const MODELO_MACBOOK = 101;
const MODELO_DESKTOP_MONTADO = 999;
const MODELO_ANCORA = 500;

const brands: EquipmentBrandCatalogItem[] = [
  { id: MARCA_SAMSUNG, nome: 'Samsung' },
  { id: MARCA_APPLE, nome: 'Apple' },
  { id: MARCA_MONTADO, nome: 'Montado' },
];

const models: EquipmentModelCatalogItem[] = [
  { id: MODELO_GALAXY_BOOK, marca_id: MARCA_SAMSUNG, nome: 'Galaxy Book' },
  { id: MODELO_MACBOOK, marca_id: MARCA_APPLE, nome: 'MacBook' },
  { id: MODELO_DESKTOP_MONTADO, marca_id: MARCA_MONTADO, nome: 'Desktop montado' },
];

const relations: EquipmentCatalogRelation[] = [
  { tipo_id: TIPO_NOTEBOOK, marca_id: MARCA_SAMSUNG, modelo_id: MODELO_GALAXY_BOOK },
  { tipo_id: TIPO_NOTEBOOK, marca_id: MARCA_APPLE, modelo_id: MODELO_MACBOOK },
  // Marca vinculada ao tipo só pela âncora (sem modelo real ainda).
  { tipo_id: TIPO_NOTEBOOK, marca_id: MARCA_MONTADO, modelo_id: MODELO_ANCORA },
];

const desktopDefaults = { marca_id: MARCA_MONTADO, modelo_id: MODELO_DESKTOP_MONTADO };

describe('normalizeCatalogLabel', () => {
  it('remove acentos e normaliza a caixa', () => {
    expect(normalizeCatalogLabel('Impressão')).toBe('impressao');
    expect(normalizeCatalogLabel('  Aço  ')).toBe('aco');
  });

  it('tolera valores ausentes', () => {
    expect(normalizeCatalogLabel(undefined)).toBe('');
    expect(normalizeCatalogLabel(null)).toBe('');
    expect(normalizeCatalogLabel('')).toBe('');
  });
});

describe('normalizeCatalogRelations', () => {
  it('tolera ausência do campo e formatos inesperados', () => {
    expect(normalizeCatalogRelations(undefined)).toEqual([]);
    expect(normalizeCatalogRelations(null)).toEqual([]);
    expect(normalizeCatalogRelations('não é array')).toEqual([]);
    expect(normalizeCatalogRelations({})).toEqual([]);
  });

  it('descarta linhas com ids inválidos e coage ids em formato string', () => {
    const raw = [
      { tipo_id: '1', marca_id: '10', modelo_id: '100' },
      { tipo_id: 0, marca_id: 10, modelo_id: 100 }, // tipo_id inválido (<=0)
      { tipo_id: 1, marca_id: -1, modelo_id: 100 }, // marca_id inválido
      { tipo_id: 1, marca_id: 10, modelo_id: -5 }, // modelo_id inválido
      null,
      'lixo',
    ];

    expect(normalizeCatalogRelations(raw)).toEqual([{ tipo_id: 1, marca_id: 10, modelo_id: 100 }]);
  });

  it('aceita modelo_id igual a zero (relação transiente de marca sem modelo)', () => {
    expect(normalizeCatalogRelations([{ tipo_id: 1, marca_id: 10, modelo_id: 0 }])).toEqual([
      { tipo_id: 1, marca_id: 10, modelo_id: 0 },
    ]);
  });
});

describe('getAllowedBrands', () => {
  const index = buildCatalogIndex(relations);

  it('filtra marcas pelo tipo selecionado', () => {
    const allowed = getAllowedBrands({ brands, desktop_defaults: null }, index, TIPO_NOTEBOOK, false);
    expect(allowed.map((b) => b.nome).sort()).toEqual(['Apple', 'Montado', 'Samsung']);
  });

  it('devolve lista vazia para tipo sem nenhuma relação (ex.: Impressora)', () => {
    const allowed = getAllowedBrands({ brands, desktop_defaults: null }, index, TIPO_IMPRESSORA, false);
    expect(allowed).toEqual([]);
  });

  it('devolve lista vazia sem tipo selecionado', () => {
    expect(getAllowedBrands({ brands, desktop_defaults: null }, index, 0, false)).toEqual([]);
  });

  it('inclui a marca padrão de desktop quando a família é desktop, mesmo sem relação', () => {
    const emptyIndex = buildCatalogIndex([]);
    const allowed = getAllowedBrands({ brands, desktop_defaults: desktopDefaults }, emptyIndex, TIPO_DESKTOP, true);
    expect(allowed.map((b) => b.id)).toEqual([MARCA_MONTADO]);
  });

  it('não injeta a marca padrão de desktop quando a família não é desktop', () => {
    const emptyIndex = buildCatalogIndex([]);
    const allowed = getAllowedBrands({ brands, desktop_defaults: desktopDefaults }, emptyIndex, TIPO_IMPRESSORA, false);
    expect(allowed).toEqual([]);
  });

  it('mantém visível um id legado fora do catálogo via includeIds', () => {
    const allowed = getAllowedBrands({ brands, desktop_defaults: null }, index, TIPO_IMPRESSORA, false, {
      includeIds: [MARCA_SAMSUNG],
    });
    expect(allowed.map((b) => b.id)).toEqual([MARCA_SAMSUNG]);
  });
});

describe('getAllowedModels', () => {
  const index = buildCatalogIndex(relations);

  it('filtra modelos pelo par (tipo, marca)', () => {
    const allowed = getAllowedModels({ models, desktop_defaults: null }, index, TIPO_NOTEBOOK, MARCA_SAMSUNG, false);
    expect(allowed.map((m) => m.id)).toEqual([MODELO_GALAXY_BOOK]);
  });

  it('caso da âncora: marca concedida ao tipo sem modelo real devolve lista vazia', () => {
    const allowed = getAllowedModels({ models, desktop_defaults: null }, index, TIPO_NOTEBOOK, MARCA_MONTADO, false);
    expect(allowed).toEqual([]);
  });

  it('exclui por nome um modelo âncora mesmo que apareça ativo em formData.models', () => {
    const modelsWithAnchorActive: EquipmentModelCatalogItem[] = [
      ...models,
      { id: MODELO_ANCORA, marca_id: MARCA_MONTADO, nome: BRAND_SCOPE_ANCHOR_MODEL_NAME },
    ];
    const allowed = getAllowedModels(
      { models: modelsWithAnchorActive, desktop_defaults: null },
      index,
      TIPO_NOTEBOOK,
      MARCA_MONTADO,
      false,
    );
    expect(allowed).toEqual([]);
  });

  it('devolve lista vazia sem tipo ou sem marca', () => {
    expect(getAllowedModels({ models, desktop_defaults: null }, index, 0, MARCA_SAMSUNG, false)).toEqual([]);
    expect(getAllowedModels({ models, desktop_defaults: null }, index, TIPO_NOTEBOOK, 0, false)).toEqual([]);
  });

  it('inclui o modelo padrão de desktop só quando a marca é a marca padrão de desktop', () => {
    const emptyIndex = buildCatalogIndex([]);
    const allowed = getAllowedModels(
      { models, desktop_defaults: desktopDefaults },
      emptyIndex,
      TIPO_DESKTOP,
      MARCA_MONTADO,
      true,
    );
    expect(allowed.map((m) => m.id)).toEqual([MODELO_DESKTOP_MONTADO]);
  });

  it('mantém visível um modelo legado fora do catálogo via includeIds', () => {
    const allowed = getAllowedModels({ models, desktop_defaults: null }, index, TIPO_IMPRESSORA, MARCA_SAMSUNG, false, {
      includeIds: [MODELO_GALAXY_BOOK],
    });
    expect(allowed.map((m) => m.id)).toEqual([MODELO_GALAXY_BOOK]);
  });
});

describe('buildCatalogIndex', () => {
  it('indexa marcas por tipo e modelos por par tipo|marca', () => {
    const index = buildCatalogIndex(relations);
    expect(index.brandIdsByType.get(TIPO_NOTEBOOK)).toEqual(new Set([MARCA_SAMSUNG, MARCA_APPLE, MARCA_MONTADO]));
    expect(index.modelIdsByTypeBrand.get(`${TIPO_NOTEBOOK}|${MARCA_SAMSUNG}`)).toEqual(new Set([MODELO_GALAXY_BOOK]));
  });

  it('aceita modelo_id 0 (relação transiente de marca recém-criada, ainda sem modelo) no índice de marcas, e o ignora no de modelos', () => {
    const index = buildCatalogIndex([{ tipo_id: TIPO_IMPRESSORA, marca_id: MARCA_SAMSUNG, modelo_id: 0 }]);
    expect(index.brandIdsByType.get(TIPO_IMPRESSORA)).toEqual(new Set([MARCA_SAMSUNG]));
    expect(index.modelIdsByTypeBrand.has(`${TIPO_IMPRESSORA}|${MARCA_SAMSUNG}`)).toBe(false);
  });
});
