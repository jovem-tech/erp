<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FinanceiroCartaoBandeira;
use App\Models\FinanceiroCartaoOperadora;
use App\Models\FinanceiroCartaoTaxa;
use App\Services\Financeiro\FinanceiroCartaoService;
use App\Services\Financeiro\FinanceiroGatewayTaxaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class FinanceiroCartaoController extends BaseApiController
{
    public function __construct(
        private readonly FinanceiroCartaoService $financeiroCartaoService,
        private readonly FinanceiroGatewayTaxaService $gatewayTaxaService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        $operadoras = FinanceiroCartaoOperadora::query()
            ->withCount('taxas')
            ->orderBy('ordem_exibicao')
            ->orderBy('nome')
            ->get()
            ->map(fn (FinanceiroCartaoOperadora $operadora): array => $this->serializeOperadora($operadora))
            ->all();

        $bandeiras = FinanceiroCartaoBandeira::query()
            ->orderBy('ordem_exibicao')
            ->orderBy('nome')
            ->get()
            ->map(fn (FinanceiroCartaoBandeira $bandeira): array => $this->serializeBandeira($bandeira))
            ->all();

        $taxas = FinanceiroCartaoTaxa::query()
            ->with(['operadora', 'bandeira'])
            ->orderBy('operadora_id')
            ->orderBy('bandeira_id')
            ->orderBy('parcelas_inicial')
            ->orderBy('parcelas_final')
            ->get()
            ->map(fn (FinanceiroCartaoTaxa $taxa): array => $this->serializeTaxa($taxa))
            ->all();

        return $this->success([
            'cartoes' => [
                'summary' => [
                    'operadoras_total' => count($operadoras),
                    'operadoras_ativas' => count(array_filter($operadoras, static fn (array $row): bool => (bool) ($row['ativo'] ?? false))),
                    'bandeiras_total' => count($bandeiras),
                    'bandeiras_ativas' => count(array_filter($bandeiras, static fn (array $row): bool => (bool) ($row['ativo'] ?? false))),
                    'taxas_total' => count($taxas),
                    'taxas_ativas' => count(array_filter($taxas, static fn (array $row): bool => (bool) ($row['ativo'] ?? false))),
                ],
                'operadoras' => $operadoras,
                'bandeiras' => $bandeiras,
                'taxas' => $taxas,
                'simulador_catalogo' => $this->financeiroCartaoService->buildActiveDataset(),
            ],
            'gateway' => $this->gatewayTaxaService->payload(),
        ], request: $request);
    }

    public function simulate(Request $request): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        $validated = $request->validate([
            'valor_bruto' => ['required', 'numeric', 'min:0.01'],
            'operadora_id' => ['required', 'integer', 'min:1', 'exists:financeiro_cartao_operadoras,id'],
            'bandeira_id' => ['nullable', 'integer', 'min:1', 'exists:financeiro_cartao_bandeiras,id'],
            'modalidade' => ['nullable', 'string', 'max:20'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'parcelas' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);

        try {
            $simulation = $this->financeiroCartaoService->simulate($validated);
        } catch (Throwable $exception) {
            return $this->error(
                $exception->getMessage() ?: 'Não foi possível simular o recebimento agora.',
                422,
                'FINANCEIRO_CARTAO_SIMULATION_FAILED',
                null,
                request: $request
            );
        }

        return $this->success(['simulation' => $simulation], request: $request);
    }

    public function storeOperadora(Request $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'ordem_exibicao' => ['nullable', 'integer', 'min:0'],
            'prazo_padrao_dias' => ['nullable', 'integer', 'min:0'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        try {
            $operadora = FinanceiroCartaoOperadora::create([
                'nome' => trim((string) $validated['nome']),
                'descricao' => trim((string) ($validated['descricao'] ?? '')) !== '' ? trim((string) $validated['descricao']) : null,
                'ordem_exibicao' => (int) ($validated['ordem_exibicao'] ?? 0),
                'prazo_padrao_dias' => (int) ($validated['prazo_padrao_dias'] ?? 30),
                'ativo' => $request->boolean('ativo', true),
            ])->loadCount('taxas');
        } catch (Throwable $exception) {
            return $this->error(
                $exception->getMessage() ?: 'Não foi possível salvar a operadora.',
                422,
                'FINANCEIRO_CARTAO_OPERADORA_SAVE_FAILED',
                null,
                request: $request
            );
        }

        return $this->success(['operadora' => $this->serializeOperadora($operadora)], 201, request: $request);
    }

    public function updateOperadora(Request $request, FinanceiroCartaoOperadora $operadora): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'ordem_exibicao' => ['nullable', 'integer', 'min:0'],
            'prazo_padrao_dias' => ['nullable', 'integer', 'min:0'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        try {
            $operadora->update([
                'nome' => trim((string) $validated['nome']),
                'descricao' => trim((string) ($validated['descricao'] ?? '')) !== '' ? trim((string) $validated['descricao']) : null,
                'ordem_exibicao' => (int) ($validated['ordem_exibicao'] ?? 0),
                'prazo_padrao_dias' => (int) ($validated['prazo_padrao_dias'] ?? 30),
                'ativo' => $request->boolean('ativo', true),
            ]);
            $operadora->loadCount('taxas');
        } catch (Throwable $exception) {
            return $this->error(
                $exception->getMessage() ?: 'Não foi possível atualizar a operadora.',
                422,
                'FINANCEIRO_CARTAO_OPERADORA_UPDATE_FAILED',
                null,
                request: $request
            );
        }

        return $this->success(['operadora' => $this->serializeOperadora($operadora->refresh())], request: $request);
    }

    public function destroyOperadora(Request $request, FinanceiroCartaoOperadora $operadora): JsonResponse
    {
        $this->authorize('financeiro:excluir');

        $operadora->update(['ativo' => false]);

        return $this->success(['operadora' => $this->serializeOperadora($operadora->refresh()->loadCount('taxas'))], request: $request);
    }

    public function storeBandeira(Request $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'ordem_exibicao' => ['nullable', 'integer', 'min:0'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        try {
            $bandeira = FinanceiroCartaoBandeira::create([
                'nome' => trim((string) $validated['nome']),
                'ordem_exibicao' => (int) ($validated['ordem_exibicao'] ?? 0),
                'ativo' => $request->boolean('ativo', true),
            ]);
        } catch (Throwable $exception) {
            return $this->error(
                $exception->getMessage() ?: 'Não foi possível salvar a bandeira.',
                422,
                'FINANCEIRO_CARTAO_BANDEIRA_SAVE_FAILED',
                null,
                request: $request
            );
        }

        return $this->success(['bandeira' => $this->serializeBandeira($bandeira)], 201, request: $request);
    }

    public function updateBandeira(Request $request, FinanceiroCartaoBandeira $bandeira): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:80'],
            'ordem_exibicao' => ['nullable', 'integer', 'min:0'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $bandeira->update([
            'nome' => trim((string) $validated['nome']),
            'ordem_exibicao' => (int) ($validated['ordem_exibicao'] ?? 0),
            'ativo' => $request->boolean('ativo', true),
        ]);

        return $this->success(['bandeira' => $this->serializeBandeira($bandeira->refresh())], request: $request);
    }

    public function destroyBandeira(Request $request, FinanceiroCartaoBandeira $bandeira): JsonResponse
    {
        $this->authorize('financeiro:excluir');

        $bandeira->update(['ativo' => false]);

        return $this->success(['bandeira' => $this->serializeBandeira($bandeira->refresh())], request: $request);
    }

    public function storeTaxa(Request $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $request->validate($this->taxaRules());

        try {
            $taxa = FinanceiroCartaoTaxa::create($this->normalizedTaxaPayload($validated, $request));
            $taxa->load(['operadora', 'bandeira']);
        } catch (Throwable $exception) {
            return $this->error(
                $exception->getMessage() ?: 'Não foi possível salvar a taxa.',
                422,
                'FINANCEIRO_CARTAO_TAXA_SAVE_FAILED',
                null,
                request: $request
            );
        }

        return $this->success(['taxa' => $this->serializeTaxa($taxa)], 201, request: $request);
    }

    public function updateTaxa(Request $request, FinanceiroCartaoTaxa $taxa): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $request->validate($this->taxaRules());

        try {
            $taxa->update($this->normalizedTaxaPayload($validated, $request));
            $taxa->load(['operadora', 'bandeira']);
        } catch (Throwable $exception) {
            return $this->error(
                $exception->getMessage() ?: 'Não foi possível atualizar a taxa.',
                422,
                'FINANCEIRO_CARTAO_TAXA_UPDATE_FAILED',
                null,
                request: $request
            );
        }

        return $this->success(['taxa' => $this->serializeTaxa($taxa->refresh()->load(['operadora', 'bandeira']))], request: $request);
    }

    public function destroyTaxa(Request $request, FinanceiroCartaoTaxa $taxa): JsonResponse
    {
        $this->authorize('financeiro:excluir');

        $taxa->update(['ativo' => false]);

        return $this->success(['taxa' => $this->serializeTaxa($taxa->refresh()->load(['operadora', 'bandeira']))], request: $request);
    }

    public function storeGatewayTaxa(Request $request): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $this->validateGatewayTaxa($request);

        try {
            $gatewayTaxa = $this->gatewayTaxaService->save($this->gatewayTaxaPayload($validated, null, $request));
        } catch (Throwable $exception) {
            return $this->error(
                $exception->getMessage() ?: 'Não foi possível salvar a taxa online.',
                422,
                'FINANCEIRO_CARTAO_GATEWAY_SAVE_FAILED',
                null,
                request: $request
            );
        }

        return $this->success(['gateway_taxa' => $gatewayTaxa], 201, request: $request);
    }

    public function updateGatewayTaxa(Request $request, int $gatewayTaxa): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $this->validateGatewayTaxa($request);

        try {
            $saved = $this->gatewayTaxaService->save($this->gatewayTaxaPayload($validated, $gatewayTaxa, $request));
        } catch (Throwable $exception) {
            return $this->error(
                $exception->getMessage() ?: 'Não foi possível atualizar a taxa online.',
                422,
                'FINANCEIRO_CARTAO_GATEWAY_UPDATE_FAILED',
                null,
                request: $request
            );
        }

        return $this->success(['gateway_taxa' => $saved], request: $request);
    }

    public function destroyGatewayTaxa(Request $request, int $gatewayTaxa): JsonResponse
    {
        $this->authorize('financeiro:excluir');

        try {
            $this->gatewayTaxaService->delete($gatewayTaxa);
        } catch (Throwable $exception) {
            return $this->error(
                $exception->getMessage() ?: 'Não foi possível desativar a taxa online.',
                422,
                'FINANCEIRO_CARTAO_GATEWAY_DELETE_FAILED',
                null,
                request: $request
            );
        }

        return $this->success(['gateway_taxa' => $this->gatewayTaxaService->find($gatewayTaxa)], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOperadora(FinanceiroCartaoOperadora $operadora): array
    {
        return [
            'id' => $operadora->id,
            'nome' => $operadora->nome,
            'descricao' => $operadora->descricao,
            'ordem_exibicao' => (int) $operadora->ordem_exibicao,
            'prazo_padrao_dias' => (int) $operadora->prazo_padrao_dias,
            'ativo' => (bool) $operadora->ativo,
            'taxas_count' => (int) ($operadora->taxas_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBandeira(FinanceiroCartaoBandeira $bandeira): array
    {
        return [
            'id' => $bandeira->id,
            'nome' => $bandeira->nome,
            'ordem_exibicao' => (int) $bandeira->ordem_exibicao,
            'ativo' => (bool) $bandeira->ativo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTaxa(FinanceiroCartaoTaxa $taxa): array
    {
        return [
            'id' => $taxa->id,
            'operadora_id' => (int) $taxa->operadora_id,
            'operadora_nome' => $taxa->operadora?->nome,
            'bandeira_id' => $taxa->bandeira_id !== null ? (int) $taxa->bandeira_id : null,
            'bandeira_nome' => $taxa->bandeira?->nome,
            'modalidade' => $taxa->modalidade,
            'parcelas_inicial' => (int) $taxa->parcelas_inicial,
            'parcelas_final' => (int) $taxa->parcelas_final,
            'taxa_percentual' => round((float) $taxa->taxa_percentual, 4),
            'taxa_fixa' => round((float) $taxa->taxa_fixa, 2),
            'prazo_recebimento_dias' => (int) $taxa->prazo_recebimento_dias,
            'observacoes' => $taxa->observacoes,
            'ativo' => (bool) $taxa->ativo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGatewayTaxa(Request $request): array
    {
        $catalog = $this->gatewayTaxaService->catalog();
        $allowedProviders = array_keys($catalog);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in($allowedProviders)],
            'modalidade' => ['required', 'string', 'max:30'],
            'taxa_percentual' => ['nullable', 'numeric', 'min:0'],
            'taxa_fixa' => ['nullable', 'numeric', 'min:0'],
            'ordem_exibicao' => ['nullable', 'integer', 'min:0'],
            'observacoes' => ['nullable', 'string', 'max:255'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        $provider = (string) $validated['provider'];
        $allowedModes = array_map(
            static fn (array $mode): string => (string) ($mode['code'] ?? ''),
            $catalog[$provider]['modes'] ?? []
        );

        if (! in_array((string) $validated['modalidade'], $allowedModes, true)) {
            throw new RuntimeException('Selecione uma modalidade válida para o gateway informado.');
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function gatewayTaxaPayload(array $validated, ?int $id, Request $request): array
    {
        return [
            'id' => $id,
            'provider' => (string) $validated['provider'],
            'modalidade' => (string) $validated['modalidade'],
            'taxa_percentual' => (float) ($validated['taxa_percentual'] ?? 0),
            'taxa_fixa' => (float) ($validated['taxa_fixa'] ?? 0),
            'ordem_exibicao' => (int) ($validated['ordem_exibicao'] ?? 0),
            'observacoes' => trim((string) ($validated['observacoes'] ?? '')) !== '' ? trim((string) $validated['observacoes']) : null,
            'ativo' => $request->boolean('ativo', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taxaRules(): array
    {
        return [
            'operadora_id' => ['required', 'integer', 'min:1', 'exists:financeiro_cartao_operadoras,id'],
            'bandeira_id' => ['nullable', 'integer', 'min:1', 'exists:financeiro_cartao_bandeiras,id'],
            'modalidade' => ['required', 'string', Rule::in([
                FinanceiroCartaoTaxa::MODALIDADE_CREDITO,
                FinanceiroCartaoTaxa::MODALIDADE_DEBITO,
            ])],
            'parcelas_inicial' => ['required', 'integer', 'min:1', 'max:24'],
            'parcelas_final' => ['required', 'integer', 'min:1', 'max:24', 'gte:parcelas_inicial'],
            'taxa_percentual' => ['required', 'numeric', 'min:0'],
            'taxa_fixa' => ['required', 'numeric', 'min:0'],
            'prazo_recebimento_dias' => ['required', 'integer', 'min:0'],
            'observacoes' => ['nullable', 'string', 'max:255'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function normalizedTaxaPayload(array $validated, Request $request): array
    {
        return [
            'operadora_id' => (int) $validated['operadora_id'],
            'bandeira_id' => ! empty($validated['bandeira_id']) ? (int) $validated['bandeira_id'] : null,
            'modalidade' => (string) $validated['modalidade'],
            'parcelas_inicial' => (int) $validated['parcelas_inicial'],
            'parcelas_final' => (int) $validated['parcelas_final'],
            'taxa_percentual' => (float) $validated['taxa_percentual'],
            'taxa_fixa' => (float) $validated['taxa_fixa'],
            'prazo_recebimento_dias' => (int) $validated['prazo_recebimento_dias'],
            'observacoes' => trim((string) ($validated['observacoes'] ?? '')) !== '' ? trim((string) $validated['observacoes']) : null,
            'ativo' => $request->boolean('ativo', true),
        ];
    }
}
