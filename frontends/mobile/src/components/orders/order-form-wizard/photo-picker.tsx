'use client';

import { useEffect, useRef, useState } from 'react';
import { compressImageFile } from '@/lib/photo-compression';
import { FieldLabel } from '@/components/ui/field-label';

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
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [previewUrls, setPreviewUrls] = useState<string[]>([]);
  // Só decidido no cliente (Clipboard API depende de `navigator`), então
  // começa false para o SSR/hidratação baterem antes de checar o suporte.
  const [canReadClipboard, setCanReadClipboard] = useState(false);

  useEffect(() => {
    setCanReadClipboard(typeof navigator !== 'undefined' && Boolean(navigator.clipboard?.read));
  }, []);

  useEffect(() => {
    const urls = value.map((file) => URL.createObjectURL(file));
    setPreviewUrls(urls);

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
      const compressed = await Promise.all(files.slice(0, remainingSlots).map((file) => compressImageFile(file)));
      onChange([...value, ...compressed]);
    } catch {
      setError('Não foi possível processar a(s) foto(s). Tente novamente.');
    } finally {
      setProcessing(false);
    }
  };

  const handleFiles = async (fileList: FileList | null): Promise<void> => {
    if (!fileList || fileList.length === 0) {
      return;
    }

    await processFiles(Array.from(fileList));
    if (inputRef.current) {
      inputRef.current.value = '';
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

  const handleRemove = (index: number): void => {
    onChange(value.filter((_, fileIndex) => fileIndex !== index));
  };

  return (
    <div className="field">
      <FieldLabel required={required}>{label}</FieldLabel>

      <div className="photo-grid">
        {value.map((file, index) => (
          <div className="photo-grid__item" key={`${file.name}-${index}`}>
            {previewUrls[index] ? (
              // eslint-disable-next-line @next/next/no-img-element -- preview de blob local, sem otimização do Next
              <img src={previewUrls[index]} alt={`Foto ${index + 1}`} />
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
            onClick={() => inputRef.current?.click()}
            disabled={disabled || processing}
          >
            {processing ? 'Processando...' : '+ Adicionar foto'}
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

      {error ? (
        <div className="notice notice--danger">
          <span>{error}</span>
        </div>
      ) : null}

      {helpText ? <span className="muted">{helpText}</span> : null}

      <input
        ref={inputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        multiple
        hidden
        onChange={(event) => handleFiles(event.target.files)}
        disabled={disabled}
      />
    </div>
  );
}
