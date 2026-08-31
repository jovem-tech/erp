'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ApiError, fetchAttachmentBlob } from '@/lib/api';
import {
  createEquipmentBrand,
  createEquipmentModel,
  getEquipmentDetail,
  getEquipmentFormData,
  searchEquipments,
} from '@/lib/orders';
import type {
  EquipmentBrandCatalogItem,
  EquipmentCatalogRelation,
  EquipmentDetail,
  EquipmentFormData,
  EquipmentModelCatalogItem,
  EquipmentSearchResult,
  EquipmentUpdatePayload,
  LinkableBudget,
  NovoEquipamentoPayload,
} from '@/lib/types';
import {
  buildCatalogIndex,
  getAllowedBrands,
  getAllowedModels,
  normalizeCatalogLabel,
  normalizeCatalogRelations,
} from '@/lib/equipment-catalog';
import {
  isWizardEquipmentComplete,
  type WizardMode,
} from '@/components/orders/order-form-wizard/wizard-state';
import { SearchSelect } from '@/components/orders/order-form-wizard/search-select';
import { CatalogSelect } from '@/components/orders/order-form-wizard/catalog-select';
import { CatalogQuickCreate } from '@/components/orders/order-form-wizard/catalog-quick-create';
import { PhotoPicker } from '@/components/orders/order-form-wizard/photo-picker';
import { PatternLockInput } from '@/components/orders/order-form-wizard/pattern-lock-input';
import { EQUIPMENT_COLOR_OPTIONS, findEquipmentColorOption } from '@/lib/equipment-colors';
import { FieldLabel } from '@/components/ui/field-label';

type StepEquipmentProps = {
  mode: WizardMode;
  clienteId: number | null;
  equipamento: EquipmentSearchResult | null;
  pendingNewEquipment: NovoEquipamentoPayload | null;
  pendingEquipmentUpdate?: EquipmentUpdatePayload | null;
  pendingNewEquipmentPhotos: File[];
  linkedBudget?: LinkableBudget | null;
  onSelectEquipamento: (equipamento: EquipmentSearchResult | null) => void;
  onChangePendingNewEquipment: (payload: NovoEquipamentoPayload | null) => void;
  onChangePendingEquipmentUpdate?: (payload: EquipmentUpdatePayload | null) => void;
  onChangePendingNewEquipmentPhotos: (files: File[]) => void;
  /** Rótulos de tipo/marca/modelo do equipamento novo, para a Revisão mostrar nomes em vez de ids. */
  onChangePendingNewEquipmentLabels?: (labels: { tipo: string; marca: string; modelo: string } | null) => void;
  canEditExisting?: boolean;
  canCreateCatalog?: boolean;
  disabled?: boolean;
};

const EMPTY_NEW_EQUIPMENT: NovoEquipamentoPayload = { tipo_id: 0, marca_id: 0, modelo_id: 0 };

/**
 * Campos que só fazem sentido para a família "desktop". Ao trocar o tipo
 * de equipamento para algo que não é desktop, eles precisam ser limpos —
 * senão um `placa_mae` preenchido para um Desktop pode vazar no payload
 * de um Smartphone depois que o usuário troca o tipo sem perceber.
 */
const DESKTOP_ONLY_FIELD_NAMES = [
  'desktop_modalidade',
  'gabinete_tipo',
  'gabinete_identificacao_status',
  'gabinete_observacao',
  'placa_mae',
  'chipset',
  'processador',
  'memoria_ram',
  'armazenamento',
  'placa_video',
  'fonte_alimentacao',
] as const;

function clearDesktopOnlyFields(payload: Record<string, unknown>): void {
  DESKTOP_ONLY_FIELD_NAMES.forEach((field) => {
    delete payload[field];
  });
}

/**
 * Insere/atualiza uma marca no catálogo local por id (nunca `push` cego):
 * `createBrand` no backend casa por nome globalmente (`firstOrNew`), então
 * POSTar um nome já existente devolve o id JÁ EXISTENTE — um push ingênuo
 * duplicaria a linha e geraria key duplicada no React.
 */
function upsertBrand(formData: EquipmentFormData, brand: EquipmentBrandCatalogItem): EquipmentFormData {
  const brands = [...formData.brands.filter((item) => item.id !== brand.id), brand].sort((a, b) =>
    a.nome.localeCompare(b.nome, 'pt-BR')
  );
  return { ...formData, brands };
}

function upsertModel(formData: EquipmentFormData, model: EquipmentModelCatalogItem): EquipmentFormData {
  const models = [...formData.models.filter((item) => item.id !== model.id), model].sort((a, b) =>
    a.nome.localeCompare(b.nome, 'pt-BR')
  );
  return { ...formData, models };
}

function equipmentDetailToUpdate(detail: EquipmentDetail): EquipmentUpdatePayload {
  return {
    tipo_id: detail.tipo_id,
    marca_id: detail.marca_id,
    modelo_id: detail.modelo_id,
    cor: detail.cor,
    cor_hex: detail.cor_hex,
    cor_rgb: detail.cor_rgb,
    numero_serie: detail.numero_serie,
    imei: detail.imei,
    estado_fisico: detail.estado_fisico,
    observacoes: detail.observacoes,
    desktop_modalidade: detail.desktop_modalidade,
    gabinete_tipo: detail.gabinete_tipo,
    gabinete_identificacao_status: detail.gabinete_identificacao_status,
    gabinete_observacao: detail.gabinete_observacao,
    placa_mae: detail.placa_mae,
    chipset: detail.chipset,
    processador: detail.processador,
    memoria_ram: detail.memoria_ram,
    armazenamento: detail.armazenamento,
    placa_video: detail.placa_video,
    fonte_alimentacao: detail.fonte_alimentacao,
    status_operacional: detail.status_operacional,
    status: detail.status,
  };
}

