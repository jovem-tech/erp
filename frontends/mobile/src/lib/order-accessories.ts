import { normalizeCatalogLabel } from '@/lib/equipment-catalog';

/**
 * Chips de atalho para o campo "Acessórios entregues junto com o equipamento".
 * Clicar em um chip alterna a presença do item no texto; os itens ficam
 * concatenados como uma lista separada por vírgula.
 */
export const ACCESSORY_CHIPS = ['Carregador', 'Capa', 'Fone', 'Cabo', 'Chip', 'Cartão SD'] as const;

/**
 * Rótulo exclusivo que satisfaz a obrigatoriedade do campo quando o
 * cliente não entrega nenhum acessório junto com o equipamento.
 */
export const NO_ACCESSORIES_LABEL = 'Nenhum acessório';

/** Divide o texto livre do campo em tokens (itens), ignorando vazios. */
export function parseAccessoryTokens(value: string): string[] {
  return value
    .split(/[,\n]/)
    .map((token) => token.trim())
    .filter((token) => token.length > 0);
}

export function isNoAccessoriesActive(value: string): boolean {
  return normalizeCatalogLabel(value) === normalizeCatalogLabel(NO_ACCESSORIES_LABEL);
}

/** Indica se um chip de item (não o de "Nenhum acessório") está presente no valor atual. */
export function isAccessoryChipActive(value: string, chipLabel: string): boolean {
  if (isNoAccessoriesActive(value)) {
    return false;
  }
  const target = normalizeCatalogLabel(chipLabel);
  return parseAccessoryTokens(value).some((token) => normalizeCatalogLabel(token) === target);
}

/**
 * Alterna um chip de item no texto de acessórios. Se "Nenhum acessório"
 * estava ativo, ele é substituído pelo item marcado (transição exclusiva).
 */
export function toggleAccessoryChip(value: string, chipLabel: string): string {
  if (isNoAccessoriesActive(value)) {
    return chipLabel;
  }

  const tokens = parseAccessoryTokens(value);
  const target = normalizeCatalogLabel(chipLabel);
  const alreadyPresent = tokens.some((token) => normalizeCatalogLabel(token) === target);

  const nextTokens = alreadyPresent
    ? tokens.filter((token) => normalizeCatalogLabel(token) !== target)
    : [...tokens, chipLabel];

  return nextTokens.join(', ');
}

/**
 * Alterna o chip "Nenhum acessório". Ativá-lo substitui todo o valor pelo
 * rótulo literal; desativá-lo limpa o campo.
 */
export function toggleNoAccessories(value: string): string {
  return isNoAccessoriesActive(value) ? '' : NO_ACCESSORIES_LABEL;
}
