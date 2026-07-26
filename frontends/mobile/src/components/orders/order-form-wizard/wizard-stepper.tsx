'use client';

export type WizardStepInfo = {
  key: string;
  label: string;
};

type WizardStepperProps = {
  steps: WizardStepInfo[];
  currentIndex: number;
  maxVisitedIndex: number;
  onNavigate: (index: number) => void;
};

export function WizardStepper({ steps, currentIndex, maxVisitedIndex, onNavigate }: WizardStepperProps) {
  return (
    <div className="wizard-steps" role="tablist" aria-label="Etapas do formulário">
      {steps.map((step, index) => {
        const clickable = index <= maxVisitedIndex;
        const state = index === currentIndex ? 'active' : clickable ? 'complete' : 'pending';

        return (
          <button
            key={step.key}
            type="button"
            role="tab"
            className={`wizard-step wizard-step--${state}`}
            onClick={() => clickable && onNavigate(index)}
            disabled={!clickable}
            aria-current={index === currentIndex ? 'step' : undefined}
            aria-selected={index === currentIndex}
          >
            <span className="wizard-step__index">{index + 1}</span>
            <span className="wizard-step__label">{step.label}</span>
          </button>
        );
      })}
    </div>
  );
}
