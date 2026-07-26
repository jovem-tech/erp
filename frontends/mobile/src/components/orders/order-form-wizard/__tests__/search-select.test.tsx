import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SearchSelect } from '@/components/orders/order-form-wizard/search-select';

type Option = { id: number; nome: string };

describe('SearchSelect', () => {
  it('busca com debounce e permite selecionar uma opção', async () => {
    const user = userEvent.setup();
    const fetchOptions = vi.fn().mockResolvedValue([{ id: 1, nome: 'João Silva' }] as Option[]);
    const onSelect = vi.fn();

    render(
      <SearchSelect<Option>
        label="Cliente"
        placeholder="Buscar cliente"
        value={null}
        onSelect={onSelect}
        fetchOptions={fetchOptions}
        getOptionKey={(option) => option.id}
        getOptionLabel={(option) => option.nome}
      />
    );

    await user.type(screen.getByPlaceholderText('Buscar cliente'), 'João');

    await waitFor(() => expect(fetchOptions).toHaveBeenCalledWith('João'));
    await waitFor(() => expect(screen.getByText('João Silva')).toBeInTheDocument());

    await user.click(screen.getByText('João Silva'));

    expect(onSelect).toHaveBeenCalledWith({ id: 1, nome: 'João Silva' });
  });

  it('não busca antes do mínimo de caracteres', async () => {
    const user = userEvent.setup();
    const fetchOptions = vi.fn().mockResolvedValue([]);

    render(
      <SearchSelect<Option>
        label="Cliente"
        value={null}
        onSelect={vi.fn()}
        fetchOptions={fetchOptions}
        getOptionKey={(option) => option.id}
        getOptionLabel={(option) => option.nome}
        minChars={3}
      />
    );

    await user.type(screen.getByRole('textbox'), 'jo');

    await new Promise((resolve) => setTimeout(resolve, 300));
    expect(fetchOptions).not.toHaveBeenCalled();
  });

  it('carrega e expõe as opções iniciais ao focar o campo quando solicitado', async () => {
    const user = userEvent.setup();
    const options = [{ id: 1, nome: 'Notebook Dell' }];
    const fetchOptions = vi.fn().mockResolvedValue(options);
    const onInitialOptionsLoaded = vi.fn();

    render(
      <SearchSelect<Option>
        label="Equipamento"
        placeholder="Buscar equipamento"
        value={null}
        onSelect={vi.fn()}
        fetchOptions={fetchOptions}
        getOptionKey={(option) => option.id}
        getOptionLabel={(option) => option.nome}
        loadOnFocus
        onInitialOptionsLoaded={onInitialOptionsLoaded}
      />
    );

    await user.click(screen.getByPlaceholderText('Buscar equipamento'));

    await waitFor(() => expect(fetchOptions).toHaveBeenCalledWith(''));
    await waitFor(() => expect(onInitialOptionsLoaded).toHaveBeenCalledWith(options));
    expect(screen.getByText('Notebook Dell')).toBeInTheDocument();
  });

  it('mostra o valor selecionado e permite trocar', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();

    render(
      <SearchSelect<Option>
        label="Cliente"
        value={{ id: 1, nome: 'João Silva' }}
        onSelect={onSelect}
        fetchOptions={vi.fn()}
        getOptionKey={(option) => option.id}
        getOptionLabel={(option) => option.nome}
      />
    );

    expect(screen.getByText('João Silva')).toBeInTheDocument();

    await user.click(screen.getByText('Trocar'));

    expect(onSelect).toHaveBeenCalledWith(null);
  });
});
