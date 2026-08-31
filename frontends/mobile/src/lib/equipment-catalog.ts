import type {
  EquipmentBrandCatalogItem,
  EquipmentCatalogRelation,
  EquipmentFormData,
  EquipmentModelCatalogItem,
} from '@/lib/types';

/**
 * Nome técnico do modelo-âncora criado pelo backend para registrar um
 * vínculo tipo -> marca sem exigir um modelo real (equipamentos_catalogo_relacoes.modelo_id
 * é NOT NULL). Ele nunca deve aparecer como opção de modelo selecionável.
 * Ver EquipmentWorkflowService::BRAND_SCOPE_ANCHOR_MODEL_NAME no backend.
 */
export const BRAND_SCOPE_ANCHOR_MODEL_NAME = '__CATALOG_BRAND_SCOPE__';

/**
 * Normaliza um rótulo de catálogo (tipo/marca/modelo/cor) para comparação
 * insensível a acento e caixa: remove diacríticos (NFD), corta espaços nas
 * pontas e usa lowercase de acordo com as regras do pt-BR.
 */
export function normalizeCatalogLabel(value: string | null | undefined): string {
  return (value ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .trim()
    .toLocaleLowerCase('pt-BR');
}

/**
 * Converte o `catalog_relations` recebido da API (opcional, e histori-
 * camente tipado como `unknown`) numa lista confiável de relações. Tolera
 * ausência do campo, formato inesperado e ids não numéricos, descartando
 * silenciosamente linhas inválidas em vez de derrubar a etapa.
 */
export function normalizeCatalogRelations(raw: unknown): EquipmentCatalogRelation[] {
  if (!Array.isArray(raw)) {
    return [];
  }

  const relations: EquipmentCatalogRelation[] = [];

  for (const entry of raw) {
    if (!entry || typeof entry !== 'object') {
      continue;
    }

    const record = entry as Record<string, unknown>;
    const tipoId = Number(record.tipo_id);
    const marcaId = Number(record.marca_id);
    const modeloId = Number(record.modelo_id);

    if (!Number.isFinite(tipoId) || tipoId <= 0) continue;
    if (!Number.isFinite(marcaId) || marcaId <= 0) continue;
    if (!Number.isFinite(modeloId) || modeloId < 0) continue;

    relations.push({ tipo_id: tipoId, marca_id: marcaId, modelo_id: modeloId });
  }

  return relations;
}

export type CatalogIndex = {
  brandIdsByType: Map<number, Set<number>>;
  modelIdsByTypeBrand: Map<string, Set<number>>;
};

function typeBrandKey(tipoId: number, marcaId: number): string {
  return `${tipoId}|${marcaId}`;
}

/**
 * Indexa a lista de relações tipo->marca->modelo para consulta O(1).
 * `modelo_id` igual a 0 (usado pelas relações transientes criadas no
 * cliente antes de o backend confirmar um modelo real) é aceito no índice
 * de marcas e ignorado no índice de modelos.
 */
export function buildCatalogIndex(relations: EquipmentCatalogRelation[]): CatalogIndex {
  const brandIdsByType = new Map<number, Set<number>>();
  const modelIdsByTypeBrand = new Map<string, Set<number>>();

  for (const relation of relations) {
    if (!brandIdsByType.has(relation.tipo_id)) {
      brandIdsByType.set(relation.tipo_id, new Set());
    }
    brandIdsByType.get(relation.tipo_id)?.add(relation.marca_id);

    if (relation.modelo_id > 0) {
      const key = typeBrandKey(relation.tipo_id, relation.marca_id);
      if (!modelIdsByTypeBrand.has(key)) {
        modelIdsByTypeBrand.set(key, new Set());
      }
      modelIdsByTypeBrand.get(key)?.add(relation.modelo_id);
    }
  }

  return { brandIdsByType, modelIdsByTypeBrand };
}

type AllowedOptions = {
  /** Ids que devem permanecer visíveis mesmo sem relação de catálogo — tipicamente o valor já selecionado (equipamento legado). */
  includeIds?: number[];
};

/**
 * Marcas permitidas para um tipo, segundo o filtro estrito de catálogo
 * (mesma regra do desktop). Um tipo sem nenhuma relação ativa devolve uma
 * lista vazia — é um estado legítimo, não um erro; a UI deve oferecer
 * "+ Nova marca" nesse caso.
 */
export function getAllowedBrands(
  formData: Pick<EquipmentFormData, 'brands' | 'desktop_defaults'>,
  index: CatalogIndex,
  tipoId: number,
  isDesktopFamily: boolean,
  options: AllowedOptions = {},
): EquipmentBrandCatalogItem[] {
  if (!tipoId) {
    return [];
  }

  const allowedIds = new Set<number>(index.brandIdsByType.get(tipoId) ?? []);

  if (isDesktopFamily && formData.desktop_defaults?.marca_id) {
    allowedIds.add(formData.desktop_defaults.marca_id);
  }

  for (const id of options.includeIds ?? []) {
    if (id) {
      allowedIds.add(id);
    }
  }

  return formData.brands.filter((brand) => allowedIds.has(brand.id));
}

/**
 * Modelos permitidos para o par (tipo, marca). Intersecta com
 * `formData.models` (que já exclui inativos, incluindo a âncora) e ainda
 * filtra por nome como defesa extra caso um seed futuro a marque ativa.
 * Um par válido pode legitimamente devolver lista vazia (marca vinculada
 * ao tipo só pela âncora, sem nenhum modelo real cadastrado ainda).
 */
export function getAllowedModels(
  formData: Pick<EquipmentFormData, 'models' | 'desktop_defaults'>,
  index: CatalogIndex,
  tipoId: number,
  marcaId: number,
  isDesktopFamily: boolean,
  options: AllowedOptions = {},
): EquipmentModelCatalogItem[] {
  if (!tipoId || !marcaId) {
    return [];
  }

  const allowedIds = new Set<number>(index.modelIdsByTypeBrand.get(typeBrandKey(tipoId, marcaId)) ?? []);

  if (isDesktopFamily && marcaId === formData.desktop_defaults?.marca_id && formData.desktop_defaults?.modelo_id) {
    allowedIds.add(formData.desktop_defaults.modelo_id);
  }

  for (const id of options.includeIds ?? []) {
    if (id) {
      allowedIds.add(id);
    }
  }

  return formData.models.filter(
    (model) => model.marca_id === marcaId && allowedIds.has(model.id) && model.nome !== BRAND_SCOPE_ANCHOR_MODEL_NAME,
  );
}
