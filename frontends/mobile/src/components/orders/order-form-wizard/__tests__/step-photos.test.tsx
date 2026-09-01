import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { StepPhotos } from '@/components/orders/order-form-wizard/steps/step-photos';

describe('StepPhotos', () => {
  it('explica que as fotos são complementares, cobrindo itens soltos, acessórios e anormalidades', () => {
    render(<StepPhotos mode="create" fotos={[]} onChangeFotos={vi.fn()} />);

    expect(screen.getByText('Fotos complementares', { selector: '.section__title' })).toBeInTheDocument();
    expect(
      screen.getByText(/itens soltos, acessórios entregues junto e qualquer anormalidade/)
    ).toBeInTheDocument();
    expect(screen.getByText(/riscos, trincas, amassados, peças faltando/)).toBeInTheDocument();
  });

  it('no modo criação deixa claro que a etapa é opcional', () => {
    render(<StepPhotos mode="create" fotos={[]} onChangeFotos={vi.fn()} />);

    expect(screen.getByText(/Opcional\. Até 4 fotos\./)).toBeInTheDocument();
  });

  it('no modo edição avisa que as fotos novas somam às existentes', () => {
    render(<StepPhotos mode="edit" fotos={[]} onChangeFotos={vi.fn()} />);

    expect(screen.getByText(/nenhuma foto anterior é removida aqui/)).toBeInTheDocument();
  });

  it('as fotos complementares não são marcadas como obrigatórias', () => {
    const { container } = render(<StepPhotos mode="create" fotos={[]} onChangeFotos={vi.fn()} />);

    expect(container.querySelector('.field__required')).toBeNull();
  });
});
