'use client';

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { useRouter } from 'next/navigation';

export type OrderCreationPlayerController = {
  canGoBack: boolean;
  canGoNext: boolean;
  canSave: boolean;
  busy: boolean;
  dirty: boolean;
  onBack: () => void;
  onNext: () => void;
  onSave: () => void;
};

type RegisterOrderCreationPlayer = (controller: OrderCreationPlayerController) => () => void;

const OrderCreationPlayerControllerContext = createContext<OrderCreationPlayerController | null>(null);
const OrderCreationPlayerRegisterContext = createContext<RegisterOrderCreationPlayer | null>(null);

export function OrderCreationPlayerProvider({ children }: { children: ReactNode }) {
  const [controller, setController] = useState<OrderCreationPlayerController | null>(null);

  const register = useCallback((nextController: OrderCreationPlayerController) => {
    setController(nextController);

    return () => {
      setController((currentController) => (currentController === nextController ? null : currentController));
    };
  }, []);

  return (
    <OrderCreationPlayerRegisterContext.Provider value={register}>
      <OrderCreationPlayerControllerContext.Provider value={controller}>
        {children}
      </OrderCreationPlayerControllerContext.Provider>
    </OrderCreationPlayerRegisterContext.Provider>
  );
}

export function useOrderCreationPlayer(controller: OrderCreationPlayerController | null): void {
  const register = useContext(OrderCreationPlayerRegisterContext);

  if (!register) {
    throw new Error('useOrderCreationPlayer deve ser usado dentro de OrderCreationPlayerProvider.');
  }

  useEffect(() => {
    if (!controller) {
      return;
    }

    return register(controller);
  }, [controller, register]);
}

function IconHome() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="m4 10 8-6 8 6v9a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1v-9Z"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconArrowLeft() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="m14 6-6 6 6 6M8 12h10"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.9"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconArrowRight() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="m10 6 6 6-6 6M6 12h10"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.9"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconSave() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M5 4h11l3 3v13H5V4Zm3 0v6h8V4M8 20v-6h8v6"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.7"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconCancel() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="m6 6 12 12M18 6 6 18"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.9"
        strokeLinecap="round"
      />
    </svg>
  );
}

export function OrderCreationPlayer() {
  const controller = useContext(OrderCreationPlayerControllerContext);
  const router = useRouter();
  const busy = controller?.busy ?? false;

  const leaveCreation = (destination: '/' | '/os'): void => {
    if (
      controller?.dirty &&
      !window.confirm('Existem dados não salvos. Deseja sair e descartar a nova OS?')
    ) {
      return;
    }

    router.push(destination);
  };

  return (
    <nav className="app-bottom-nav" aria-label="Controles da criação da OS">
      <div className="app-bottom-nav-inner app-bottom-nav-inner--creation">
        <button className="app-bottom-nav-item" type="button" onClick={() => leaveCreation('/')} disabled={busy}>
          <IconHome />
          <span>Início</span>
        </button>

        <button
          className="app-bottom-nav-item"
          type="button"
          onClick={controller?.onBack}
          disabled={!controller?.canGoBack || busy}
        >
          <IconArrowLeft />
          <span>Voltar</span>
        </button>

        <button
          className="app-bottom-nav-item app-bottom-nav-item--creation-primary"
          type="button"
          onClick={controller?.onNext}
          disabled={!controller?.canGoNext || busy}
        >
          <IconArrowRight />
          <span>Próximo</span>
        </button>

        <button
          className="app-bottom-nav-item app-bottom-nav-item--save"
          type="button"
          onClick={controller?.onSave}
          disabled={!controller?.canSave || busy}
          title={!controller?.canSave ? 'Preencha todos os campos obrigatórios para salvar' : undefined}
        >
          <IconSave />
          <span>{busy ? 'Salvando' : 'Salvar'}</span>
        </button>

        <button
          className="app-bottom-nav-item app-bottom-nav-item--cancel"
          type="button"
          onClick={() => leaveCreation('/os')}
          disabled={busy}
        >
          <IconCancel />
          <span>Cancelar</span>
        </button>
      </div>
    </nav>
  );
}
