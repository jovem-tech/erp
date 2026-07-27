'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError, fetchAttachmentBlob } from '@/lib/api';
import { getEquipmentDetail, getEquipmentFormData, searchEquipments } from '@/lib/orders';
import type {
  EquipmentDetail,
  EquipmentFormData,
  EquipmentSearchResult,
  EquipmentUpdatePayload,
  NovoEquipamentoPayload,
} from '@/lib/types';
import {
  isWizardEquipmentComplete,
  type WizardMode,
} from '@/components/orders/order-form-wizard/wizard-state';
import { SearchSelect } from '@/components/orders/order-form-wizard/search-select';
import { PhotoPicker } from '@/components/orders/order-form-wizard/photo-picker';

type StepEquipmentProps = {
  mode: WizardMode;
  clienteId: number | null;
  equipamento: EquipmentSearchResult | null;
  pendingNewEquipment: NovoEquipamentoPayload | null;
  pendingEquipmentUpdate?: EquipmentUpdatePayload | null;
  pendingNewEquipmentPhotos: File[];
  onSelectEquipamento: (equipamento: EquipmentSearchResult | null) => void;
  onChangePendingNewEquipment: (payload: NovoEquipamentoPayload | null) => void;
  onChangePendingEquipmentUpdate?: (payload: EquipmentUpdatePayload | null) => void;
  onChangePendingNewEquipmentPhotos: (files: File[]) => void;
  canEditExisting?: boolean;
  disabled?: boolean;
};

const EMPTY_NEW_EQUIPMENT: NovoEquipamentoPayload = { tipo_id: 0, marca_id: 0, modelo_id: 0 };

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
  onSelectEquipamento,
  onChangePendingNewEquipment,
  onChangePendingEquipmentUpdate = () => undefined,
  onChangePendingNewEquipmentPhotos,
  canEditExisting = false,
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

  const fetchEquipmentOptions = useCallback(
    (query: string) => (
      clienteId
        ? searchEquipments({ clientId: clienteId, search: query, perPage: 50 })
        : Promise.resolve([])
    ),
    [clienteId]
  );

  const switchToSearch = (): void => {
    if (mode === 'create' && !clienteId) {
      return;
    }

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
    if (field === 'marca_id' && base.marca_id !== value) {
      next.modelo_id = 0;
    }
    onChangePendingNewEquipment(next);
  };

  const activeEquipmentPayload = editingExisting ? pendingEquipmentUpdate : pendingNewEquipment;
  const selectedType = formData?.types.find((type) => type.id === activeEquipmentPayload?.tipo_id) ?? null;
  const family = selectedType?.family ?? '';
  const modelsForBrand = (formData?.models ?? []).filter(
    (model) => model.marca_id === activeEquipmentPayload?.marca_id
  );

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
        <div className="toolbar" style={{ marginBottom: 16 }}>
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

          {loadingFormData ? <span className="muted">Carregando catálogos...</span> : null}
          {formDataError ? (
            <div className="notice notice--danger">
              <span>{formDataError}</span>
            </div>
          ) : null}

          {formData ? (
            <>
              <label className="field">
                <span className="field__label">Tipo de equipamento *</span>
                <select
                  className="select"
                  value={activeEquipmentPayload?.tipo_id || ''}
                  onChange={(event) => updateField('tipo_id', Number(event.target.value))}
                  disabled={disabled}
                >
                  <option value="">Selecione...</option>
                  {formData.types.map((type) => (
                    <option key={type.id} value={type.id}>
                      {type.nome}
                    </option>
                  ))}
                </select>
              </label>

              <label className="field">
                <span className="field__label">Marca *</span>
                <select
                  className="select"
                  value={activeEquipmentPayload?.marca_id || ''}
                  onChange={(event) => updateField('marca_id', Number(event.target.value))}
                  disabled={disabled || !activeEquipmentPayload?.tipo_id}
                >
                  <option value="">Selecione...</option>
                  {formData.brands.map((brand) => (
                    <option key={brand.id} value={brand.id}>
                      {brand.nome}
                    </option>
                  ))}
                </select>
              </label>

              <label className="field">
                <span className="field__label">Modelo *</span>
                <select
                  className="select"
                  value={activeEquipmentPayload?.modelo_id || ''}
                  onChange={(event) => updateField('modelo_id', Number(event.target.value))}
                  disabled={disabled || !activeEquipmentPayload?.marca_id}
                >
                  <option value="">Selecione...</option>
                  {modelsForBrand.map((model) => (
                    <option key={model.id} value={model.id}>
                      {model.nome}
                    </option>
                  ))}
                </select>
              </label>

              <label className="field">
                <span className="field__label">Cor</span>
                <input className="input" value={activeEquipmentPayload?.cor ?? ''} onChange={(event) => updateField('cor', event.target.value)} disabled={disabled} />
              </label>

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
                <label className="field">
                  <span className="field__label">Padrão do desenho</span>
                  <input
                    className="input"
                    placeholder="Ex.: 1-2-3-6-9"
                    value={activeEquipmentPayload?.senha_desenho ?? ''}
                    onChange={(event) => updateField('senha_desenho', event.target.value)}
                    disabled={disabled}
                  />
                </label>
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
                  label="Fotos do equipamento *"
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
                    : 'Este equipamento será cadastrado somente ao criar a OS.'}
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
      pendingEquipmentUpdate.modelo_id
    );
  }

  return isWizardEquipmentComplete(equipamento, pendingNewEquipment, pendingNewEquipmentPhotos);
}
