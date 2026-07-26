(() => {
  const promptKey = '__SISTEMA_ERP_PWA_INSTALL_PROMPT__';
  const readyEvent = 'sistema-erp:pwa-install-ready';

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    window[promptKey] = event;
    window.dispatchEvent(new Event(readyEvent));
  });

  window.addEventListener('appinstalled', () => {
    window[promptKey] = null;
  });
})();
