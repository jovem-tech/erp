'use client';

export type ReviewSection = {
  title: string;
  stepIndex: number;
  rows: Array<{ label: string; value: string }>;
};

type StepReviewProps = {
  sections: ReviewSection[];
  onEditSection: (stepIndex: number) => void;
  onSubmit: () => void;
  busy: boolean;
  submitLabel: string;
  errorMessage: string | null;
  warnings?: string[];
  disabled?: boolean;
  showSubmit?: boolean;
};

export function StepReview({
  sections,
  onEditSection,
  onSubmit,
  busy,
  submitLabel,
  errorMessage,
  warnings = [],
  disabled = false,
  showSubmit = true,
}: StepReviewProps) {
  return (
    <section className="section">
      <div className="section__header">
        <h3 className="section__title">Revisão</h3>
      </div>

      <div className="list">
        {sections.map((section) => (
          <div className="card" key={section.title}>
            <div className="section__header" style={{ marginBottom: 8 }}>
              <strong>{section.title}</strong>
              <button
                type="button"
                className="button button--ghost button-small"
                onClick={() => onEditSection(section.stepIndex)}
                disabled={busy || disabled}
              >
                Editar
              </button>
            </div>

            {section.rows.length > 0 ? (
              <div className="list list--tight">
                {section.rows.map((row) => (
                  <div key={row.label}>
                    <span className="muted">{row.label}</span>
                    <div>{row.value || '—'}</div>
                  </div>
                ))}
              </div>
            ) : (
              <span className="muted">Nada preenchido.</span>
            )}
          </div>
        ))}
      </div>

      {warnings.length > 0 ? (
        <div className="notice notice--warning" style={{ marginTop: 16 }}>
          <span>{warnings.join(' ')}</span>
        </div>
      ) : null}

      {errorMessage ? (
        <div className="notice notice--danger" style={{ marginTop: 16 }}>
          <span>{errorMessage}</span>
        </div>
      ) : null}

      {showSubmit ? (
        <div className="toolbar" style={{ marginTop: 16 }}>
          <button type="button" className="button button--primary button-full" onClick={onSubmit} disabled={busy || disabled}>
            {busy ? <span className="spinner" aria-hidden="true" /> : null}
            {busy ? 'Enviando...' : submitLabel}
          </button>
        </div>
      ) : null}
    </section>
  );
}
