import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PwaInstallButton } from '@/components/pwa-install-button';

type DeferredInstallPrompt = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>;
};

const installWindow = window as typeof window & {
  __SISTEMA_ERP_PWA_INSTALL_PROMPT__?: DeferredInstallPrompt | null;
};

describe('PwaInstallButton', () => {
  beforeEach(() => {
    installWindow.__SISTEMA_ERP_PWA_INSTALL_PROMPT__ = null;

    Object.defineProperty(window, 'matchMedia', {
      configurable: true,
      value: vi.fn().mockImplementation(() => ({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      })),
    });
  });

  afterEach(() => {
    installWindow.__SISTEMA_ERP_PWA_INSTALL_PROMPT__ = null;
    vi.restoreAllMocks();
  });

  it('uses an install prompt captured before React hydration', async () => {
    const user = userEvent.setup();
    const prompt = vi.fn().mockResolvedValue(undefined);

    installWindow.__SISTEMA_ERP_PWA_INSTALL_PROMPT__ = Object.assign(new Event('beforeinstallprompt'), {
      prompt,
      userChoice: Promise.resolve({ outcome: 'accepted' as const, platform: 'web' }),
    });

    render(<PwaInstallButton variant="menu" />);

    await user.click(await screen.findByRole('menuitem', { name: 'Instalar' }));

    expect(prompt).toHaveBeenCalledOnce();
    expect(installWindow.__SISTEMA_ERP_PWA_INSTALL_PROMPT__).toBeNull();
  });

  it('shows Safari and Add to Home Screen instructions on iPhone', async () => {
    const user = userEvent.setup();
    vi.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue(
      'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15'
    );

    render(<PwaInstallButton variant="menu" />);
    await user.click(screen.getByRole('menuitem', { name: 'Instalar app' }));

    expect(screen.getByRole('dialog', { name: 'Como instalar o aplicativo' })).toBeInTheDocument();
    expect(screen.getByText('Instalar no iPhone ou iPad')).toBeInTheDocument();
    expect(screen.getByText(/Abra este endereço no/)).toHaveTextContent('Safari');
    expect(screen.getByText(/Toque em/)).toHaveTextContent('Adicionar à Tela de Início');
  });

  it('shows Chrome instructions when Android has no automatic prompt', async () => {
    const user = userEvent.setup();
    vi.spyOn(window.navigator, 'userAgent', 'get').mockReturnValue(
      'Mozilla/5.0 (Linux; Android 15; Pixel 8) AppleWebKit/537.36 Chrome/126.0 Mobile Safari/537.36'
    );

    render(<PwaInstallButton variant="menu" />);
    await user.click(screen.getByRole('menuitem', { name: 'Instalar app' }));

    expect(screen.getByText(/Abra este endereço no/)).toHaveTextContent('Google Chrome');
    expect(screen.getByText(/Abra o menu/)).toHaveTextContent('Instalar app');
  });
});
