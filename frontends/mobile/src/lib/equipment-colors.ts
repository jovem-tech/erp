import { normalizeCatalogLabel } from '@/lib/equipment-catalog';

export type EquipmentColorOption = {
  label: string;
  hex: string;
  /** Formato "r, g, b", igual ao usado pelo cadastro de equipamentos do desktop. */
  rgb: string;
};

/**
 * Chips de cores comuns para o campo "Cor" do equipamento. O input
 * permanece de texto livre — estes chips só aceleram o caso comum e
 * preenchem cor_hex/cor_rgb de forma consistente com o desktop.
 */
export const EQUIPMENT_COLOR_OPTIONS: EquipmentColorOption[] = [
  { label: 'Preto', hex: '#1A1A1A', rgb: '26, 26, 26' },
  { label: 'Branco', hex: '#FFFFFF', rgb: '255, 255, 255' },
  { label: 'Azul', hex: '#1E63C4', rgb: '30, 99, 196' },
  { label: 'Vermelho', hex: '#D32F2F', rgb: '211, 47, 47' },
  { label: 'Prata', hex: '#C0C0C0', rgb: '192, 192, 192' },
  { label: 'Dourado', hex: '#D4AF37', rgb: '212, 175, 55' },
  { label: 'Cinza', hex: '#808080', rgb: '128, 128, 128' },
  { label: 'Verde', hex: '#2E7D32', rgb: '46, 125, 50' },
  { label: 'Rosa', hex: '#EC407A', rgb: '236, 64, 122' },
];

/** Encontra o chip correspondente a um rótulo de cor (comparação insensível a acento/caixa). */
export function findEquipmentColorOption(label: string): EquipmentColorOption | null {
  const target = normalizeCatalogLabel(label);
  if (!target) {
    return null;
  }
  return EQUIPMENT_COLOR_OPTIONS.find((option) => normalizeCatalogLabel(option.label) === target) ?? null;
}
