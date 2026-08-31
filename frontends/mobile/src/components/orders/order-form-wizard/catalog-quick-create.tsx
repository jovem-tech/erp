'use client';

import { useEffect, useRef, useState } from 'react';

type CatalogQuickCreateProps = {
  kind: 'marca' | 'modelo';
  initialName: string;
  /** Contexto exibido no título — ex.: "Notebook" ou "Notebook • Samsung". */
  contextLabel: string;
  busy: boolean;
  error: string | null;
  /** Aviso não-bloqueante — ex.: nome já existe no catálogo global e será só vinculado. */
  notice?: string | null;
  onCancel: () => void;
  onSubmit: (nome: string) => void;
};

const MAX_NAME_LENGTH = 120;

export function CatalogQuickCreate({
  kind,
  initialName,
  contextLabel,
  busy,
  error,
  notice = null,
  onCancel,
  onSubmit,
}: CatalogQuickCreateProps) {
  const [name, setName] = useState(initialName);
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    inputRef.current?.focus();
    inputRef.current?.select();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- só foca ao abrir o painel, não a cada digitação
  }, []);

  const title = kind === 'marca' ? 'Nova marca' : 'Novo modelo';
  const trimmed = name.trim();
  const localError = trimmed.length === 0 ? null : trimmed.length > MAX_NAME_LENGTH ? `Máximo de ${MAX_NAME_LENGTH} caracteres.` : null;

  const handleSubmit = (): void => {
    if (busy || trimmed.length === 0 || trimmed.length > MAX_NAME_LENGTH) {
      return;
    }
    onSubmit(trimmed);
  };

  return (
    <div className="catalog-quick-create">
      <div className="catalog-quick-create__header">
        <strong>{title}</strong>
        <span className="muted">{contextLabel}</span>
      </div>

      <label className="field">
        <span className="field__label">Nome</span>
        <input
          ref={inputRef}
          className="input"
          value={name}
          maxLength={MAX_NAME_LENGTH}
          onChange={(event) => setName(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') {
              event.preventDefault();
              handleSubmit();
            }
            if (event.key === 'Escape') {
              event.preventDefault();
              onCancel();
            }
          }}
          disabled={busy}
        />
      </label>

      {(error || localError) ? (
        <span className="field__error" role="alert">
          {error ?? localError}
        </span>
      ) : null}

      {notice ? (
        <div className="notice notice--warning">
          <span>{notice}</span>
        </div>
      ) : null}

      <div className="toolbar toolbar--compact-spaced">
        <div className="toolbar__group">
          <button type="button" className="button button--soft button-small" onClick={onCancel} disabled={busy}>
            Cancelar
          </button>
          <button
            type="button"
            className="button button--primary button-small"
            onClick={handleSubmit}
            disabled={busy || trimmed.length === 0 || trimmed.length > MAX_NAME_LENGTH}
          >
            {busy ? 'Salvando...' : 'Salvar'}
          </button>
        </div>
      </div>
    </div>
  );
}
