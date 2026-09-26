'use client';

import { useEffect, useRef, useState } from 'react';
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';

/**
 * Recorte OPCIONAL de uma foto — o mesmo padrão de inserção de imagem do
 * desktop (specs/049), com a mesma biblioteca (Cropper.js 1.6.2). Este módulo
 * é carregado sob demanda pelo PhotoPicker (React.lazy): só baixa quando o
 * técnico toca em "Recortar".
 */
type PhotoCropDialogProps = {
  file: File;
  onCancel: () => void;
  onDone: (file: File) => void;
};

const MAX_SIDE = 2560;

export default function PhotoCropDialog({ file, onCancel, onDone }: PhotoCropDialogProps) {
  const imageRef = useRef<HTMLImageElement>(null);
  const cropperRef = useRef<Cropper | null>(null);
  const [url] = useState(() => URL.createObjectURL(file));
  const [ready, setReady] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => () => {
    cropperRef.current?.destroy();
    cropperRef.current = null;
    URL.revokeObjectURL(url);
  }, [url]);

  const handleLoad = (): void => {
    if (!imageRef.current || cropperRef.current) {
      return;
    }

    cropperRef.current = new Cropper(imageRef.current, {
      viewMode: 1,
      autoCropArea: 1,
      background: false,
      responsive: true,
      // Sem XHR no blob: para ler EXIF — a CSP (connect-src) não libera blob:,
      // e os navegadores atuais já giram a foto pela orientação EXIF.
      checkOrientation: false,
      ready: () => setReady(true),
    });
  };

  const handleConfirm = async (): Promise<void> => {
    const cropper = cropperRef.current;
    if (!cropper || saving) {
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const canvas = cropper.getCroppedCanvas({
        maxWidth: MAX_SIDE,
        maxHeight: MAX_SIDE,
        fillColor: '#ffffff',
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high',
      });
      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
      if (!blob) {
        setError('Não foi possível recortar a foto. Tente de novo.');
        return;
      }

      const baseName = file.name.replace(/\.[^.]*$/, '').replace(/-recorte$/, '') || 'foto';
      onDone(new File([blob], `${baseName}-recorte.jpg`, { type: 'image/jpeg', lastModified: Date.now() }));
    } catch {
      setError('Não foi possível recortar a foto. Tente de novo.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="modal-backdrop photo-crop-backdrop" role="presentation">
      <div className="modal-card photo-crop-card" role="dialog" aria-modal="true" aria-labelledby="photoCropTitle">
        <div className="modal-header">
          <div>
            <h3 id="photoCropTitle">Recortar foto</h3>
            <p>Ajuste a área que deve ficar. Recortar é opcional.</p>
          </div>
        </div>

        <div className="photo-crop-stage">
          {/* eslint-disable-next-line @next/next/no-img-element -- blob local; o Cropper precisa do <img> real */}
          <img
            ref={imageRef}
            src={url}
            alt="Foto para recortar"
            onLoad={handleLoad}
            onError={() => setError('Esta foto não abre no editor (ex.: HEIC). Ela segue sem recorte e o servidor converte.')}
          />
        </div>

        <div className="photo-crop-tools" role="toolbar" aria-label="Ferramentas do recorte">
          <button type="button" className="button button--ghost button-small" onClick={() => cropperRef.current?.rotate(-90)} disabled={!ready}>
            ↺ Girar
          </button>
          <button type="button" className="button button--ghost button-small" onClick={() => cropperRef.current?.rotate(90)} disabled={!ready}>
            ↻ Girar
          </button>
          <button type="button" className="button button--ghost button-small" onClick={() => cropperRef.current?.reset()} disabled={!ready}>
            Restaurar
          </button>
        </div>

        {error ? (
          <div className="notice notice--danger">
            <span>{error}</span>
          </div>
        ) : null}

        <div className="dialog-actions">
          <button type="button" className="button button--ghost" onClick={onCancel} disabled={saving}>
            Cancelar
          </button>
          <button type="button" className="button button--primary" onClick={() => void handleConfirm()} disabled={!ready || saving}>
            {saving ? 'Recortando…' : 'Usar recorte'}
          </button>
        </div>
      </div>
    </div>
  );
}
