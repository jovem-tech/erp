(function () {
  try {
    var storageKey = 'sistema-erp.mobile.theme';
    var light = 'light';
    var dark = 'dark';
    var stored = window.localStorage.getItem(storageKey);
    var theme = stored === light || stored === dark
      ? stored
      : window.matchMedia('(prefers-color-scheme: light)').matches
        ? light
        : dark;
    var root = document.documentElement;
    root.dataset.theme = theme;
    root.style.colorScheme = theme;

    var meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) {
      meta = document.createElement('meta');
      meta.setAttribute('name', 'theme-color');
      document.head.appendChild(meta);
    }

    meta.setAttribute('content', theme === light ? '#eef2ff' : '#06111f');
  } catch (error) {
    // Fallback to CSS defaults if localStorage or matchMedia is unavailable.
  }
})();
