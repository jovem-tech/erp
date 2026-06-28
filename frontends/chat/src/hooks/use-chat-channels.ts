'use client';

import { useEffect, useRef } from 'react';
import { ACCOUNT_ID, getEcho } from '@/lib/echo';
import type { ChatMessage } from '@/lib/types';

type MessageUpdatedPayload = Pick<ChatMessage, 'id' | 'conversa_id' | 'status'>;

type ConversationChannelHandlers = {
  onMessageCreated?: (message: ChatMessage) => void;
  onMessageUpdated?: (message: MessageUpdatedPayload) => void;
};

/**
 * Mensagens em tempo real de UMA conversa aberta (canal privado conversa.{id}).
 */
export function useConversationChannel(conversationId: number | null, handlers: ConversationChannelHandlers): void {
  const handlersRef = useRef(handlers);
  handlersRef.current = handlers;

  useEffect(() => {
    if (!conversationId) {
      return;
    }

    const echo = getEcho();
    if (!echo) {
      return;
    }

    const channelName = `conversa.${conversationId}`;
    const channel = echo.private(channelName);

    channel.listen('.message.created', (payload: { message: ChatMessage }) => {
      handlersRef.current.onMessageCreated?.(payload.message);
    });

    channel.listen('.message.updated', (payload: { message: MessageUpdatedPayload }) => {
      handlersRef.current.onMessageUpdated?.(payload.message);
    });

    return () => {
      echo.leave(channelName);
    };
  }, [conversationId]);
}

type AccountChannelHandlers = {
  onMessageCreated?: (message: ChatMessage) => void;
  onMessageUpdated?: (message: MessageUpdatedPayload) => void;
};

/**
 * Atividade em tempo real de TODAS as conversas da conta (canal privado
 * conta.{contaId}.conversas) — usado pela lista de conversas, para refletir mensagem
 * nova mesmo numa conversa que o atendente nao esta com a thread aberta.
 */
export function useAccountConversationsChannel(handlers: AccountChannelHandlers): void {
  const handlersRef = useRef(handlers);
  handlersRef.current = handlers;

  useEffect(() => {
    const echo = getEcho();
    if (!echo) {
      return;
    }

    const channelName = `conta.${ACCOUNT_ID}.conversas`;
    const channel = echo.private(channelName);

    channel.listen('.message.created', (payload: { message: ChatMessage }) => {
      handlersRef.current.onMessageCreated?.(payload.message);
    });

    channel.listen('.message.updated', (payload: { message: MessageUpdatedPayload }) => {
      handlersRef.current.onMessageUpdated?.(payload.message);
    });

    return () => {
      echo.leave(channelName);
    };
  }, []);
}
