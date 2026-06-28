<?php

use App\Models\Chat\Conversation;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;
use App\Services\Chat\ConversationAccessService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return (int) $user->id === $id;
});

// Central de Atendimento (specs/010-inbox-whatsapp-tempo-real). Autenticado via Bearer
// (Broadcast::routes(['middleware' => ['auth:sanctum']]) em AppServiceProvider::boot()).
Broadcast::channel('conta.{contaId}.conversas', function (User $user, int $contaId) {
    return app(RbacAuthorizationService::class)->allows($user, 'atendimento_whatsapp', 'visualizar')
        && app(ConversationAccessService::class)->canAccessAccount($user, $contaId);
});

Broadcast::channel('conversa.{conversaId}', function (User $user, int $conversaId) {
    $conversation = Conversation::query()->find($conversaId);

    if (! $conversation instanceof Conversation) {
        return false;
    }

    return app(RbacAuthorizationService::class)->allows($user, 'atendimento_whatsapp', 'visualizar')
        && app(ConversationAccessService::class)->canAccessConversation($user, $conversation);
});
