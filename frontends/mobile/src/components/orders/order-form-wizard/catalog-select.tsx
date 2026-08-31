'use client';

import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { normalizeCatalogLabel } from '@/lib/equipment-catalog';

export type CatalogCreateAction = {
  label: string;
  onTrigger: (query: string) => void;
  disabled?: boolean;
  /** Motivo exibido via `title` quando `disabled` — ex.: falta de permissão. */
  disabledReason?: string;
};

type CatalogSelectProps<T> = {
  label: ReactNode;
  placeholder?: string;
  options: T[];
  value: T | null;
  onSelect: (option: T | null) => void;
  getOptionKey: (option: T) => number | string;
  getOptionLabel: (option: T) => string;
  getOptionSubtitle?: (option: T) => string | null;
  required?: boolean;
  disabled?: boolean;
  emptyMessage?: string;
  helpText?: string;
  /** Teto de opções renderizadas por vez — evita montar milhares de <li> a cada tecla. */
  maxVisibleOptions?: number;
  /** Semeia o campo de busca (ex.: rótulo bruto de um orçamento que não casou com o catálogo). */
  initialQuery?: string;
  createAction?: CatalogCreateAction | null;
};

const DEFAULT_MAX_VISIBLE = 50;

export function CatalogSelect<T>({
  label,
  placeholder = 'Buscar...',
  options,
  value,
  onSelect,
  getOptionKey,
  getOptionLabel,
  getOptionSubtitle,
  required = false,
  disabled = false,
  emptyMessage = 'Nenhum resultado encontrado.',
  helpText,
  maxVisibleOptions = DEFAULT_MAX_VISIBLE,
  initialQuery = '',
  createAction = null,
}: CatalogSelectProps<T>) {
  const baseId = useId();
  const labelId = `${baseId}-label`;
  const listboxId = `${baseId}-listbox`;

  const displayText = (current: T | null): string => (current ? getOptionLabel(current) : initialQuery);

  const [query, setQuery] = useState<string>(() => displayText(value));
  const [open, setOpen] = useState(false);
  const [highlightIndex, setHighlightIndex] = useState(0);
  const wrapperRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Mantém o texto exibido em sincronia quando o valor muda por fora
  // (seleção limpa por um reset em cascata, por exemplo).
  useEffect(() => {
    if (!open) {
      setQuery(displayText(value));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- displayText/initialQuery não devem reabrir o efeito a cada render
  }, [value, open]);

  const normalizedQuery = normalizeCatalogLabel(query);

  const matches = useMemo(() => {
    const filtered = options.filter((option) =>
      normalizeCatalogLabel(getOptionLabel(option)).includes(normalizedQuery),
    );

    filtered.sort((a, b) => {
      const aLabel = normalizeCatalogLabel(getOptionLabel(a));
      const bLabel = normalizeCatalogLabel(getOptionLabel(b));
      const aStarts = aLabel.startsWith(normalizedQuery) ? 0 : 1;
      const bStarts = bLabel.startsWith(normalizedQuery) ? 0 : 1;
      if (aStarts !== bStarts) {
        return aStarts - bStarts;
      }
      return getOptionLabel(a).localeCompare(getOptionLabel(b), 'pt-BR');
    });

    return filtered;
    // eslint-disable-next-line react-hooks/exhaustive-deps -- getOptionLabel é recriada a cada render pelo chamador
  }, [options, normalizedQuery]);

  const visibleMatches = matches.slice(0, maxVisibleOptions);
  const hiddenCount = matches.length - visibleMatches.length;

  useEffect(() => {
    setHighlightIndex(0);
  }, [normalizedQuery, options]);

  const closeAndRestore = (): void => {
    setOpen(false);
    setQuery(displayText(value));
  };

  const handleSelect = (option: T): void => {
    onSelect(option);
    setQuery(getOptionLabel(option));
    setOpen(false);
  };

  const handleClear = (): void => {
    onSelect(null);
    setQuery('');
    setOpen(true);
    inputRef.current?.focus();
  };

  const handleFocus = (): void => {
    if (disabled) {
      return;
    }
    setOpen(true);
    if (value) {
      // Texto já preenchido: seleciona tudo para que digitar substitua a
      // busca inteira em vez de exigir apagar manualmente.
      inputRef.current?.select();
    }
  };

  const handleBlur = (event: React.FocusEvent<HTMLDivElement>): void => {
    const next = event.relatedTarget as Node | null;
    if (next && wrapperRef.current?.contains(next)) {
      return;
    }
    closeAndRestore();
  };

  const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>): void => {
    if (disabled) {
      return;
    }

    if (event.key === 'Escape') {
      event.preventDefault();
      closeAndRestore();
      return;
    }

    if (!open) {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        setOpen(true);
      }
      return;
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (visibleMatches.length > 0) {
        setHighlightIndex((index) => (index + 1) % visibleMatches.length);
      }
      return;
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault();
      if (visibleMatches.length > 0) {
        setHighlightIndex((index) => (index - 1 + visibleMatches.length) % visibleMatches.length);
      }
      return;
    }

    if (event.key === 'Home') {
      event.preventDefault();
      setHighlightIndex(0);
      return;
    }

    if (event.key === 'End') {
      event.preventDefault();
      setHighlightIndex(Math.max(visibleMatches.length - 1, 0));
      return;
    }

    if (event.key === 'Enter') {
      event.preventDefault();
      const target = visibleMatches[highlightIndex] ?? (visibleMatches.length === 1 ? visibleMatches[0] : null);
      if (target) {
        handleSelect(target);
      }
    }
  };

  const activeOptionId =
    open && visibleMatches[highlightIndex] ? `${listboxId}-opt-${getOptionKey(visibleMatches[highlightIndex])}` : undefined;

  const createButtonLabel = query.trim()
    ? `${createAction?.label ?? '+ Novo'} "${query.trim()}"`
    : createAction?.label ?? '+ Novo';

  return (
    <div className="field">
      <span id={labelId}>{label}</span>

      <div className="catalog-select" ref={wrapperRef} onBlur={handleBlur}>
        <div className="catalog-select__control">
          <input
            ref={inputRef}
            role="combobox"
            className="input"
            type="text"
            value={query}
            aria-labelledby={labelId}
            aria-expanded={open}
            aria-controls={listboxId}
            aria-autocomplete="list"
            aria-activedescendant={activeOptionId}
            aria-required={required}
            placeholder={placeholder}
            onFocus={handleFocus}
            onChange={(event) => {
              setQuery(event.target.value);
              setOpen(true);
            }}
            onKeyDown={handleKeyDown}
            disabled={disabled}
            autoComplete="off"
            autoCorrect="off"
            spellCheck={false}
            inputMode="search"
          />
          {value ? (
            <button
              type="button"
              className="catalog-select__clear"
              onClick={handleClear}
              disabled={disabled}
              aria-label="Limpar seleção"
            >
              ×
            </button>
          ) : null}
        </div>

        {open ? (
          <div className="catalog-select__panel" onPointerDown={(event) => event.preventDefault()}>
            {visibleMatches.length > 0 ? (
              <ul className="catalog-select__list" role="listbox" id={listboxId} aria-labelledby={labelId}>
                {visibleMatches.map((option, index) => {
                  const key = getOptionKey(option);
                  const optionId = `${listboxId}-opt-${key}`;
                  const isActive = index === highlightIndex;
                  const isSelected = value ? getOptionKey(value) === key : false;
                  return (
                    <li key={key}>
                      <button
                        type="button"
                        id={optionId}
                        role="option"
                        aria-selected={isSelected}
                        className={
                          isActive
                            ? 'catalog-select__option catalog-select__option--active'
                            : 'catalog-select__option'
                        }
                        onMouseEnter={() => setHighlightIndex(index)}
                        onClick={() => handleSelect(option)}
                      >
                        <span className="catalog-select__option-copy">
                          <strong>{getOptionLabel(option)}</strong>
                          {getOptionSubtitle?.(option) ? (
                            <span className="muted">{getOptionSubtitle(option)}</span>
                          ) : null}
                        </span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            ) : (
              <div className="muted-box">{emptyMessage}</div>
            )}

            {hiddenCount > 0 ? (
              <p className="catalog-select__hint">
                Mostrando {visibleMatches.length} de {matches.length} — refine a busca.
              </p>
            ) : null}

            {createAction ? (
              <button
                type="button"
                className="catalog-select__footer"
                onClick={() => createAction.onTrigger(query.trim())}
                disabled={createAction.disabled}
                title={createAction.disabled ? createAction.disabledReason : undefined}
              >
                {createButtonLabel}
              </button>
            ) : null}
          </div>
        ) : null}
      </div>

      {helpText ? <span className="muted">{helpText}</span> : null}
    </div>
  );
}
