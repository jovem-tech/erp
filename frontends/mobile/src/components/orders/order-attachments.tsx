'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { fetchAttachmentBlob } from '@/lib/api';
import type { OrderDetail, OrderDocument, OrderPhoto } from '@/lib/types';

type AttachmentItem = OrderPhoto | OrderDocument;

type AttachmentPreview = {
  title: string;
  url: string;
  contentType: string;
  filename: string;
};

type OrderAttachmentsProps = {
  order: Pick<OrderDetail, 'id' | 'fotos' | 'documentos'>;
};

function isOrderPhoto(item: AttachmentItem): item is OrderPhoto {
  return 'tipo' in item;
}

function attachmentPath(orderId: number, item: AttachmentItem): string | null {
  if (!Number.isSafeInteger(orderId) || orderId <= 0 || !Number.isSafeInteger(item.id) || item.id <= 0) {
    return null;
  }

  return isOrderPhoto(item)
    ? `/orders/${orderId}/photos/${item.id}`
    : `/orders/${orderId}/documents/${item.id}`;
}

function AttachmentFallback({
  item,
  status,
}: {
  item: AttachmentItem;
  status?: 'loading' | 'error';
}) {
  return (
    <span className="attachment-card__thumb attachment-card__fallback">
      <strong>{item.tipo_label}</strong>
      <span>
        {status === 'loading'
          ? 'Carregando foto'
          : status === 'error'
            ? 'Foto indisponível'
            : item.nome_arquivo}
      </span>
    </span>
  );
}

function AttachmentThumbnail({
  orderId,
  item,
  onOpen,
}: {
  orderId: number;
  item: AttachmentItem;
  onOpen: (item: AttachmentItem) => void;
}) {
  const containerRef = useRef<HTMLButtonElement>(null);
  const [shouldLoad, setShouldLoad] = useState(false);
  const [photoUrl, setPhotoUrl] = useState<string | null>(null);
  const [photoFailed, setPhotoFailed] = useState(false);
  const photo = isOrderPhoto(item);

  useEffect(() => {
    setShouldLoad(false);

    if (!photo) {
      return;
    }

    if (typeof IntersectionObserver === 'undefined') {
      setShouldLoad(true);
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setShouldLoad(true);
          observer.disconnect();
        }
      },
      { rootMargin: '160px' }
    );

    if (containerRef.current) {
      observer.observe(containerRef.current);
    }

    return () => observer.disconnect();
  }, [item.id, photo]);

  useEffect(() => {
    let cancelled = false;
    let objectUrl: string | null = null;

    setPhotoUrl(null);
    setPhotoFailed(false);

    if (!photo || !shouldLoad) {
      return;
    }

    const sourcePath = attachmentPath(orderId, item);
    if (!sourcePath) {
      setPhotoFailed(true);
      return;
    }

    fetchAttachmentBlob(sourcePath)
      .then((attachment) => {
        if (!attachment.contentType.toLowerCase().startsWith('image/')) {
          if (!cancelled) {
            setPhotoFailed(true);
          }
          return;
        }

        objectUrl = URL.createObjectURL(attachment.blob);
        if (cancelled) {
          URL.revokeObjectURL(objectUrl);
          objectUrl = null;
          return;
        }

        setPhotoUrl(objectUrl);
      })
      .catch(() => {
        if (!cancelled) {
          setPhotoFailed(true);
        }
      });

    return () => {
      cancelled = true;
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [item, orderId, photo, shouldLoad]);

  return (
    <button
      ref={containerRef}
      type="button"
      className="attachment-card__preview-button"
      onClick={() => onOpen(item)}
      aria-label={`Abrir ${item.nome_arquivo}`}
    >
      {photoUrl ? (
        // eslint-disable-next-line @next/next/no-img-element -- blob privado obtido com Bearer e MIME de imagem validado
        <img className="attachment-card__thumb" src={photoUrl} alt={`Foto ${item.tipo_label}`} />
      ) : (
        <AttachmentFallback
          item={item}
          status={photo ? (photoFailed ? 'error' : 'loading') : undefined}
        />
      )}
    </button>
  );
}

function AttachmentList({
  orderId,
  title,
  items,
  onOpen,
  onDownload,
}: {
  orderId: number;
  title: string;
  items: AttachmentItem[];
  onOpen: (item: AttachmentItem) => void;
  onDownload?: (item: AttachmentItem) => void;
}) {
  return (
    <div className="attachments">
      <div className="section__header section__header--flush">
        <h4 className="section__title">{title}</h4>
        <span className="muted">{items.length} item(ns)</span>
      </div>

      <div className="attachments__grid">
        {items.map((item) => (
          <article key={item.id} className="attachment-card">
            <AttachmentThumbnail orderId={orderId} item={item} onOpen={onOpen} />

            <div>
              <p className="card__title attachment-card__title">
                {item.nome_arquivo}
              </p>
              <p className="muted attachment-card__meta">
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

  const loadAttachment = (attachment: AttachmentItem) => {
    const sourcePath = attachmentPath(order.id, attachment);
    if (!sourcePath) {
      throw new Error('Identificador de anexo inválido.');
    }

    return fetchAttachmentBlob(sourcePath);
  };

  const openAttachment = async (attachment: AttachmentItem): Promise<void> => {
    setLoading(true);
    setError(null);

    try {
      const result = await loadAttachment(attachment);
      if (isOrderPhoto(attachment) && !result.contentType.toLowerCase().startsWith('image/')) {
        throw new Error('A foto retornou um formato inválido.');
      }

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

  const downloadAttachment = async (attachment: AttachmentItem): Promise<void> => {
    setLoading(true);
    setError(null);

    try {
      const result = await loadAttachment(attachment);
      const previewUrl = URL.createObjectURL(result.blob);
      const link = document.createElement('a');
      link.href = previewUrl;
      link.download = result.filename;
      link.rel = 'noopener noreferrer';
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(previewUrl), 1000);
    } catch (attachmentError) {
      console.error('[Mobile] falha ao baixar anexo', attachmentError);
      setError('Não foi possível baixar este arquivo.');
    } finally {
      setLoading(false);
    }
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
          <AttachmentList
            orderId={order.id}
            title="Fotos"
            items={photos}
            onOpen={openAttachment}
            onDownload={downloadAttachment}
          />
        ) : (
          <div className="muted-box">Nenhuma foto vinculada a esta OS.</div>
        )}

        {documents.length > 0 ? (
          <AttachmentList
            orderId={order.id}
            title="Documentos"
            items={documents}
            onOpen={openAttachment}
            onDownload={downloadAttachment}
          />
        ) : (
          <div className="muted-box">Nenhum documento vinculado a esta OS.</div>
        )}
      </div>

      {preview ? (
        <div className="preview-panel preview-panel--spaced">
          <div className="toolbar">
            <div>
              <p className="card__title">{preview.title}</p>
              <p className="muted preview-panel__filename">
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

          {preview.contentType.toLowerCase().startsWith('image/') ? (
            <img
              src={preview.url}
              alt={preview.title}
              className="preview-panel__image"
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
