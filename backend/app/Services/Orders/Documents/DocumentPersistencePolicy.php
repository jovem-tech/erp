<?php

namespace App\Services\Orders\Documents;

/**
 * Decide se a versão documental recém-emitida guarda o binário em disco.
 * Regra: só assinatura formal (pendência assinada por outro usuário ou
 * rubrica do cliente por link) é prova e fica persistida; o resto é
 * renderizado sob demanda a partir do snapshot. Em modo 'dual' tudo vai a
 * disco (transição/validação).
 */
final class DocumentPersistencePolicy
{
    public function mode(): string
    {
        return strtolower(trim((string) config('document-rendering.mode', 'dual'))) === 'snapshot' ? 'snapshot' : 'dual';
    }

    /**
     * @param  array<string, mixed>  $generationOptions  opções passadas à geração (signature_method, customer_signature...)
     * @param  array<string, mixed>  $engineResult  retorno de PdfGenerationService::generate() do A4
     */
    public function shouldPersistBinary(string $legacyType, array $generationOptions, array $engineResult = []): bool
    {
        if ($this->mode() === 'dual') {
            return true;
        }

        return $this->isFormalSignature($generationOptions, $engineResult)
            || in_array($legacyType, (array) config('document-rendering.always_persist_types', []), true);
    }

    /**
     * @param  array<string, mixed>  $generationOptions
     * @param  array<string, mixed>  $engineResult
     */
    public function isFormalSignature(array $generationOptions, array $engineResult = []): bool
    {
        if (is_array($generationOptions['customer_signature'] ?? null)) {
            return true;
        }

        $method = trim((string) ($generationOptions['signature_method'] ?? $engineResult['assinatura']['metodo'] ?? ''));

        return $method !== '' && in_array($method, $this->persistedSignatureMethods(), true);
    }

    /**
     * @return array<int, string>
     */
    public function persistedSignatureMethods(): array
    {
        return array_values(array_filter(array_map(
            static fn ($method): string => strtolower(trim((string) $method)),
            (array) config('document-rendering.persist_signature_methods', ['pendencia_sessao', 'pendencia_reautenticada', 'cliente_link'])
        )));
    }

    /**
     * Perfil de compressão de fotos para a emissão: o binário persistido
     * vai com o perfil 'assinado' (máxima compressão).
     *
     * @param  array<string, mixed>  $generationOptions
     */
    public function renderProfile(array $generationOptions): string
    {
        return $this->isFormalSignature($generationOptions) ? 'assinado' : 'padrao';
    }
}
