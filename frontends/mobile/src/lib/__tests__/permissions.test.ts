import { describe, expect, it } from 'vitest';
import { hasPermission } from '@/lib/permissions';
import type { MobileUser } from '@/lib/types';

function buildUser(overrides: Partial<MobileUser> = {}): MobileUser {
  return {
    id: 1,
    nome: 'Técnico',
    email: 'tecnico@example.com',
    perfil: 'tecnico',
    grupo_id: 1,
    foto: '',
    ativo: true,
    ultimo_acesso: null,
    ...overrides,
  };
}

describe('hasPermission', () => {
  it('returns true when the module/action pair is present', () => {
    const user = buildUser({ permissions: { os: ['criar', 'editar'] } });
    expect(hasPermission(user, 'os', 'criar')).toBe(true);
  });

  it('returns false when the action is missing from the module', () => {
    const user = buildUser({ permissions: { os: ['visualizar'] } });
    expect(hasPermission(user, 'os', 'criar')).toBe(false);
  });

  it('returns false when the module is missing entirely', () => {
    const user = buildUser({ permissions: { os: ['criar'] } });
    expect(hasPermission(user, 'orcamentos', 'converter_os')).toBe(false);
  });

  it('returns false when permissions is undefined', () => {
    const user = buildUser({ permissions: undefined });
    expect(hasPermission(user, 'os', 'criar')).toBe(false);
  });

  it('returns false when user is null or undefined', () => {
    expect(hasPermission(null, 'os', 'criar')).toBe(false);
    expect(hasPermission(undefined, 'os', 'criar')).toBe(false);
  });
});
