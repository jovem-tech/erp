import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MessageBubble } from '@/components/conversas/message-bubble';
import type { ChatMessage } from '@/lib/types';

function buildMessage(overrides: Partial<ChatMessage> = {}): ChatMessage {
  return {
    id: 1,
    conversa_id: 1,
    message_type: 'incoming',
    conteudo: 'Olá, preciso de ajuda',
    status: 'sent',
    sender_type: 'contato',
    sender_id: 1,
    created_at: '2026-06-27T10:00:00-03:00',
    ...overrides,
  };
}

describe('MessageBubble', () => {
  it('renderiza o conteudo da mensagem', () => {
    render(<MessageBubble message={buildMessage()} />);
    expect(screen.getByText('Olá, preciso de ajuda')).toBeInTheDocument();
  });

  it('aplica a classe de mensagem entrante', () => {
    const { container } = render(<MessageBubble message={buildMessage({ message_type: 'incoming' })} />);
    expect(container.querySelector('.message-bubble--incoming')).not.toBeNull();
  });

  it('aplica a classe de mensagem enviada e mostra o status', () => {
    const { container } = render(
      <MessageBubble message={buildMessage({ message_type: 'outgoing', status: 'delivered' })} />
    );

    expect(container.querySelector('.message-bubble--outgoing')).not.toBeNull();
    expect(screen.getByText('Entregue')).toBeInTheDocument();
  });

  it('nao mostra rotulo de status para mensagens entrantes', () => {
    render(<MessageBubble message={buildMessage({ message_type: 'incoming', status: 'failed' })} />);
    expect(screen.queryByText('Falha no envio')).not.toBeInTheDocument();
  });
});
