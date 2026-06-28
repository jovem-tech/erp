<?php

namespace App\Services\Channels\Whatsapp;

class PhoneNumberNormalizationService
{
    /**
     * Normaliza um remoteJid ou telefone livre da Evolution API para E.164 (+55...).
     * Critico para o source_id de ContactInbox: o mesmo numero MUST sempre normalizar
     * para o mesmo valor, ou contatos duplicados sao criados.
     */
    public function normalize(string $rawPhoneOrJid): string
    {
        $withoutJidSuffix = explode('@', trim($rawPhoneOrJid), 2)[0];
        $digits = preg_replace('/\D+/', '', $withoutJidSuffix) ?? '';

        if ($digits === '') {
            return '';
        }

        if (! str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }

        return '+' . $digits;
    }

    public function isGroupOrBroadcastJid(string $rawJid): bool
    {
        $normalized = strtolower(trim($rawJid));

        return str_contains($normalized, '@g.us')
            || str_contains($normalized, '@broadcast')
            || str_contains($normalized, '@newsletter');
    }
}
