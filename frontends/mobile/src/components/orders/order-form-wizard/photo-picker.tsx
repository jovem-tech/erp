'use client';

import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import type { DragEvent } from 'react';
import {
  compressImageFile,
  isOperationalPhotoFile,
  MAX_OPERATIONAL_PHOTO_SOURCE_BYTES,
} from '@/lib/photo-compression';
import { FieldLabel } from '@/components/ui/field-label';

// Carregado só quando o técnico toca em "Recortar" (Cropper.js fica fora do
// pacote inicial do app).
const PhotoCropDialog = lazy(() => import('@/components/orders/order-form-wizard/photo-crop-dialog'));

function LocalPhotoPreview({
  file,
  url,
  index,
  onFailed,
}: {
  file: File;
  url: string;
  index: number;
  onFailed: () => void;
}) {
  const [failed, setFailed] = useState(false);

  useEffect(() => setFailed(false), [url]);

  if (failed) {
    return (
      <div className="photo-grid__fallback">
        <strong>{file.name}</strong>
        <span>{Math.max(1, Math.round(file.size / 1024))} KB</span>
        <span>Conversão no servidor</span>
      </div>
    );
  }

  // eslint-disable-next-line @next/next/no-img-element -- preview de blob local, sem otimização do Next
  return <img src={url} alt={`Foto ${index + 1}`} onError={() => { setFailed(true); onFailed(); }} />;
}

type PhotoPickerProps = {
  label: string;
  value: File[];
  onChange: (files: File[]) => void;
  maxFiles: number;
  disabled?: boolean;
  helpText?: string;
  required?: boolean;
};

let pasteFileCounter = 0;

const hasDraggedFiles = (event: DragEvent<HTMLElement>): boolean => Array.from(event.dataTransfer?.types ?? []).includes('Files');

/**
 * Padrão único de inserção de imagem (specs/049, o mesmo do desktop): Câmera
 * (a do aparelho), Galeria, Colar (botão e Ctrl+V fora de campo de texto),
 * arrastar e recorte OPCIONAL em cada foto.
 */
