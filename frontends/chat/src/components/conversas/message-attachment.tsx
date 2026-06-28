'use client';

import { useEffect, useState } from 'react';
import { ApiError, apiFetchAttachmentBlob } from '@/lib/api';
import type { ChatAttachment } from '@/lib/types';

type MessageAttachmentProps = {
  attachment: ChatAttachment;
};

function formatBytes(value: number | null): string {
  if (!value || value <= 0) {
    return '';
  }

  const units = ['B', 'KB', 'MB', 'GB'];
  let size = value;
  let unitIndex = 0;

  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024;
    unitIndex += 1;
  }

  return `${size.toFixed(size >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

function isPreviewable(type: ChatAttachment['attachment_type']): boolean {
  return type === 'image' || type === 'audio' || type === 'video';
}

function attachmentLabel(type: ChatAttachment['attachment_type']): string {
  switch (type) {
    case 'image':
      return 'Imagem';
    case 'audio':
      return 'Áudio';
    case 'video':
      return 'Vídeo';
    case 'document':
      return 'Documento';
    default:
      return 'Anexo';
  }
}

export function MessageAttachmentView({ attachment }: MessageAttachmentProps) {
  const previewable = attachment.available && isPreviewable(attachment.attachment_type);
  const [objectUrl, setObjectUrl] = useState<string | null>(null);
  const [loading, setLoading] = useState<boolean>(previewable);
  const [error, setError] = useState<string | null>(attachment.available ? null : 'Mídia indisponível.');

  useEffect(() => {
    let active = true;
    let createdUrl: string | null = null;

    if (!attachment.available) {
      setLoading(false);
      setError('Mídia indisponível.');
      return undefined;
    }

    if (!previewable) {
      setLoading(false);
      setError(null);
      return undefined;
    }

    setLoading(true);
    setError(null);

    void apiFetchAttachmentBlob(attachment.url)
      .then((blob) => {
        if (!active) {
          return;
        }

        createdUrl = URL.createObjectURL(blob);
        setObjectUrl(createdUrl);
      })
      .catch((fetchError) => {
        if (!active) {
          return;
        }

        setError(fetchError instanceof ApiError ? fetchError.message : 'Falha ao carregar o anexo.');
      })
      .finally(() => {
        if (active) {
          setLoading(false);
        }
      });

    return () => {
      active = false;
      if (createdUrl) {
        URL.revokeObjectURL(createdUrl);
      }
    };
  }, [attachment.available, attachment.id, attachment.url, previewable]);

  const handleDownload = async (): Promise<void> => {
    try {
      let temporaryUrl: string | null = null;
      let downloadUrl = objectUrl;

      if (!downloadUrl) {
        const blob = await apiFetchAttachmentBlob(attachment.url);
        temporaryUrl = URL.createObjectURL(blob);
        downloadUrl = temporaryUrl;
      }

      const anchor = document.createElement('a');
      anchor.href = downloadUrl;
      anchor.download = attachment.original_name ?? `anexo-${attachment.id}`;
      anchor.rel = 'noreferrer';
      anchor.click();

      if (temporaryUrl) {
        setTimeout(() => URL.revokeObjectURL(temporaryUrl as string), 500);
      }
    } catch (downloadError) {
      setError(downloadError instanceof ApiError ? downloadError.message : 'Falha ao baixar o anexo.');
    }
  };

  return (
    <div className="message-attachment">
      <div className="message-attachment__header">
        <strong>{attachment.original_name ?? attachmentLabel(attachment.attachment_type)}</strong>
        {attachment.byte_size ? <span>{formatBytes(attachment.byte_size)}</span> : null}
      </div>

      {loading ? <div className="message-attachment__placeholder">Carregando anexo...</div> : null}

      {!loading && error ? (
        <div className="message-attachment__placeholder message-attachment__placeholder--error">
          {error}
        </div>
      ) : null}

      {!loading && !error && previewable && attachment.attachment_type === 'image' && objectUrl ? (
        <img
          className="message-attachment__image"
          src={objectUrl}
          alt={attachment.original_name ?? 'Imagem enviada'}
          loading="lazy"
          decoding="async"
        />
      ) : null}

      {!loading && !error && previewable && attachment.attachment_type === 'audio' && objectUrl ? (
        <audio className="message-attachment__media" controls preload="metadata" src={objectUrl}>
          Seu navegador não suporta áudio.
        </audio>
      ) : null}

      {!loading && !error && previewable && attachment.attachment_type === 'video' && objectUrl ? (
        <video className="message-attachment__media" controls preload="metadata" src={objectUrl}>
          Seu navegador não suporta vídeo.
        </video>
      ) : null}

      {!loading && !error && attachment.available && !previewable ? (
        <div className="message-attachment__placeholder">Pré-visualização indisponível para este arquivo.</div>
      ) : null}

      <div className="message-attachment__footer">
        <span>{attachmentLabel(attachment.attachment_type)}</span>
        <button type="button" className="button button--ghost button--sm" onClick={() => void handleDownload()}>
          Baixar
        </button>
      </div>
    </div>
  );
}
