'use client';

import { useEffect, useRef, useState } from 'react';
import { ApiError } from '@/lib/api';
import {
  getClientDetail,
  getLinkableBudgetDetail,
  isApprovedBudget,
  listApprovedBudgetsForClient,
  lookupCepAddress,
  searchAvulsoBudgetContacts,
  searchClients,
} from '@/lib/orders';
import { formatCep, formatPhone, isPhoneComplete, onlyDigits } from '@/lib/input-masks';
import type {
  ClientDetail,
  ClientSearchResult,
  ClientUpdatePayload,
  LinkableBudget,
  NovoClientePayload,
} from '@/lib/types';
import {
  isWizardClientComplete,
  type WizardMode,
} from '@/components/orders/order-form-wizard/wizard-state';
import { SearchSelect } from '@/components/orders/order-form-wizard/search-select';
import { FieldLabel } from '@/components/ui/field-label';

type StepClientProps = {
  mode: WizardMode;
  cliente: ClientSearchResult | null;
  pendingNewClient: NovoClientePayload | null;
  pendingClientUpdate?: ClientUpdatePayload | null;
  onSelectCliente: (cliente: ClientSearchResult | null) => void;
  onChangePendingNewClient: (payload: NovoClientePayload | null) => void;
  onChangePendingClientUpdate?: (payload: ClientUpdatePayload | null) => void;
  linkedBudget?: LinkableBudget | null;
  onChangeLinkedBudget?: (budget: LinkableBudget | null) => void;
  canLinkBudget?: boolean;
  canEditExisting?: boolean;
  disabled?: boolean;
};

const EMPTY_NEW_CLIENT: NovoClientePayload = { nome_razao: '', telefone1: '' };

function clientDetailToUpdate(detail: ClientDetail): ClientUpdatePayload {
  return {
    tipo_pessoa: detail.tipo_pessoa || 'fisica',
    nome_razao: detail.nome_razao,
    cpf_cnpj: detail.cpf_cnpj,
    rg_ie: detail.rg_ie,
    email: detail.email,
    telefone1: formatPhone(detail.telefone1),
    telefone2: formatPhone(detail.telefone2),
    nome_contato: detail.nome_contato,
    telefone_contato: formatPhone(detail.telefone_contato),
    cep: formatCep(detail.cep),
    endereco: detail.endereco,
    numero: detail.numero,
    complemento: detail.complemento,
    referencia: detail.referencia,
    bairro: detail.bairro,
    cidade: detail.cidade,
    uf: detail.uf,
    observacoes: detail.observacoes,
    status_cadastro: detail.status_cadastro || 'completo',
    preferencia_contato: detail.preferencia_contato,
  };
}

