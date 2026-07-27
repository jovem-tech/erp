'use client';

export type ReviewSectionKey =
  | 'cliente'
  | 'equipamento'
  | 'checklist'
  | 'detalhes'
  | 'atendimento'
  | 'fotos'
  | 'extras';

export type ReviewSection = {
  key: ReviewSectionKey;
  title: string;
  stepIndex: number;
  rows: Array<{ label: string; value: string }>;
  verified: boolean;
};

type StepReviewProps = {
  sections: ReviewSection[];
  onEditSection: (stepIndex: number, key: ReviewSectionKey) => void;
  onVerifySection: (key: ReviewSectionKey) => void;
  onSubmit: () => void;
  busy: boolean;
  submitLabel: string;
  errorMessage: string | null;
  warnings?: string[];
  disabled?: boolean;
  submitDisabled?: boolean;
  showSubmit?: boolean;
};

export function StepReview({
  sections,
  onEditSection,
  onVerifySection,
  onSubmit,
  busy,
  submitLabel,
  errorMessage,
  warnings = [],
  disabled = false,
  submitDisabled = false,
  showSubmit = true,
}: StepReviewProps) {
  return (
    <section className="section">
      <div className="section__header">
        <h3 className="section__title">Revisão</h3>
      </div>

      <div className="list">
        {sections.map((section) => (
          <div
            className={`card review-card${section.verified ? ' review-card--verified' : ''}`}
            key={section.key}
          >
            <div className="section__header" style={{ marginBottom: 8 }}>
              <strong>{section.title}</strong>
              <div className="review-card__actions">
                <button
                  type="button"
                  className="button button--ghost button-small"
                  onClick={() => onEditSection(section.stepIndex, section.key)}
                  disabled={busy || disabled}
                >
                  Editar
                </button>
                <button
                  type="button"
                  className={section.verified ? 'button button--success button-small' : 'button button--soft button-small'}
                  onClick={() => onVerifySection(section.key)}
                  disabled={busy || disabled || section.verified}
                  aria-pressed={section.verified}
                >
                  {section.verified ? 'Verificado' : 'Verificar'}
                </button>
              </div>
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

      {!sections.every((section) => section.verified) ? (
        <div className="notice notice--warning" style={{ marginTop: 16 }}>
          <span>Verifique todos os itens da revisão para liberar o salvamento.</span>
        </div>
      ) : null}

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
          <button
            type="button"
            className="button button--primary button-full"
            onClick={onSubmit}
            disabled={busy || disabled || submitDisabled}
          >
            {busy ? <span className="spinner" aria-hidden="true" /> : null}
            {busy ? 'Enviando...' : submitLabel}
          </button>
        </div>
      ) : null}
    </section>
  );
}
