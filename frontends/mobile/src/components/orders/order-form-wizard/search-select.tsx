'use client';

import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

type SearchSelectProps<T> = {
  label: string;
  placeholder?: string;
  value: T | null;
  onSelect: (option: T | null) => void;
  fetchOptions: (query: string) => Promise<T[]>;
  getOptionKey: (option: T) => string | number;
  getOptionLabel: (option: T) => string;
  getOptionSubtitle?: (option: T) => string | null;
  renderOptionLeading?: (option: T) => ReactNode;
  minChars?: number;
  loadOnFocus?: boolean;
  onInitialOptionsLoaded?: (options: T[]) => void;
  disabled?: boolean;
  emptyMessage?: string;
  changeLabel?: string;
};

export function SearchSelect<T>({
  label,
  placeholder,
  value,
  onSelect,
  fetchOptions,
  getOptionKey,
  getOptionLabel,
  getOptionSubtitle,
  renderOptionLeading,
  minChars = 1,
  loadOnFocus = false,
  onInitialOptionsLoaded,
  disabled = false,
  emptyMessage = 'Nenhum resultado encontrado.',
  changeLabel = 'Trocar',
}: SearchSelectProps<T>) {
  const labelId = useId();
  const [query, setQuery] = useState('');
  const [options, setOptions] = useState<T[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [wasFocused, setWasFocused] = useState(false);
  const requestIdRef = useRef(0);

  useEffect(() => {
    const trimmed = query.trim();
    const requestId = ++requestIdRef.current;
    const shouldLoadInitialOptions = loadOnFocus && wasFocused && trimmed.length === 0;

    if (!shouldLoadInitialOptions && trimmed.length < minChars) {
      setOptions([]);
      setLoading(false);
      setError(null);
      return;
    }

    setLoading(true);
    setError(null);

    const timeout = setTimeout(() => {
      fetchOptions(trimmed)
        .then((results) => {
          if (requestIdRef.current === requestId) {
            setOptions(results);
            if (shouldLoadInitialOptions) {
              onInitialOptionsLoaded?.(results);
            }
          }
        })
        .catch(() => {
          if (requestIdRef.current === requestId) {
            setError('Não foi possível buscar. Tente novamente.');
            setOptions([]);
          }
        })
        .finally(() => {
          if (requestIdRef.current === requestId) {
            setLoading(false);
          }
        });
    }, shouldLoadInitialOptions ? 0 : 250);

    return () => clearTimeout(timeout);
  }, [query, minChars, fetchOptions, loadOnFocus, onInitialOptionsLoaded, wasFocused]);

  const handleSelect = (option: T): void => {
    onSelect(option);
    setQuery('');
    setOptions([]);
    setWasFocused(false);
  };

  const handleClear = (): void => {
    onSelect(null);
    setQuery('');
    setOptions([]);
    setWasFocused(false);
  };

  return (
    <div className="field">
      <span className="field__label" id={labelId}>{label}</span>
      <div className="search-select">
        {value ? (
          <div className="search-select__selected">
            <div className="search-select__option-content">
              {renderOptionLeading?.(value)}
              <div className="search-select__option-copy">
                <strong>{getOptionLabel(value)}</strong>
                {getOptionSubtitle?.(value) ? <div className="muted">{getOptionSubtitle(value)}</div> : null}
              </div>
            </div>
            <button type="button" className="button button--soft button-small" onClick={handleClear} disabled={disabled}>
              {changeLabel}
            </button>
          </div>
        ) : (
          <>
            <input
              className="input"
              type="text"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              onFocus={() => setWasFocused(true)}
              placeholder={placeholder}
              aria-labelledby={labelId}
              disabled={disabled}
            />

            {loading ? <span className="muted">Buscando...</span> : null}

            {error ? (
              <div className="notice notice--danger">
                <span>{error}</span>
              </div>
            ) : null}

            {!loading && !error && query.trim().length >= minChars && options.length === 0 ? (
              <div className="muted-box">{emptyMessage}</div>
            ) : null}

            {options.length > 0 ? (
              <ul className="list list--tight search-select__results">
                {options.map((option) => (
                  <li key={getOptionKey(option)}>
                    <button
                      type="button"
                      className="card card--interactive search-select__option"
                      onClick={() => handleSelect(option)}
                    >
                      <span className="search-select__option-content">
                        {renderOptionLeading?.(option)}
                        <span className="search-select__option-copy">
                          <strong>{getOptionLabel(option)}</strong>
                          {getOptionSubtitle?.(option) ? <span className="muted">{getOptionSubtitle(option)}</span> : null}
                        </span>
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}
          </>
        )}
      </div>
    </div>
  );
}
