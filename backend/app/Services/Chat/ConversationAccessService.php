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
    public function __construct(
        private readonly ChatAccountContextService $accountContextService
    ) {
    }

    public function canAccessConversation(User $user, Conversation $conversation): bool
    {
        return $this->isActive($user)
            && in_array((int) $conversation->conta_id, $this->accessibleAccountIds($user), true);
    }

    public function canAccessAccount(User $user, int $contaId): bool
    {
        return $this->isActive($user)
            && in_array($contaId, $this->accessibleAccountIds($user), true);
    }

    /**
     * @return array<int, int>
     */
    public function accessibleAccountIds(User $user): array
    {
        if (! $this->isActive($user)) {
            return [];
        }

        return $this->accountContextService->allowedAccountIds();
    }

    public function defaultAccountIdForUser(User $user): ?int
    {
        if (! $this->isActive($user)) {
            return null;
        }

        return $this->accountContextService->defaultAccountId();
    }

    private function isActive(User $user): bool
    {
        return (bool) $user->ativo;
    }
}