export function StepClient({
  mode,
  cliente,
  pendingNewClient,
  pendingClientUpdate = null,
  onSelectCliente,
  onChangePendingNewClient,
  onChangePendingClientUpdate = () => undefined,
  linkedBudget = null,
  onChangeLinkedBudget = () => undefined,
  canLinkBudget = false,
  canEditExisting = false,
  disabled = false,
}: StepClientProps) {
  const [view, setView] = useState<'buscar' | 'novo'>(cliente ? 'buscar' : pendingNewClient ? 'novo' : 'buscar');
  const [editingExisting, setEditingExisting] = useState(false);
  const [loadingExisting, setLoadingExisting] = useState(false);
  const [existingError, setExistingError] = useState<string | null>(null);
  const [budgetMatches, setBudgetMatches] = useState<LinkableBudget[]>([]);
  const [budgetCandidate, setBudgetCandidate] = useState<LinkableBudget | null>(null);
  const [dismissedBudgetId, setDismissedBudgetId] = useState<number | null>(null);
  const [budgetLoading, setBudgetLoading] = useState(false);
  const [budgetError, setBudgetError] = useState<string | null>(null);
  const [approvedBudgets, setApprovedBudgets] = useState<LinkableBudget[]>([]);
  const [approvedBudgetsLoading, setApprovedBudgetsLoading] = useState(false);
  const [approvedBudgetsError, setApprovedBudgetsError] = useState<string | null>(null);
  const [linkingBudgetId, setLinkingBudgetId] = useState<number | null>(null);
  const [cepLoading, setCepLoading] = useState(false);
  const [cepMessage, setCepMessage] = useState<string | null>(null);
  const [cepError, setCepError] = useState<string | null>(null);
  const cepRequestId = useRef(0);

  const activePayload = editingExisting ? pendingClientUpdate : pendingNewClient;
  const activePayloadRef = useRef<ClientUpdatePayload | NovoClientePayload | null>(activePayload);
  activePayloadRef.current = activePayload;

  useEffect(() => {
    if (
      mode !== 'create'
      || view !== 'novo'
      || !canLinkBudget
      || budgetCandidate
      || linkedBudget
    ) {
      setBudgetMatches([]);
      setBudgetLoading(false);
      return;
    }

    const name = pendingNewClient?.nome_razao.trim() ?? '';
    const phone = onlyDigits(pendingNewClient?.telefone1 ?? '', 11);
    const query = phone.length >= 8 ? phone : name.length >= 3 ? name : '';
    if (!query) {
      setBudgetMatches([]);
      setBudgetError(null);
      return;
    }

    let cancelled = false;
    const timer = window.setTimeout(() => {
      setBudgetLoading(true);
      setBudgetError(null);

      searchAvulsoBudgetContacts(query)
        .then((budgets) => {
          if (!cancelled) {
            setBudgetMatches(
              budgets.filter((budget) => budget.linkable !== false && budget.id !== dismissedBudgetId)
            );
          }
        })
        .catch((error) => {
          if (!cancelled) {
            setBudgetMatches([]);
            setBudgetError(
              error instanceof ApiError
                ? error.message
                : 'Não foi possível consultar os orçamentos em aberto.'
            );
          }
        })
        .finally(() => {
          if (!cancelled) {
            setBudgetLoading(false);
          }
        });
    }, 350);

    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [
    budgetCandidate,
    canLinkBudget,
    dismissedBudgetId,
    linkedBudget,
    mode,
    pendingNewClient?.nome_razao,
    pendingNewClient?.telefone1,
    view,
  ]);

  // Cliente cadastrado escolhido: busca os orçamentos dele que já foram
  // aprovados e ainda não viraram OS. É o caminho de "abrir a OS a partir de
  // um orçamento aprovado" — o técnico só precisa escolher qual.
  const clienteId = cliente?.id ?? null;
  useEffect(() => {
    if (mode !== 'create' || !canLinkBudget || clienteId === null) {
      setApprovedBudgets([]);
      setApprovedBudgetsError(null);
      setApprovedBudgetsLoading(false);
      return;
    }

    let cancelled = false;
    setApprovedBudgetsLoading(true);
    setApprovedBudgetsError(null);

    listApprovedBudgetsForClient(clienteId)
      .then((budgets) => {
        if (!cancelled) {
          setApprovedBudgets(budgets);
        }
      })
      .catch((error) => {
        if (!cancelled) {
          setApprovedBudgets([]);
          setApprovedBudgetsError(
            error instanceof ApiError
              ? error.message
              : 'Não foi possível consultar os orçamentos aprovados deste cliente.'
          );
        }
      })
      .finally(() => {
        if (!cancelled) {
          setApprovedBudgetsLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [canLinkBudget, clienteId, mode]);

  const switchToSearch = (): void => {
    setView('buscar');
    setEditingExisting(false);
    setBudgetCandidate(null);
    setBudgetMatches([]);
    onChangeLinkedBudget(null);
    onChangePendingNewClient(null);
  };

  const switchToNew = (): void => {
    setView('novo');
    setEditingExisting(false);
    setBudgetCandidate(null);
    setBudgetMatches([]);
    onChangeLinkedBudget(null);
    onSelectCliente(null);
    if (!pendingNewClient) {
      onChangePendingNewClient(EMPTY_NEW_CLIENT);
    }
  };

  const patchFields = (fields: Partial<ClientUpdatePayload>): void => {
    if (editingExisting && cliente) {
      const base = (activePayloadRef.current as ClientUpdatePayload | null) ?? {
        ...EMPTY_NEW_CLIENT,
        tipo_pessoa: cliente.tipo_pessoa || 'fisica',
        status_cadastro: cliente.status_cadastro || 'completo',
      };
      const next = { ...base, ...fields };
      activePayloadRef.current = next;
      onChangePendingClientUpdate(next);
      return;
    }

    const next = {
      ...((activePayloadRef.current as NovoClientePayload | null) ?? EMPTY_NEW_CLIENT),
      ...fields,
    };
    activePayloadRef.current = next;
    onChangePendingNewClient(next);
  };

  const updateField = (field: keyof ClientUpdatePayload, value: string): void => {
    patchFields({ [field]: value });
  };

  const updateIdentityField = (field: 'nome_razao' | 'telefone1', value: string): void => {
    if (budgetCandidate || linkedBudget) {
      setBudgetCandidate(null);
      setDismissedBudgetId(null);
      onChangeLinkedBudget(null);
    }
    updateField(field, value);
  };

  const selectBudgetCandidate = (budget: LinkableBudget): void => {
    setBudgetCandidate(budget);
    setBudgetMatches([]);
    setDismissedBudgetId(null);
    patchFields({
      nome_razao: budget.cliente_nome_avulso?.trim() || pendingNewClient?.nome_razao || '',
      telefone1: formatPhone(
        budget.telefone_contato?.trim() || pendingNewClient?.telefone1 || ''
      ),
      email: budget.email_contato?.trim() || pendingNewClient?.email || '',
    });
  };

  /**
   * A listagem usada em `budgetMatches`/`approvedBudgets` não traz
   * `equipamento_*_avulso`/`equipamento_cor` (só o detalhe traz). Sem eles, a
   * etapa Equipamento não tem como casar tipo/marca/modelo do orçamento
   * contra o catálogo para pré-preencher os campos. Busca o detalhe completo
   * antes de vincular; se falhar, vincula com o resumo mesmo assim — o
   * técnico ainda preenche o equipamento manualmente.
   */
  const linkBudget = (budget: LinkableBudget): void => {
    setLinkingBudgetId(budget.id);
    getLinkableBudgetDetail(budget.id)
      .then((detail) => onChangeLinkedBudget({ ...budget, ...detail }))
      .catch(() => onChangeLinkedBudget(budget))
      .finally(() => setLinkingBudgetId(null));
  };

  const handleCepChange = (value: string): void => {
    const cep = formatCep(value);
    const requestId = ++cepRequestId.current;
    updateField('cep', cep);
    setCepMessage(null);
    setCepError(null);

    if (onlyDigits(cep).length !== 8) {
      setCepLoading(false);
      return;
    }

    setCepLoading(true);
    lookupCepAddress(cep)
      .then((address) => {
        if (cepRequestId.current !== requestId) {
          return;
        }

        patchFields({
          cep: formatCep(address.cep),
          endereco: address.endereco,
          bairro: address.bairro,
          cidade: address.cidade,
          uf: address.uf,
        });
        setCepMessage('Endereço preenchido automaticamente. Confira o número e o complemento.');
      })
      .catch((error) => {
        if (cepRequestId.current !== requestId) {
          return;
        }
        setCepError(
          error instanceof ApiError
            ? error.message
            : 'Não foi possível consultar o CEP. Preencha o endereço manualmente.'
        );
      })
      .finally(() => {
        if (cepRequestId.current === requestId) {
          setCepLoading(false);
        }
      });
  };

  const openExistingEditor = async (): Promise<void> => {
    if (!cliente || !canEditExisting || disabled) {
      return;
    }

    setExistingError(null);
    if (pendingClientUpdate) {
      setEditingExisting(true);
      return;
    }

    setLoadingExisting(true);
    try {
      const detail = await getClientDetail(cliente.id);
      onChangePendingClientUpdate(clientDetailToUpdate(detail));
      setEditingExisting(true);
    } catch (error) {
      setExistingError(error instanceof ApiError ? error.message : 'Não foi possível carregar os dados do cliente.');
    } finally {
      setLoadingExisting(false);
    }
  };

  return (
    <section className="section">
      <div className="section__header">
        <h3 className="section__title">Cliente</h3>
      </div>

      {mode === 'create' ? (
        <div className="toolbar toolbar--section-leading">
          <div className="toolbar__group">
            <button
              type="button"
              className={view === 'buscar' ? 'button button--primary button-small' : 'button button--soft button-small'}
              onClick={switchToSearch}
              disabled={disabled}
            >
              Cliente já cadastrado
            </button>
            <button
              type="button"
              className={view === 'novo' ? 'button button--primary button-small' : 'button button--soft button-small'}
              onClick={switchToNew}
              disabled={disabled}
            >
              Cliente novo
            </button>
          </div>
        </div>
      ) : null}

      {(view === 'buscar' || mode === 'edit') && !editingExisting ? (
        <>
          <SearchSelect<ClientSearchResult>
            label="Buscar cliente"
            placeholder="Nome, telefone, e-mail ou CPF/CNPJ"
            value={cliente}
            onSelect={(value) => {
              setEditingExisting(false);
              onSelectCliente(value);
            }}
            fetchOptions={searchClients}
            getOptionKey={(option) => option.id}
            getOptionLabel={(option) => option.nome_razao}
            getOptionSubtitle={(option) => [option.telefone1, option.cidade].filter(Boolean).join(' • ') || null}
            renderSelectedActions={
              mode === 'create'
                ? () => (
                    <button
                      type="button"
                      className="button button--ghost button-small"
                      onClick={() => void openExistingEditor()}
                      disabled={disabled || loadingExisting || !canEditExisting}
                      title={!canEditExisting ? 'Sem permissão para editar clientes.' : undefined}
                    >
                      {loadingExisting ? 'Carregando...' : 'Editar'}
                    </button>
                  )
                : undefined
            }
            disabled={disabled}
          />
          {existingError ? (
            <div className="notice notice--danger">
              <span>{existingError}</span>
            </div>
          ) : null}

          {mode === 'create' && canLinkBudget && cliente ? (
            <>
              {approvedBudgetsLoading ? (
                <span className="muted" role="status">Consultando orçamentos aprovados deste cliente...</span>
              ) : null}
              {approvedBudgetsError ? (
                <div className="notice notice--danger" role="alert">
                  <span>{approvedBudgetsError}</span>
                </div>
              ) : null}
              {linkedBudget ? (
                <div className="notice">
                  <div>
                    <strong>OS a partir do orçamento {linkedBudget.numero}</strong>
                    <div>{linkedBudget.equipamento_resumo || 'Equipamento não informado'}</div>
                    {linkedBudget.total_formatado ? (
                      <div className="muted">Total: R$ {linkedBudget.total_formatado}</div>
                    ) : null}
                    {isApprovedBudget(linkedBudget) ? (
                      <div className="muted">
                        Orçamento já aprovado: a OS será aberta em Aguardando Reparo.
                      </div>
                    ) : null}
                    <div className="toolbar__group toolbar__group--offset">
                      <button
                        type="button"
                        className="button button--soft button-small"
                        onClick={() => onChangeLinkedBudget(null)}
                        disabled={disabled}
                      >
                        Desvincular
                      </button>
                    </div>
                  </div>
                </div>
              ) : approvedBudgets.length > 0 ? (
                <div className="notice">
                  <div>
                    <strong>Orçamentos aprovados deste cliente</strong>
                    <div className="muted">
                      Abra a OS a partir de um deles: os valores vêm do orçamento e a OS já entra em
                      Aguardando Reparo.
                    </div>
                    <div className="toolbar__group toolbar__group--offset">
                      {approvedBudgets.map((budget) => (
                        <button
                          key={budget.id}
                          type="button"
                          className="button button--soft button-small"
                          onClick={() => linkBudget(budget)}
                          disabled={disabled || linkingBudgetId === budget.id}
                        >
                          {linkingBudgetId === budget.id
                            ? 'Vinculando...'
                            : `${budget.numero} · ${budget.equipamento_resumo || 'Equipamento não informado'}${budget.total_formatado ? ` · R$ ${budget.total_formatado}` : ''}`}
                        </button>
                      ))}
                    </div>
                  </div>
                </div>
              ) : null}
            </>
          ) : null}
        </>
      ) : (
        <div className="form">
          {editingExisting ? (
            <div className="toolbar">
              <div>
                <strong>Editar cliente selecionado</strong>
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

          <label className="field">
            <FieldLabel required>Nome / razão social</FieldLabel>
            <input
              className="input"
              value={activePayload?.nome_razao ?? ''}
              onChange={(event) => updateIdentityField('nome_razao', event.target.value)}
              disabled={disabled}
              aria-required="true"
            />
          </label>

          {!editingExisting && canLinkBudget && budgetLoading ? (
            <span className="muted" role="status">Consultando orçamentos em aberto...</span>
          ) : null}
          {!editingExisting && canLinkBudget && budgetError ? (
            <div className="notice notice--danger" role="alert">
              <span>{budgetError}</span>
            </div>
          ) : null}
          {!editingExisting && canLinkBudget && budgetMatches.length > 0 ? (
            <div className="notice notice--warning">
              <div>
                <strong>Orçamento em aberto encontrado</strong>
                <div className="muted">Confira se algum orçamento pertence a este atendimento.</div>
                <div className="toolbar__group toolbar__group--offset">
                  {budgetMatches.map((budget) => (
                    <button
                      key={budget.id}
                      type="button"
                      className="button button--soft button-small"
                      onClick={() => selectBudgetCandidate(budget)}
                      disabled={disabled}
                    >
                      {budget.numero} · {budget.equipamento_resumo || 'Equipamento não informado'}
                    </button>
                  ))}
                </div>
              </div>
            </div>
          ) : null}
          {!editingExisting && canLinkBudget && (budgetCandidate || linkedBudget) ? (
            <div className="notice">
              <div>
                <strong>
                  {linkedBudget ? 'Orçamento vinculado à OS' : 'Este é o orçamento do cliente?'}
                </strong>
                <div>
                  {(linkedBudget ?? budgetCandidate)?.numero}
                  {' · '}
                  {(linkedBudget ?? budgetCandidate)?.equipamento_resumo || 'Equipamento não informado'}
                </div>
                {(linkedBudget ?? budgetCandidate)?.total_formatado ? (
                  <div className="muted">
                    Total: R$ {(linkedBudget ?? budgetCandidate)?.total_formatado}
                  </div>
                ) : null}
                <div className="toolbar__group toolbar__group--offset">
                  {!linkedBudget && budgetCandidate ? (
                    <button
                      type="button"
                      className="button button--primary button-small"
                      onClick={() => linkBudget(budgetCandidate)}
                      disabled={disabled || linkingBudgetId === budgetCandidate.id}
                    >
                      {linkingBudgetId === budgetCandidate.id ? 'Vinculando...' : 'Vincular à OS'}
                    </button>
                  ) : null}
                  <button
                    type="button"
                    className="button button--soft button-small"
                    onClick={() => {
                      const budget = linkedBudget ?? budgetCandidate;
                      setDismissedBudgetId(budget?.id ?? null);
                      setBudgetCandidate(null);
                      onChangeLinkedBudget(null);
                    }}
                    disabled={disabled}
                  >
                    {linkedBudget ? 'Desvincular' : 'Não vincular'}
                  </button>
                </div>
              </div>
            </div>
          ) : null}

          <label className="field">
            <FieldLabel required>Telefone</FieldLabel>
            <input
              className="input"
              type="tel"
              inputMode="tel"
              maxLength={14}
              value={activePayload?.telefone1 ?? ''}
              onChange={(event) => updateIdentityField('telefone1', formatPhone(event.target.value))}
              disabled={disabled}
              aria-required="true"
            />
          </label>

          <label className="field">
            <span className="field__label">Telefone secundário</span>
            <input
              className="input"
              type="tel"
              inputMode="tel"
              maxLength={14}
              value={activePayload?.telefone2 ?? ''}
              onChange={(event) => updateField('telefone2', formatPhone(event.target.value))}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">E-mail</span>
            <input
              className="input"
              type="email"
              value={activePayload?.email ?? ''}
              onChange={(event) => updateField('email', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">CPF/CNPJ</span>
            <input
              className="input"
              value={activePayload?.cpf_cnpj ?? ''}
              onChange={(event) => updateField('cpf_cnpj', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Nome do contato</span>
            <input
              className="input"
              value={activePayload?.nome_contato ?? ''}
              onChange={(event) => updateField('nome_contato', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Telefone do contato</span>
            <input
              className="input"
              type="tel"
              inputMode="tel"
              maxLength={14}
              value={activePayload?.telefone_contato ?? ''}
              onChange={(event) => updateField('telefone_contato', formatPhone(event.target.value))}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">CEP</span>
            <input
              className="input"
              inputMode="numeric"
              maxLength={9}
              value={activePayload?.cep ?? ''}
              onChange={(event) => handleCepChange(event.target.value)}
              disabled={disabled}
            />
            {cepLoading ? <span className="muted" role="status">Buscando endereço...</span> : null}
            {cepMessage ? <span className="muted" role="status">{cepMessage}</span> : null}
            {cepError ? <span className="field__error" role="alert">{cepError}</span> : null}
          </label>

          <label className="field">
            <span className="field__label">Endereço</span>
            <input
              className="input"
              value={activePayload?.endereco ?? ''}
              onChange={(event) => updateField('endereco', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Número</span>
            <input
              className="input"
              value={activePayload?.numero ?? ''}
              onChange={(event) => updateField('numero', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Complemento</span>
            <input
              className="input"
              value={activePayload?.complemento ?? ''}
              onChange={(event) => updateField('complemento', event.target.value)}
              disabled={disabled}
            />
          </label>

          {editingExisting ? (
            <label className="field">
              <span className="field__label">Referência</span>
              <input
                className="input"
                value={pendingClientUpdate?.referencia ?? ''}
                onChange={(event) => updateField('referencia', event.target.value)}
                disabled={disabled}
              />
            </label>
          ) : null}

          <label className="field">
            <span className="field__label">Bairro</span>
            <input
              className="input"
              value={activePayload?.bairro ?? ''}
              onChange={(event) => updateField('bairro', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">Cidade</span>
            <input
              className="input"
              value={activePayload?.cidade ?? ''}
              onChange={(event) => updateField('cidade', event.target.value)}
              disabled={disabled}
            />
          </label>

          <label className="field">
            <span className="field__label">UF</span>
            <input
              className="input"
              maxLength={2}
              value={activePayload?.uf ?? ''}
              onChange={(event) => updateField('uf', event.target.value.toUpperCase())}
              disabled={disabled}
            />
          </label>

          {editingExisting ? (
            <label className="field">
              <span className="field__label">Observações</span>
              <textarea
                className="textarea"
                value={pendingClientUpdate?.observacoes ?? ''}
                onChange={(event) => updateField('observacoes', event.target.value)}
                disabled={disabled}
              />
            </label>
          ) : null}

          <div className="notice notice--warning">
            <span>
              {editingExisting
                ? 'Nenhuma alteração será gravada antes do salvamento final da OS.'
                : 'Este cliente será cadastrado somente ao criar a OS.'}
            </span>
          </div>
        </div>
      )}
    </section>
  );
}

export function isStepClientValid(
  cliente: ClientSearchResult | null,
  pendingNewClient: NovoClientePayload | null,
  pendingClientUpdate: ClientUpdatePayload | null = null
): boolean {
  if (pendingClientUpdate) {
    return Boolean(
      pendingClientUpdate.nome_razao.trim()
      && isPhoneComplete(pendingClientUpdate.telefone1)
    );
  }

  return isWizardClientComplete(cliente, pendingNewClient);
}
