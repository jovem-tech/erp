import { beforeEach, describe, expect, it } from 'vitest';
import {
  applyThemePreference,
  resolveThemeToggle,
  THEME_DARK,
  THEME_LIGHT,
  THEME_STORAGE_KEY,
} from '@/lib/theme';

beforeEach(() => {
  window.localStorage.clear();
  document.documentElement.removeAttribute('data-theme');
  document.documentElement.removeAttribute('style');
  document.querySelector('meta[name="theme-color"]')?.remove();
});

describe('mobile theme preference', () => {
  it('applies the light theme to the document and browser chrome', () => {
    applyThemePreference(THEME_LIGHT);

    expect(document.documentElement.dataset.theme).toBe(THEME_LIGHT);
    expect(document.documentElement.hasAttribute('style')).toBe(false);
    expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe(THEME_LIGHT);
    expect(document.querySelector('meta[name="theme-color"]')?.getAttribute('content')).toBe(
      '#f4f8ff'
    );
  });

  it('switches between the supported theme modes', () => {
    expect(resolveThemeToggle(THEME_LIGHT)).toBe(THEME_DARK);
    expect(resolveThemeToggle(THEME_DARK)).toBe(THEME_LIGHT);
  });
});
