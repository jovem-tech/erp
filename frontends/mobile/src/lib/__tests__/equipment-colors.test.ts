import { describe, expect, it } from 'vitest';
import { EQUIPMENT_COLOR_OPTIONS, findEquipmentColorOption } from '@/lib/equipment-colors';

describe('findEquipmentColorOption', () => {
  it('encontra a opção por rótulo exato', () => {
    const option = findEquipmentColorOption('Preto');
    expect(option?.label).toBe('Preto');
    expect(option?.hex).toMatch(/^#[0-9A-F]{6}$/i);
  });

  it('é insensível a acento e caixa', () => {
    expect(findEquipmentColorOption('preto')?.label).toBe('Preto');
    expect(findEquipmentColorOption('DOURADO')?.label).toBe('Dourado');
  });

  it('devolve null para cores fora da lista ou valores vazios', () => {
    expect(findEquipmentColorOption('Azul meia-noite')).toBeNull();
    expect(findEquipmentColorOption('')).toBeNull();
  });

  it('toda opção tem hex e rgb preenchidos', () => {
    EQUIPMENT_COLOR_OPTIONS.forEach((option) => {
      expect(option.hex).toMatch(/^#[0-9A-F]{6}$/i);
      expect(option.rgb).toMatch(/^\d{1,3}, \d{1,3}, \d{1,3}$/);
    });
  });
});
