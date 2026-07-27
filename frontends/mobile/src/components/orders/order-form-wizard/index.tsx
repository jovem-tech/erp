'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { ApiError } from '@/lib/api';
import { createOrder, getEntryChecklistModel, updateOrder } from '@/lib/orders';
import { hasPermission } from '@/lib/permissions';
import { useSession } from '@/components/session-provider';
import type { OrderDetail } from '@/lib/types';
import {
  buildOrderPayload,
  areWizardRequiredFieldsComplete,
  createInitialWizardState,
  createWizardStateFromOrder,
  isChecklistComplete,
  isWizardDirty,
  resolveEquipmentTypeId,
  selectClientForWizard,
  selectEquipmentForWizard,
  type WizardFormState,
  type WizardMode,
} from '@/components/orders/order-form-wizard/wizard-state';
import { WizardStepper, type WizardStepInfo } from '@/components/orders/order-form-wizard/wizard-stepper';
import { StepClient, isStepClientValid } from '@/components/orders/order-form-wizard/steps/step-client';
import { StepEquipment, isStepEquipmentValid } from '@/components/orders/order-form-wizard/steps/step-equipment';
import { StepChecklist } from '@/components/orders/order-form-wizard/steps/step-checklist';
import { StepDetails, isStepDetailsValid } from '@/components/orders/order-form-wizard/steps/step-details';
import { StepOperations, isStepOperationsValid } from '@/components/orders/order-form-wizard/steps/step-operations';
import { StepPhotos } from '@/components/orders/order-form-wizard/steps/step-photos';
import {
  StepReview,
  type ReviewSection,
  type ReviewSectionKey,
} from '@/components/orders/order-form-wizard/steps/step-review';
import { useOrderCreationPlayer } from '@/components/orders/order-creation-player';

type OrderFormWizardProps = {
  mode: WizardMode;
  order?: OrderDetail;
  idempotencyKey?: string;
};

function buildReviewSections(
  state: WizardFormState,
  steps: WizardStepInfo[],
  mode: WizardMode,
  verifiedSections: Partial<Record<ReviewSectionKey, boolean>>
): ReviewSection[] {
  const stepIndex = (key: string): number => steps.findIndex((step) => step.key === key);

  const sections: ReviewSection[] = [];

  sections.push({
    key: 'cliente',
    title: 'Cliente',
    stepIndex: stepIndex('cliente'),
    verified: verifiedSections.cliente === true,
    rows: state.pendingClientUpdate
      ? [
          { label: 'Cliente editado', value: state.pendingClientUpdate.nome_razao },
          { label: 'Telefone', value: state.pendingClientUpdate.telefone1 },
        ]
      : state.cliente
      ? [
          { label: 'Cliente', value: state.cliente.nome_razao },
          { label: 'Telefone', value: state.cliente.telefone1 },
        ]
      : state.pendingNewClient
        ? [
            { label: 'Cliente novo', value: state.pendingNewClient.nome_razao },
            { label: 'Telefone', value: state.pendingNewClient.telefone1 },
          ]
        : [],
  });

  sections.push({
    key: 'equipamento',
    title: 'Equipamento',
    stepIndex: stepIndex('equipamento'),
    verified: verifiedSections.equipamento === true,
    rows: state.pendingEquipmentUpdate
      ? [
          {
            label: 'Equipamento editado',
            value: state.equipamento?.resumo_tecnico || state.equipamento?.tipo_nome || 'Equipamento selecionado',
          },
          { label: 'Número de série', value: state.pendingEquipmentUpdate.numero_serie ?? '' },
          { label: 'IMEI', value: state.pendingEquipmentUpdate.imei ?? '' },
        ]
      : state.equipamento
      ? [{ label: 'Equipamento', value: state.equipamento.resumo_tecnico || `${state.equipamento.marca_nome} ${state.equipamento.modelo_nome}` }]
      : state.pendingNewEquipment
        ? [
            { label: 'Equipamento', value: 'Cadastro novo' },
            { label: 'Fotos anexadas', value: String(state.pendingNewEquipmentPhotos.length) },
          ]
        : [],
  });

  if (state.checklistModel) {
    const total = state.checklistModel.itens.length;
    const answered = state.checklistModel.itens.filter((item) => state.checklistAnswers[item.id]).length;
    sections.push({
      key: 'checklist',
      title: 'Checklist de entrada',
      stepIndex: stepIndex('checklist'),
      verified: verifiedSections.checklist === true,
      rows: [{ label: 'Itens respondidos', value: `${answered}/${total}` }],
    });
  }

  sections.push({
    key: 'detalhes',
    title: 'Relato e defeito',
    stepIndex: stepIndex('detalhes'),
    verified: verifiedSections.detalhes === true,
    rows: [
      { label: 'Relato do cliente', value: state.relatoCliente },
      { label: 'Acessórios', value: state.acessorios },
    ],
  });

  sections.push({
    key: 'atendimento',
    title: 'Atendimento',
    stepIndex: stepIndex('atendimento'),
    verified: verifiedSections.atendimento === true,
    rows: [
      { label: 'Prioridade', value: state.prioridade },
      {
        label: 'Prazo',
        value: state.prazoEntregaDias
          ? `${state.prazoEntregaDias} ${state.prazoEntregaDias === 1 ? 'dia corrido' : 'dias corridos'}`
          : '',
      },
      { label: 'Previsão de entrega', value: state.dataPrevisao },
      { label: 'Técnico responsável', value: state.tecnicoLabel ?? '' },
    ],
  });

  sections.push({
    key: 'fotos',
    title: 'Fotos',
    stepIndex: stepIndex('fotos'),
    verified: verifiedSections.fotos === true,
    rows: [{ label: 'Fotos anexadas', value: String(state.fotos.length) }],
  });

  if (mode === 'create') {
    sections.push({
      key: 'extras',
      title: 'Extras',
      stepIndex: stepIndex('atendimento'),
      verified: verifiedSections.extras === true,
      rows: [
        { label: 'Orçamento vinculado', value: state.orcamentoVinculado?.numero ?? 'Nenhum' },
      ],
    });
  }

  return sections;
}

