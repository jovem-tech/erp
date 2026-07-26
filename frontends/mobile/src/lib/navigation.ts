const INTERNAL_ORIGIN = 'https://erp.internal';
const CONTROL_CHARACTER_PATTERN = /[\u0000-\u001f\u007f]/;

export function isSafeInternalPath(value: string | null | undefined): value is string {
  if (
    !value ||
    !value.startsWith('/') ||
    value.startsWith('//') ||
    value.includes('\\') ||
    CONTROL_CHARACTER_PATTERN.test(value)
  ) {
    return false;
  }

  try {
    return new URL(value, INTERNAL_ORIGIN).origin === INTERNAL_ORIGIN;
  } catch {
    return false;
  }
}

export function resolveInternalPath(
  value: string | null | undefined,
  fallback = '/'
): string {
  return isSafeInternalPath(value) ? value : fallback;
}
