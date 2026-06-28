<?php

namespace App\Services\Suppliers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupplierCnpjLookupService
{
    private const CACHE_TTL_SECONDS = 86400;

    /**
     * @var array<int, array{name: string, url: string}>
     */
    private array $providers = [
        [
            'name' => 'brasilapi',
            'url' => 'https://brasilapi.com.br/api/cnpj/v1/',
        ],
        [
            'name' => 'cnpjws_publica',
            'url' => 'https://publica.cnpj.ws/cnpj/',
        ],
    ];

    /**
     * @return array{success: bool, status: string, message: string, data?: array<string, mixed>, provider?: string}
     */
    public function lookup(string $cnpj): array
    {
        $cnpjDigits = preg_replace('/\D+/', '', $cnpj) ?? '';

        if (strlen($cnpjDigits) !== 14) {
            return [
                'success' => false,
                'status' => 'validation_error',
                'message' => 'Informe um CNPJ valido com 14 digitos.',
            ];
        }

        $cacheKey = 'supplier_cnpj_lookup_' . $cnpjDigits;
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && ($cached['success'] ?? false) === true) {
            return $cached;
        }

        $notFoundCount = 0;
        $rateLimitedCount = 0;
        $lastErrorMessage = 'Nao foi possivel consultar o CNPJ agora. Voce pode continuar o cadastro manualmente.';

        foreach ($this->providers as $provider) {
            $result = $this->queryProvider($provider, $cnpjDigits);

            if (($result['success'] ?? false) === true) {
                $result = $this->enrichWithAdditionalProviderData(
                    $result,
                    (string) ($provider['name'] ?? '')
                );

                Cache::put($cacheKey, $result, self::CACHE_TTL_SECONDS);

                return $result;
            }

            $status = (string) ($result['status'] ?? '');
            $lastErrorMessage = (string) ($result['message'] ?? $lastErrorMessage);

            if ($status === 'not_found') {
                $notFoundCount++;
                continue;
            }

            if ($status === 'rate_limited') {
                $rateLimitedCount++;
                continue;
            }
        }

        if ($notFoundCount === count($this->providers)) {
            return [
                'success' => false,
                'status' => 'not_found',
                'message' => 'Nao encontramos dados publicos para este CNPJ.',
            ];
        }

        if ($rateLimitedCount === count($this->providers)) {
            return [
                'success' => false,
                'status' => 'rate_limited',
                'message' => 'Os provedores publicos de CNPJ atingiram o limite de consultas temporariamente. Aguarde um minuto e tente novamente, ou siga com o cadastro manual.',
            ];
        }

