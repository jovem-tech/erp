'use client';

import { useEffect } from 'react';

export function PwaRegister() {
  useEffect(() => {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) {
      return;
    }

    const isProduction = process.env.NODE_ENV === 'production';

    if (!isProduction) {
      void (async () => {
        const registrations = await navigator.serviceWorker.getRegistrations();
        await Promise.all(registrations.map((registration) => registration.unregister()));

        if ('caches' in window) {
          const cacheNames = await caches.keys();
          await Promise.all(
            cacheNames
              .filter((cacheName) => cacheName.startsWith('pages-sistema-erp-chat') || cacheName.startsWith('assets-sistema-erp-chat'))
              .map((cacheName) => caches.delete(cacheName))
          );
        }
      })();

      return;
    }

    const register = (): void => {
      void navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch((error) => {
        if (process.env.NODE_ENV !== 'production') {
          console.error('Falha ao registrar o service worker.', error);
        }
      });
    };

    if (document.readyState === 'complete') {
      register();
      return;
    }

    window.addEventListener('load', register, { once: true });
  }, []);

  return null;
}
