import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const cropper = {
  rotate: vi.fn(),
  reset: vi.fn(),
  destroy: vi.fn(),
  getCroppedCanvas: vi.fn(() => ({
    toBlob: (callback: (blob: Blob | null) => void) => callback(new Blob(['jpeg'], { type: 'image/jpeg' })),
  })),
};
const constructed: Array<Record<string, unknown>> = [];

vi.mock('cropperjs', () => ({
  default: vi.fn().mockImplementation(function (this: unknown, _image: HTMLImageElement, options: Record<string, unknown>) {
    constructed.push(options);
    queueMicrotask(() => (options.ready as () => void)?.());
    return cropper;
  }),
}));
vi.mock('cropperjs/dist/cropper.css', () => ({}));

import PhotoCropDialog from '@/components/orders/order-form-wizard/photo-crop-dialog';

describe('PhotoCropDialog', () => {
  beforeEach(() => {
    constructed.length = 0;
    vi.clearAllMocks();
    window.URL.createObjectURL = vi.fn(() => 'blob:mock-url');
    window.URL.revokeObjectURL = vi.fn();
  });

  it('abre o Cropper na imagem, gira e entrega um JPEG "-recorte"', async () => {
    const user = userEvent.setup();
    const onDone = vi.fn();
    render(<PhotoCropDialog file={new File(['x'], 'placa.png', { type: 'image/png' })} onCancel={vi.fn()} onDone={onDone} />);

    fireEvent.load(screen.getByAltText('Foto para recortar'));
    await waitFor(() => expect(screen.getByText('Usar recorte')).not.toBeDisabled());
    expect(constructed[0]).toMatchObject({ viewMode: 1, autoCropArea: 1, checkOrientation: false });

    await user.click(screen.getAllByText('↻ Girar')[0]);
    expect(cropper.rotate).toHaveBeenCalledWith(90);

    await user.click(screen.getByText('Usar recorte'));
    await waitFor(() => expect(onDone).toHaveBeenCalledTimes(1));
    const file = onDone.mock.calls[0][0] as File;
    expect(file.name).toBe('placa-recorte.jpg');
    expect(file.type).toBe('image/jpeg');
    expect(cropper.getCroppedCanvas).toHaveBeenCalledWith(expect.objectContaining({ maxWidth: 2560, maxHeight: 2560, fillColor: '#ffffff' }));
  });

  it('cancelar não entrega nada', async () => {
    const user = userEvent.setup();
    const onCancel = vi.fn();
    const onDone = vi.fn();
    render(<PhotoCropDialog file={new File(['x'], 'a.jpg', { type: 'image/jpeg' })} onCancel={onCancel} onDone={onDone} />);

    await user.click(screen.getByText('Cancelar'));
    expect(onCancel).toHaveBeenCalled();
    expect(onDone).not.toHaveBeenCalled();
  });

  it('avisa quando o navegador não abre a imagem (HEIC)', async () => {
    render(<PhotoCropDialog file={new File(['x'], 'IMG.HEIC', { type: 'image/heic' })} onCancel={vi.fn()} onDone={vi.fn()} />);

    fireEvent.error(screen.getByAltText('Foto para recortar'));
    expect(await screen.findByText(/não abre no editor/)).toBeInTheDocument();
    expect(screen.getByText('Usar recorte')).toBeDisabled();
  });
});