        return [
            'success' => false,
            'status' => 'provider_unreachable',
            'message' => $lastErrorMessage,
        ];
    }

    /**
     * @param array{name: string, url: string} $provider
     * @return array<string, mixed>
     */
    private function queryProvider(array $provider, string $cnpjDigits): array
    {
        $providerName = (string) ($provider['name'] ?? 'unknown');
        $providerUrl = (string) ($provider['url'] ?? '');

        try {
            $response = Http::acceptJson()
                ->timeout(12)
                ->get($providerUrl . $cnpjDigits);
        } catch (\Throwable $exception) {
            Log::error('[Supplier CNPJ Lookup] Falha de comunicacao com o provedor.', [
                'provider' => $providerName,
                'message' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 'provider_unreachable',
                'message' => 'Nao foi possivel consultar o CNPJ agora. Voce pode continuar o cadastro manualmente.',
            ];
        }

        $statusCode = (int) $response->status();
        $payload = $response->json();

        if ($statusCode === 404) {
            return [
                'success' => false,
                'status' => 'not_found',
                'message' => 'Nao encontramos dados publicos para este CNPJ.',
            ];
        }

        if ($statusCode === 429) {
            Log::warning('[Supplier CNPJ Lookup] Provedor com limite temporario.', [
                'provider' => $providerName,
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'status' => 'rate_limited',
                'message' => 'O provedor publico consultado atingiu o limite de requisicoes temporariamente.',
            ];
        }

        if ($statusCode < 200 || $statusCode >= 300 || ! is_array($payload)) {
            Log::error('[Supplier CNPJ Lookup] Resposta invalida do provedor.', [
                'provider' => $providerName,
                'status' => $statusCode,
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'status' => 'invalid_response',
                'message' => 'O servico de consulta de CNPJ retornou uma resposta invalida. Tente novamente em instantes.',
            ];
        }

        $mapped = $this->mapPayload($providerName, $cnpjDigits, $payload);
        if ($mapped === null) {
            Log::error('[Supplier CNPJ Lookup] Payload sem estrutura suportada.', [
                'provider' => $providerName,
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'status' => 'invalid_response',
                'message' => 'O provedor de consulta retornou dados em formato inesperado.',
            ];
        }

        return [
            'success' => true,
            'status' => 'ok',
            'message' => 'CNPJ localizado com sucesso.',
            'provider' => $providerName,
            'data' => $mapped,
        ];
    }

    /**
     * @param array{name: string, url: string} $provider
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function mapPayload(string $providerName, string $cnpjDigits, array $payload): ?array
    {
        return match ($providerName) {
            'brasilapi' => $this->mapBrasilApiPayload($cnpjDigits, $payload),
            'cnpjws_publica' => $this->mapCnpjWsPayload($cnpjDigits, $payload),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mapBrasilApiPayload(string $cnpjDigits, array $payload): array
    {
        return [
            'cnpj' => $cnpjDigits,
            'razao_social' => trim((string) ($payload['razao_social'] ?? '')),
            'nome_fantasia' => trim((string) ($payload['nome_fantasia'] ?? '')),
            'email' => $this->normalizeEmail((string) ($payload['email'] ?? '')),
            'telefone1' => $this->normalizeDigits((string) ($payload['ddd_telefone_1'] ?? '')),
            'telefone2' => $this->normalizeDigits((string) ($payload['ddd_telefone_2'] ?? '')),
            'cep' => $this->normalizeDigits((string) ($payload['cep'] ?? '')),
            'endereco' => trim((string) ($payload['logradouro'] ?? '')),
            'numero' => trim((string) ($payload['numero'] ?? '')),
            'complemento' => trim((string) ($payload['complemento'] ?? '')),
            'bairro' => trim((string) ($payload['bairro'] ?? '')),
            'cidade' => trim((string) ($payload['municipio'] ?? '')),
            'uf' => strtoupper(trim((string) ($payload['uf'] ?? ''))),
            'ie_rg' => trim((string) ($payload['inscricao_estadual'] ?? '')),
            'situacao_cadastral' => trim((string) ($payload['descricao_situacao_cadastral'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function mapCnpjWsPayload(string $cnpjDigits, array $payload): ?array
    {
        $estabelecimento = isset($payload['estabelecimento']) && is_array($payload['estabelecimento'])
            ? $payload['estabelecimento']
            : null;

        if ($estabelecimento === null) {
            return null;
        }

        $telefone1 = $this->joinPhone(
            (string) ($estabelecimento['ddd1'] ?? ''),
            (string) ($estabelecimento['telefone1'] ?? '')
        );
        $telefone2 = $this->joinPhone(
            (string) ($estabelecimento['ddd2'] ?? ''),
            (string) ($estabelecimento['telefone2'] ?? '')
        );
        $tipoLogradouro = trim((string) ($estabelecimento['tipo_logradouro'] ?? ''));
        $logradouro = trim((string) ($estabelecimento['logradouro'] ?? ''));

        return [
            'cnpj' => $cnpjDigits,
            'razao_social' => trim((string) ($payload['razao_social'] ?? '')),
            'nome_fantasia' => trim((string) ($estabelecimento['nome_fantasia'] ?? '')),
            'email' => $this->normalizeEmail((string) ($estabelecimento['email'] ?? '')),
            'telefone1' => $telefone1,
            'telefone2' => $telefone2,
            'cep' => $this->normalizeDigits((string) ($estabelecimento['cep'] ?? '')),
            'endereco' => trim(trim($tipoLogradouro . ' ' . $logradouro)),
            'numero' => trim((string) ($estabelecimento['numero'] ?? '')),
            'complemento' => trim((string) ($estabelecimento['complemento'] ?? '')),
            'bairro' => trim((string) ($estabelecimento['bairro'] ?? '')),
            'cidade' => trim((string) (($estabelecimento['cidade']['nome'] ?? '') ?: '')),
            'uf' => strtoupper(trim((string) (($estabelecimento['estado']['sigla'] ?? '') ?: ''))),
            'ie_rg' => trim((string) ($estabelecimento['inscricoes_estaduais'][0]['inscricao_estadual'] ?? '')),
            'situacao_cadastral' => trim((string) ($estabelecimento['situacao_cadastral'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function enrichWithAdditionalProviderData(array $result, string $primaryProviderName): array
    {
        $cnpjDigits = (string) ($result['data']['cnpj'] ?? '');
        $baseData = isset($result['data']) && is_array($result['data'])
            ? $result['data']
            : [];

        if ($cnpjDigits === '' || $this->hasCompleteCoreData($baseData)) {
            return $result;
        }

        foreach ($this->providers as $provider) {
            $providerName = (string) ($provider['name'] ?? '');
            if ($providerName === '' || $providerName === $primaryProviderName) {
                continue;
            }

            $fallback = $this->queryProvider($provider, $cnpjDigits);
            if (($fallback['success'] ?? false) !== true || ! isset($fallback['data']) || ! is_array($fallback['data'])) {
                continue;
            }

            $baseData = $this->mergeLookupData($baseData, $fallback['data']);

            if ($this->hasCompleteCoreData($baseData)) {
                break;
            }
        }

        $result['data'] = $baseData;

        return $result;
    }

    /**
     * @param array<string, mixed> $baseData
     * @param array<string, mixed> $fallbackData
     * @return array<string, mixed>
     */
    private function mergeLookupData(array $baseData, array $fallbackData): array
    {
        foreach ($fallbackData as $key => $value) {
            if ($this->isLookupValueEmpty($baseData[$key] ?? null) && ! $this->isLookupValueEmpty($value)) {
                $baseData[$key] = $value;
            }
        }

        return $baseData;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasCompleteCoreData(array $data): bool
    {
        foreach (['razao_social', 'nome_fantasia', 'email', 'telefone1', 'cep', 'endereco', 'numero', 'bairro', 'cidade', 'uf', 'ie_rg'] as $field) {
            if ($this->isLookupValueEmpty($data[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function isLookupValueEmpty(mixed $value): bool
    {
        return trim((string) $value) === '';
    }

    private function normalizeDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function normalizeEmail(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    private function joinPhone(string $ddd, string $number): string
    {
        return $this->normalizeDigits($ddd . $number);
    }
}
