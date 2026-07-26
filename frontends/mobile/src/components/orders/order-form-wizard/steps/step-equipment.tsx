'use client';

import { useCallback, useEffect, useState } from 'react';
import { ApiError } from '@/lib/api';
import { createEquipmentBrand, createEquipmentModel, getEquipmentFormData, searchEquipments } from '@/lib/orders';
import type {
  EquipmentBrandCatalogItem,
  EquipmentFormData,
  EquipmentModelCatalogItem,
  EquipmentSearchResult,
  NovoEquipamentoPayload,
} from '@/lib/types';
import type { WizardMode } from '@/components/orders/order-form-wizard/wizard-state';
import { SearchSelect } from '@/components/orders/order-form-wizard/search-select';
import { PhotoPicker } from '@/components/orders/order-form-wizard/photo-picker';

type StepEquipmentProps = {
  mode: WizardMode;
  clienteId: number | null;
  equipamento: EquipmentSearchResult | null;
  pendingNewEquipment: NovoEquipamentoPayload | null;
  pendingNewEquipmentPhotos: File[];
  onSelectEquipamento: (equipamento: EquipmentSearchResult | null) => void;
  onChangePendingNewEquipment: (payload: NovoEquipamentoPayload | null) => void;
  onChangePendingNewEquipmentPhotos: (files: File[]) => void;
  disabled?: boolean;
};

const EMPTY_NEW_EQUIPMENT: NovoEquipamentoPayload = { tipo_id: 0, marca_id: 0, modelo_id: 0 };

