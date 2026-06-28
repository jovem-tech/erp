'use client';

import { startTransition, useCallback, useDeferredValue, useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { apiListConversations, ApiError } from '@/lib/api';
import { useAccountConversationsChannel } from '@/hooks/use-chat-channels';
import type { ChatMessage, ConversationMessagePreview, ConversationSummary } from '@/lib/types';
import { ConversationListItem } from '@/components/conversas/conversation-list-item';

type FilterKey = 'all' | 'unread';

function attachmentLabel(type: ChatMessage['attachments'][number]['attachment_type']): string {
  switch (type) {
    case 'image':
      return 'Imagem';
    case 'audio':
      return 'Áudio';
    case 'video':
      return 'Vídeo';
    case 'document':
      return 'Documento';
    default:
      return 'Anexo';
  }
}

function buildPreviewFromMessage(message: ChatMessage): ConversationMessagePreview {
  const attachmentCount = message.attachments.length;
  const text = (message.conteudo ?? '').trim();
  const attachmentLabelText = attachmentLabel(message.attachments[0]?.attachment_type ?? 'unknown');

  let preview = text;
  if (preview === '') {
    preview = attachmentCount > 0 ? attachmentLabelText : 'Mensagem sem texto';
  } else if (attachmentCount > 0) {
    preview = `${preview} · ${attachmentCount} anexo${attachmentCount > 1 ? 's' : ''}`;
  }

  return {
    id: message.id,
    message_type: message.message_type,
    content_type: message.content_type,
    sender_type: message.sender_type,
    status: message.status,
    created_at: message.created_at,
    preview,
    attachment_count: attachmentCount,
  };
}

export function ConversationList() {
  const params = useParams<{ id?: string }>();
  const activeId = params?.id ? Number(params.id) : null;

  const [conversations, setConversations] = useState<ConversationSummary[]>([]);
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<FilterKey>('all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const deferredSearch = useDeferredValue(search);

  const load = useCallback(async (currentSearch: string, currentFilter: FilterKey): Promise<void> => {
    try {
      const payload = await apiListConversations({
        search: currentSearch,
        unread_only: currentFilter === 'unread',
      });
      setConversations(payload.items);
      setError(null);
    } catch (loadError) {
      setError(loadError instanceof ApiError ? loadError.message : 'Não foi possível carregar as conversas.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    setLoading(true);
    void load(deferredSearch, filter);
  }, [deferredSearch, filter, load]);

  useAccountConversationsChannel({
    onMessageCreated: (message) => {
      if (activeId === message.conversa_id) {
        setConversations((current) =>
          current.map((conversation) =>
            conversation.id !== message.conversa_id
              ? conversation
              : {
                  ...conversation,
                  last_activity_at: message.created_at ?? conversation.last_activity_at,
                  unread: false,
                  unread_count: 0,
                  last_message: buildPreviewFromMessage(message),
                }
          )
        );
        return;
      }

      void load(deferredSearch, filter);
    },
    onMessageUpdated: (message) => {
      if (activeId === message.conversa_id) {
        setConversations((current) =>
          current.map((conversation) => {
            if (conversation.id !== message.conversa_id) {
              return conversation;
            }

            if (conversation.last_message?.id !== message.id) {
              return conversation;
            }

            return {
              ...conversation,
              last_message: {
                ...conversation.last_message,
                status: message.status,
              },
            };
          })
        );
        return;
      }

      void load(deferredSearch, filter);
    },
  });

  return (
    <div className="conversation-list-shell">
      <div className="conversation-list-toolbar">
        <input
          className="input"
          type="search"
          placeholder="Buscar por nome, cliente ou telefone"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />

        <div className="conversation-list-filters">
          <button
            type="button"
            className={`button button--ghost button--sm${filter === 'all' ? ' is-active' : ''}`}
            onClick={() => startTransition(() => setFilter('all'))}
          >
            Todas
          </button>
          <button
            type="button"
            className={`button button--ghost button--sm${filter === 'unread' ? ' is-active' : ''}`}
            onClick={() => startTransition(() => setFilter('unread'))}
          >
            Não lidas
          </button>
        </div>
      </div>

      {loading ? <p className="muted conversation-list__feedback">Carregando conversas...</p> : null}

      {!loading && error ? (
        <div className="notice notice--danger conversation-list__feedback">{error}</div>
      ) : null}

      {!loading && !error && conversations.length === 0 ? (
        <div className="empty-state conversation-list__feedback">
          <strong>Nenhuma conversa encontrada</strong>
          <p>As mensagens novas e os disparos automáticos do ERP aparecem aqui.</p>
        </div>
      ) : null}

      {!loading && !error && conversations.length > 0 ? (
        <div className="conversation-list">
          {conversations.map((conversation) => (
            <ConversationListItem
              key={conversation.id}
              conversation={conversation}
              active={conversation.id === activeId}
            />
          ))}
        </div>
      ) : null}
    </div>
  );
}
