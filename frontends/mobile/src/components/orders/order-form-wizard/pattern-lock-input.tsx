'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

type PatternLockInputProps = {
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
};

/**
 * Coordenadas fixas dos 9 pontos da grade, em viewBox 300x300. Nunca
 * derivadas de layout medido — é o que faz o traço funcionar também em
 * jsdom (sem layout) e evita `style` inline (CSP `style-src 'self'` em
 * produção não permite estilos inline).
 */
const DOT_POSITIONS: Record<number, { x: number; y: number }> = {
  1: { x: 50, y: 50 },
  2: { x: 150, y: 50 },
  3: { x: 250, y: 50 },
  4: { x: 50, y: 150 },
  5: { x: 150, y: 150 },
  6: { x: 250, y: 150 },
  7: { x: 50, y: 250 },
  8: { x: 150, y: 250 },
  9: { x: 250, y: 250 },
};

const DOT_IDS = Object.keys(DOT_POSITIONS).map(Number);

/**
 * Converte a string armazenada ("1-2-3-6-9") na sequência de pontos.
 * Tolerante a valores legados fora do padrão: descarta tokens que não
 * sejam dígitos de 1 a 9 e remove repetições, mas NUNCA lança nem perde
 * o texto original — quem chama continua mostrando o valor bruto no
 * fallback textual mesmo quando a grade não consegue representá-lo.
 */
function parsePattern(value: string): number[] {
  const seen = new Set<number>();
  const sequence: number[] = [];

  for (const token of value.split('-')) {
    const digit = Number(token.trim());
    if (Number.isInteger(digit) && digit >= 1 && digit <= 9 && !seen.has(digit)) {
      seen.add(digit);
      sequence.push(digit);
    }
  }

  return sequence;
}

function formatPattern(sequence: number[]): string {
  return sequence.join('-');
}

function formatReadout(sequence: number[]): string {
  if (sequence.length === 0) {
    return 'Nenhum desenho definido.';
  }
  return `Desenho: ${sequence.join(' → ')}`;
}

function findDotFromElement(element: Element | null): number | null {
  const dotElement = element?.closest<HTMLElement>('[data-pattern-dot]') ?? null;
  const raw = dotElement?.dataset.patternDot;
  return raw ? Number(raw) : null;
}

