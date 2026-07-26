import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { OrderAttachments } from '@/components/orders/order-attachments';
import { fetchAttachmentBlob } from '@/lib/api';
import type { OrderDocument, OrderPhoto } from '@/lib/types';

vi.mock('@/lib/api', () => ({
  fetchAttachmentBlob: vi.fn(),
}));

const photo: OrderPhoto = {
  id: 31,
  tipo: 'recepcao',
  tipo_label: 'Recepção',
  arquivo: 'orders/3647/photo.jpg',
  nome_arquivo: 'os_3647_01.jpg',
  url: 'https://untrusted.example.test/collect-token',
  created_at: '2026-07-26T15:27:03-03:00',
};

const document: OrderDocument = {
  id: 42,
  tipo_documento: 'abertura',
  tipo_label: 'Abertura',
  arquivo: 'orders/3647/document.pdf',
  nome_arquivo: 'os_3647_abertura.pdf',
  versao: 1,
  hash_sha1: 'a'.repeat(40),
  url: 'https://untrusted.example.test/collect-token',
  created_at: '2026-07-26T15:27:03-03:00',
  updated_at: '2026-07-26T15:27:03-03:00',
  gerado_por: 1,
  gerado_por_usuario: null,
};

describe('OrderAttachments', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('carrega a miniatura privada da foto usando somente IDs confiáveis', async () => {
    const createObjectUrl = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:order-photo');
    const revokeObjectUrl = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);

    vi.mocked(fetchAttachmentBlob).mockResolvedValue({
      blob: new Blob(['foto'], { type: 'image/jpeg' }),
      contentType: 'image/jpeg',
      filename: photo.nome_arquivo,
    });

    const { unmount } = render(
      <OrderAttachments order={{ id: 3647, fotos: [photo], documentos: [] }} />
    );

    await waitFor(() => {
      expect(fetchAttachmentBlob).toHaveBeenCalledWith('/orders/3647/photos/31');
    });

    expect(fetchAttachmentBlob).not.toHaveBeenCalledWith(photo.url);
    expect(await screen.findByAltText('Foto Recepção')).toHaveAttribute('src', 'blob:order-photo');
    expect(createObjectUrl).toHaveBeenCalledTimes(1);

    unmount();
    expect(revokeObjectUrl).toHaveBeenCalledWith('blob:order-photo');
  });

  it('mantém documento como fallback textual e abre pela rota interna autenticada', async () => {
    const user = userEvent.setup();
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:order-document');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);

    vi.mocked(fetchAttachmentBlob).mockResolvedValue({
      blob: new Blob(['pdf'], { type: 'application/pdf' }),
      contentType: 'application/pdf',
      filename: document.nome_arquivo,
    });

    render(
      <OrderAttachments order={{ id: 3647, fotos: [], documentos: [document] }} />
    );

    expect(screen.getAllByText('Abertura')).toHaveLength(2);
    expect(screen.getAllByText(document.nome_arquivo)).toHaveLength(2);
    expect(fetchAttachmentBlob).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: `Abrir ${document.nome_arquivo}` }));

    await waitFor(() => {
      expect(fetchAttachmentBlob).toHaveBeenCalledWith('/orders/3647/documents/42');
    });
    expect(fetchAttachmentBlob).not.toHaveBeenCalledWith(document.url);
    expect(await screen.findByTitle(document.nome_arquivo)).toHaveAttribute(
      'src',
      'blob:order-document'
    );
  });

  it('preserva um fallback identificável quando a miniatura não pode ser carregada', async () => {
    vi.mocked(fetchAttachmentBlob).mockRejectedValue(new Error('arquivo indisponível'));

    render(
      <OrderAttachments order={{ id: 3647, fotos: [photo], documentos: [] }} />
    );

    expect(await screen.findByText('Foto indisponível')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: `Abrir ${photo.nome_arquivo}` })).toBeInTheDocument();
  });
});
