import { describe, expect, it } from 'vitest';
import { formatCep, formatPhone, isPhoneComplete, onlyDigits } from '@/lib/input-masks';

describe('máscaras de entrada', () => {
  it('formata celular com DDD e nove dígitos', () => {
    expect(formatPhone('22999999999')).toBe('(22)99999-9999');
  });

  it('formata telefone fixo com DDD e oito dígitos', () => {
    expect(formatPhone('2226212621')).toBe('(22)2621-2621');
  });

  it('remove caracteres inválidos e limita o telefone a onze dígitos', () => {
    expect(formatPhone('(22) 99999-9999 ramal 123')).toBe('(22)99999-9999');
    expect(onlyDigits('ABC-123.456', 4)).toBe('1234');
  });

  it('considera completo somente telefone com dez ou onze dígitos', () => {
    expect(isPhoneComplete('(22)2621-2621')).toBe(true);
    expect(isPhoneComplete('(22)99999-9999')).toBe(true);
    expect(isPhoneComplete('(22)9999')).toBe(false);
  });

  it('formata o CEP e limita a oito dígitos', () => {
    expect(formatCep('01001000')).toBe('01001-000');
    expect(formatCep('01001-00099')).toBe('01001-000');
  });
});