function equipmentLabel(equipment: EquipmentSearchResult): string {
  return equipment.resumo_tecnico.trim()
    || [equipment.marca_nome, equipment.modelo_nome].filter(Boolean).join(' ')
    || `${equipment.tipo_nome || 'Equipamento'} #${equipment.id}`;
}

function EquipmentThumbnail({ equipment }: { equipment: EquipmentSearchResult }) {
  const containerRef = useRef<HTMLSpanElement>(null);
  const [shouldLoad, setShouldLoad] = useState(false);
  const [photoUrl, setPhotoUrl] = useState<string | null>(null);

  useEffect(() => {
    if (!equipment.primary_photo_id) {
      setShouldLoad(false);
      return;
    }

    if (typeof IntersectionObserver === 'undefined') {
      setShouldLoad(true);
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setShouldLoad(true);
          observer.disconnect();
        }
      },
      { rootMargin: '120px' }
    );

    if (containerRef.current) {
      observer.observe(containerRef.current);
    }

    return () => observer.disconnect();
  }, [equipment.primary_photo_id]);

  useEffect(() => {
    const photoId = equipment.primary_photo_id;
    let cancelled = false;
    let objectUrl: string | null = null;

    setPhotoUrl(null);

    if (!shouldLoad || !photoId) {
      return;
    }

    // A rota é derivada exclusivamente de IDs numéricos retornados pela API.
    // Não reutilizamos uma URL absoluta no header Authorization, evitando
    // encaminhar o Bearer token para uma origem inesperada.
    const sourcePath = `/equipments/${equipment.id}/photos/${photoId}`;

    fetchAttachmentBlob(sourcePath)
      .then((attachment) => {
        if (!attachment.contentType.toLowerCase().startsWith('image/')) {
          return;
        }

        objectUrl = URL.createObjectURL(attachment.blob);
        if (cancelled) {
          URL.revokeObjectURL(objectUrl);
          objectUrl = null;
          return;
        }

        setPhotoUrl(objectUrl);
      })
      .catch(() => {
        // A opção continua identificável por texto caso a foto privada falhe.
      });

    return () => {
      cancelled = true;
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [equipment.id, equipment.primary_photo_id, shouldLoad]);

  return (
    <span className="equipment-thumbnail" ref={containerRef}>
      {photoUrl ? (
        // eslint-disable-next-line @next/next/no-img-element -- URL blob autenticada, criada somente após validar o MIME da API
        <img src={photoUrl} alt={`Foto de ${equipmentLabel(equipment)}`} />
      ) : (
        <span aria-hidden="true">{(equipment.tipo_nome || 'EQ').slice(0, 2).toUpperCase()}</span>
      )}
    </span>
  );
}

export function StepEquipment({
  mode,
  clienteId,
  equipamento,
  pendingNewEquipment,
  pendingEquipmentUpdate = null,
  pendingNewEquipmentPhotos,
  linkedBudget = null,
  onSelectEquipamento,
  onChangePendingNewEquipment,
  onChangePendingEquipmentUpdate = () => undefined,
  onChangePendingNewEquipmentPhotos,
  onChangePendingNewEquipmentLabels = () => undefined,
  canEditExisting = false,
  canCreateCatalog = false,
  disabled = false,
}: StepEquipmentProps) {
  const [view, setView] = useState<'buscar' | 'novo'>(
    equipamento ? 'buscar' : pendingNewEquipment || (mode === 'create' && !clienteId) ? 'novo' : 'buscar'
  );
  const [newEquipmentReason, setNewEquipmentReason] = useState<'new-client' | 'empty-client' | null>(
    mode === 'create' && !clienteId ? 'new-client' : null
  );
  const [editingExisting, setEditingExisting] = useState(false);
  const [loadingExisting, setLoadingExisting] = useState(false);
  const [existingError, setExistingError] = useState<string | null>(null);

  const [formData, setFormData] = useState<EquipmentFormData | null>(null);
  const [loadingFormData, setLoadingFormData] = useState(false);
  const [formDataError, setFormDataError] = useState<string | null>(null);
  const [budgetPrefillPartial, setBudgetPrefillPartial] = useState(false);
  const appliedBudgetIdRef = useRef<number | null>(null);

  // Relações criadas nesta sessão do wizard (marca/modelo cadastrados via
  // "+ Nova marca"/"+ Novo modelo") que ainda não vieram de volta em
  // formData.catalog_relations. Mantidas à parte para o item aparecer na
  // hora na cascata, sem precisar refazer o fetch do catálogo inteiro.
  const [extraRelations, setExtraRelations] = useState<EquipmentCatalogRelation[]>([]);
  const [creating, setCreating] = useState<{ kind: 'marca' | 'modelo'; name: string } | null>(null);
  const [creatingBusy, setCreatingBusy] = useState(false);
  const [creatingError, setCreatingError] = useState<string | null>(null);
  const [creatingNotice, setCreatingNotice] = useState<string | null>(null);
  // Evita aplicar o resultado de um POST em voo depois que o usuário já
  // trocou de view (ex.: voltou para "Equipamento já cadastrado") — mesmo
  // padrão dos flags de cancelamento usados nos effects de foto/detalhe.
  const creatingCancelRef = useRef(false);

  // Cascata estrita tipo -> marca -> modelo (mesma regra do desktop). Um
  // par (tipo, marca) sem nenhuma relação é um estado legítimo — a UI
  // oferece "+ Nova marca"/"+ Novo modelo" nesse caso, não um erro.
  const catalogIndex = useMemo(
    () => buildCatalogIndex([...normalizeCatalogRelations(formData?.catalog_relations), ...extraRelations]),
    [formData, extraRelations]
  );

  const isTypeFamilyDesktop = useCallback(
    (tipoId: number): boolean => formData?.types.find((type) => type.id === tipoId)?.family === 'desktop',
    [formData]
  );

  useEffect(() => {
    if ((view !== 'novo' && !editingExisting) || formData || loadingFormData) {
      return;
    }

    setLoadingFormData(true);
    setFormDataError(null);

    getEquipmentFormData()
      .then((data) => {
        setFormData(data);
      })
      .catch(() => setFormDataError('Não foi possível carregar os catálogos de equipamento.'))
      .finally(() => setLoadingFormData(false));
  }, [view, editingExisting, formData, loadingFormData]);

  useEffect(() => {
    if (
      mode !== 'create'
      || view !== 'novo'
      || editingExisting
      || !formData
      || !linkedBudget
      || appliedBudgetIdRef.current === linkedBudget.id
    ) {
      return;
    }

    appliedBudgetIdRef.current = linkedBudget.id;
    const current = pendingNewEquipment ?? EMPTY_NEW_EQUIPMENT;
    const next: NovoEquipamentoPayload = { ...current };

    const typeLabel = normalizeCatalogLabel(linkedBudget.equipamento_tipo_avulso);
    const type = typeLabel
      ? formData.types.find((item) => normalizeCatalogLabel(item.nome) === typeLabel)
      : undefined;
    if (!next.tipo_id && type) {
      next.tipo_id = type.id;
    }

    // A marca e o modelo só podem ser pré-preenchidos DENTRO do conjunto
    // permitido pelo catálogo para o tipo resolvido — senão o prefill
    // grava um id que o filtro estrito esconde da UI (campo "preso" num
    // valor invisível).
    const effectiveTypeId = next.tipo_id || current.tipo_id || 0;
    const isDesktopFamilyForBudget = isTypeFamilyDesktop(effectiveTypeId);
    const allowedBrandsForBudget = effectiveTypeId
      ? getAllowedBrands(formData, catalogIndex, effectiveTypeId, isDesktopFamilyForBudget)
      : [];

    const brandLabelRaw = linkedBudget.equipamento_marca_avulso?.trim() ?? '';
    const brandLabel = normalizeCatalogLabel(brandLabelRaw);
    const brand = brandLabel
      ? allowedBrandsForBudget.find((item) => normalizeCatalogLabel(item.nome) === brandLabel)
      : undefined;
    if (!next.marca_id && brand) {
      next.marca_id = brand.id;
    }

    const effectiveBrandId = next.marca_id || brand?.id || 0;
    const allowedModelsForBudget = effectiveTypeId && effectiveBrandId
      ? getAllowedModels(formData, catalogIndex, effectiveTypeId, effectiveBrandId, isDesktopFamilyForBudget)
      : [];

    const modelLabelRaw = linkedBudget.equipamento_modelo_avulso?.trim() ?? '';
    const modelLabel = normalizeCatalogLabel(modelLabelRaw);
    const model = modelLabel && effectiveBrandId
      ? allowedModelsForBudget.find((item) => normalizeCatalogLabel(item.nome) === modelLabel)
      : undefined;
    if (!next.modelo_id && model) {
      next.modelo_id = model.id;
    }

    if (!next.cor?.trim() && linkedBudget.equipamento_cor?.trim()) {
      next.cor = linkedBudget.equipamento_cor.trim();
    }

    // O rótulo bruto existia no orçamento mas não casou com nenhum item do
    // catálogo permitido: a nota de "dados reconhecidos" precisa avisar
    // que o reconhecimento foi parcial, em vez de sugerir que tudo bateu.
    setBudgetPrefillPartial((Boolean(brandLabelRaw) && !brand) || (Boolean(modelLabelRaw) && !model));

    if (
      next.tipo_id !== current.tipo_id
      || next.marca_id !== current.marca_id
      || next.modelo_id !== current.modelo_id
      || next.cor !== current.cor
    ) {
      onChangePendingNewEquipment(next);
    }
  }, [
    catalogIndex,
    editingExisting,
    formData,
    isTypeFamilyDesktop,
    linkedBudget,
    mode,
    onChangePendingNewEquipment,
    pendingNewEquipment,
    view,
  ]);

  const fetchEquipmentOptions = useCallback(
    (query: string) => (
      clienteId
        ? searchEquipments({ clientId: clienteId, search: query, perPage: 50 })
        : Promise.resolve([])
    ),
    [clienteId]
  );

  const closeQuickCreate = (): void => {
    creatingCancelRef.current = true;
    setCreating(null);
    setCreatingError(null);
    setCreatingNotice(null);
    setCreatingBusy(false);
  };

  const switchToSearch = (): void => {
    if (mode === 'create' && !clienteId) {
      return;
    }

    closeQuickCreate();
    setView('buscar');
    setEditingExisting(false);
    setNewEquipmentReason(null);
    onChangePendingNewEquipment(null);
    onChangePendingNewEquipmentPhotos([]);
  };

  const switchToNew = (reason: 'new-client' | 'empty-client' | null = null): void => {
    setView('novo');
    setEditingExisting(false);
    setNewEquipmentReason(reason);
    onSelectEquipamento(null);
    if (!pendingNewEquipment) {
      onChangePendingNewEquipment(EMPTY_NEW_EQUIPMENT);
    }
  };

  const handleInitialEquipmentOptions = useCallback(
    (options: EquipmentSearchResult[]): void => {
      if (mode === 'create' && clienteId && options.length === 0) {
        switchToNew('empty-client');
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps -- callbacks do formulário são estáveis durante a etapa
    [clienteId, mode, pendingNewEquipment]
  );

  const updateField = (
    field: keyof NovoEquipamentoPayload | keyof EquipmentUpdatePayload,
    value: NovoEquipamentoPayload[keyof NovoEquipamentoPayload] | EquipmentUpdatePayload[keyof EquipmentUpdatePayload]
  ): void => {
    if (editingExisting && equipamento) {
      const base = pendingEquipmentUpdate ?? {
        tipo_id: equipamento.tipo_id,
        marca_id: 0,
        modelo_id: 0,
      };
      const next = { ...base, [field]: value } as EquipmentUpdatePayload;
      if (field === 'tipo_id' && base.tipo_id !== value) {
        next.marca_id = 0;
        next.modelo_id = 0;
        if (!isTypeFamilyDesktop(value as number)) {
          clearDesktopOnlyFields(next as unknown as Record<string, unknown>);
        }
      }
      if (field === 'marca_id' && base.marca_id !== value) {
        next.modelo_id = 0;
      }
      onChangePendingEquipmentUpdate(next);
      return;
    }

    const base = pendingNewEquipment ?? EMPTY_NEW_EQUIPMENT;
    const next = {
      ...base,
      [field]: value,
    } as NovoEquipamentoPayload;
    if (field === 'tipo_id' && base.tipo_id !== value) {
      next.marca_id = 0;
      next.modelo_id = 0;
      if (!isTypeFamilyDesktop(value as number)) {
        clearDesktopOnlyFields(next as unknown as Record<string, unknown>);
      }
    }
    if (field === 'marca_id' && base.marca_id !== value) {
      next.modelo_id = 0;
    }
    onChangePendingNewEquipment(next);
  };

  /**
   * Atualiza cor + cor_hex + cor_rgb numa única mesclagem. Necessário
   * porque `updateField` chamado três vezes seguidas (um campo por vez)
   * mesclaria cada chamada a partir do MESMO `base` desatualizado — a
   * última chamada sobrescreveria as duas anteriores.
   */
  const updateColor = (cor: string, hex?: string, rgb?: string): void => {
    if (editingExisting && equipamento) {
      const base = pendingEquipmentUpdate ?? {
        tipo_id: equipamento.tipo_id,
        marca_id: 0,
        modelo_id: 0,
      };
      onChangePendingEquipmentUpdate({ ...base, cor, cor_hex: hex, cor_rgb: rgb });
      return;
    }

    const base = pendingNewEquipment ?? EMPTY_NEW_EQUIPMENT;
    onChangePendingNewEquipment({ ...base, cor, cor_hex: hex, cor_rgb: rgb });
  };

  const activeEquipmentPayload = editingExisting ? pendingEquipmentUpdate : pendingNewEquipment;
  const selectedType = formData?.types.find((type) => type.id === activeEquipmentPayload?.tipo_id) ?? null;
  const family = selectedType?.family ?? '';
  const isDesktopFamily = family === 'desktop';

  const allowedBrands = formData && activeEquipmentPayload?.tipo_id
    ? getAllowedBrands(formData, catalogIndex, activeEquipmentPayload.tipo_id, isDesktopFamily, {
        includeIds: activeEquipmentPayload.marca_id ? [activeEquipmentPayload.marca_id] : [],
      })
    : [];
  const selectedBrand = allowedBrands.find((brand) => brand.id === activeEquipmentPayload?.marca_id) ?? null;
  const brandOutsideCatalog = Boolean(
    selectedBrand
    && activeEquipmentPayload?.tipo_id
    && !catalogIndex.brandIdsByType.get(activeEquipmentPayload.tipo_id)?.has(selectedBrand.id)
  );

  const allowedModels = formData && activeEquipmentPayload?.tipo_id && activeEquipmentPayload?.marca_id
    ? getAllowedModels(formData, catalogIndex, activeEquipmentPayload.tipo_id, activeEquipmentPayload.marca_id, isDesktopFamily, {
        includeIds: activeEquipmentPayload.modelo_id ? [activeEquipmentPayload.modelo_id] : [],
      })
    : [];
  const selectedModel = allowedModels.find((model) => model.id === activeEquipmentPayload?.modelo_id) ?? null;
  const modelOutsideCatalog = Boolean(
    selectedModel
    && activeEquipmentPayload?.tipo_id
    && activeEquipmentPayload?.marca_id
    && !catalogIndex.modelIdsByTypeBrand
      .get(`${activeEquipmentPayload.tipo_id}|${activeEquipmentPayload.marca_id}`)
      ?.has(selectedModel.id)
  );

  const handleQuickCreateSubmit = async (nome: string): Promise<void> => {
    if (!creating || !formData || !activeEquipmentPayload?.tipo_id) {
      return;
    }

    const tipoId = activeEquipmentPayload.tipo_id;
    const normalized = normalizeCatalogLabel(nome);
    creatingCancelRef.current = false;
    setCreatingError(null);
    setCreatingNotice(null);

    if (creating.kind === 'marca') {
      // Já permitida para este tipo: só seleciona, sem POST.
      const existingAllowed = allowedBrands.find((brand) => normalizeCatalogLabel(brand.nome) === normalized);
      if (existingAllowed) {
        updateField('marca_id', existingAllowed.id);
        closeQuickCreate();
        return;
      }

      // Existe no catálogo global mas fora do conjunto permitido: o POST
      // ainda é necessário (o backend só cria o vínculo com este tipo),
      // mas o usuário merece saber que não é uma marca nova de verdade.
      const existingGlobal = formData.brands.find((brand) => normalizeCatalogLabel(brand.nome) === normalized);
      if (existingGlobal) {
        setCreatingNotice(
          `Esta marca já existe no catálogo; ela será vinculada a ${selectedType?.nome ?? 'este tipo'}.`
        );
      }

      setCreatingBusy(true);
      try {
        const brand = await createEquipmentBrand(nome, tipoId);
        if (creatingCancelRef.current) {
          return;
        }
        setFormData((prev) => (prev ? upsertBrand(prev, brand) : prev));
        setExtraRelations((prev) => [...prev, { tipo_id: tipoId, marca_id: brand.id, modelo_id: 0 }]);
        updateField('marca_id', brand.id);
        closeQuickCreate();
      } catch (error) {
        if (creatingCancelRef.current) {
          return;
        }
        setCreatingError(
          error instanceof ApiError ? error.message : 'Não foi possível cadastrar a marca. Tente novamente.'
        );
        setCreatingBusy(false);
      }
      return;
    }

    const marcaId = activeEquipmentPayload.marca_id;
    if (!marcaId) {
      return;
    }

    const existingAllowed = allowedModels.find((model) => normalizeCatalogLabel(model.nome) === normalized);
    if (existingAllowed) {
      updateField('modelo_id', existingAllowed.id);
      closeQuickCreate();
      return;
    }

    const existingGlobal = formData.models.find(
      (model) => model.marca_id === marcaId && normalizeCatalogLabel(model.nome) === normalized
    );
    if (existingGlobal) {
      setCreatingNotice(
        `Este modelo já existe no catálogo; ele será vinculado a ${selectedType?.nome ?? 'este tipo'}.`
      );
    }

    setCreatingBusy(true);
    try {
      const model = await createEquipmentModel(marcaId, nome, tipoId);
      if (creatingCancelRef.current) {
        return;
      }
      setFormData((prev) => (prev ? upsertModel(prev, model) : prev));
      setExtraRelations((prev) => [...prev, { tipo_id: tipoId, marca_id: marcaId, modelo_id: model.id }]);
      updateField('modelo_id', model.id);
      closeQuickCreate();
    } catch (error) {
      if (creatingCancelRef.current) {
        return;
      }
      setCreatingError(
        error instanceof ApiError ? error.message : 'Não foi possível cadastrar o modelo. Tente novamente.'
      );
      setCreatingBusy(false);
    }
  };

  const openExistingEditor = async (): Promise<void> => {
    if (!equipamento || !canEditExisting || disabled) {
      return;
    }

    setExistingError(null);
    if (pendingEquipmentUpdate) {
      setEditingExisting(true);
      return;
    }

    setLoadingExisting(true);
    try {
      const detail = await getEquipmentDetail(equipamento.id);
      if (detail.cliente_id !== clienteId) {
        throw new Error('O equipamento não pertence ao cliente selecionado.');
      }
      onChangePendingEquipmentUpdate(equipmentDetailToUpdate(detail));
      setEditingExisting(true);
    } catch (error) {
      setExistingError(error instanceof ApiError ? error.message : 'Não foi possível carregar os dados do equipamento.');
    } finally {
      setLoadingExisting(false);
    }
  };

  return (
    <section className="section">
      <div className="section__header">
        <h3 className="section__title">Equipamento</h3>
      </div>

      {mode === 'create' ? (
        <div className="toolbar toolbar--section-leading">
          <div className="toolbar__group">
            <button
              type="button"
              className={view === 'buscar' ? 'button button--primary button-small' : 'button button--soft button-small'}
              onClick={switchToSearch}
              disabled={disabled || !clienteId}
              title={!clienteId ? 'O cliente novo ainda não possui equipamentos cadastrados.' : undefined}
            >
              Equipamento já cadastrado
            </button>
            <button
              type="button"
              className={view === 'novo' ? 'button button--primary button-small' : 'button button--soft button-small'}
              onClick={() => switchToNew(null)}
              disabled={disabled}
            >
              Equipamento novo
            </button>
          </div>
        </div>
      ) : null}

      {(view === 'buscar' || mode === 'edit') && !editingExisting ? (
        <>
          <SearchSelect<EquipmentSearchResult>
            label="Buscar equipamento"
            placeholder="Marca, modelo, nº de série ou resumo técnico"
            value={equipamento}
            onSelect={(value) => {
              setEditingExisting(false);
              onSelectEquipamento(value);
            }}
            fetchOptions={fetchEquipmentOptions}
            getOptionKey={(option) => option.id}
            getOptionLabel={equipmentLabel}
            getOptionSubtitle={(option) => [
              option.tipo_nome,
              option.numero_serie ? `Série ${option.numero_serie}` : '',
            ].filter(Boolean).join(' • ') || null}
            renderOptionLeading={(option) => <EquipmentThumbnail equipment={option} />}
            renderSelectedActions={
              mode === 'create'
                ? () => (
                    <button
                      type="button"
                      className="button button--ghost button-small"
                      onClick={() => void openExistingEditor()}
                      disabled={disabled || loadingExisting || !canEditExisting}
                      title={!canEditExisting ? 'Sem permissão para editar equipamentos.' : undefined}
                    >
                      {loadingExisting ? 'Carregando...' : 'Editar'}
                    </button>
                  )
                : undefined
            }
            loadOnFocus
            onInitialOptionsLoaded={handleInitialEquipmentOptions}
            emptyMessage="Nenhum equipamento cadastrado para este cliente."
            disabled={disabled}
          />
          {existingError ? (
            <div className="notice notice--danger">
              <span>{existingError}</span>
            </div>
          ) : null}
        </>
      ) : (
        <div className="form">
          {editingExisting ? (
            <div className="toolbar">
              <div>
                <strong>Editar equipamento selecionado</strong>
                <div className="muted">As alterações serão aplicadas somente ao salvar a OS.</div>
              </div>
              <button
                type="button"
                className="button button--soft button-small"
                onClick={() => setEditingExisting(false)}
                disabled={disabled}
              >
                Concluir edição
              </button>
            </div>
          ) : null}
          {!editingExisting && newEquipmentReason === 'empty-client' ? (
            <div className="notice">
              <span>Nenhum equipamento cadastrado para este cliente. Prossiga com o novo cadastro; o vínculo será feito ao criar a OS.</span>
            </div>
          ) : null}
          {!editingExisting && newEquipmentReason === 'new-client' ? (
            <div className="notice">
              <span>Como o cliente também é novo, cadastre o equipamento para que ambos sejam vinculados ao criar a OS.</span>
            </div>
          ) : null}
          {!editingExisting && linkedBudget ? (
            <div className="notice">
              <span>
                Equipamento informado no orçamento {linkedBudget.numero}:{' '}
                {linkedBudget.equipamento_resumo || 'não detalhado'}.{' '}
                {budgetPrefillPartial
                  ? 'Só parte dos dados foi reconhecida no catálogo — confira marca e modelo antes de avançar.'
                  : 'Os dados reconhecidos foram pré-preenchidos; confirme os campos antes de avançar.'}
              </span>
            </div>
          ) : null}

          {loadingFormData ? <span className="muted">Carregando catálogos...</span> : null}
          {formDataError ? (
            <div className="notice notice--danger">
              <span>{formDataError}</span>
            </div>
          ) : null}

          {formData ? (
            <>
              <CatalogSelect
                label={<FieldLabel required>Tipo de equipamento</FieldLabel>}
                placeholder="Buscar tipo de equipamento..."
                options={formData.types}
                value={selectedType}
                onSelect={(type) => {
                  updateField('tipo_id', type ? type.id : 0);
                  onChangePendingNewEquipmentLabels(null);
                }}
                getOptionKey={(type) => type.id}
                getOptionLabel={(type) => type.nome}
                required
                disabled={disabled}
              />

              <CatalogSelect
                label={<FieldLabel required>Marca</FieldLabel>}
                placeholder="Buscar marca..."
                options={allowedBrands}
                value={selectedBrand}
                onSelect={(brand) => {
                  updateField('marca_id', brand ? brand.id : 0);
                  onChangePendingNewEquipmentLabels(null);
                }}
                getOptionKey={(brand) => brand.id}
                getOptionLabel={(brand) => brand.nome}
                required
                disabled={disabled || !activeEquipmentPayload?.tipo_id}
                emptyMessage={
                  selectedType
                    ? `Nenhuma marca vinculada a ${selectedType.nome} ainda.`
                    : 'Nenhuma marca encontrada.'
                }
                helpText={brandOutsideCatalog ? 'Vínculo atual fora do catálogo deste tipo.' : undefined}
                initialQuery={!selectedBrand ? linkedBudget?.equipamento_marca_avulso ?? '' : ''}
                createAction={{
                  label: '+ Nova marca',
                  onTrigger: (query) => {
                    setCreating({ kind: 'marca', name: query });
                    setCreatingError(null);
                    setCreatingNotice(null);
                  },
                  disabled: disabled || !canCreateCatalog || !activeEquipmentPayload?.tipo_id,
                  disabledReason: !canCreateCatalog ? 'Sem permissão para cadastrar marcas.' : undefined,
                }}
              />

              {creating?.kind === 'marca' ? (
                <CatalogQuickCreate
                  kind="marca"
                  initialName={creating.name}
                  contextLabel={selectedType?.nome ?? ''}
                  busy={creatingBusy}
                  error={creatingError}
                  notice={creatingNotice}
                  onCancel={closeQuickCreate}
                  onSubmit={(nome) => void handleQuickCreateSubmit(nome)}
                />
              ) : null}

              <CatalogSelect
                label={<FieldLabel required>Modelo</FieldLabel>}
                placeholder="Buscar modelo..."
                options={allowedModels}
                value={selectedModel}
                onSelect={(model) => {
                  updateField('modelo_id', model ? model.id : 0);
                  onChangePendingNewEquipmentLabels(
                    model && selectedType && selectedBrand
                      ? { tipo: selectedType.nome, marca: selectedBrand.nome, modelo: model.nome }
                      : null
                  );
                }}
                getOptionKey={(model) => model.id}
                getOptionLabel={(model) => model.nome}
                required
                disabled={disabled || !activeEquipmentPayload?.marca_id}
                emptyMessage={
                  selectedBrand
                    ? `Nenhum modelo cadastrado para ${selectedBrand.nome} ainda.`
                    : 'Nenhum modelo encontrado.'
                }
                helpText={modelOutsideCatalog ? 'Vínculo atual fora do catálogo desta marca.' : undefined}
                initialQuery={!selectedModel ? linkedBudget?.equipamento_modelo_avulso ?? '' : ''}
                createAction={{
                  label: '+ Novo modelo',
                  onTrigger: (query) => {
                    setCreating({ kind: 'modelo', name: query });
                    setCreatingError(null);
                    setCreatingNotice(null);
                  },
                  disabled: disabled || !canCreateCatalog || !activeEquipmentPayload?.marca_id,
                  disabledReason: !canCreateCatalog ? 'Sem permissão para cadastrar modelos.' : undefined,
                }}
              />

              {creating?.kind === 'modelo' ? (
                <CatalogQuickCreate
                  kind="modelo"
                  initialName={creating.name}
                  contextLabel={[selectedType?.nome, selectedBrand?.nome].filter(Boolean).join(' • ')}
                  busy={creatingBusy}
                  error={creatingError}
                  notice={creatingNotice}
                  onCancel={closeQuickCreate}
                  onSubmit={(nome) => void handleQuickCreateSubmit(nome)}
                />
              ) : null}

              <div className="field">
                <FieldLabel required>Cor</FieldLabel>
                <div className="toolbar toolbar--compact-spaced">
                  <div className="toolbar__group">
                    {EQUIPMENT_COLOR_OPTIONS.map((option) => {
                      const isSelected =
                        normalizeCatalogLabel(activeEquipmentPayload?.cor) === normalizeCatalogLabel(option.label);
                      return (
                        <button
                          key={option.label}
                          type="button"
                          className={isSelected ? 'chip chip--suggestion chip--selected' : 'chip chip--suggestion'}
                          aria-pressed={isSelected}
                          onClick={() => updateColor(option.label, option.hex, option.rgb)}
                          disabled={disabled}
                        >
                          {option.label}
                        </button>
                      );
                    })}
                  </div>
                </div>
                <input
                  className="input"
                  value={activeEquipmentPayload?.cor ?? ''}
                  onChange={(event) => {
                    const value = event.target.value;
                    const match = findEquipmentColorOption(value);
                    updateColor(value, match?.hex, match?.rgb);
                  }}
                  maxLength={50}
                  disabled={disabled}
                  aria-required="true"
                />
              </div>

              <label className="field">
                <span className="field__label">Número de série</span>
                <input
                  className="input"
                  value={
                    editingExisting
                      ? pendingEquipmentUpdate?.numero_serie ?? ''
                      : pendingNewEquipment?.numero_serie_visual ?? ''
                  }
                  onChange={(event) =>
                    updateField(editingExisting ? 'numero_serie' : 'numero_serie_visual', event.target.value)
                  }
                  disabled={disabled}
                />
              </label>

              <label className="field">
                <span className="field__label">IMEI</span>
                <input className="input" value={activeEquipmentPayload?.imei ?? ''} onChange={(event) => updateField('imei', event.target.value)} disabled={disabled} />
              </label>

              <label className="field">
                <span className="field__label">Tipo de senha</span>
                <select
                  className="select"
                  value={activeEquipmentPayload?.senha_tipo ?? ''}
                  onChange={(event) => updateField('senha_tipo', (event.target.value || undefined) as 'desenho' | 'texto' | undefined)}
                  disabled={disabled}
                >
                  <option value="">Nenhuma</option>
                  {formData.password_modes.map((mode) => (
                    <option key={mode.value} value={mode.value}>
                      {mode.label}
                    </option>
                  ))}
                </select>
              </label>

              {activeEquipmentPayload?.senha_tipo === 'texto' ? (
                <label className="field">
                  <span className="field__label">Senha de acesso</span>
                  <input
                    className="input"
                    value={activeEquipmentPayload?.senha_acesso ?? ''}
                    onChange={(event) => updateField('senha_acesso', event.target.value)}
                    disabled={disabled}
                  />
                </label>
              ) : null}

              {activeEquipmentPayload?.senha_tipo === 'desenho' ? (
                <div className="field">
                  <span className="field__label">Padrão do desenho</span>
                  <PatternLockInput
                    value={activeEquipmentPayload?.senha_desenho ?? ''}
                    onChange={(next) => updateField('senha_desenho', next)}
                    disabled={disabled}
                  />
                </div>
              ) : null}

              <label className="field">
                <span className="field__label">Estado físico</span>
                <textarea
                  className="textarea"
                  value={activeEquipmentPayload?.estado_fisico ?? ''}
                  onChange={(event) => updateField('estado_fisico', event.target.value)}
                  disabled={disabled}
                />
              </label>

              <label className="field">
                <span className="field__label">Observações</span>
                <textarea
                  className="textarea"
                  value={activeEquipmentPayload?.observacoes ?? ''}
                  onChange={(event) => updateField('observacoes', event.target.value)}
                  disabled={disabled}
                />
              </label>

              {family === 'desktop' ? (
                <>
                  <label className="field">
                    <span className="field__label">Modalidade</span>
                    <select
                      className="select"
                      value={activeEquipmentPayload?.desktop_modalidade ?? ''}
                      onChange={(event) => updateField('desktop_modalidade', (event.target.value || undefined) as 'montado' | 'oem' | undefined)}
                      disabled={disabled}
                    >
                      <option value="">Selecione...</option>
                      <option value="montado">Montado</option>
                      <option value="oem">OEM</option>
                    </select>
                  </label>

                  {activeEquipmentPayload?.desktop_modalidade === 'montado' ? (
                    <>
                      <label className="field">
                        <span className="field__label">Tipo de gabinete</span>
                        <input
                          className="input"
                          value={activeEquipmentPayload?.gabinete_tipo ?? ''}
                          onChange={(event) => updateField('gabinete_tipo', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                      <label className="field">
                        <span className="field__label">Placa-mãe</span>
                        <input className="input" value={activeEquipmentPayload?.placa_mae ?? ''} onChange={(event) => updateField('placa_mae', event.target.value)} disabled={disabled} />
                      </label>
                      <label className="field">
                        <span className="field__label">Chipset</span>
                        <input className="input" value={activeEquipmentPayload?.chipset ?? ''} onChange={(event) => updateField('chipset', event.target.value)} disabled={disabled} />
                      </label>
                      <label className="field">
                        <span className="field__label">Processador</span>
                        <input
                          className="input"
                          value={activeEquipmentPayload?.processador ?? ''}
                          onChange={(event) => updateField('processador', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                      <label className="field">
                        <span className="field__label">Memória RAM</span>
                        <input
                          className="input"
                          value={activeEquipmentPayload?.memoria_ram ?? ''}
                          onChange={(event) => updateField('memoria_ram', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                      <label className="field">
                        <span className="field__label">Armazenamento</span>
                        <input
                          className="input"
                          value={activeEquipmentPayload?.armazenamento ?? ''}
                          onChange={(event) => updateField('armazenamento', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                      <label className="field">
                        <span className="field__label">Placa de vídeo</span>
                        <input
                          className="input"
                          value={activeEquipmentPayload?.placa_video ?? ''}
                          onChange={(event) => updateField('placa_video', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                      <label className="field">
                        <span className="field__label">Fonte de alimentação</span>
                        <input
                          className="input"
                          value={activeEquipmentPayload?.fonte_alimentacao ?? ''}
                          onChange={(event) => updateField('fonte_alimentacao', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                    </>
                  ) : null}
                </>
              ) : null}

              {!editingExisting ? (
                <PhotoPicker
                  label="Fotos do equipamento"
                  required
                  value={pendingNewEquipmentPhotos}
                  onChange={onChangePendingNewEquipmentPhotos}
                  maxFiles={4}
                  disabled={disabled}
                  helpText="Pelo menos 1 foto é obrigatória para cadastrar um equipamento novo."
                />
              ) : null}

              <div className="notice notice--warning">
                <span>
                  {editingExisting
                    ? 'Nenhuma alteração será gravada antes do salvamento final da OS.'
                    : 'Este equipamento será cadastrado somente ao criar a OS. Marcas e modelos novos, porém, são gravados no catálogo assim que você confirma o cadastro.'}
                </span>
              </div>
            </>
          ) : null}
        </div>
      )}
    </section>
  );
}

export function isStepEquipmentValid(
  equipamento: EquipmentSearchResult | null,
  pendingNewEquipment: NovoEquipamentoPayload | null,
  pendingNewEquipmentPhotos: File[],
  pendingEquipmentUpdate: EquipmentUpdatePayload | null = null
): boolean {
  if (pendingEquipmentUpdate) {
    return Boolean(
      pendingEquipmentUpdate.tipo_id &&
      pendingEquipmentUpdate.marca_id &&
      pendingEquipmentUpdate.modelo_id &&
      pendingEquipmentUpdate.cor?.trim()
    );
  }

  return isWizardEquipmentComplete(equipamento, pendingNewEquipment, pendingNewEquipmentPhotos);
}
