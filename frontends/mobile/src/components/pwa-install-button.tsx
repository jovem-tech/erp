'use client';

import { useEffect, useState } from 'react';

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{
    outcome: 'accepted' | 'dismissed';
    platform: string;
  }>;
};

type InstallPlatform = 'android' | 'ios' | 'other';

declare global {
  interface Window {
    __SISTEMA_ERP_PWA_INSTALL_PROMPT__?: BeforeInstallPromptEvent | null;
  }
}

const INSTALL_READY_EVENT = 'sistema-erp:pwa-install-ready';

function isStandaloneMode(): boolean {
  if (typeof window === 'undefined') {
    return false;
  }

  const navigatorWithStandalone = window.navigator as Navigator & { standalone?: boolean };

  return window.matchMedia('(display-mode: standalone)').matches || navigatorWithStandalone.standalone === true;
}

function detectInstallPlatform(): InstallPlatform {
  const userAgent = window.navigator.userAgent.toLowerCase();
  const navigatorWithTouchPoints = window.navigator as Navigator & { maxTouchPoints?: number };
  const isIPadOs = userAgent.includes('macintosh') && (navigatorWithTouchPoints.maxTouchPoints ?? 0) > 1;

  if (/iphone|ipad|ipod/.test(userAgent) || isIPadOs) {
    return 'ios';
  }

  if (userAgent.includes('android')) {
    return 'android';
  }

  return 'other';
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

type PwaInstallButtonProps = {
  variant?: 'navbar' | 'menu';
};

export function PwaInstallButton({ variant = 'navbar' }: PwaInstallButtonProps) {
  const [installable, setInstallable] = useState(false);
  const [promptEvent, setPromptEvent] = useState<BeforeInstallPromptEvent | null>(null);
  const [standalone, setStandalone] = useState(false);
  const [busy, setBusy] = useState(false);
  const [helpOpen, setHelpOpen] = useState(false);
  const [platform, setPlatform] = useState<InstallPlatform>('other');

  useEffect(() => {
    const syncStandalone = (): void => {
      setStandalone(isStandaloneMode());
    };

    syncStandalone();
    setPlatform(detectInstallPlatform());

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
      window.__SISTEMA_ERP_PWA_INSTALL_PROMPT__ = installEvent;
      setPromptEvent(installEvent);
      setInstallable(true);
    };

    const syncCapturedPrompt = (): void => {
      const capturedPrompt = window.__SISTEMA_ERP_PWA_INSTALL_PROMPT__;

      if (capturedPrompt) {
        setPromptEvent(capturedPrompt);
        setInstallable(true);
      }
    };

    const handleAppInstalled = (): void => {
      window.__SISTEMA_ERP_PWA_INSTALL_PROMPT__ = null;
      setInstallable(false);
      setPromptEvent(null);
      setStandalone(true);
      setHelpOpen(false);
    };

    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
    window.addEventListener(INSTALL_READY_EVENT, syncCapturedPrompt);
    window.addEventListener('appinstalled', handleAppInstalled);
    syncCapturedPrompt();

    return () => {
      window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
      window.removeEventListener(INSTALL_READY_EVENT, syncCapturedPrompt);
      window.removeEventListener('appinstalled', handleAppInstalled);
    };
  }, [standalone]);

  const canPrompt = !standalone && installable && promptEvent !== null;
  const buttonLabel = busy ? 'Abrindo...' : canPrompt ? 'Instalar' : 'Instalar app';
  const buttonTitle = canPrompt
    ? 'Instalar o Sistema ERP como aplicativo'
    : 'Abrir instruções para instalar como aplicativo';
  const menuVariant = variant === 'menu';

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
      window.__SISTEMA_ERP_PWA_INSTALL_PROMPT__ = null;
      setBusy(false);
      setInstallable(false);
      setPromptEvent(null);
      setHelpOpen(false);
    }
  };

  if (standalone) {
    return menuVariant ? (
      <div
        className="install-button install-button--menu install-button--installed"
        role="menuitem"
        aria-disabled="true"
      >
        <span className="install-button-icon">
          <IconInstall />
        </span>
        <span className="install-button-label">Aplicativo instalado</span>
      </div>
    ) : null;
  }

  return (
    <div className={`nav-action-group install-action${menuVariant ? ' install-action--menu' : ''}`}>
      <button
        className={`install-button${menuVariant ? ' install-button--menu' : ''} ${
          canPrompt ? 'install-button--ready' : 'install-button--fallback'
        }`}
        type="button"
        role={menuVariant ? 'menuitem' : undefined}
        onClick={() => void handleInstall()}
        disabled={busy}
        aria-expanded={helpOpen}
        aria-haspopup="dialog"
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
              <strong>{platform === 'ios' ? 'Instalar no iPhone ou iPad' : 'Como instalar'}</strong>
              <p>
                {platform === 'ios'
                  ? 'A instalação no iOS é concluída pelo menu Compartilhar do Safari.'
                  : 'O navegador ainda não liberou o prompt automático.'}
              </p>
            </div>
          </div>

          <div className="install-help">
            {platform === 'ios' ? (
              <>
                <p>
                  1. Abra este endereço no <strong>Safari</strong>. Se estiver no WhatsApp, Instagram ou
                  outro app, escolha <strong>Abrir no Safari</strong>.
                </p>
                <p>
                  2. Toque em <strong>Compartilhar</strong> e depois em{' '}
                  <strong>Adicionar à Tela de Início</strong>.
                </p>
                <p>
                  3. Confirme em <strong>Adicionar</strong>.
                </p>
              </>
            ) : platform === 'android' ? (
              <>
                <p>
                  1. Abra este endereço no <strong>Google Chrome</strong>. Se estiver dentro do WhatsApp,
                  Instagram ou outro app, escolha <strong>Abrir no Chrome</strong>.
                </p>
                <p>
                  2. Abra o menu <strong>⋮</strong> e escolha <strong>Instalar app</strong> ou{' '}
                  <strong>Adicionar à tela inicial</strong>.
                </p>
                <p>3. Confirme a instalação.</p>
              </>
            ) : (
              <>
                <p>
                  Abra o menu do navegador e escolha <strong>Instalar aplicativo</strong> ou{' '}
                  <strong>Adicionar à tela inicial</strong>.
                </p>
                <p>Se a opção não aparecer, atualize a página e aguarde alguns segundos com esta aba aberta.</p>
              </>
            )}
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
