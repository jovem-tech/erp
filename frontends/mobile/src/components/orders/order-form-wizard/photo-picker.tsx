'use client';

import { useEffect, useRef, useState } from 'react';
import { compressImageFile } from '@/lib/photo-compression';

type PhotoPickerProps = {
  label: string;
  value: File[];
  onChange: (files: File[]) => void;
  maxFiles: number;
  disabled?: boolean;
  helpText?: string;
};

export function PhotoPicker({ label, value, onChange, maxFiles, disabled = false, helpText }: PhotoPickerProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [processing, setProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [previewUrls, setPreviewUrls] = useState<string[]>([]);

  useEffect(() => {
    const urls = value.map((file) => URL.createObjectURL(file));
    setPreviewUrls(urls);

    return () => {
      urls.forEach((url) => URL.revokeObjectURL(url));
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- recalcula só quando a lista de arquivos muda
  }, [value]);

  const remainingSlots = maxFiles - value.length;

  const handleFiles = async (fileList: FileList | null): Promise<void> => {
    if (!fileList || fileList.length === 0) {
      return;
    }

    const files = Array.from(fileList).slice(0, remainingSlots);
    setProcessing(true);
    setError(null);

    try {
      const compressed = await Promise.all(files.map((file) => compressImageFile(file)));
      onChange([...value, ...compressed]);
    } catch {
      setError('Não foi possível processar a(s) foto(s). Tente novamente.');
    } finally {
      setProcessing(false);
      if (inputRef.current) {
        inputRef.current.value = '';
      }
    }
  };

  const handleRemove = (index: number): void => {
    onChange(value.filter((_, fileIndex) => fileIndex !== index));
  };

  return (
    <div className="field">
      <span className="field__label">{label}</span>

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
        capture="environment"
        multiple
        hidden
        onChange={(event) => handleFiles(event.target.files)}
        disabled={disabled}
      />
    </div>
  );
}
