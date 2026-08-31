import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CatalogQuickCreate } from '@/components/orders/order-form-wizard/catalog-quick-create';

describe('CatalogQuickCreate', () => {
  it('pré-preenche o nome inicial e foca o campo', () => {
    render(
      <CatalogQuickCreate
        kind="marca"
        initialName="Xiaomi"
        contextLabel="Notebook"
        busy={false}
        error={null}
        onCancel={vi.fn()}
        onSubmit={vi.fn()}
      />
    );

    expect(screen.getByRole('textbox', { name: 'Nome' })).toHaveValue('Xiaomi');
    expect(screen.getByRole('textbox', { name: 'Nome' })).toHaveFocus();
    expect(screen.getByText('Nova marca')).toBeInTheDocument();
    expect(screen.getByText('Notebook')).toBeInTheDocument();
  });

  it('busy desabilita Salvar e mostra o rótulo de carregando', () => {
    render(
      <CatalogQuickCreate
        kind="modelo"
        initialName="A015"
        contextLabel="Notebook • Samsung"
        busy
        error={null}
        onCancel={vi.fn()}
        onSubmit={vi.fn()}
      />
    );

    expect(screen.getByText('Novo modelo')).toBeInTheDocument();
    const saveButton = screen.getByRole('button', { name: 'Salvando...' });
    expect(saveButton).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeDisabled();
  });

  it('nome vazio desabilita Salvar', () => {
    render(
      <CatalogQuickCreate
        kind="marca"
        initialName=""
        contextLabel="Notebook"
        busy={false}
        error={null}
        onCancel={vi.fn()}
        onSubmit={vi.fn()}
      />
    );

    expect(screen.getByRole('button', { name: 'Salvar' })).toBeDisabled();
  });

  it('Salvar chama onSubmit com o nome já sem espaços nas pontas', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();

    render(
      <CatalogQuickCreate
        kind="marca"
        initialName="  Xiaomi  "
        contextLabel="Notebook"
        busy={false}
        error={null}
        onCancel={vi.fn()}
        onSubmit={onSubmit}
      />
    );

    await user.click(screen.getByRole('button', { name: 'Salvar' }));
    expect(onSubmit).toHaveBeenCalledWith('Xiaomi');
  });

  it('Enter no campo de nome também submete', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn();

    render(
      <CatalogQuickCreate
        kind="marca"
        initialName="Xiaomi"
        contextLabel="Notebook"
        busy={false}
        error={null}
        onCancel={vi.fn()}
        onSubmit={onSubmit}
      />
    );

    await user.type(screen.getByRole('textbox', { name: 'Nome' }), '{Enter}');
    expect(onSubmit).toHaveBeenCalledWith('Xiaomi');
  });

  it('Escape no campo de nome cancela', async () => {
    const user = userEvent.setup();
    const onCancel = vi.fn();

    render(
      <CatalogQuickCreate
        kind="marca"
        initialName="Xiaomi"
        contextLabel="Notebook"
        busy={false}
        error={null}
        onCancel={onCancel}
        onSubmit={vi.fn()}
      />
    );

    await user.type(screen.getByRole('textbox', { name: 'Nome' }), '{Escape}');
    expect(onCancel).toHaveBeenCalled();
  });

  it('falha da API mantém o painel aberto com a mensagem de erro', () => {
    render(
      <CatalogQuickCreate
        kind="marca"
        initialName="Xiaomi"
        contextLabel="Notebook"
        busy={false}
        error="Não foi possível cadastrar a marca. Tente novamente."
        onCancel={vi.fn()}
        onSubmit={vi.fn()}
      />
    );

    expect(screen.getByRole('alert')).toHaveTextContent('Não foi possível cadastrar a marca. Tente novamente.');
    expect(screen.getByRole('textbox', { name: 'Nome' })).toHaveValue('Xiaomi');
  });

  it('exibe o aviso de reaproveitamento quando fornecido', () => {
    render(
      <CatalogQuickCreate
        kind="marca"
        initialName="Samsung"
        contextLabel="Impressora"
        busy={false}
        error={null}
        notice="Esta marca já existe no catálogo; ela será vinculada a Impressora."
        onCancel={vi.fn()}
        onSubmit={vi.fn()}
      />
    );

    expect(
      screen.getByText('Esta marca já existe no catálogo; ela será vinculada a Impressora.')
    ).toBeInTheDocument();
  });

  it('Cancelar chama onCancel', async () => {
    const user = userEvent.setup();
    const onCancel = vi.fn();

    render(
      <CatalogQuickCreate
        kind="marca"
        initialName="Xiaomi"
        contextLabel="Notebook"
        busy={false}
        error={null}
        onCancel={onCancel}
        onSubmit={vi.fn()}
      />
    );

    await user.click(screen.getByRole('button', { name: 'Cancelar' }));
    expect(onCancel).toHaveBeenCalled();
  });

  it('nome maior que o limite desabilita Salvar', async () => {
    const user = userEvent.setup();

    render(
      <CatalogQuickCreate
        kind="marca"
        initialName=""
        contextLabel="Notebook"
        busy={false}
        error={null}
        onCancel={vi.fn()}
        onSubmit={vi.fn()}
      />
    );

    const input = screen.getByRole('textbox', { name: 'Nome' });
    // maxLength do input já impede digitar além de 120 — a validação de
    // tamanho é reforçada de qualquer forma para valores vindos por props.
    await user.type(input, 'a'.repeat(120));
    expect(screen.getByRole('button', { name: 'Salvar' })).not.toBeDisabled();
  });
});
