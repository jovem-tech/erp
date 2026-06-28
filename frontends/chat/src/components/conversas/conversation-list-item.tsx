import Link from 'next/link';
import type { ConversationSummary } from '@/lib/types';

function formatRelativeTime(value: string | null): string {
  if (!value) {
    return '';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '';
  }

  return new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(date);
}

function statusLabel(lastMessage: ConversationSummary['last_message']): string {
  const value = lastMessage?.status;

  switch (value) {
    case 'read':
      return 'Lida';
    case 'delivered':
      return 'Entregue';
    case 'failed':
      return 'Falha';
    case 'pending':
      return 'Enviando';
    case 'sent':
      return 'Enviada';
    default:
      return '';
  }
}

type ConversationListItemProps = {
  conversation: ConversationSummary;
  active: boolean;
};

export function ConversationListItem({ conversation, active }: ConversationListItemProps) {
  const name =
    conversation.contact.client?.nome_razao ||
    conversation.contact.cliente_nome ||
    conversation.contact.nome ||
    conversation.contact.telefone ||
    `Conversa #${conversation.display_id}`;

  return (
    <Link
      href={`/conversas/${conversation.id}`}
      className={`conversation-list-item${active ? ' conversation-list-item--active' : ''}`}
    >
      <div className="conversation-list-item__top">
        <span className="conversation-list-item__name">{name}</span>
        <span className="conversation-list-item__time">{formatRelativeTime(conversation.last_activity_at)}</span>
      </div>

      <div className="conversation-list-item__preview">
        <span className="conversation-list-item__preview-text">
          {conversation.last_message?.preview ?? 'Conversa sem mensagens ainda'}
        </span>
      </div>

      <div className="conversation-list-item__bottom">
        <span className="conversation-list-item__phone">{conversation.contact.telefone ?? 'Telefone não identificado'}</span>
        <div className="conversation-list-item__indicators">
          {conversation.last_message?.message_type === 'outgoing' ? (
            <span className="conversation-list-item__status">{statusLabel(conversation.last_message)}</span>
          ) : null}
          {conversation.unread_count > 0 ? (
            <span className="conversation-list-item__badge" aria-label={`${conversation.unread_count} não lidas`}>
              {conversation.unread_count}
            </span>
          ) : null}
        </div>
      </div>
    </Link>
  );
}
