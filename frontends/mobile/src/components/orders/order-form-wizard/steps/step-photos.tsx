'use client';

import type { WizardMode } from '@/components/orders/order-form-wizard/wizard-state';
import { PhotoPicker } from '@/components/orders/order-form-wizard/photo-picker';

type StepPhotosProps = {
  mode: WizardMode;
  fotos: File[];
  onChangeFotos: (files: File[]) => void;
  disabled?: boolean;
};

export function StepPhotos({ mode, fotos, onChangeFotos, disabled = false }: StepPhotosProps) {
  return (
    <section className="section">
      <div className="section__header">
        <h3 className="section__title">Fotos da OS</h3>
      </div>

      <PhotoPicker
        label="Fotos"
        value={fotos}
        onChange={onChangeFotos}
        maxFiles={4}
        disabled={disabled}
        helpText={
          mode === 'edit'
            ? 'Fotos novas serão adicionadas às já existentes na OS — nenhuma foto anterior é removida aqui.'
            : 'Opcional. Até 4 fotos do estado do equipamento na entrada.'
        }
      />
    </section>
  );
}