export function PhotoPicker({
  label,
  value,
  onChange,
  maxFiles,
  disabled = false,
  helpText,
  required = false,
}: PhotoPickerProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const cameraInputRef = useRef<HTMLInputElement>(null);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [previewUrls, setPreviewUrls] = useState<string[]>([]);
  const [failedPreviews, setFailedPreviews] = useState<Set<number>>(new Set());
  const [cropIndex, setCropIndex] = useState<number | null>(null);
  const [dragging, setDragging] = useState(false);
  // Só decidido no cliente (Clipboard API depende de `navigator`), então
  // começa false para o SSR/hidratação baterem antes de checar o suporte.
  const [canReadClipboard, setCanReadClipboard] = useState(false);

  useEffect(() => {
    setCanReadClipboard(typeof navigator !== 'undefined' && Boolean(navigator.clipboard?.read));
  }, []);

  useEffect(() => {
    const urls = value.map((file) => URL.createObjectURL(file));
    setPreviewUrls(urls);
    setFailedPreviews(new Set());

    return () => {
      urls.forEach((url) => URL.revokeObjectURL(url));
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- recalcula só quando a lista de arquivos muda
  }, [value]);

  const remainingSlots = maxFiles - value.length;

  const processFiles = async (files: File[]): Promise<void> => {
    if (files.length === 0) {
      return;
    }

    setProcessing(true);
    setError(null);

    try {
      const candidates = files.slice(0, remainingSlots);
      const invalidFormat = candidates.find((file) => !isOperationalPhotoFile(file));
      if (invalidFormat) {
        setError(`${invalidFormat.name}: use JPEG, PNG, WebP, AVIF, HEIC ou HEIF.`);
        return;
      }
      const invalidSize = candidates.find(
        (file) => file.size <= 0 || file.size > MAX_OPERATIONAL_PHOTO_SOURCE_BYTES
      );
      if (invalidSize) {
        setError(`${invalidSize.name}: a foto original deve ter até 20 MB.`);
        return;
      }

      const compressed: File[] = [];
      for (const file of candidates) {
        // eslint-disable-next-line no-await-in-loop -- limita pico de memória em celulares
        compressed.push(await compressImageFile(file));
      }
      onChange([...value, ...compressed]);
    } catch {
      setError('Não foi possível processar a(s) foto(s). Tente novamente.');
    } finally {
      setProcessing(false);
    }
  };

  const handleFiles = async (fileList: FileList | null, input: HTMLInputElement | null): Promise<void> => {
    if (!fileList || fileList.length === 0) {
      return;
    }

    await processFiles(Array.from(fileList));
    if (input) {
      input.value = '';
    }
  };

  /**
   * Cola uma foto copiada em outro app (ex.: WhatsApp) sem precisar salvá-la
   * na galeria antes. Ignorado quando um campo de texto está focado, para
   * não interceptar um "colar" de texto comum nas etapas do formulário.
   */
  useEffect(() => {
    if (disabled || remainingSlots <= 0) {
      return;
    }

    const handlePaste = (event: ClipboardEvent): void => {
      const active = document.activeElement;
      if (active instanceof HTMLInputElement || active instanceof HTMLTextAreaElement) {
        return;
      }

      const items = event.clipboardData?.items;
      if (!items) {
        return;
      }

      const files: File[] = [];
      for (const item of items) {
        if (item.kind === 'file' && item.type.startsWith('image/')) {
          const file = item.getAsFile();
          if (file) {
            files.push(file);
          }
        }
      }

      if (files.length > 0) {
        event.preventDefault();
        void processFiles(files);
      }
    };

    document.addEventListener('paste', handlePaste);
    return () => document.removeEventListener('paste', handlePaste);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- processFiles fecha sobre `value`/`remainingSlots` atuais a cada render; não precisa entrar nas deps do listener
  }, [disabled, remainingSlots]);

  /**
   * Atalho explícito para colar via Clipboard API — no toque, o evento
   * `paste` nativo não dispara fora de um campo de texto, então em mobile
   * este botão é o único jeito de colar uma imagem copiada em outro app.
   */
  const handleClipboardRead = async (): Promise<void> => {
    if (!navigator.clipboard?.read) {
      return;
    }

    setError(null);
    try {
      const clipboardItems = await navigator.clipboard.read();
      const files: File[] = [];

      for (const clipboardItem of clipboardItems) {
        const imageType = clipboardItem.types.find((type) => type.startsWith('image/'));
        if (!imageType) {
          continue;
        }
        const blob = await clipboardItem.getType(imageType);
        const extension = imageType.split('/')[1] ?? 'png';
        files.push(
          new File([blob], `colada-${Date.now()}-${pasteFileCounter++}.${extension}`, { type: imageType })
        );
      }

      if (files.length === 0) {
        setError('Nenhuma imagem encontrada na área de transferência.');
        return;
      }

      await processFiles(files);
    } catch {
      setError('Não foi possível colar a imagem da área de transferência.');
    }
  };

  const handleDrop = (event: DragEvent<HTMLElement>): void => {
    if (!hasDraggedFiles(event)) {
      return;
    }
    event.preventDefault();
    setDragging(false);
    if (disabled || remainingSlots <= 0) {
      return;
    }
    void processFiles(Array.from(event.dataTransfer.files ?? []));
  };

  const handleRemove = (index: number): void => {
    onChange(value.filter((_, fileIndex) => fileIndex !== index));
  };

  const handleCropped = async (index: number, cropped: File): Promise<void> => {
    setCropIndex(null);
    try {
      const compressed = await compressImageFile(cropped);
      onChange(value.map((file, fileIndex) => (fileIndex === index ? compressed : file)));
    } catch {
      setError('Não foi possível usar o recorte. A foto original foi mantida.');
    }
  };

  const cropFile = cropIndex !== null ? value[cropIndex] : undefined;

  return (
    <div className="field">
      <FieldLabel required={required}>{label}</FieldLabel>

      <div
        className={`photo-grid${dragging ? ' photo-grid--dragging' : ''}`}
        data-testid="photo-picker-grid"
        onDragEnter={(event) => {
          if (hasDraggedFiles(event)) {
            event.preventDefault();
            setDragging(true);
          }
        }}
        onDragOver={(event) => {
          if (hasDraggedFiles(event)) {
            event.preventDefault();
          }
        }}
        onDragLeave={(event) => {
          if (event.currentTarget === event.target) {
            setDragging(false);
          }
        }}
        onDrop={handleDrop}
      >
        {value.map((file, index) => (
          <div className="photo-grid__item" key={`${file.name}-${index}`}>
            {previewUrls[index] ? (
              <LocalPhotoPreview
                file={file}
                url={previewUrls[index]}
                index={index}
                onFailed={() => setFailedPreviews((current) => new Set(current).add(index))}
              />
            ) : null}
            {!failedPreviews.has(index) ? (
              <button
                type="button"
                className="photo-grid__crop"
                onClick={() => setCropIndex(index)}
                disabled={disabled || processing}
                aria-label={`Recortar foto ${index + 1}`}
              >
                ✂
              </button>
            ) : null}
            <button
              type="button"
              className="photo-grid__remove"
              onClick={() => handleRemove(index)}
              disabled={disabled}
              aria-label={`Remover foto ${index + 1}`}
            >
              ✕
            </button>
          </div>
        ))}

        {remainingSlots > 0 ? (
          <button
            type="button"
            className="photo-grid__add"
            onClick={() => cameraInputRef.current?.click()}
            disabled={disabled || processing}
          >
            {processing ? 'Processando...' : 'Câmera'}
          </button>
        ) : null}

        {remainingSlots > 0 ? (
          <button
            type="button"
            className="photo-grid__add"
            onClick={() => inputRef.current?.click()}
            disabled={disabled || processing}
          >
            {processing ? 'Processando...' : 'Galeria'}
          </button>
        ) : null}

        {remainingSlots > 0 && canReadClipboard ? (
          <button
            type="button"
            className="photo-grid__add"
            onClick={() => void handleClipboardRead()}
            disabled={disabled || processing}
          >
            {processing ? 'Processando...' : 'Colar imagem'}
          </button>
        ) : null}
      </div>

      {remainingSlots > 0 ? (
        <span className="muted photo-picker__hint">Também dá para arrastar fotos para cá ou colar com Ctrl+V. Recortar é opcional.</span>
      ) : null}

      {error ? (
        <div className="notice notice--danger">
          <span>{error}</span>
        </div>
      ) : null}

      {helpText ? <span className="muted">{helpText}</span> : null}

      <input
        ref={inputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp,image/avif,image/heic,image/heif,.heic,.heif,.avif"
        multiple
        hidden
        onChange={(event) => handleFiles(event.target.files, inputRef.current)}
        disabled={disabled}
      />
      <input
        ref={cameraInputRef}
        type="file"
        accept="image/*"
        capture="environment"
        hidden
        onChange={(event) => handleFiles(event.target.files, cameraInputRef.current)}
        disabled={disabled}
        data-testid="photo-picker-camera-input"
      />

      {cropFile && cropIndex !== null ? (
        <Suspense fallback={null}>
          <PhotoCropDialog
            file={cropFile}
            onCancel={() => setCropIndex(null)}
            onDone={(cropped) => void handleCropped(cropIndex, cropped)}
          />
        </Suspense>
      ) : null}
    </div>
  );
}
