export function onlyDigits(value: string | null | undefined, maxLength?: number): string {
  const digits = (value ?? '').replace(/\D+/g, '');
  return typeof maxLength === 'number' ? digits.slice(0, maxLength) : digits;
}

export function formatPhone(value: string | null | undefined): string {
  const digits = onlyDigits(value, 11);
  if (!digits) {
    return '';
  }

  if (digits.length <= 2) {
    return `(${digits}`;
  }

  const areaCode = digits.slice(0, 2);
  const localNumber = digits.slice(2);
  if (localNumber.length <= 4) {
    return `(${areaCode})${localNumber}`;
  }

  const prefixLength = digits.length === 11 ? 5 : 4;
  return `(${areaCode})${localNumber.slice(0, prefixLength)}-${localNumber.slice(prefixLength)}`;
}

export function isPhoneComplete(value: string | null | undefined): boolean {
  const length = onlyDigits(value).length;
  return length === 10 || length === 11;
}

export function formatCep(value: string | null | undefined): string {
  const digits = onlyDigits(value, 8);
  return digits.length <= 5 ? digits : `${digits.slice(0, 5)}-${digits.slice(5)}`;
}
