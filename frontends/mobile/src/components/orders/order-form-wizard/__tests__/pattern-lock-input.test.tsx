import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PatternLockInput } from '@/components/orders/order-form-wizard/pattern-lock-input';

function ControlledPatternLockInput({ onChange, initialValue = '' }: { onChange: (value: string) => void; initialValue?: string }) {
  const [value, setValue] = useState(initialValue);
  return (
    <PatternLockInput
      value={value}
      onChange={(next) => {
        setValue(next);
        onChange(next);
      }}
    />
  );
}

describe('PatternLockInput', () => {
  it('clicar em pontos em sequência emite a string separada por hífen', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<PatternLockInput value="" onChange={onChange} />);

    await user.click(screen.getByRole('button', { name: /^Ponto 1$/ }));
    await user.click(screen.getByRole('button', { name: /^Ponto 2$/ }));
    await user.click(screen.getByRole('button', { name: /^Ponto 3$/ }));

    expect(onChange).toHaveBeenNthCalledWith(1, '1');
    expect(onChange).toHaveBeenNthCalledWith(2, '1-2');
    expect(onChange).toHaveBeenNthCalledWith(3, '1-2-3');
  });

  it('clicar de novo no último ponto desfaz esse ponto', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    const { rerender } = render(<PatternLockInput value="1-2" onChange={onChange} />);

    await user.click(screen.getByRole('button', { name: /Ponto 2, posição 2/ }));

    expect(onChange).toHaveBeenLastCalledWith('1');

    rerender(<PatternLockInput value="1" onChange={onChange} />);
    expect(screen.getByRole('button', { name: /Ponto 1, posição 1/ })).toBeInTheDocument();
  });

  it('clicar num ponto do meio já usado (não o último) é ignorado', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<PatternLockInput value="1-2-3" onChange={onChange} />);

    await user.click(screen.getByRole('button', { name: /Ponto 1, posição 1/ }));

    expect(onChange).not.toHaveBeenCalled();
  });

  it('"Limpar desenho" emite string vazia e desabilita a si mesmo quando já vazio', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<PatternLockInput value="1-2-3" onChange={onChange} />);

    const clearButton = screen.getByRole('button', { name: 'Limpar desenho' });
    expect(clearButton).not.toBeDisabled();

    await user.click(clearButton);
    expect(onChange).toHaveBeenCalledWith('');
  });

  it('"Limpar desenho" fica desabilitado quando o valor já está vazio', () => {
    render(<PatternLockInput value="" onChange={vi.fn()} />);
    expect(screen.getByRole('button', { name: 'Limpar desenho' })).toBeDisabled();
  });

  it('montar com um valor existente marca os pontos como ativos e ordenados', () => {
    render(<PatternLockInput value="1-5-9" onChange={vi.fn()} />);

    expect(screen.getByRole('button', { name: /Ponto 1, posição 1/ })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('button', { name: /Ponto 5, posição 2/ })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('button', { name: /Ponto 9, posição 3/ })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('button', { name: /^Ponto 2$/ })).toHaveAttribute('aria-pressed', 'false');
    expect(screen.getByText('Desenho: 1 → 5 → 9')).toBeInTheDocument();
  });

  it('mostra mensagem de vazio quando não há desenho', () => {
    render(<PatternLockInput value="" onChange={vi.fn()} />);
    expect(screen.getByText('Nenhum desenho definido.')).toBeInTheDocument();
  });

  it('digitar no fallback textual emite o valor diretamente', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<ControlledPatternLockInput onChange={onChange} />);

    await user.click(screen.getByText('Digitar a sequência'));
    await user.type(screen.getByPlaceholderText('Ex.: 1-2-3-6-9'), '2-4');

    expect(onChange).toHaveBeenLastCalledWith('2-4');
  });

  it('valor inválido não quebra e mantém o texto bruto visível no fallback', () => {
    render(<PatternLockInput value="abc" onChange={vi.fn()} />);

    expect(screen.getByText('Nenhum desenho definido.')).toBeInTheDocument();
    expect(screen.getByPlaceholderText('Ex.: 1-2-3-6-9')).toHaveValue('abc');
  });

  it('desabilitado impede cliques nos pontos e no botão limpar', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(<PatternLockInput value="1-2" onChange={onChange} disabled />);

    await user.click(screen.getByRole('button', { name: /^Ponto 3$/ }));
    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Limpar desenho' })).toBeDisabled();
  });
});
