export function normalizeText(value: unknown, fallback = ''): string {
  const text = typeof value === 'string' ? value.trim() : '';
  return text !== '' ? text : fallback;
}

export function firstWord(value: unknown, fallback = 'Operador'): string {
  const text = normalizeText(value, fallback);
  return text.split(/\s+/).filter(Boolean)[0] || fallback;
}

export function formatDateTime(value: string | null | undefined, fallback = 'Sem data'): string {
  if (!value) {
    return fallback;
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return fallback;
  }

  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(date);
}

export function formatDate(value: string | null | undefined, fallback = 'Sem data'): string {
  if (!value) {
    return fallback;
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return fallback;
  }

  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
  }).format(date);
}

export function formatCurrency(value: number | string | null | undefined, fallback = 'R$ 0,00'): string {
  if (value === null || value === undefined || value === '') {
    return fallback;
  }

  const amount = Number(value);
  if (!Number.isFinite(amount)) {
    return fallback;
  }

  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  }).format(amount);
}
