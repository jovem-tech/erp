<?php

namespace App\Services\Channels\Whatsapp;

use App\Models\Chat\Account;
use App\Models\Chat\Contact;
use App\Models\Chat\ContactInbox;
use App\Models\Chat\Conversation;
use App\Models\Chat\Inbox;
use App\Services\Chat\ChatClientLookupService;
use Illuminate\Support\Facades\DB;

class ContactConversationResolver
{
    public function __construct(
        private readonly ChatClientLookupService $clientLookup
    ) {
    }

    public function resolve(string $phone, ?string $nome = null, ?int $clientId = null): Conversation
    {
        return DB::connection('chat')->transaction(function () use ($phone, $nome, $clientId) {
            $account = Account::query()->lockForUpdate()->firstOrFail();

            $contact = $this->findOrCreateContact($account, $phone, $nome ?? '', $clientId);
            $inbox = $this->firstWhatsappInbox($account);
            $contactInbox = $this->findOrCreateContactInbox($account, $contact, $inbox, $phone);

            return $this->findOrCreateConversation($account, $inbox, $contact, $contactInbox);
        });
    }

    private function findOrCreateContact(Account $account, string $phone, string $nome, ?int $clientId = null): Contact
    {
        $contact = Contact::query()
            ->where('conta_id', $account->id)
            ->where('telefone', $phone)
            ->first();

        $resolvedClientId = $clientId ?? $this->findClientIdByPhone($phone);

        if ($contact instanceof Contact) {
            $updates = [];

            if (($contact->nome === null || trim((string) $contact->nome) === '') && $nome !== '') {
                $updates['nome'] = $nome;
            }

            if ((int) ($contact->cliente_id ?? 0) <= 0 && $resolvedClientId !== null) {
                $updates['cliente_id'] = $resolvedClientId;
            }

            if ($updates !== []) {
                $contact->forceFill($updates)->save();
            }

            return $contact;
        }

        return Contact::create([
            'conta_id' => $account->id,
            'nome' => $nome !== '' ? $nome : null,
            'telefone' => $phone,
            'cliente_id' => $resolvedClientId,
        ]);
    }

    private function findClientIdByPhone(string $phone): ?int
    {
        return $this->clientLookup->findByPhone($phone)?->id;
    }

    private function firstWhatsappInbox(Account $account): Inbox
    {
        return Inbox::query()
            ->where('conta_id', $account->id)
            ->where('channel_type', 'whatsapp')
            ->firstOrFail();
    }

    private function findOrCreateContactInbox(Account $account, Contact $contact, Inbox $inbox, string $phone): ContactInbox
    {
        return ContactInbox::query()->firstOrCreate(
            ['caixa_entrada_id' => $inbox->id, 'source_id' => $phone],
            ['conta_id' => $account->id, 'contato_id' => $contact->id]
        );
    }

    private function findOrCreateConversation(Account $account, Inbox $inbox, Contact $contact, ContactInbox $contactInbox): Conversation
    {
        $existing = Conversation::query()
            ->where('contato_caixa_entrada_id', $contactInbox->id)
            ->where('status', '!=', 'resolved')
            ->orderByDesc('id')
            ->first();

        if ($existing instanceof Conversation) {
            return $existing;
        }

        return Conversation::create([
            'conta_id' => $account->id,
            'caixa_entrada_id' => $inbox->id,
            'contato_id' => $contact->id,
            'contato_caixa_entrada_id' => $contactInbox->id,
            'display_id' => $account->reserveNextDisplayId(),
            'status' => 'open',
            'last_activity_at' => now(),
        ]);
    }
}
