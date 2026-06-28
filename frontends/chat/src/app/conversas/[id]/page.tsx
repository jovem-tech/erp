'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { apiConversationDetail, apiSendMessage, ApiError } from '@/lib/api';
import { useConversationChannel } from '@/hooks/use-chat-channels';
import type { ConversationDetail, ChatMessage } from '@/lib/types';
import { MessageBubble } from '@/components/conversas/message-bubble';
import { MessageComposer } from '@/components/conversas/message-composer';
import { ContactPanel } from '@/components/conversas/contact-panel';

export default function ConversationPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const conversationId = Number(params.id);
  const messagesEndRef = useRef<HTMLDivElement | null>(null);

  const [conversation, setConversation] = useState<ConversationDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [profileOpen, setProfileOpen] = useState(false);

  const load = useCallback(async (options: { silent?: boolean } = {}): Promise<void> => {
    const silent = options.silent ?? false;

    if (!silent) {
      setLoading(true);
    }

    try {
      const detail = await apiConversationDetail(conversationId);
      setConversation(detail);
      setError(null);
    } catch (loadError) {
      setError(loadError instanceof ApiError ? loadError.message : 'Não foi possível carregar a conversa.');
    } finally {
      if (!silent) {
        setLoading(false);
      }
    }
  }, [conversationId]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
  }, [conversation?.messages.length]);

  useConversationChannel(conversationId, {
    onMessageCreated: (message) => {
      if (message.sender_type !== 'usuario') {
        void load({ silent: true });
        return;
      }

      setConversation((current) => {
        if (!current || current.messages.some((existing) => existing.id === message.id)) {
          return current;
        }

        return { ...current, messages: [...current.messages, message] };
      });
    },
    onMessageUpdated: (update) => {
      setConversation((current) => {
        if (!current) {
          return current;
        }

        return {
          ...current,
          messages: current.messages.map((existing) =>
            existing.id === update.id ? { ...existing, status: update.status } : existing
          ),
        };
      });
    },
  });

  const handleSend = async (payload: { text: string; attachments: File[] }): Promise<void> => {
    const sent = await apiSendMessage(conversationId, {
      conteudo: payload.text,
      attachments: payload.attachments,
    });

    setConversation((current) => {
      if (!current || current.messages.some((existing) => existing.id === sent.id)) {
        return current;
      }

      return { ...current, messages: [...current.messages, sent] };
    });
  };

  if (loading) {
    return (
      <>
        <div className="chat-column chat-column--thread">
          <p className="muted" style={{ padding: 18 }}>
            Carregando conversa...
          </p>
        </div>
        <div className="chat-column chat-column--contact" />
      </>
    );
  }

  if (error || !conversation) {
    return (
      <>
        <div className="chat-column chat-column--thread">
          <div className="notice notice--danger" style={{ margin: 18 }}>
            {error ?? 'Conversa não encontrada.'}
          </div>
        </div>
        <div className="chat-column chat-column--contact" />
      </>
    );
  }

  const name =
    conversation.contact.client?.nome_razao ||
    conversation.contact.cliente_nome ||
    conversation.contact.nome ||
    conversation.contact.telefone ||
    `Conversa #${conversation.display_id}`;

  return (
    <>
      <div className="chat-column chat-column--thread">
        <div className="chat-thread-header">
          <div className="chat-thread-header__left">
            <button type="button" className="button button--ghost button--sm mobile-only" onClick={() => router.push('/conversas')}>
              Voltar
            </button>
            <div>
              <strong>{name}</strong>
              <div className="muted chat-thread-header__subtitle">{conversation.contact.telefone}</div>
            </div>
          </div>

          <div className="chat-thread-header__actions">
            <button type="button" className="button button--ghost button--sm mobile-only" onClick={() => setProfileOpen(true)}>
              Perfil
            </button>
            <span className="badge badge--accent">{conversation.status_label}</span>
          </div>
        </div>

        <div className="chat-thread-messages">
          {conversation.messages.map((message: ChatMessage) => (
            <MessageBubble key={message.id} message={message} />
          ))}
          <div ref={messagesEndRef} />
        </div>

        <MessageComposer onSend={handleSend} />
      </div>

      <div className="chat-column chat-column--contact">
        <ContactPanel contact={conversation.contact} displayId={conversation.display_id} />
      </div>

      {profileOpen ? (
        <div className="mobile-sheet" role="presentation" onClick={() => setProfileOpen(false)}>
          <div className="mobile-sheet__panel" onClick={(event) => event.stopPropagation()}>
            <ContactPanel
              contact={conversation.contact}
              displayId={conversation.display_id}
              onBack={() => setProfileOpen(false)}
            />
          </div>
        </div>
      ) : null}
    </>
  );
}
