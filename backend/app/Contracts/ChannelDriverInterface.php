<?php

namespace App\Contracts;

use App\Models\Chat\Conversation;
use App\Models\Chat\Message;

interface ChannelDriverInterface
{
    /**
     * Envia uma mensagem de saida (agente -> contato) atraves do provider do canal.
     *
     * @return array<string, mixed>
     */
    public function sendMessage(Conversation $conversation, Message $message): array;

    /**
     * @param array<string, mixed> $config
     */
    public function validateProviderConfig(array $config): bool;

    /**
     * @return array<int, mixed>
     */
    public function syncTemplates(): array;

    public function webhookSetup(): void;
}
