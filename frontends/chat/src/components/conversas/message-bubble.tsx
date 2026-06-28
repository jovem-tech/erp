import type { ChatMessage } from '@/lib/types';
import { MessageAttachmentView } from '@/components/conversas/message-attachment';

function formatTime(value: string | null): string {
  if (!value) {
    return '';
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '';
  }

  return new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(date);
}

const STATUS_LABEL: Record<string, string> = {
  pending: 'Enviando',
  sent: 'Enviada',
  delivered: 'Entregue',
  read: 'Lida',
  failed: 'Falha',
};

export function MessageBubble({ message }: { message: ChatMessage }) {
  const isOutgoing = message.message_type === 'outgoing';
  const isSystem = message.sender_type === 'system';
  const attachments = message.attachments ?? [];

  return (
    <div
      className={[
        'message-bubble',
        `message-bubble--${isOutgoing ? 'outgoing' : 'incoming'}`,
        isSystem ? 'message-bubble--system' : '',
      ].join(' ').trim()}
    >
      {isSystem ? <span className="message-bubble__badge">Automática</span> : null}

      {message.conteudo ? <div className="message-bubble__text">{message.conteudo}</div> : null}

      {attachments.length > 0 ? (
        <div className="message-bubble__attachments">
          {attachments.map((attachment) => (
            <MessageAttachmentView key={attachment.id} attachment={attachment} />
          ))}
        </div>
      ) : null}

      <div className="message-bubble__meta">
        <span>{formatTime(message.created_at)}</span>
        {isOutgoing ? <span>{STATUS_LABEL[message.status] ?? message.status}</span> : null}
      </div>
    </div>
  );
}
