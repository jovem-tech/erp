import { describe, expect, it } from 'vitest';
import { buildFormData } from '@/lib/form-data';

function entries(formData: FormData): Array<[string, FormDataEntryValue]> {
  return Array.from(formData.entries());
}

describe('buildFormData', () => {
  it('serializes a nested object with bracket notation', () => {
    const formData = buildFormData({
      novo_cliente: { nome_razao: 'João Silva', telefone1: '11999999999' },
    });

    expect(entries(formData)).toEqual([
      ['novo_cliente[nome_razao]', 'João Silva'],
      ['novo_cliente[telefone1]', '11999999999'],
    ]);
  });

  it('serializes an array of objects with numeric index', () => {
    const formData = buildFormData({
      itens: [{ campo: 'a' }, { campo: 'b' }],
    });

    expect(entries(formData)).toEqual([
      ['itens[0][campo]', 'a'],
      ['itens[1][campo]', 'b'],
    ]);
  });

  it('serializes a loose array of files as repeated key[]', () => {
    const fileA = new File(['a'], 'a.jpg', { type: 'image/jpeg' });
    const fileB = new File(['b'], 'b.jpg', { type: 'image/jpeg' });

    const formData = buildFormData({ fotos: [fileA, fileB] });
    const values = entries(formData);

    expect(values).toHaveLength(2);
    expect(values[0][0]).toBe('fotos[]');
    expect(values[1][0]).toBe('fotos[]');
    expect(values[0][1]).toBe(fileA);
    expect(values[1][1]).toBe(fileB);
  });

  it('omits null and undefined values instead of sending literal strings', () => {
    const formData = buildFormData({
      cliente_id: 5,
      orcamento_id: null,
      tecnico_id: undefined,
    });

    expect(entries(formData)).toEqual([['cliente_id', '5']]);
  });

  it('serializes booleans as "1"/"0"', () => {
    const formData = buildFormData({ enviar_pdf_cliente: true, outro: false });

    expect(entries(formData)).toEqual([
      ['enviar_pdf_cliente', '1'],
      ['outro', '0'],
    ]);
  });

  it('serializes a real checklist_entrada payload with the exact keys UpsertOrderRequest expects', () => {
    const formData = buildFormData({
      checklist_entrada: {
        observacoes_estado: 'Tudo confere',
        respostas: [
          { checklist_item_id: 10, status: 'ok', observacao: null },
          { checklist_item_id: 11, status: 'discrepancia', observacao: 'Tela riscada' },
          { checklist_item_id: 12, status: 'nao_se_aplica', observacao: null },
        ],
      },
    });

    expect(entries(formData)).toEqual([
      ['checklist_entrada[observacoes_estado]', 'Tudo confere'],
      ['checklist_entrada[respostas][0][checklist_item_id]', '10'],
      ['checklist_entrada[respostas][0][status]', 'ok'],
      ['checklist_entrada[respostas][1][checklist_item_id]', '11'],
      ['checklist_entrada[respostas][1][status]', 'discrepancia'],
      ['checklist_entrada[respostas][1][observacao]', 'Tela riscada'],
      ['checklist_entrada[respostas][2][checklist_item_id]', '12'],
      ['checklist_entrada[respostas][2][status]', 'nao_se_aplica'],
    ]);
  });
});