export function PatternLockInput({ value, onChange, disabled = false }: PatternLockInputProps) {
  const [sequence, setSequence] = useState<number[]>(() => parsePattern(value));
  const draggingRef = useRef(false);
  const lastDraggedDotRef = useRef<number | null>(null);
  /**
   * Evita contar o mesmo toque duas vezes: um toque simples dispara
   * pointerdown e, em seguida, click no mesmo ponto. O pointerdown já
   * registra o ponto; quando o click correspondente chega, ele é
   * silenciado por este ref em vez de acrescentar o ponto de novo.
   */
  const suppressNextClickRef = useRef<number | null>(null);

  // Mantém a grade em sincronia quando o valor externo muda (ex.: carregar
  // um equipamento existente para edição).
  useEffect(() => {
    setSequence(parsePattern(value));
  }, [value]);

  const appendPoint = useCallback(
    (dot: number) => {
      setSequence((current) => {
        if (current.length > 0 && current[current.length - 1] === dot) {
          // Tocar de novo no último ponto desfaz — único caso em que um
          // ponto pode ser "removido" pelo toque.
          const next = current.slice(0, -1);
          onChange(formatPattern(next));
          return next;
        }
        if (current.includes(dot)) {
          // Ponto do meio já usado: sem repetição, e sem inferir pontos
          // intermediários — a ação é ignorada (ver nota no fim do arquivo).
          return current;
        }
        const next = [...current, dot];
        onChange(formatPattern(next));
        return next;
      });
    },
    [onChange],
  );

  const handleClear = useCallback(() => {
    setSequence([]);
    onChange('');
  }, [onChange]);

  const handleDotClick = useCallback(
    (dot: number) => {
      if (suppressNextClickRef.current === dot) {
        suppressNextClickRef.current = null;
        return;
      }
      appendPoint(dot);
    },
    [appendPoint],
  );

  const handlePointerDown = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (disabled) {
        return;
      }
      const dot = findDotFromElement(event.target instanceof Element ? event.target : null);
      if (!dot) {
        return;
      }
      draggingRef.current = true;
      lastDraggedDotRef.current = dot;
      suppressNextClickRef.current = dot;
      if (typeof event.currentTarget.setPointerCapture === 'function') {
        event.currentTarget.setPointerCapture(event.pointerId);
      }
      appendPoint(dot);
    },
    [appendPoint, disabled],
  );

  const handlePointerMove = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (!draggingRef.current || disabled) {
        return;
      }
      // jsdom não implementa layout real, então `elementFromPoint` não é
      // confiável em teste de unidade — o arraste vira no-op ali, e o
      // toque (testável via clique nos botões) continua cobrindo a
      // interação completa.
      if (typeof document.elementFromPoint !== 'function') {
        return;
      }
      const target = document.elementFromPoint(event.clientX, event.clientY);
      const dot = findDotFromElement(target);
      if (dot && dot !== lastDraggedDotRef.current) {
        lastDraggedDotRef.current = dot;
        appendPoint(dot);
      }
    },
    [appendPoint, disabled],
  );

  const stopDragging = useCallback(() => {
    draggingRef.current = false;
    lastDraggedDotRef.current = null;
    // Descarta a supressão pendente depois que o click correspondente já
    // teve chance de chegar (mesma tarefa síncrona do gesto de toque).
    setTimeout(() => {
      suppressNextClickRef.current = null;
    }, 0);
  }, []);

  const polylinePoints = useMemo(
    () => sequence.map((dot) => `${DOT_POSITIONS[dot].x},${DOT_POSITIONS[dot].y}`).join(' '),
    [sequence],
  );

  return (
    <div className="pattern-lock">
      <div
        className="pattern-lock__grid"
        role="group"
        aria-label="Padrão de desenho"
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={stopDragging}
        onPointerCancel={stopDragging}
        onPointerLeave={stopDragging}
      >
        <svg className="pattern-lock__trace" viewBox="0 0 300 300" aria-hidden="true" focusable="false">
          {sequence.length > 1 ? <polyline className="pattern-lock__line" points={polylinePoints} /> : null}
        </svg>

        {DOT_IDS.map((dot, index) => {
          const order = sequence.indexOf(dot);
          const isActive = order >= 0;
          return (
            <button
              key={dot}
              type="button"
              className={isActive ? 'pattern-lock__dot pattern-lock__dot--active' : 'pattern-lock__dot'}
              data-pattern-dot={dot}
              aria-pressed={isActive}
              aria-label={isActive ? `Ponto ${index + 1}, posição ${order + 1} do desenho` : `Ponto ${index + 1}`}
              onClick={() => handleDotClick(dot)}
              disabled={disabled}
            />
          );
        })}
      </div>

      <p className="pattern-lock__readout" aria-live="polite">
        {formatReadout(sequence)}
      </p>

      <button
        type="button"
        className="button button--soft button-small"
        onClick={handleClear}
        disabled={disabled || sequence.length === 0}
      >
        Limpar desenho
      </button>

      <details className="pattern-lock__fallback">
        <summary>Digitar a sequência</summary>
        <input
          className="input"
          inputMode="numeric"
          placeholder="Ex.: 1-2-3-6-9"
          value={value}
          onChange={(event) => {
            onChange(event.target.value);
            setSequence(parsePattern(event.target.value));
          }}
          disabled={disabled}
        />
      </details>
    </div>
  );
}

/*
 * Nota de design: a regra do Android de "atravessar um ponto intermediário
 * conecta-o automaticamente" foi deliberadamente NÃO implementada aqui.
 * Este campo registra o que o cliente informou verbalmente sobre o
 * desenho da senha — não é um desafio de autenticação — então inventar
 * pontos que o usuário não tocou/marcou corromperia o registro. O
 * desktop também não implementa essa regra, e os dois frontends precisam
 * concordar na mesma string gravada.
 */