export function OrderFormWizard({ mode, order, idempotencyKey }: OrderFormWizardProps) {
  const router = useRouter();
  const { session } = useSession();

  const [state, setState] = useState<WizardFormState>(() =>
    mode === 'edit' && order ? createWizardStateFromOrder(order) : createInitialWizardState()
  );
  const [currentIndex, setCurrentIndex] = useState(0);
  const [maxVisitedIndex, setMaxVisitedIndex] = useState(0);
  const [busy, setBusy] = useState(false);
  const [checklistLoading, setChecklistLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successState, setSuccessState] = useState<{ orderId: number; warnings: string[] } | null>(null);
  const [verifiedSections, setVerifiedSections] = useState<Partial<Record<ReviewSectionKey, boolean>>>({});
  const submittingRef = useRef(false);

  const canLinkBudget = hasPermission(session?.user, 'orcamentos', 'converter_os');
  const canEditClient = hasPermission(session?.user, 'clientes', 'editar');
  const canEditEquipment = hasPermission(session?.user, 'equipamentos', 'editar');
  const equipmentTypeId = resolveEquipmentTypeId(state);

  useEffect(() => {
    const prevId = state.checklistModel?.id ?? null;

    if (!equipmentTypeId) {
      setChecklistLoading(false);
      if (prevId !== null) {
        setState((prev) => ({ ...prev, checklistModel: null, checklistAnswers: {} }));
      }
      return;
    }

    let cancelled = false;
    setChecklistLoading(true);

    getEntryChecklistModel(equipmentTypeId)
      .then((modelo) => {
        if (cancelled) {
          return;
        }

        setState((prev) => {
          const currentId = prev.checklistModel?.id ?? null;
          const nextId = modelo?.id ?? null;

          if (currentId === nextId) {
            return prev;
          }

          return { ...prev, checklistModel: modelo, checklistAnswers: {} };
        });
      })
      .catch(() => {
        if (!cancelled) {
          setState((prev) => ({ ...prev, checklistModel: null, checklistAnswers: {} }));
        }
      })
      .finally(() => {
        if (!cancelled) {
          setChecklistLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- so' deve re-executar quando o tipo de equipamento muda
  }, [equipmentTypeId]);

  const steps: WizardStepInfo[] = useMemo(() => {
    const list: WizardStepInfo[] = [
      { key: 'cliente', label: 'Cliente' },
      { key: 'equipamento', label: 'Equipamento' },
    ];

    if (state.checklistModel && state.checklistModel.itens.length > 0) {
      list.push({ key: 'checklist', label: 'Checklist' });
    }

    list.push({ key: 'detalhes', label: 'Relato' });
    list.push({ key: 'atendimento', label: 'Atendimento' });
    list.push({ key: 'fotos', label: 'Fotos' });

    list.push({ key: 'revisao', label: 'Revisão' });

    return list;
  }, [state.checklistModel]);

  useEffect(() => {
    if (currentIndex >= steps.length) {
      setCurrentIndex(steps.length - 1);
    }
  }, [steps.length, currentIndex]);

  const currentStepKey = steps[currentIndex]?.key;

  const isCurrentStepValid = useCallback((): boolean => {
    switch (currentStepKey) {
      case 'cliente':
        return isStepClientValid(state.cliente, state.pendingNewClient, state.pendingClientUpdate);
      case 'equipamento':
        return isStepEquipmentValid(
          state.equipamento,
          state.pendingNewEquipment,
          state.pendingNewEquipmentPhotos,
          state.pendingEquipmentUpdate
        );
      case 'checklist':
        return isChecklistComplete(state);
      case 'detalhes':
        return isStepDetailsValid(state.relatoCliente);
      case 'atendimento':
        return isStepOperationsValid(state.tecnicoId, state.prazoEntregaDias, state.dataPrevisao);
      default:
        return true;
    }
  }, [currentStepKey, state]);

  const goNext = useCallback((): void => {
    const nextIndex = Math.min(currentIndex + 1, steps.length - 1);
    setCurrentIndex(nextIndex);
    setMaxVisitedIndex((prev) => Math.max(prev, nextIndex));
  }, [currentIndex, steps.length]);

  const goBack = useCallback((): void => {
    setCurrentIndex((prev) => Math.max(prev - 1, 0));
  }, []);

  const goToStep = useCallback((index: number): void => {
    if (index <= maxVisitedIndex) {
      const stepKey = steps[index]?.key;
      setVerifiedSections((current) => {
        const next = { ...current };

        if (stepKey === 'cliente') {
          delete next.cliente;
          delete next.equipamento;
          delete next.checklist;
        }
        if (stepKey === 'equipamento') {
          delete next.equipamento;
          delete next.checklist;
        }
        if (stepKey === 'checklist') delete next.checklist;
        if (stepKey === 'detalhes') delete next.detalhes;
        if (stepKey === 'atendimento') {
          delete next.atendimento;
          delete next.extras;
        }
        if (stepKey === 'fotos') delete next.fotos;

        return next;
      });
      setCurrentIndex(index);
    }
  }, [maxVisitedIndex, steps]);

  const requiredFieldsComplete = useMemo(
    () => areWizardRequiredFieldsComplete(state),
    [state]
  );
  const dirty = useMemo(() => isWizardDirty(state), [state]);
  const reviewSections = useMemo(
    () => buildReviewSections(state, steps, mode, verifiedSections),
    [mode, state, steps, verifiedSections]
  );
  const reviewComplete = useMemo(
    () => reviewSections.length > 0 && reviewSections.every((section) => section.verified),
    [reviewSections]
  );

  const handleSubmit = useCallback(async (): Promise<void> => {
    if (
      submittingRef.current ||
      busy ||
      !reviewComplete ||
      (mode === 'create' && (!requiredFieldsComplete || checklistLoading))
    ) {
      return;
    }

    submittingRef.current = true;
    setBusy(true);
    setErrorMessage(null);

    try {
      if (mode === 'create') {
        const payload = buildOrderPayload(state, 'create', idempotencyKey ?? '');
        const result = await createOrder(payload, state.fotos, state.pendingNewEquipmentPhotos);

        if (result.warnings.length > 0) {
          setSuccessState({ orderId: result.order.id, warnings: result.warnings });
        } else {
          router.replace(`/os/${result.order.id}`);
        }
      } else if (order) {
        const payload = buildOrderPayload(state, 'edit');
        await updateOrder(order.id, payload, state.fotos);
        router.replace(`/os/${order.id}`);
      }
    } catch (error) {
      setErrorMessage(error instanceof ApiError ? error.message : 'Não foi possível salvar a OS. Tente novamente.');
      if (mode === 'create') {
        const reviewIndex = steps.length - 1;
        setCurrentIndex(reviewIndex);
        setMaxVisitedIndex(reviewIndex);
      }
    } finally {
      submittingRef.current = false;
      setBusy(false);
    }
  }, [
    busy,
    checklistLoading,
    idempotencyKey,
    mode,
    order,
    requiredFieldsComplete,
    reviewComplete,
    router,
    state,
    steps.length,
  ]);

  const creationPlayerController = useMemo(
    () =>
      mode === 'create'
        ? {
            canGoBack: !successState && currentIndex > 0,
            canGoNext: !successState && currentIndex < steps.length - 1 && isCurrentStepValid(),
            canSave: requiredFieldsComplete && reviewComplete && !checklistLoading && !busy && !successState,
            busy,
            dirty: !successState && dirty,
            onBack: goBack,
            onNext: goNext,
            onSave: () => void handleSubmit(),
          }
        : null,
    [
      busy,
      checklistLoading,
      currentIndex,
      dirty,
      goBack,
      goNext,
      handleSubmit,
      isCurrentStepValid,
      mode,
      requiredFieldsComplete,
      reviewComplete,
      steps.length,
      successState,
    ]
  );

  useOrderCreationPlayer(creationPlayerController);

  useEffect(() => {
    if (mode !== 'create' || !dirty || successState) {
      return;
    }

    const preventAccidentalExit = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = true;
    };

    window.addEventListener('beforeunload', preventAccidentalExit);
    return () => window.removeEventListener('beforeunload', preventAccidentalExit);
  }, [dirty, mode, successState]);

  if (successState) {
    return (
      <section className="section">
        <div className="notice">
          <span>OS criada com sucesso. Alguns passos extras não puderam ser concluídos: {successState.warnings.join(' ')}</span>
        </div>
        <div className="toolbar" style={{ marginTop: 16 }}>
          <button
            type="button"
            className="button button--primary"
            onClick={() => router.replace(`/os/${successState.orderId}`)}
          >
            Ver OS criada
          </button>
        </div>
      </section>
    );
  }

  return (
    <section>
      <WizardStepper steps={steps} currentIndex={currentIndex} maxVisitedIndex={maxVisitedIndex} onNavigate={goToStep} />

      {currentStepKey === 'cliente' ? (
        <StepClient
          mode={mode}
          cliente={state.cliente}
          pendingNewClient={state.pendingNewClient}
          pendingClientUpdate={state.pendingClientUpdate}
          onSelectCliente={(cliente) => setState((prev) => selectClientForWizard(prev, cliente))}
          onChangePendingNewClient={(pendingNewClient) => setState((prev) => ({ ...prev, pendingNewClient }))}
          onChangePendingClientUpdate={(pendingClientUpdate) =>
            setState((prev) => ({ ...prev, pendingClientUpdate }))
          }
          canEditExisting={canEditClient}
          disabled={busy}
        />
      ) : null}

      {currentStepKey === 'equipamento' ? (
        <StepEquipment
          mode={mode}
          clienteId={state.cliente?.id ?? null}
          equipamento={state.equipamento}
          pendingNewEquipment={state.pendingNewEquipment}
          pendingEquipmentUpdate={state.pendingEquipmentUpdate}
          pendingNewEquipmentPhotos={state.pendingNewEquipmentPhotos}
          onSelectEquipamento={(equipamento) => setState((prev) => selectEquipmentForWizard(prev, equipamento))}
          onChangePendingNewEquipment={(pendingNewEquipment) => setState((prev) => ({ ...prev, pendingNewEquipment }))}
          onChangePendingEquipmentUpdate={(pendingEquipmentUpdate) =>
            setState((prev) => ({ ...prev, pendingEquipmentUpdate }))
          }
          onChangePendingNewEquipmentPhotos={(pendingNewEquipmentPhotos) => setState((prev) => ({ ...prev, pendingNewEquipmentPhotos }))}
          canEditExisting={canEditEquipment}
          disabled={busy}
        />
      ) : null}

      {currentStepKey === 'checklist' && state.checklistModel ? (
        <StepChecklist
          model={state.checklistModel}
          answers={state.checklistAnswers}
          observacoesEstado={state.checklistObservacoesEstado}
          onChangeAnswer={(itemId, answer) =>
            setState((prev) => ({ ...prev, checklistAnswers: { ...prev.checklistAnswers, [itemId]: answer } }))
          }
          onChangeObservacoesEstado={(value) => setState((prev) => ({ ...prev, checklistObservacoesEstado: value }))}
          onMarkAllOk={() =>
            setState((prev) => {
              if (!prev.checklistModel) {
                return prev;
              }

              const answers = { ...prev.checklistAnswers };
              prev.checklistModel.itens.forEach((item) => {
                answers[item.id] = { status: 'ok', observacao: '' };
              });

              return { ...prev, checklistAnswers: answers };
            })
          }
          onUnmarkAll={() =>
            setState((prev) => ({ ...prev, checklistAnswers: {} }))
          }
          disabled={busy}
        />
      ) : null}

      {currentStepKey === 'detalhes' ? (
        <StepDetails
          tipoEquipamentoId={equipmentTypeId}
          relatoCliente={state.relatoCliente}
          acessorios={state.acessorios}
          onChangeRelatoCliente={(value) => setState((prev) => ({ ...prev, relatoCliente: value }))}
          onChangeAcessorios={(value) => setState((prev) => ({ ...prev, acessorios: value }))}
          disabled={busy}
        />
      ) : null}

      {currentStepKey === 'atendimento' ? (
        <StepOperations
          prioridade={state.prioridade}
          prazoEntregaDias={state.prazoEntregaDias}
          dataPrevisao={state.dataPrevisao}
          tecnicoId={state.tecnicoId}
          observacoesInternas={state.observacoesInternas}
          orcamentoVinculado={state.orcamentoVinculado}
          canLinkBudget={canLinkBudget}
          onChangePrioridade={(value) => setState((prev) => ({ ...prev, prioridade: value }))}
          onChangePrazoEntrega={(prazoEntregaDias, dataPrevisao) =>
            setState((prev) => ({ ...prev, prazoEntregaDias, dataPrevisao }))
          }
          onChangeTecnico={(tecnicoId, tecnicoLabel) => setState((prev) => ({ ...prev, tecnicoId, tecnicoLabel }))}
          onChangeObservacoesInternas={(value) => setState((prev) => ({ ...prev, observacoesInternas: value }))}
          onChangeOrcamentoVinculado={(orcamentoVinculado) =>
            setState((prev) => ({ ...prev, orcamentoVinculado }))
          }
          disabled={busy}
        />
      ) : null}

      {currentStepKey === 'fotos' ? (
        <StepPhotos
          mode={mode}
          fotos={state.fotos}
          onChangeFotos={(fotos) => setState((prev) => ({ ...prev, fotos }))}
          disabled={busy}
        />
      ) : null}

      {currentStepKey === 'revisao' ? (
        <StepReview
          sections={reviewSections}
          extrasControl={
            mode === 'create'
              ? {
                  enviarPdfCliente: state.enviarPdfCliente,
                  onChangeEnviarPdfCliente: (enviarPdfCliente) => {
                    setState((prev) => ({ ...prev, enviarPdfCliente }));
                    setVerifiedSections((current) => {
                      const next = { ...current };
                      delete next.extras;
                      return next;
                    });
                  },
                }
              : undefined
          }
          onEditSection={(stepIndex, key) => {
            setVerifiedSections((current) => {
              const next = { ...current };
              delete next[key];
              return next;
            });
            if (key !== 'extras') {
              goToStep(stepIndex);
            }
          }}
          onVerifySection={(key) =>
            setVerifiedSections((current) => ({ ...current, [key]: true }))
          }
          onSubmit={handleSubmit}
          busy={busy}
          submitLabel={mode === 'create' ? 'Criar OS' : 'Salvar alterações'}
          errorMessage={errorMessage}
          showSubmit={mode !== 'create'}
          submitDisabled={!reviewComplete}
        />
      ) : null}

      {mode !== 'create' && currentStepKey !== 'revisao' ? (
        <div className="toolbar" style={{ marginTop: 16 }}>
          <button type="button" className="button button--soft" onClick={goBack} disabled={currentIndex === 0 || busy}>
            Voltar
          </button>
          <button type="button" className="button button--primary" onClick={goNext} disabled={!isCurrentStepValid() || busy}>
            Próximo
          </button>
        </div>
      ) : null}
    </section>
  );
}
