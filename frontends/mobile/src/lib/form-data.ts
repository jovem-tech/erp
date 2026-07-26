function isFileLike(value: unknown): value is Blob {
  return value instanceof Blob;
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value) && !isFileLike(value) && !(value instanceof Date);
}

function appendToFormData(formData: FormData, value: unknown, key: string): void {
  if (value === null || value === undefined) {
    return;
  }

  if (isFileLike(value)) {
    formData.append(key, value);
    return;
  }

  if (value instanceof Date) {
    formData.append(key, value.toISOString());
    return;
  }

  if (Array.isArray(value)) {
    if (value.every(isFileLike)) {
      value.forEach((file) => formData.append(`${key}[]`, file as Blob));
      return;
    }

    value.forEach((item, index) => appendToFormData(formData, item, `${key}[${index}]`));
    return;
  }

  if (isPlainObject(value)) {
    Object.entries(value).forEach(([subKey, subValue]) => appendToFormData(formData, subValue, `${key}[${subKey}]`));
    return;
  }

  if (typeof value === 'boolean') {
    formData.append(key, value ? '1' : '0');
    return;
  }

  formData.append(key, String(value));
}

export function buildFormData(payload: Record<string, unknown>): FormData {
  const formData = new FormData();
  Object.entries(payload).forEach(([key, value]) => appendToFormData(formData, value, key));
  return formData;
}
