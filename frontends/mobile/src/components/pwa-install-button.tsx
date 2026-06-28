'use client';

import { useEffect, useState } from 'react';

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{
    outcome: 'accepted' | 'dismissed';
    platform: string;
  }>;
};

function isStandaloneMode(): boolean {
  if (typeof window === 'undefined') {
    return false;
  }

  const navigatorWithStandalone = window.navigator as Navigator & { standalone?: boolean };

  return window.matchMedia('(display-mode: standalone)').matches || navigatorWithStandalone.standalone === true;
}

function IconInstall() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M12 3v10m0 0 3.5-3.5M12 13 8.5 9.5M5 15v3.5A1.5 1.5 0 0 0 6.5 20h11a1.5 1.5 0 0 0 1.5-1.5V15"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

export function PwaInstallButton() {
  const [installable, setInstallable] = useState(false);
  const [promptEvent, setPromptEvent] = useState<BeforeInstallPromptEvent | null>(null);
  const [standalone, setStandalone] = useState(false);
  const [busy, setBusy] = useState(false);
  const [helpOpen, setHelpOpen] = useState(false);

  useEffect(() => {
    const syncStandalone = (): void => {
      setStandalone(isStandaloneMode());
    };

    syncStandalone();

    const media = window.matchMedia('(display-mode: standalone)');
    const handleDisplayModeChange = (): void => {
      syncStandalone();
    };

    media.addEventListener('change', handleDisplayModeChange);

    return () => {
      media.removeEventListener('change', handleDisplayModeChange);
    };
  }, []);

  useEffect(() => {
    if (standalone) {
      setHelpOpen(false);
      setInstallable(false);
      setPromptEvent(null);
      return;
    }

    const handleBeforeInstallPrompt = (event: Event): void => {
      const installEvent = event as BeforeInstallPromptEvent;
      event.preventDefault();
      setPromptEvent(installEvent);
      setInstallable(true);
    };

    const handleAppInstalled = (): void => {
      setInstallable(false);
      setPromptEvent(null);
      setStandalone(true);
      setHelpOpen(false);
    };

    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
    window.addEventListener('appinstalled', handleAppInstalled);

    return () => {
      window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
      window.removeEventListener('appinstalled', handleAppInstalled);
    };
  }, [standalone]);

  const canPrompt = !standalone && installable && promptEvent !== null;
  const buttonLabel = busy ? 'Abrindo...' : canPrompt ? 'Instalar' : 'Instalar app';
  const buttonTitle = canPrompt
    ? 'Instalar o Sistema ERP como aplicativo'
    : 'Abrir instruções para instalar como aplicativo';

  const handleInstall = async (): Promise<void> => {
    if (!canPrompt || !promptEvent) {
      setHelpOpen((current) => !current);
      return;
    }

    setBusy(true);

    try {
      await promptEvent.prompt();
      await promptEvent.userChoice;
    } finally {
      setBusy(false);
      setInstallable(false);
      setPromptEvent(null);
      setHelpOpen(false);
    }
  };

  if (standalone) {
    return null;
  }

  return (
    <div className="nav-action-group install-action">
      <button
        className={`install-button ${canPrompt ? 'install-button--ready' : 'install-button--fallback'}`}
        type="button"
        onClick={() => void handleInstall()}
        disabled={busy}
        aria-expanded={helpOpen}
        aria-haspopup={canPrompt ? 'dialog' : 'menu'}
        title={buttonTitle}
      >
        <span className="install-button-icon">
          <IconInstall />
        </span>
        <span className="install-button-label">{buttonLabel}</span>
      </button>

      {helpOpen && !canPrompt ? (
        <div className="nav-popover nav-popover-install" role="dialog" aria-label="Como instalar o aplicativo">
          <div className="popover-header">
            <div>
              <span className="popover-kicker">Instalação</span>
              <strong>Como instalar</strong>
              <p>O navegador ainda não liberou o prompt automático.</p>
            </div>
          </div>

          <div className="install-help">
            <p>
              Abra o menu do navegador e escolha <strong>Instalar aplicativo</strong> ou{' '}
              <strong>Adicionar à tela inicial</strong>.
            </p>
            <p>Se a opção não aparecer, atualize a página e aguarde alguns segundos com esta aba aberta.</p>
          </div>

          <div className="popover-footer">
            <button className="button button-secondary button-small button-full" type="button" onClick={() => setHelpOpen(false)}>
              Fechar
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

export default PwaInstallButton;
