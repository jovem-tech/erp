'use client';

import { useEffect, useState } from 'react';

type PreviewItem = {
  name: string;
  type: string;
  url: string | null;
};

type AttachmentPreviewListProps = {
  files: File[];
  onRemove?: (index: number) => void;
};

export function AttachmentPreviewList({ files, onRemove }: AttachmentPreviewListProps) {
  const [previews, setPreviews] = useState<PreviewItem[]>([]);

  useEffect(() => {
    const nextPreviews = files.map((file) => ({
      name: file.name,
      type: file.type,
      url: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
    }));

    setPreviews(nextPreviews);

    return () => {
      nextPreviews.forEach((preview) => {
        if (preview.url) {
          URL.revokeObjectURL(preview.url);
        }
      });
    };
  }, [files]);

  if (files.length === 0) {
    return null;
  }

  return (
    <div className="attachment-preview-list">
      {previews.map((preview, index) => (
        <div key={`${preview.name}-${index}`} className="attachment-preview-card">
          {preview.url ? (
            <img className="attachment-preview-card__image" src={preview.url} alt={preview.name} />
          ) : (
            <div className="attachment-preview-card__icon">{preview.type.startsWith('audio/') ? 'Á' : 'Doc'}</div>
          )}
          <div className="attachment-preview-card__meta">
            <strong>{preview.name}</strong>
            <span>{preview.type || 'Arquivo'}</span>
          </div>
          {onRemove ? (
            <button type="button" className="button button--ghost button--sm" onClick={() => onRemove(index)}>
              Remover
            </button>
          ) : null}
        </div>
      ))}
    </div>
  );
}
