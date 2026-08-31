import { describe, expect, it } from 'vitest';
import {
  ACCESSORY_CHIPS,
  NO_ACCESSORIES_LABEL,
  isAccessoryChipActive,
  isNoAccessoriesActive,
  parseAccessoryTokens,
  toggleAccessoryChip,
  toggleNoAccessories,
} from '@/lib/order-accessories';

describe('parseAccessoryTokens', () => {
  it('divide por vírgula e quebra de linha, ignorando vazios', () => {
    expect(parseAccessoryTokens('Carregador, Capa\nFone')).toEqual(['Carregador', 'Capa', 'Fone']);
    expect(parseAccessoryTokens('')).toEqual([]);
    expect(parseAccessoryTokens('  ,  ,')).toEqual([]);
  });
});

describe('isNoAccessoriesActive / toggleNoAccessories', () => {
  it('detecta o rótulo exclusivo de forma insensível a caixa', () => {
    expect(isNoAccessoriesActive(NO_ACCESSORIES_LABEL)).toBe(true);
    expect(isNoAccessoriesActive('nenhum acessório')).toBe(true);
    expect(isNoAccessoriesActive('Carregador')).toBe(false);
    expect(isNoAccessoriesActive('')).toBe(false);
  });

  it('alterna entre ativar e limpar', () => {
    expect(toggleNoAccessories('')).toBe(NO_ACCESSORIES_LABEL);
    expect(toggleNoAccessories(NO_ACCESSORIES_LABEL)).toBe('');
  });
});

describe('isAccessoryChipActive / toggleAccessoryChip', () => {
  it('liga um chip vazio fazendo append', () => {
    expect(toggleAccessoryChip('', 'Carregador')).toBe('Carregador');
    expect(toggleAccessoryChip('Carregador', 'Capa')).toBe('Carregador, Capa');
  });

  it('desliga um chip presente removendo o token e rejuntando o restante', () => {
    expect(toggleAccessoryChip('Carregador, Capa, Fone', 'Capa')).toBe('Carregador, Fone');
    expect(toggleAccessoryChip('Carregador', 'Carregador')).toBe('');
  });

  it('isAccessoryChipActive reflete a presença do token', () => {
    const value = 'Carregador, Cabo';
    expect(isAccessoryChipActive(value, 'Carregador')).toBe(true);
    expect(isAccessoryChipActive(value, 'Cabo')).toBe(true);
    expect(isAccessoryChipActive(value, 'Capa')).toBe(false);
  });

  it('nenhum chip de item fica ativo quando "Nenhum acessório" está selecionado', () => {
    expect(isAccessoryChipActive(NO_ACCESSORIES_LABEL, 'Carregador')).toBe(false);
  });

  it('ligar um chip de item enquanto "Nenhum acessório" está ativo substitui o valor (exclusividade)', () => {
    expect(toggleAccessoryChip(NO_ACCESSORIES_LABEL, 'Carregador')).toBe('Carregador');
  });

  it('a lista de chips de atalho tem os itens esperados', () => {
    expect(ACCESSORY_CHIPS).toEqual(['Carregador', 'Capa', 'Fone', 'Cabo', 'Chip', 'Cartão SD']);
  });
});
