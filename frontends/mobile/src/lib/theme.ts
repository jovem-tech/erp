export const THEME_STORAGE_KEY = 'sistema-erp.mobile.theme';
export const THEME_LIGHT = 'light';
export const THEME_DARK = 'dark';

export type ThemeMode = typeof THEME_LIGHT | typeof THEME_DARK;

function hasWindow(): boolean {
  return typeof window !== 'undefined';
}

export function getPreferredTheme(): ThemeMode {
  if (!hasWindow()) {
    return THEME_DARK;
  }

  const stored = window.localStorage.getItem(THEME_STORAGE_KEY);
  if (stored === THEME_LIGHT || stored === THEME_DARK) {
    return stored;
  }

  if (window.matchMedia('(prefers-color-scheme: light)').matches) {
    return THEME_LIGHT;
  }

  return THEME_DARK;
}

export function applyThemePreference(theme: ThemeMode): void {
  if (!hasWindow()) {
    return;
  }

  const resolvedTheme = theme === THEME_LIGHT ? THEME_LIGHT : THEME_DARK;
  const root = document.documentElement;
  root.dataset.theme = resolvedTheme;
  root.style.colorScheme = resolvedTheme;
  window.localStorage.setItem(THEME_STORAGE_KEY, resolvedTheme);

  const themeColor = resolvedTheme === THEME_LIGHT ? '#eef2ff' : '#06111f';
  let meta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]');

  if (!meta) {
    meta = document.createElement('meta');
    meta.name = 'theme-color';
    document.head.appendChild(meta);
  }

  meta.setAttribute('content', themeColor);
}

export function resolveThemeToggle(theme: ThemeMode): ThemeMode {
  return theme === THEME_LIGHT ? THEME_DARK : THEME_LIGHT;
}
