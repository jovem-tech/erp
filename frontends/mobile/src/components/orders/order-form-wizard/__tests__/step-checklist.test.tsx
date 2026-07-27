import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StepChecklist } from '@/components/orders/order-form-wizard/steps/step-checklist';
import type { EntryChecklistModel } from '@/lib/types';

const model: EntryChecklistModel = {
  id: 1,
  checklist_tipo_id: 1,
  tipo_equipamento_id: 3,
  nome: 'Checklist padrão de smartphones',
  descricao: '',
  itens: [
    { id: 100, descricao: 'Tela', ordem: 1 },
    { id: 101, descricao: 'Bateria', ordem: 2 },
  ],
};

describe('StepChecklist', () => {
  it('mostra o campo de observação só quando o item está marcado como discrepância', async () => {
    const user = userEvent.setup();
    const onChangeAnswer = vi.fn();

    render(
      <StepChecklist
        model={model}
        answers={{}}
        observacoesEstado=""
        onChangeAnswer={onChangeAnswer}
        onChangeObservacoesEstado={vi.fn()}
        onMarkAllOk={vi.fn()}
      />
    );

    expect(screen.queryByPlaceholderText('Descreva a discrepância *')).not.toBeInTheDocument();

    const [telaSelect] = screen.getAllByRole('combobox');
    await user.selectOptions(telaSelect, 'discrepancia');

    expect(onChangeAnswer).toHaveBeenCalledWith(100, { status: 'discrepancia', observacao: '' });
  });

  it('exibe o campo de observação quando a resposta já é discrepância', () => {
    render(
      <StepChecklist
        model={model}
        answers={{ 100: { status: 'discrepancia', observacao: 'Risco na tela' } }}
        observacoesEstado=""
        onChangeAnswer={vi.fn()}
        onChangeObservacoesEstado={vi.fn()}
        onMarkAllOk={vi.fn()}
      />
    );

    expect(screen.getByPlaceholderText('Descreva a discrepância *')).toHaveValue('Risco na tela');
  });

  it('"Marcar tudo OK" dispara o callback correspondente', async () => {
    const user = userEvent.setup();
    const onMarkAllOk = vi.fn();

    render(
      <StepChecklist
        model={model}
        answers={{}}
        observacoesEstado=""
        onChangeAnswer={vi.fn()}
        onChangeObservacoesEstado={vi.fn()}
        onMarkAllOk={onMarkAllOk}
      />
    );

    await user.click(screen.getByText('Marcar tudo OK'));

    expect(onMarkAllOk).toHaveBeenCalled();
  });

  it('"Desmarcar tudo" limpa o checklist pelo callback correspondente', async () => {
    const user = userEvent.setup();
    const onUnmarkAll = vi.fn();

    render(
      <StepChecklist
        model={model}
        answers={{
          100: { status: 'ok', observacao: '' },
          101: { status: 'ok', observacao: '' },
        }}
        observacoesEstado=""
        onChangeAnswer={vi.fn()}
        onChangeObservacoesEstado={vi.fn()}
        onMarkAllOk={vi.fn()}
        onUnmarkAll={onUnmarkAll}
      />
    );

    await user.click(screen.getByText('Desmarcar tudo'));

    expect(onUnmarkAll).toHaveBeenCalledOnce();
  });
});
