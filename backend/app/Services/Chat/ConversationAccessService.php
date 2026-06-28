<?php

namespace App\Services\Chat;

use App\Models\Chat\Conversation;
use App\Models\User;

/**
 * Regra de acesso central da Central de Atendimento (mesmo padrao de
 * OrderWorkflowService::canAccessOrder). Fase 1: qualquer atendente autenticado e ativo
 * pode ver/responder qualquer conversa (sem atribuicao/times ainda — ver
 * specs/010-inbox-whatsapp-tempo-real/spec.md, Assumptions). Usado tanto pela API quanto
 * pela autorizacao de canais privados do broadcasting (routes/channels.php).
 */
class ConversationAccessService
{
    public function canAccessConversation(User $user, Conversation $conversation): bool
    {
        return (bool) $user->ativo;
    }

    public function canAccessAccount(User $user, int $contaId): bool
    {
        return (bool) $user->ativo;
    }
}
