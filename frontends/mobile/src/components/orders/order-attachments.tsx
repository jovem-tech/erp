'use client';

import { useEffect, useMemo, useState } from 'react';
import { fetchAttachmentBlob } from '@/lib/api';
import type { OrderDetail, OrderDocument, OrderPhoto } from '@/lib/types';

type AttachmentPreview = {
  title: string;
  url: string;
  contentType: string;
  filename: string;
};

type OrderAttachmentsProps = {
  order: Pick<OrderDetail, 'id' | 'fotos' | 'documentos'>;
};

function AttachmentList({
  title,
  items,
  onOpen,
  onDownload,
}: {
  title: string;
  items: Array<OrderPhoto | OrderDocument>;
  onOpen: (item: OrderPhoto | OrderDocument) => void;
  onDownload?: (item: OrderPhoto | OrderDocument) => void;
}) {
  return (
    <div className="attachments">
      <div className="section__header" style={{ marginBottom: 0 }}>
        <h4 className="section__title">{title}</h4>
        <span className="muted">{items.length} item(ns)</span>
      </div>

      <div className="attachments__grid">
        {items.map((item) => (
          <article key={item.id} className="attachment-card">
            {'tipo_label' in item ? (
              <img
                className="attachment-card__thumb"
                src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(
                  `<svg xmlns="http://www.w3.org/2000/svg" width="640" height="480" viewBox="0 0 640 480">
                    <rect width="640" height="480" fill="#11243f"/>
                    <text x="50%" y="48%" fill="#57dac7" font-size="32" text-anchor="middle" font-family="Trebuchet MS, sans-serif">${item.tipo_label}</text>
                    <text x="50%" y="58%" fill="#edf3ff" font-size="18" text-anchor="middle" font-family="Trebuchet MS, sans-serif">${item.nome_arquivo}</text>
                  </svg>`
                )}`}
                alt={item.nome_arquivo}
              />
            ) : null}

            <div>
              <p className="card__title" style={{ fontSize: '0.95rem' }}>
                {item.nome_arquivo}
              </p>
              <p className="muted" style={{ margin: '6px 0 0', fontSize: '0.84rem' }}>
                {item.tipo_label}
              </p>
            </div>

            <div className="toolbar">
              <button type="button" className="button button--soft" onClick={() => onOpen(item)}>
                Abrir
              </button>
              {onDownload ? (
                <button type="button" className="button button--ghost" onClick={() => onDownload(item)}>
                  Baixar
                </button>
              ) : null}
            </div>
          </article>
        ))}
      </div>
    </div>
  );
}

export function OrderAttachments({ order }: OrderAttachmentsProps) {
  const photos = useMemo(() => order.fotos ?? [], [order.fotos]);
  const documents = useMemo(() => order.documentos ?? [], [order.documentos]);
  const [preview, setPreview] = useState<AttachmentPreview | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    return () => {
      if (preview?.url) {
        URL.revokeObjectURL(preview.url);
      }
    };
  }, [preview]);

  const openAttachment = async (attachment: OrderPhoto | OrderDocument): Promise<void> => {
    setLoading(true);
    setError(null);

    try {
      const result = await fetchAttachmentBlob(attachment.url);
      const previewUrl = URL.createObjectURL(result.blob);

      setPreview((currentPreview) => {
        if (currentPreview?.url) {
          URL.revokeObjectURL(currentPreview.url);
        }

        return {
          title: attachment.nome_arquivo,
          url: previewUrl,
          contentType: result.contentType,
          filename: result.filename,
        };
      });
    } catch (attachmentError) {
      console.error('[Mobile] falha ao abrir anexo', attachmentError);
      setError('Não foi possível carregar este arquivo.');
    } finally {
      setLoading(false);
    }
  };

  const downloadAttachment = async (attachment: OrderPhoto | OrderDocument): Promise<void> => {
    const result = await fetchAttachmentBlob(attachment.url);
    const previewUrl = URL.createObjectURL(result.blob);
    const link = document.createElement('a');
    link.href = previewUrl;
    link.download = result.filename;
    link.rel = 'noopener noreferrer';
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(previewUrl), 1000);
  };

  return (
    <section className="card">
      <div className="section__header">
        <h3 className="section__title">Fotos e documentos</h3>
        {loading ? (
          <span className="badge badge--accent">
            <span className="spinner" aria-hidden="true" />
            Carregando arquivo
          </span>
        ) : (
          <span className="muted">Acesso controlado pelo backend</span>
        )}
      </div>

      {error ? <div className="notice notice--danger">{error}</div> : null}

      <div className="list">
        {photos.length > 0 ? (
          <AttachmentList title="Fotos" items={photos} onOpen={openAttachment} onDownload={downloadAttachment} />
        ) : (
          <div className="muted-box">Nenhuma foto vinculada a esta OS.</div>
        )}

        {documents.length > 0 ? (
          <AttachmentList title="Documentos" items={documents} onOpen={openAttachment} onDownload={downloadAttachment} />
        ) : (
          <div className="muted-box">Nenhum documento vinculado a esta OS.</div>
        )}
      </div>

      {preview ? (
        <div className="preview-panel" style={{ marginTop: '16px' }}>
          <div className="toolbar">
            <div>
              <p className="card__title">{preview.title}</p>
              <p className="muted" style={{ margin: '6px 0 0' }}>
                {preview.filename}
              </p>
            </div>
            <button
              type="button"
              className="button button--ghost"
              onClick={() => {
                URL.revokeObjectURL(preview.url);
                setPreview(null);
              }}
            >
              Fechar
            </button>
          </div>

          {preview.contentType.includes('image') ? (
            <img
              src={preview.url}
              alt={preview.title}
              style={{ width: '100%', maxHeight: '520px', objectFit: 'contain', borderRadius: '16px' }}
            />
          ) : (
            <iframe
              className="preview-panel__frame"
              src={preview.url}
              title={preview.title}
            />
          )}
        </div>
      ) : null}
    </section>
  );
}
