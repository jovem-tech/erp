import type { ReactNode } from 'react';

/**
 * Asterisco de campo obrigatório, em vermelho (`--danger`) para ficar
 * nitidamente distinto do rótulo. Decorativo: a semântica de obrigatório
 * é comunicada por `aria-required` no controle, não por este texto.
 */
export function RequiredMark() {
  return (
    <span className="field__required" aria-hidden="true">
      *
    </span>
  );
}

type FieldLabelProps = {
  children: ReactNode;
  required?: boolean;
  id?: string;
};

/**
 * Rótulo padrão de campo (`.field__label`), com o asterisco de obrigatório
 * destacado em `<span>` próprio — necessário para o asterisco poder ter
 * cor diferente do texto do rótulo.
 */
export function FieldLabel({ children, required = false, id }: FieldLabelProps) {
  return (
    <span className="field__label" id={id}>
      {children}
      {required ? (
        <>
          {' '}
          <RequiredMark />
        </>
      ) : null}
    </span>
  );
}
