import { describe, expect, it } from 'vitest';
import { isSafeInternalPath, resolveInternalPath } from '@/lib/navigation';

describe('mobile navigation safety', () => {
  it('accepts only application-local paths', () => {
    expect(isSafeInternalPath('/')).toBe(true);
    expect(isSafeInternalPath('/os/123?tab=fotos')).toBe(true);
    expect(isSafeInternalPath('https://evil.example')).toBe(false);
    expect(isSafeInternalPath('//evil.example/path')).toBe(false);
    expect(isSafeInternalPath('/\\evil.example/path')).toBe(false);
    expect(isSafeInternalPath('javascript:alert(1)')).toBe(false);
  });

  it('falls back to the workspace when the requested destination is unsafe', () => {
    expect(resolveInternalPath('/os/novo')).toBe('/os/novo');
    expect(resolveInternalPath('//evil.example')).toBe('/');
    expect(resolveInternalPath(null, '/login')).toBe('/login');
  });
});
