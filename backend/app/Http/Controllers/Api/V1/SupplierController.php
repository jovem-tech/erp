<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierCnpjLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SupplierController extends BaseApiController
{
    public function __construct(
        private readonly SupplierCnpjLookupService $cnpjLookupService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('fornecedores:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));
        $active = $request->query('active');

        $query = Supplier::query();

        if ($search !== '') {
            $term = '%' . mb_strtolower($search) . '%';
            $query->where(static function ($builder) use ($term): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(nome_fantasia, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(razao_social, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(cnpj_cpf, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(ie_rg, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(telefone1, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(telefone2, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(cep, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(endereco, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(numero, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(complemento, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(bairro, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(cidade, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(uf, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(observacoes, \'\')) LIKE ?', [$term]);
            });
        }

        if ($active !== null && $active !== '') {
            $query->where('ativo', filter_var($active, FILTER_VALIDATE_BOOL));
        }

        $paginator = $query
            ->orderBy('nome_fantasia')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Supplier $supplier): array => $this->mapSupplierSummary($supplier))
        );

        return $this->success(
            ['suppliers' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('fornecedores:criar');

        $payload = $this->validatedSupplierPayload($request);
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $supplier = Supplier::query()->create($payload);

        return $this->success(
            ['supplier' => $this->mapSupplierDetail($supplier)],
            201,
            request: $request
        );
    }

    public function show(Request $request, int $supplier): JsonResponse
    {
        $this->authorize('fornecedores:visualizar');

        $supplierModel = Supplier::query()->find($supplier);

        if (! $supplierModel instanceof Supplier) {
            return $this->error(
                'Fornecedor nao encontrado.',
                404,
                'SUPPLIER_NOT_FOUND',
                null,
                request: $request
            );
        }

        return $this->success(
            ['supplier' => $this->mapSupplierDetail($supplierModel)],
            request: $request
        );
    }

    public function update(Request $request, int $supplier): JsonResponse
    {
        $this->authorize('fornecedores:editar');

        $supplierModel = Supplier::query()->find($supplier);

        if (! $supplierModel instanceof Supplier) {
            return $this->error(
                'Fornecedor nao encontrado.',
                404,
                'SUPPLIER_NOT_FOUND',
                null,
                request: $request
            );
        }

        $payload = $this->validatedSupplierPayload($request);
        $payload['updated_at'] = now();

        $supplierModel->fill($payload);
        $supplierModel->save();

        return $this->success(
            ['supplier' => $this->mapSupplierDetail($supplierModel->fresh() ?? $supplierModel)],
            request: $request
        );
    }

    public function destroy(Request $request, int $supplier): JsonResponse
    {
        $this->authorize('fornecedores:excluir');

        $supplierModel = Supplier::query()->find($supplier);

        if (! $supplierModel instanceof Supplier) {
            return $this->error(
                'Fornecedor nao encontrado.',
                404,
                'SUPPLIER_NOT_FOUND',
                null,
                request: $request
            );
        }

        $supplierModel->delete();

        return $this->success([
            'deleted' => true,
            'supplier_id' => $supplier,
        ], request: $request);
    }

    public function close(Request $request, int $supplier): JsonResponse
    {
        $this->authorize('fornecedores:encerrar');

        $supplierModel = Supplier::query()->find($supplier);

        if (! $supplierModel instanceof Supplier) {
            return $this->error(
                'Fornecedor nao encontrado.',
                404,
                'SUPPLIER_NOT_FOUND',
                null,
                request: $request
            );
        }

        $supplierModel->forceFill([
            'ativo' => false,
            'updated_at' => now(),
        ])->save();

        return $this->success(
            ['supplier' => $this->mapSupplierDetail($supplierModel->fresh() ?? $supplierModel)],
            request: $request
        );
    }

    public function lookupCnpj(Request $request): JsonResponse
    {
        $this->authorize('fornecedores:visualizar');

        $validated = $request->validate([
            'cnpj' => ['required', 'string', 'max:32'],
        ]);

        $lookup = $this->cnpjLookupService->lookup((string) $validated['cnpj']);

        return $this->success([
            'lookup' => $lookup,
        ], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSupplierSummary(Supplier $supplier): array
    {
        return [
            'id' => (int) $supplier->id,
            'tipo_pessoa' => (string) ($supplier->tipo_pessoa ?? ''),
            'nome_fantasia' => (string) ($supplier->nome_fantasia ?? ''),
            'razao_social' => (string) ($supplier->razao_social ?? ''),
            'cnpj_cpf' => (string) ($supplier->cnpj_cpf ?? ''),
            'telefone1' => (string) ($supplier->telefone1 ?? ''),
            'ativo' => (bool) ($supplier->ativo ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSupplierDetail(Supplier $supplier): array
    {
        return [
            'id' => (int) $supplier->id,
            'tipo_pessoa' => (string) ($supplier->tipo_pessoa ?? ''),
            'nome_fantasia' => (string) ($supplier->nome_fantasia ?? ''),
            'razao_social' => (string) ($supplier->razao_social ?? ''),
            'cnpj_cpf' => (string) ($supplier->cnpj_cpf ?? ''),
            'ie_rg' => (string) ($supplier->ie_rg ?? ''),
            'email' => (string) ($supplier->email ?? ''),
            'telefone1' => (string) ($supplier->telefone1 ?? ''),
            'telefone2' => (string) ($supplier->telefone2 ?? ''),
            'cep' => (string) ($supplier->cep ?? ''),
            'endereco' => (string) ($supplier->endereco ?? ''),
            'numero' => (string) ($supplier->numero ?? ''),
            'complemento' => (string) ($supplier->complemento ?? ''),
            'bairro' => (string) ($supplier->bairro ?? ''),
            'cidade' => (string) ($supplier->cidade ?? ''),
            'uf' => (string) ($supplier->uf ?? ''),
            'observacoes' => (string) ($supplier->observacoes ?? ''),
            'ativo' => (bool) ($supplier->ativo ?? false),
            'created_at' => $this->formatDateTime($supplier->created_at),
            'updated_at' => $this->formatDateTime($supplier->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSupplierPayload(Request $request): array
    {
        $validated = $request->validate([
            'tipo_pessoa' => ['required', 'string', 'max:20'],
            'nome_fantasia' => ['required', 'string', 'max:100'],
            'razao_social' => ['nullable', 'string', 'max:100'],
            'cnpj_cpf' => ['nullable', 'string', 'max:20'],
            'ie_rg' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],
            'telefone1' => ['required', 'string', 'max:20'],
            'telefone2' => ['nullable', 'string', 'max:20'],
            'cep' => ['nullable', 'string', 'max:10'],
            'endereco' => ['nullable', 'string', 'max:100'],
            'numero' => ['nullable', 'string', 'max:10'],
            'complemento' => ['nullable', 'string', 'max:50'],
            'bairro' => ['nullable', 'string', 'max:50'],
            'cidade' => ['nullable', 'string', 'max:50'],
            'uf' => ['nullable', 'string', 'max:2'],
            'observacoes' => ['nullable', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'tipo_pessoa' => 'tipo de pessoa',
            'nome_fantasia' => 'nome fantasia',
            'razao_social' => 'razao social',
            'cnpj_cpf' => 'CNPJ/CPF',
            'ie_rg' => 'inscricao estadual',
            'email' => 'e-mail',
            'telefone1' => 'telefone principal',
            'telefone2' => 'telefone secundario',
            'cep' => 'CEP',
            'endereco' => 'endereco',
            'numero' => 'numero',
            'complemento' => 'complemento',
            'bairro' => 'bairro',
            'cidade' => 'cidade',
            'uf' => 'UF',
            'observacoes' => 'observacoes',
            'ativo' => 'status',
        ]);

        $payload = [];

        foreach ($validated as $field => $value) {
            if ($field === 'ativo') {
                continue;
            }

            $payload[$field] = $this->normalizeFieldValue($value);
        }

        $payload['tipo_pessoa'] = $payload['tipo_pessoa'] !== null && $payload['tipo_pessoa'] !== ''
            ? $payload['tipo_pessoa']
            : 'juridica';
        $payload['ativo'] = $request->boolean('ativo', true);

        return $payload;
    }

    private function normalizeFieldValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return (string) $value;
    }
}
