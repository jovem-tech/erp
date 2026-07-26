'use client';

import { useEffect, useRef, useState } from 'react';

type SearchSelectProps<T> = {
  label: string;
  placeholder?: string;
  value: T | null;
  onSelect: (option: T | null) => void;
  fetchOptions: (query: string) => Promise<T[]>;
  getOptionKey: (option: T) => string | number;
  getOptionLabel: (option: T) => string;
  getOptionSubtitle?: (option: T) => string | null;
  minChars?: number;
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
  minChars = 1,
  disabled = false,
  emptyMessage = 'Nenhum resultado encontrado.',
  changeLabel = 'Trocar',
}: SearchSelectProps<T>) {
  const [query, setQuery] = useState('');
  const [options, setOptions] = useState<T[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const requestIdRef = useRef(0);

  useEffect(() => {
    const trimmed = query.trim();

    if (trimmed.length < minChars) {
      setOptions([]);
      setLoading(false);
      return;
    }

    const requestId = ++requestIdRef.current;
    setLoading(true);
    setError(null);

    const timeout = setTimeout(() => {
      fetchOptions(trimmed)
        .then((results) => {
          if (requestIdRef.current === requestId) {
            setOptions(results);
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
    }, 250);

    return () => clearTimeout(timeout);
  }, [query, minChars, fetchOptions]);

  const handleSelect = (option: T): void => {
    onSelect(option);
    setQuery('');
    setOptions([]);
  };

  const handleClear = (): void => {
    onSelect(null);
    setQuery('');
    setOptions([]);
  };

  return (
    <label className="field">
      <span className="field__label">{label}</span>
      <div className="search-select">
        {value ? (
          <div className="search-select__selected">
            <div>
              <strong>{getOptionLabel(value)}</strong>
              {getOptionSubtitle?.(value) ? <div className="muted">{getOptionSubtitle(value)}</div> : null}
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
              placeholder={placeholder}
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
                      <strong>{getOptionLabel(option)}</strong>
                      {getOptionSubtitle?.(option) ? <div className="muted">{getOptionSubtitle(option)}</div> : null}
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}
          </>
        )}
      </div>
    </label>
  );
}
