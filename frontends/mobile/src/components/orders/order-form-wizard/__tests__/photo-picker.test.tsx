import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PhotoPicker } from '@/components/orders/order-form-wizard/photo-picker';

// O diálogo real usa o Cropper.js (canvas), que o jsdom não tem: aqui ele
// "recorta" na hora — o próprio diálogo tem teste separado.
vi.mock('@/components/orders/order-form-wizard/photo-crop-dialog', () => ({
  default: ({ file, onDone }: { file: File; onDone: (file: File) => void }) => {
    const base = file.name.replace(/\.[^.]*$/, '');
    queueMicrotask(() => onDone(new File(['recortado'], `${base}-recorte.jpg`, { type: 'image/jpeg' })));
    return null;
  },
}));

vi.mock('@/lib/photo-compression', () => ({
  compressImageFile: vi.fn(async (file: File) => file),
  isOperationalPhotoFile: vi.fn(() => true),
  MAX_OPERATIONAL_PHOTO_SOURCE_BYTES: 20 * 1024 * 1024,
}));

function buildFile(name: string, type = 'image/jpeg'): File {
  return new File(['conteudo'], name, { type });
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

  it('não mostra os botões de adicionar quando o limite já foi atingido', () => {
    const existing = [buildFile('a.jpg'), buildFile('b.jpg')];

    render(<PhotoPicker label="Fotos" value={existing} onChange={vi.fn()} maxFiles={2} />);

    expect(screen.queryByText('Galeria')).not.toBeInTheDocument();
    expect(screen.queryByText('Câmera')).not.toBeInTheDocument();
  });

  // Padrão único de inserção de imagem (specs/049): câmera direta, arrastar e
  // recorte opcional — o mesmo comportamento do desktop.
  it('oferece a câmera do aparelho num input próprio, sem tirar a galeria do outro', async () => {
    const user = userEvent.setup();
    render(<PhotoPicker label="Fotos" value={[]} onChange={vi.fn()} maxFiles={4} />);

    const camera = screen.getByTestId('photo-picker-camera-input') as HTMLInputElement;
    expect(camera.getAttribute('capture')).toBe('environment');
    expect(camera.accept).toBe('image/*');

    const click = vi.spyOn(camera, 'click');
    await user.click(screen.getByText('Câmera'));
    expect(click).toHaveBeenCalled();
  });

  it('adiciona a foto tirada pela câmera', async () => {
    const onChange = vi.fn();
    render(<PhotoPicker label="Fotos" value={[]} onChange={onChange} maxFiles={4} />);

    const file = buildFile('camera.jpg');
    await userEvent.upload(screen.getByTestId('photo-picker-camera-input') as HTMLInputElement, file);

    await waitFor(() => expect(onChange).toHaveBeenCalledWith([file]));
  });

  it('aceita fotos arrastadas para a grade', async () => {
    const onChange = vi.fn();
    render(<PhotoPicker label="Fotos" value={[]} onChange={onChange} maxFiles={4} />);

    const file = buildFile('arrastada.png', 'image/png');
    fireEvent.drop(screen.getByTestId('photo-picker-grid'), {
      dataTransfer: { files: [file], types: ['Files'] },
    });

    await waitFor(() => expect(onChange).toHaveBeenCalledWith([file]));
  });

  it('recorta uma foto sob demanda e troca só ela na lista', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const existing = [buildFile('a.jpg'), buildFile('b.jpg')];

    render(<PhotoPicker label="Fotos" value={existing} onChange={onChange} maxFiles={4} />);

    await user.click(screen.getByLabelText('Recortar foto 2'));

    await waitFor(() => expect(onChange).toHaveBeenCalledTimes(1));
    const [nextValue] = onChange.mock.calls[0] as [File[]];
    expect(nextValue[0]).toBe(existing[0]);
    expect(nextValue[1].name).toBe('b-recorte.jpg');
  });

  it('não força a câmera: o input de arquivo aceita galeria/arquivos também', () => {
    render(<PhotoPicker label="Fotos" value={[]} onChange={vi.fn()} maxFiles={4} />);

    const input = document.querySelector('input[type="file"]') as HTMLInputElement;

    expect(input.getAttribute('capture')).toBeNull();
  });

  it('aceita HEIC de iPhone mesmo quando a prévia depende do backend', async () => {
    const onChange = vi.fn();
    render(<PhotoPicker label="Fotos" value={[]} onChange={onChange} maxFiles={4} />);

    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    const file = buildFile('IMG_0042.HEIC', 'image/heic');

    await userEvent.upload(input, file);

    await waitFor(() => expect(onChange).toHaveBeenCalledWith([file]));
  });

  it('declara todos os formatos operacionais no seletor', () => {
    render(<PhotoPicker label="Fotos" value={[]} onChange={vi.fn()} maxFiles={4} />);

    const accept = (document.querySelector('input[type="file"]') as HTMLInputElement).accept;

    expect(accept).toContain('image/avif');
    expect(accept).toContain('.heic');
    expect(accept).toContain('.heif');
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
