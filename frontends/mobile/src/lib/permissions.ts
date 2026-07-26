import type { MobileUser } from '@/lib/types';

export function hasPermission(user: MobileUser | null | undefined, module: string, action: string): boolean {
  return Boolean(user?.permissions?.[module]?.includes(action));
}
