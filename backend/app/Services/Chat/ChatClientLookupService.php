<?php

namespace App\Services\Chat;

use App\Models\Legacy\LegacyClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ChatClientLookupService
{
    /**
     * @return Collection<int, LegacyClient>
     */
    public function search(string $term, int $limit = 12): Collection
    {
        $term = trim($term);
        if ($term === '') {
            return collect();
        }

        $normalizedDigits = $this->digits($term);
        $likeTerm = '%' . mb_strtolower($term) . '%';

        return LegacyClient::query()
            ->where(function (Builder $query) use ($likeTerm, $normalizedDigits): void {
                $query
                    ->whereRaw('LOWER(COALESCE(nome_razao, \'\')) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(COALESCE(cpf_cnpj, \'\')) LIKE ?', [$likeTerm])
                    ->orWhereRaw('LOWER(COALESCE(nome_contato, \'\')) LIKE ?', [$likeTerm]);

                if ($normalizedDigits !== '') {
                    $query
                        ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone1, \'\'), \'(\', \'\'), \')\', \'\'), \'-\', \'\'), \' \', \'\') LIKE ?', ['%' . $normalizedDigits . '%'])
                        ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone2, \'\'), \'(\', \'\'), \')\', \'\'), \'-\', \'\'), \' \', \'\') LIKE ?', ['%' . $normalizedDigits . '%'])
                        ->orWhereRaw('REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefone_contato, \'\'), \'(\', \'\'), \')\', \'\'), \'-\', \'\'), \' \', \'\') LIKE ?', ['%' . $normalizedDigits . '%']);
                }
            })
            ->orderBy('nome_razao')
            ->limit(max(1, min(20, $limit)))
            ->get();
    }

    public function findById(int $clientId): ?LegacyClient
    {
        if ($clientId <= 0) {
            return null;
        }

        return LegacyClient::query()->find($clientId);
    }

    public function findByPhone(string $phone): ?LegacyClient
    {
        $variants = $this->phoneVariants($phone);
        if ($variants === []) {
            return null;
        }

        $expression = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(%s, ''), '(', ''), ')', ''), '-', ''), ' ', '')";

        return LegacyClient::query()
            ->where(function (Builder $query) use ($variants, $expression): void {
                foreach (['telefone1', 'telefone2', 'telefone_contato'] as $field) {
                    $query->orWhereRaw(sprintf($expression . ' IN (%s)', $field, implode(',', array_fill(0, count($variants), '?'))), $variants);
                }
            })
            ->first();
    }

    /**
     * @return array<int, string>
     */
    public function phonesFor(LegacyClient $client): array
    {
        $phones = [
            (string) ($client->telefone1 ?? ''),
            (string) ($client->telefone_contato ?? ''),
            (string) ($client->telefone2 ?? ''),
        ];

        return array_values(array_filter(array_unique(array_map(
            static fn (string $value): string => trim($value),
            $phones
        ))));
    }

    public function preferredPhoneFor(LegacyClient $client): ?string
    {
        foreach ($this->phonesFor($client) as $phone) {
            if ($phone !== '') {
                return $phone;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapSummary(LegacyClient $client): array
    {
        return [
            'id' => (int) $client->id,
            'nome_razao' => trim((string) ($client->nome_razao ?? '')),
            'cpf_cnpj' => trim((string) ($client->cpf_cnpj ?? '')),
            'cidade' => trim((string) ($client->cidade ?? '')),
            'uf' => trim((string) ($client->uf ?? '')),
            'telefone_principal' => $this->preferredPhoneFor($client),
            'telefones' => $this->phonesFor($client),
            'nome_contato' => trim((string) ($client->nome_contato ?? '')),
        ];
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * @return array<int, string>
     */
    private function phoneVariants(string $value): array
    {
        $digits = $this->digits($value);
        if ($digits === '') {
            return [];
        }

        $variants = [$digits];

        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            $variants[] = substr($digits, 2);
        }

        if (! str_starts_with($digits, '55') && strlen($digits) >= 10) {
            $variants[] = '55' . $digits;
        }

        return array_values(array_unique(array_filter($variants, static fn (string $candidate): bool => $candidate !== '')));
    }
}
