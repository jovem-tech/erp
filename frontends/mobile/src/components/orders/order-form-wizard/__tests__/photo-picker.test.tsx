import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PhotoPicker } from '@/components/orders/order-form-wizard/photo-picker';

vi.mock('@/lib/photo-compression', () => ({
  compressImageFile: vi.fn(async (file: File) => file),
}));

function buildFile(name: string): File {
  return new File(['conteudo'], name, { type: 'image/jpeg' });
}

describe('PhotoPicker', () => {
  beforeEach(() => {
    window.URL.createObjectURL = vi.fn(() => 'blob:mock-url');
    window.URL.revokeObjectURL = vi.fn();
  });

  it('adiciona arquivos selecionados (após "compressão") à lista', async () => {
    const onChange = vi.fn();

    render(<PhotoPicker label="Fotos da OS" value={[]} onChange={onChange} maxFiles={4} />);

    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    const file = buildFile('foto1.jpg');

    await userEvent.upload(input, file);

    await waitFor(() => expect(onChange).toHaveBeenCalledWith([file]));
  });

  it('limita a quantidade de arquivos ao espaço restante (maxFiles - já anexados)', async () => {
    const onChange = vi.fn();
    const existing = [buildFile('a.jpg'), buildFile('b.jpg'), buildFile('c.jpg')];

    render(<PhotoPicker label="Fotos" value={existing} onChange={onChange} maxFiles={4} />);

    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    const extra1 = buildFile('d.jpg');
    const extra2 = buildFile('e.jpg');

    await userEvent.upload(input, [extra1, extra2]);

    await waitFor(() => expect(onChange).toHaveBeenCalledWith([...existing, extra1]));
  });

  it('não mostra o botão de adicionar quando o limite já foi atingido', () => {
    const existing = [buildFile('a.jpg'), buildFile('b.jpg')];

    render(<PhotoPicker label="Fotos" value={existing} onChange={vi.fn()} maxFiles={2} />);

    expect(screen.queryByText('+ Adicionar foto')).not.toBeInTheDocument();
  });

  it('remove um arquivo pelo índice', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const existing = [buildFile('a.jpg'), buildFile('b.jpg')];

    render(<PhotoPicker label="Fotos" value={existing} onChange={onChange} maxFiles={4} />);

    await user.click(screen.getByLabelText('Remover foto 1'));

    expect(onChange).toHaveBeenCalledWith([existing[1]]);
  });
});
