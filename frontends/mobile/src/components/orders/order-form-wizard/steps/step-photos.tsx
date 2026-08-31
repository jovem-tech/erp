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
        <h3 className="section__title">Fotos complementares</h3>
      </div>

      <div className="notice">
        <span>
          Use este passo para fotos complementares do equipamento: itens soltos, acessórios entregues junto e
          qualquer anormalidade (riscos, trincas, amassados, peças faltando).
        </span>
      </div>

      <PhotoPicker
        label="Fotos complementares"
        value={fotos}
        onChange={onChangeFotos}
        maxFiles={4}
        disabled={disabled}
        helpText={
          mode === 'edit'
            ? 'Fotos novas serão adicionadas às já existentes na OS — nenhuma foto anterior é removida aqui.'
            : 'Opcional. Até 4 fotos.'
        }
      />
    </section>
  );
}