export function StepEquipment({
  mode,
  clienteId,
  equipamento,
  pendingNewEquipment,
  pendingNewEquipmentPhotos,
  onSelectEquipamento,
  onChangePendingNewEquipment,
  onChangePendingNewEquipmentPhotos,
  disabled = false,
}: StepEquipmentProps) {
  const [view, setView] = useState<'buscar' | 'novo'>(equipamento ? 'buscar' : pendingNewEquipment ? 'novo' : 'buscar');

  const [formData, setFormData] = useState<EquipmentFormData | null>(null);
  const [loadingFormData, setLoadingFormData] = useState(false);
  const [formDataError, setFormDataError] = useState<string | null>(null);
  const [brands, setBrands] = useState<EquipmentBrandCatalogItem[]>([]);
  const [models, setModels] = useState<EquipmentModelCatalogItem[]>([]);

  const [showNewBrand, setShowNewBrand] = useState(false);
  const [newBrandName, setNewBrandName] = useState('');
  const [showNewModel, setShowNewModel] = useState(false);
  const [newModelName, setNewModelName] = useState('');
  const [catalogBusy, setCatalogBusy] = useState(false);
  const [catalogError, setCatalogError] = useState<string | null>(null);

  useEffect(() => {
    if (view !== 'novo' || formData || loadingFormData) {
      return;
    }

    setLoadingFormData(true);
    setFormDataError(null);

    getEquipmentFormData()
      .then((data) => {
        setFormData(data);
        setBrands(data.brands);
        setModels(data.models);
      })
      .catch(() => setFormDataError('Não foi possível carregar os catálogos de equipamento.'))
      .finally(() => setLoadingFormData(false));
  }, [view, formData, loadingFormData]);

  const fetchEquipmentOptions = useCallback(
    (query: string) => searchEquipments({ clientId: clienteId ?? undefined, search: query }),
    [clienteId]
  );

  const switchToSearch = (): void => {
    setView('buscar');
    onChangePendingNewEquipment(null);
    onChangePendingNewEquipmentPhotos([]);
  };

  const switchToNew = (): void => {
    setView('novo');
    onSelectEquipamento(null);
    if (!pendingNewEquipment) {
      onChangePendingNewEquipment(EMPTY_NEW_EQUIPMENT);
    }
  };

  const updateField = <K extends keyof NovoEquipamentoPayload>(field: K, value: NovoEquipamentoPayload[K]): void => {
    onChangePendingNewEquipment({ ...(pendingNewEquipment ?? EMPTY_NEW_EQUIPMENT), [field]: value });
  };

  const selectedType = formData?.types.find((type) => type.id === pendingNewEquipment?.tipo_id) ?? null;
  const family = selectedType?.family ?? '';
  const modelsForBrand = models.filter((model) => model.marca_id === pendingNewEquipment?.marca_id);

  const handleCreateBrand = async (): Promise<void> => {
    if (!newBrandName.trim() || !pendingNewEquipment?.tipo_id) {
      return;
    }

    setCatalogBusy(true);
    setCatalogError(null);

    try {
      const brand = await createEquipmentBrand(newBrandName.trim(), pendingNewEquipment.tipo_id);
      setBrands((current) => [...current, brand]);
      updateField('marca_id', brand.id);
      setNewBrandName('');
      setShowNewBrand(false);
    } catch (error) {
      setCatalogError(error instanceof ApiError ? error.message : 'Não foi possível cadastrar a marca.');
    } finally {
      setCatalogBusy(false);
    }
  };

  const handleCreateModel = async (): Promise<void> => {
    if (!newModelName.trim() || !pendingNewEquipment?.marca_id || !pendingNewEquipment?.tipo_id) {
      return;
    }

    setCatalogBusy(true);
    setCatalogError(null);

    try {
      const model = await createEquipmentModel(pendingNewEquipment.marca_id, newModelName.trim(), pendingNewEquipment.tipo_id);
      setModels((current) => [...current, model]);
      updateField('modelo_id', model.id);
      setNewModelName('');
      setShowNewModel(false);
    } catch (error) {
      setCatalogError(error instanceof ApiError ? error.message : 'Não foi possível cadastrar o modelo.');
    } finally {
      setCatalogBusy(false);
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
              disabled={disabled}
            >
              Equipamento já cadastrado
            </button>
            <button
              type="button"
              className={view === 'novo' ? 'button button--primary button-small' : 'button button--soft button-small'}
              onClick={switchToNew}
              disabled={disabled}
            >
              Equipamento novo
            </button>
          </div>
        </div>
      ) : null}

      {view === 'buscar' || mode === 'edit' ? (
        <SearchSelect<EquipmentSearchResult>
          label="Buscar equipamento"
          placeholder="Marca, modelo, nº de série ou resumo técnico"
          value={equipamento}
          onSelect={onSelectEquipamento}
          fetchOptions={fetchEquipmentOptions}
          getOptionKey={(option) => option.id}
          getOptionLabel={(option) => option.resumo_tecnico || `${option.marca_nome} ${option.modelo_nome}`}
          getOptionSubtitle={(option) => [option.cliente_nome, option.numero_serie].filter(Boolean).join(' • ') || null}
          disabled={disabled}
        />
      ) : (
        <div className="form">
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
                  value={pendingNewEquipment?.tipo_id || ''}
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
                  value={pendingNewEquipment?.marca_id || ''}
                  onChange={(event) => updateField('marca_id', Number(event.target.value))}
                  disabled={disabled || !pendingNewEquipment?.tipo_id}
                >
                  <option value="">Selecione...</option>
                  {brands.map((brand) => (
                    <option key={brand.id} value={brand.id}>
                      {brand.nome}
                    </option>
                  ))}
                </select>
                {!showNewBrand ? (
                  <button
                    type="button"
                    className="button button--ghost button-small"
                    onClick={() => setShowNewBrand(true)}
                    disabled={disabled || !pendingNewEquipment?.tipo_id}
                  >
                    + Nova marca
                  </button>
                ) : (
                  <div className="toolbar">
                    <input
                      className="input"
                      placeholder="Nome da marca"
                      value={newBrandName}
                      onChange={(event) => setNewBrandName(event.target.value)}
                      disabled={disabled || catalogBusy}
                    />
                    <button type="button" className="button button--soft button-small" onClick={handleCreateBrand} disabled={disabled || catalogBusy}>
                      Salvar
                    </button>
                  </div>
                )}
              </label>

              <label className="field">
                <span className="field__label">Modelo *</span>
                <select
                  className="select"
                  value={pendingNewEquipment?.modelo_id || ''}
                  onChange={(event) => updateField('modelo_id', Number(event.target.value))}
                  disabled={disabled || !pendingNewEquipment?.marca_id}
                >
                  <option value="">Selecione...</option>
                  {modelsForBrand.map((model) => (
                    <option key={model.id} value={model.id}>
                      {model.nome}
                    </option>
                  ))}
                </select>
                {!showNewModel ? (
                  <button
                    type="button"
                    className="button button--ghost button-small"
                    onClick={() => setShowNewModel(true)}
                    disabled={disabled || !pendingNewEquipment?.marca_id}
                  >
                    + Novo modelo
                  </button>
                ) : (
                  <div className="toolbar">
                    <input
                      className="input"
                      placeholder="Nome do modelo"
                      value={newModelName}
                      onChange={(event) => setNewModelName(event.target.value)}
                      disabled={disabled || catalogBusy}
                    />
                    <button type="button" className="button button--soft button-small" onClick={handleCreateModel} disabled={disabled || catalogBusy}>
                      Salvar
                    </button>
                  </div>
                )}
              </label>

              {catalogError ? (
                <div className="notice notice--danger">
                  <span>{catalogError}</span>
                </div>
              ) : null}

              <label className="field">
                <span className="field__label">Cor</span>
                <input className="input" value={pendingNewEquipment?.cor ?? ''} onChange={(event) => updateField('cor', event.target.value)} disabled={disabled} />
              </label>

              <label className="field">
                <span className="field__label">Número de série</span>
                <input
                  className="input"
                  value={pendingNewEquipment?.numero_serie_visual ?? ''}
                  onChange={(event) => updateField('numero_serie_visual', event.target.value)}
                  disabled={disabled}
                />
              </label>

              <label className="field">
                <span className="field__label">IMEI</span>
                <input className="input" value={pendingNewEquipment?.imei ?? ''} onChange={(event) => updateField('imei', event.target.value)} disabled={disabled} />
              </label>

              <label className="field">
                <span className="field__label">Tipo de senha</span>
                <select
                  className="select"
                  value={pendingNewEquipment?.senha_tipo ?? ''}
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

              {pendingNewEquipment?.senha_tipo === 'texto' ? (
                <label className="field">
                  <span className="field__label">Senha de acesso</span>
                  <input
                    className="input"
                    value={pendingNewEquipment?.senha_acesso ?? ''}
                    onChange={(event) => updateField('senha_acesso', event.target.value)}
                    disabled={disabled}
                  />
                </label>
              ) : null}

              {pendingNewEquipment?.senha_tipo === 'desenho' ? (
                <label className="field">
                  <span className="field__label">Padrão do desenho</span>
                  <input
                    className="input"
                    placeholder="Ex.: 1-2-3-6-9"
                    value={pendingNewEquipment?.senha_desenho ?? ''}
                    onChange={(event) => updateField('senha_desenho', event.target.value)}
                    disabled={disabled}
                  />
                </label>
              ) : null}

              <label className="field">
                <span className="field__label">Estado físico</span>
                <textarea
                  className="textarea"
                  value={pendingNewEquipment?.estado_fisico ?? ''}
                  onChange={(event) => updateField('estado_fisico', event.target.value)}
                  disabled={disabled}
                />
              </label>

              <label className="field">
                <span className="field__label">Observações</span>
                <textarea
                  className="textarea"
                  value={pendingNewEquipment?.observacoes ?? ''}
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
                      value={pendingNewEquipment?.desktop_modalidade ?? ''}
                      onChange={(event) => updateField('desktop_modalidade', (event.target.value || undefined) as 'montado' | 'oem' | undefined)}
                      disabled={disabled}
                    >
                      <option value="">Selecione...</option>
                      <option value="montado">Montado</option>
                      <option value="oem">OEM</option>
                    </select>
                  </label>

                  {pendingNewEquipment?.desktop_modalidade === 'montado' ? (
                    <>
                      <label className="field">
                        <span className="field__label">Tipo de gabinete</span>
                        <input
                          className="input"
                          value={pendingNewEquipment?.gabinete_tipo ?? ''}
                          onChange={(event) => updateField('gabinete_tipo', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                      <label className="field">
                        <span className="field__label">Placa-mãe</span>
                        <input className="input" value={pendingNewEquipment?.placa_mae ?? ''} onChange={(event) => updateField('placa_mae', event.target.value)} disabled={disabled} />
                      </label>
                      <label className="field">
                        <span className="field__label">Chipset</span>
                        <input className="input" value={pendingNewEquipment?.chipset ?? ''} onChange={(event) => updateField('chipset', event.target.value)} disabled={disabled} />
                      </label>
                      <label className="field">
                        <span className="field__label">Processador</span>
                        <input
                          className="input"
                          value={pendingNewEquipment?.processador ?? ''}
                          onChange={(event) => updateField('processador', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                      <label className="field">
                        <span className="field__label">Memória RAM</span>
                        <input
                          className="input"
                          value={pendingNewEquipment?.memoria_ram ?? ''}
                          onChange={(event) => updateField('memoria_ram', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                      <label className="field">
                        <span className="field__label">Armazenamento</span>
                        <input
                          className="input"
                          value={pendingNewEquipment?.armazenamento ?? ''}
                          onChange={(event) => updateField('armazenamento', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                      <label className="field">
                        <span className="field__label">Placa de vídeo</span>
                        <input
                          className="input"
                          value={pendingNewEquipment?.placa_video ?? ''}
                          onChange={(event) => updateField('placa_video', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                      <label className="field">
                        <span className="field__label">Fonte de alimentação</span>
                        <input
                          className="input"
                          value={pendingNewEquipment?.fonte_alimentacao ?? ''}
                          onChange={(event) => updateField('fonte_alimentacao', event.target.value)}
                          disabled={disabled}
                        />
                      </label>
                    </>
                  ) : null}
                </>
              ) : null}

              <PhotoPicker
                label="Fotos do equipamento *"
                value={pendingNewEquipmentPhotos}
                onChange={onChangePendingNewEquipmentPhotos}
                maxFiles={4}
                disabled={disabled}
                helpText="Pelo menos 1 foto é obrigatória para cadastrar um equipamento novo."
              />

              <div className="notice notice--warning">
                <span>Este equipamento será cadastrado somente ao criar a OS.</span>
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
