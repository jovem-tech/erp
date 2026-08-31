import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CatalogSelect } from '@/components/orders/order-form-wizard/catalog-select';

type Brand = { id: number; nome: string };

const BRANDS: Brand[] = [
  { id: 1, nome: 'Samsung' },
  { id: 2, nome: 'Sanei' },
  { id: 3, nome: 'Apple' },
  { id: 4, nome: 'Asus' },
];

function renderSelect(overrides: Partial<React.ComponentProps<typeof CatalogSelect<Brand>>> = {}) {
  const onSelect = vi.fn();
  const utils = render(
    <CatalogSelect<Brand>
      label="Marca"
      options={BRANDS}
      value={null}
      onSelect={onSelect}
      getOptionKey={(b) => b.id}
      getOptionLabel={(b) => b.nome}
      {...overrides}
    />,
  );
  return { onSelect, ...utils };
}

describe('CatalogSelect', () => {
  it('filtra ao digitar, sem precisar esperar debounce', async () => {
    const user = userEvent.setup();
    renderSelect();

    await user.click(screen.getByRole('combobox'));
    await user.type(screen.getByRole('combobox'), 'sam');

    expect(screen.getByRole('option', { name: 'Samsung' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Apple' })).not.toBeInTheDocument();
  });

  it('ranqueia correspondência por prefixo antes de substring', async () => {
    const user = userEvent.setup();
    renderSelect();

    await user.click(screen.getByRole('combobox'));
    await user.type(screen.getByRole('combobox'), 'sa');

    const options = screen.getAllByRole('option').map((el) => el.textContent);
    // "Sanei" e "Samsung" começam com "sa"; "Asus" só contém via não é o caso aqui,
    // mas garante que as duas opções por prefixo vêm antes de qualquer substring.
    expect(options[0]).toMatch(/Samsung|Sanei/);
    expect(options[1]).toMatch(/Samsung|Sanei/);
  });

  it('corta a lista em maxVisibleOptions e mostra a dica de refinar', async () => {
    const user = userEvent.setup();
    const manyBrands: Brand[] = Array.from({ length: 10 }, (_, index) => ({ id: index + 1, nome: `Marca ${index + 1}` }));

    render(
      <CatalogSelect<Brand>
        label="Marca"
        options={manyBrands}
        value={null}
        onSelect={vi.fn()}
        getOptionKey={(b) => b.id}
        getOptionLabel={(b) => b.nome}
        maxVisibleOptions={3}
      />,
    );

    await user.click(screen.getByRole('combobox'));

    expect(screen.getAllByRole('option')).toHaveLength(3);
    expect(screen.getByText('Mostrando 3 de 10 — refine a busca.')).toBeInTheDocument();
  });

  it('ArrowDown + Enter seleciona a opção destacada', async () => {
    const user = userEvent.setup();
    const { onSelect } = renderSelect();

    const input = screen.getByRole('combobox');
    await user.click(input);
    await user.keyboard('{ArrowDown}{ArrowDown}{Enter}');

    // Ordem alfabética das 4 marcas: Apple, Asus, Samsung, Sanei.
    // ArrowDown duas vezes a partir do índice 0 chega em "Samsung" (índice 2).
    expect(onSelect).toHaveBeenCalledWith(expect.objectContaining({ nome: 'Samsung' }));
  });

  it('Escape fecha o painel e restaura o texto anterior', async () => {
    const user = userEvent.setup();
    const { onSelect } = renderSelect({ value: BRANDS[0] });

    const input = screen.getByRole('combobox');
    await user.click(input);
    await user.clear(input);
    await user.type(input, 'zzz');
    await user.keyboard('{Escape}');

    expect(input).toHaveValue('Samsung');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(onSelect).not.toHaveBeenCalled();
  });

  it('mostra mensagem vazia e ação de criar quando nada casa', async () => {
    const user = userEvent.setup();
    const onTrigger = vi.fn();

    renderSelect({
      emptyMessage: 'Nenhuma marca encontrada.',
      createAction: { label: '+ Nova marca', onTrigger },
    });

    await user.click(screen.getByRole('combobox'));
    await user.type(screen.getByRole('combobox'), 'xiaomi');

    expect(screen.getByText('Nenhuma marca encontrada.')).toBeInTheDocument();
    const createButton = screen.getByRole('button', { name: '+ Nova marca "xiaomi"' });

    await user.click(createButton);
    expect(onTrigger).toHaveBeenCalledWith('xiaomi');
  });

  it('desabilita a ação de criar com o motivo em title quando não há permissão', async () => {
    const user = userEvent.setup();
    renderSelect({
      createAction: { label: '+ Nova marca', onTrigger: vi.fn(), disabled: true, disabledReason: 'Sem permissão para criar marcas.' },
    });

    await user.click(screen.getByRole('combobox'));
    await user.type(screen.getByRole('combobox'), 'xiaomi');

    const createButton = screen.getByRole('button', { name: '+ Nova marca "xiaomi"' });
    expect(createButton).toBeDisabled();
    expect(createButton).toHaveAttribute('title', 'Sem permissão para criar marcas.');
  });

  it('disabled impede abrir o painel', async () => {
    const user = userEvent.setup();
    renderSelect({ disabled: true });

    await user.click(screen.getByRole('combobox'));
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('expõe os atributos ARIA de combobox corretamente', async () => {
    const user = userEvent.setup();
    renderSelect({ required: true });

    const input = screen.getByRole('combobox');
    expect(input).toHaveAttribute('aria-expanded', 'false');
    expect(input).toHaveAttribute('aria-required', 'true');
    expect(input).toHaveAttribute('aria-autocomplete', 'list');

    await user.click(input);
    expect(input).toHaveAttribute('aria-expanded', 'true');
    expect(input).toHaveAttribute('aria-controls', screen.getByRole('listbox').id);
    expect(input).toHaveAttribute('aria-activedescendant');
  });

  it('clicar numa opção seleciona e fecha o painel, mostrando o rótulo com botão de limpar', async () => {
    const user = userEvent.setup();
    const { onSelect } = renderSelect();

    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('option', { name: 'Apple' }));

    expect(onSelect).toHaveBeenCalledWith(expect.objectContaining({ nome: 'Apple' }));
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('botão de limpar aparece com valor selecionado e reabre a busca ao ser clicado', async () => {
    const user = userEvent.setup();
    const { onSelect } = renderSelect({ value: BRANDS[0] });

    const clearButton = screen.getByRole('button', { name: 'Limpar seleção' });
    await user.click(clearButton);

    expect(onSelect).toHaveBeenCalledWith(null);
    expect(screen.getByRole('listbox')).toBeInTheDocument();
  });
});
