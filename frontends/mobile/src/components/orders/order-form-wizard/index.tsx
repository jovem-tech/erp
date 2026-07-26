'use client';

import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { ApiError } from '@/lib/api';
import { createOrder, getEntryChecklistModel, updateOrder } from '@/lib/orders';
import { hasPermission } from '@/lib/permissions';
import { useSession } from '@/components/session-provider';
import type { OrderDetail } from '@/lib/types';
import {
  buildOrderPayload,
  createInitialWizardState,
  createWizardStateFromOrder,
  isChecklistComplete,
  resolveEquipmentTypeId,
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
import { StepExtras } from '@/components/orders/order-form-wizard/steps/step-extras';
import { StepReview, type ReviewSection } from '@/components/orders/order-form-wizard/steps/step-review';

type OrderFormWizardProps = {
  mode: WizardMode;
  order?: OrderDetail;
  idempotencyKey?: string;
};

function buildReviewSections(state: WizardFormState, steps: WizardStepInfo[], mode: WizardMode): ReviewSection[] {
  const stepIndex = (key: string): number => steps.findIndex((step) => step.key === key);

  const sections: ReviewSection[] = [];

  sections.push({
    title: 'Cliente',
    stepIndex: stepIndex('cliente'),
    rows: state.cliente
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
    title: 'Equipamento',
    stepIndex: stepIndex('equipamento'),
    rows: state.equipamento
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
      title: 'Checklist de entrada',
      stepIndex: stepIndex('checklist'),
      rows: [{ label: 'Itens respondidos', value: `${answered}/${total}` }],
    });
  }

  sections.push({
    title: 'Relato e defeito',
    stepIndex: stepIndex('detalhes'),
    rows: [
      { label: 'Relato do cliente', value: state.relatoCliente },
      { label: 'Acessórios', value: state.acessorios },
    ],
  });

  sections.push({
    title: 'Atendimento',
    stepIndex: stepIndex('atendimento'),
    rows: [
      { label: 'Prioridade', value: state.prioridade },
      { label: 'Previsão de entrega', value: state.dataPrevisao },
      { label: 'Técnico responsável', value: state.tecnicoLabel ?? '' },
    ],
  });

  sections.push({
    title: 'Fotos',
    stepIndex: stepIndex('fotos'),
    rows: [{ label: 'Fotos anexadas', value: String(state.fotos.length) }],
  });

  if (mode === 'create') {
    sections.push({
      title: 'Extras',
      stepIndex: stepIndex('extras'),
      rows: [
        { label: 'Enviar PDF ao cliente', value: state.enviarPdfCliente ? 'Sim' : 'Não' },
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
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successState, setSuccessState] = useState<{ orderId: number; warnings: string[] } | null>(null);

  const canLinkBudget = hasPermission(session?.user, 'orcamentos', 'converter_os');
  const equipmentTypeId = resolveEquipmentTypeId(state);

  useEffect(() => {
    const prevId = state.checklistModel?.id ?? null;

    if (!equipmentTypeId) {
      if (prevId !== null) {
        setState((prev) => ({ ...prev, checklistModel: null, checklistAnswers: {} }));
      }
      return;
    }

    let cancelled = false;

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

    if (mode === 'create') {
      list.push({ key: 'extras', label: 'Extras' });
    }

    list.push({ key: 'revisao', label: 'Revisão' });

    return list;
  }, [mode, state.checklistModel]);

  useEffect(() => {
    if (currentIndex >= steps.length) {
      setCurrentIndex(steps.length - 1);
    }
  }, [steps.length, currentIndex]);

  const currentStepKey = steps[currentIndex]?.key;

  const isCurrentStepValid = (): boolean => {
    switch (currentStepKey) {
      case 'cliente':
        return isStepClientValid(state.cliente, state.pendingNewClient);
      case 'equipamento':
        return isStepEquipmentValid(state.equipamento, state.pendingNewEquipment, state.pendingNewEquipmentPhotos);
      case 'checklist':
        return isChecklistComplete(state);
      case 'detalhes':
        return isStepDetailsValid(state.relatoCliente);
      case 'atendimento':
        return isStepOperationsValid(state.tecnicoId);
      default:
        return true;
    }
  };

  const goNext = (): void => {
    const nextIndex = Math.min(currentIndex + 1, steps.length - 1);
    setCurrentIndex(nextIndex);
    setMaxVisitedIndex((prev) => Math.max(prev, nextIndex));
  };

  const goBack = (): void => {
    setCurrentIndex((prev) => Math.max(prev - 1, 0));
  };

  const goToStep = (index: number): void => {
    if (index <= maxVisitedIndex) {
      setCurrentIndex(index);
    }
  };

  const handleSubmit = async (): Promise<void> => {
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
    } finally {
      setBusy(false);
    }
  };

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
          onSelectCliente={(cliente) => setState((prev) => ({ ...prev, cliente }))}
          onChangePendingNewClient={(pendingNewClient) => setState((prev) => ({ ...prev, pendingNewClient }))}
          disabled={busy}
        />
      ) : null}

      {currentStepKey === 'equipamento' ? (
        <StepEquipment
          mode={mode}
          clienteId={state.cliente?.id ?? null}
          equipamento={state.equipamento}
          pendingNewEquipment={state.pendingNewEquipment}
          pendingNewEquipmentPhotos={state.pendingNewEquipmentPhotos}
          onSelectEquipamento={(equipamento) => setState((prev) => ({ ...prev, equipamento }))}
          onChangePendingNewEquipment={(pendingNewEquipment) => setState((prev) => ({ ...prev, pendingNewEquipment }))}
          onChangePendingNewEquipmentPhotos={(pendingNewEquipmentPhotos) => setState((prev) => ({ ...prev, pendingNewEquipmentPhotos }))}
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
          dataPrevisao={state.dataPrevisao}
          tecnicoId={state.tecnicoId}
          observacoesInternas={state.observacoesInternas}
          onChangePrioridade={(value) => setState((prev) => ({ ...prev, prioridade: value }))}
          onChangeDataPrevisao={(value) => setState((prev) => ({ ...prev, dataPrevisao: value }))}
          onChangeTecnico={(tecnicoId, tecnicoLabel) => setState((prev) => ({ ...prev, tecnicoId, tecnicoLabel }))}
          onChangeObservacoesInternas={(value) => setState((prev) => ({ ...prev, observacoesInternas: value }))}
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

      {currentStepKey === 'extras' ? (
        <StepExtras
          enviarPdfCliente={state.enviarPdfCliente}
          onChangeEnviarPdfCliente={(value) => setState((prev) => ({ ...prev, enviarPdfCliente: value }))}
          orcamentoVinculado={state.orcamentoVinculado}
          onChangeOrcamentoVinculado={(budget) => setState((prev) => ({ ...prev, orcamentoVinculado: budget }))}
          canLinkBudget={canLinkBudget}
          disabled={busy}
        />
      ) : null}

      {currentStepKey === 'revisao' ? (
        <StepReview
          sections={buildReviewSections(state, steps, mode)}
          onEditSection={goToStep}
          onSubmit={handleSubmit}
          busy={busy}
          submitLabel={mode === 'create' ? 'Criar OS' : 'Salvar alterações'}
          errorMessage={errorMessage}
        />
      ) : null}

      {currentStepKey !== 'revisao' ? (
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
