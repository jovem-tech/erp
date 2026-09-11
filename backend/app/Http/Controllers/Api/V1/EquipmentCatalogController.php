<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EquipmentBrand;
use App\Models\EquipmentModel;
use App\Models\EquipmentType;
use App\Services\EquipmentCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Tela "Equipamentos" em Cadastros: lista e mantem o catalogo de
 * tipos/marcas/modelos usado para padronizar o cadastro de aparelhos em
 * OS/orcamento/mobile (specs/044-catalogo-equipamentos-csv). Puramente
 * consultivo — nenhum endpoint aqui cria vinculo com `os`/`orcamentos`.
 *
 * Convivio com o EquipmentController existente: o quick-add do formulario de
 * equipamento (`POST equipments/brands|models`) continua intacto e chama o
 * mesmo `EquipmentCatalogService` por baixo — ver
 * `documentacao/04-governanca-ai/playbooks/catalogo-equipamentos-vinculo-rapido.md`.
 *
 * Nunca exclui de verdade — mesma convencao de EstoqueCatalogController: toda
 * "exclusao" aqui e' `ativo = false`, porque `equipamentos` referencia o
 * catalogo por FK.
 */
class EquipmentCatalogController extends BaseApiController
{
    public function __construct(private readonly EquipmentCatalogService $catalogService)
    {
    }

    public function indexModels(Request $request): JsonResponse
    {
        $this->authorize('equipamentos:visualizar');

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));

        $paginator = $this->catalogService->paginateModels([
            'search' => (string) $request->query('search', ''),
            'tipo_id' => $request->query('tipo_id', 0),
            'marca_id' => $request->query('marca_id', 0),
            'ativo' => (string) $request->query('ativo', ''),
        ], $perPage);

        return $this->success(
            ['modelos' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function storeModel(Request $request): JsonResponse
    {
        $this->authorize('equipamentos:criar');

        $validated = $request->validate([
            'tipo_id' => ['required', 'integer', 'min:1', Rule::exists('equipamentos_tipos', 'id')],
            'marca_id' => ['required', 'integer', 'min:1', Rule::exists('equipamentos_marcas', 'id')],
            'nome' => [
                'required', 'string', 'max:100',
                Rule::unique('equipamentos_modelos', 'nome')
                    ->where(fn ($query) => $query->where('marca_id', $request->input('marca_id'))),
            ],
        ]);

        try {
            $model = $this->catalogService->createModelInCatalog(
                (int) $validated['marca_id'],
                (string) $validated['nome'],
                (int) $validated['tipo_id']
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'EQUIPMENT_CATALOG_MODEL_INVALID', request: $request);
        }

        return $this->success(['modelo' => $this->mapModel($model)], Response::HTTP_CREATED, request: $request);
    }

    public function updateModel(Request $request, EquipmentModel $model): JsonResponse
    {
        $this->authorize('equipamentos:editar');

        $validated = $request->validate([
            'nome' => [
                'nullable', 'string', 'max:100',
                Rule::unique('equipamentos_modelos', 'nome')
                    ->where(fn ($query) => $query->where('marca_id', $model->marca_id))
                    ->ignore($model->id),
            ],
            'ativo' => ['nullable', 'boolean'],
        ]);

        try {
            if (array_key_exists('nome', $validated) && $validated['nome'] !== null) {
                $model = $this->catalogService->renameModel($model, (string) $validated['nome']);
            }

            if (array_key_exists('ativo', $validated) && $validated['ativo'] !== null) {
                $model = $this->catalogService->setModelActive($model, (bool) $validated['ativo']);
            }
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'EQUIPMENT_CATALOG_MODEL_INVALID', request: $request);
        }

        return $this->success(['modelo' => $this->mapModel($model->fresh() ?? $model)], request: $request);
    }

    public function indexBrands(Request $request): JsonResponse
    {
        $this->authorize('equipamentos:visualizar');

        $brands = $this->catalogService->listBrands([
            'search' => (string) $request->query('search', ''),
            'tipo_id' => $request->query('tipo_id', 0),
            'ativo' => (string) $request->query('ativo', ''),
        ]);

        return $this->success(['marcas' => $brands->all()], request: $request);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $this->authorize('equipamentos:criar');

        $validated = $request->validate([
            'tipo_id' => ['required', 'integer', 'min:1', Rule::exists('equipamentos_tipos', 'id')],
            'nome' => ['required', 'string', 'max:100', Rule::unique('equipamentos_marcas', 'nome')],
        ]);

        try {
            $brand = $this->catalogService->createBrandInCatalog((string) $validated['nome'], (int) $validated['tipo_id']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'EQUIPMENT_CATALOG_BRAND_INVALID', request: $request);
        }

        return $this->success(['marca' => $this->mapBrand($brand)], Response::HTTP_CREATED, request: $request);
    }

    public function updateBrand(Request $request, EquipmentBrand $brand): JsonResponse
    {
        $this->authorize('equipamentos:editar');

        $validated = $request->validate([
            'nome' => ['nullable', 'string', 'max:100', Rule::unique('equipamentos_marcas', 'nome')->ignore($brand->id)],
            'ativo' => ['nullable', 'boolean'],
        ]);

        try {
            if (array_key_exists('nome', $validated) && $validated['nome'] !== null) {
                $brand = $this->catalogService->renameBrand($brand, (string) $validated['nome']);
            }

            if (array_key_exists('ativo', $validated) && $validated['ativo'] !== null) {
                $brand = $this->catalogService->setBrandActive($brand, (bool) $validated['ativo']);
            }
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'EQUIPMENT_CATALOG_BRAND_INVALID', request: $request);
        }

        return $this->success(['marca' => $this->mapBrand($brand->fresh() ?? $brand)], request: $request);
    }

    public function indexTypes(Request $request): JsonResponse
    {
        $this->authorize('equipamentos:visualizar');

        return $this->success(['tipos' => $this->catalogService->listTypes()->all()], request: $request);
    }

    public function storeType(Request $request): JsonResponse
    {
        $this->authorize('equipamentos:criar');

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:100', Rule::unique('equipamentos_tipos', 'nome')],
        ]);

        try {
            $type = $this->catalogService->createType((string) $validated['nome']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'EQUIPMENT_CATALOG_TYPE_INVALID', request: $request);
        }

        return $this->success(['tipo' => $this->mapType($type)], Response::HTTP_CREATED, request: $request);
    }

    public function updateType(Request $request, EquipmentType $type): JsonResponse
    {
        $this->authorize('equipamentos:editar');

        $validated = $request->validate([
            'nome' => ['nullable', 'string', 'max:100', Rule::unique('equipamentos_tipos', 'nome')->ignore($type->id)],
            'ativo' => ['nullable', 'boolean'],
        ]);

        try {
            if (array_key_exists('nome', $validated) && $validated['nome'] !== null) {
                $type = $this->catalogService->renameType($type, (string) $validated['nome']);
            }

            if (array_key_exists('ativo', $validated) && $validated['ativo'] !== null) {
                $type = $this->catalogService->setTypeActive($type, (bool) $validated['ativo']);
            }
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'EQUIPMENT_CATALOG_TYPE_INVALID', request: $request);
        }

        return $this->success(['tipo' => $this->mapType($type->fresh() ?? $type)], request: $request);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorize('equipamentos:exportar');

        $filename = 'catalogo_equipamentos_'.now()->format('Y-m-d_H-i').'.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['id', 'tipo', 'marca', 'modelo', 'ativo', 'mesclar_com_id'], ';');

            foreach ($this->catalogService->exportRows() as $row) {
                fputcsv($handle, $row, ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function downloadCsvTemplate(Request $request): StreamedResponse
    {
        $this->authorize('equipamentos:importar');

        $filename = 'modelo_importacao_catalogo_equipamentos.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            foreach ($this->catalogService->csvTemplateRows() as $row) {
                fputcsv($handle, $row, ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function importCsv(Request $request): JsonResponse
    {
        $this->authorize('equipamentos:importar');

        $validated = $request->validate([
            'arquivo' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        try {
            $result = $this->catalogService->importCsv($validated['arquivo']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422, 'EQUIPMENT_CATALOG_IMPORT_INVALID', request: $request);
        } catch (Throwable $exception) {
            Log::error('Falha ao importar catalogo de equipamentos', ['erro' => $exception->getMessage()]);

            return $this->error('Nao foi possivel importar o arquivo agora.', 500, 'EQUIPMENT_CATALOG_IMPORT_FAILED', request: $request);
        }

        return $this->success(['resultado' => $result], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapModel(EquipmentModel $model): array
    {
        return [
            'id' => (int) $model->id,
            'nome' => (string) $model->nome,
            'ativo' => (bool) $model->ativo,
            'marca_id' => (int) $model->marca_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapBrand(EquipmentBrand $brand): array
    {
        return [
            'id' => (int) $brand->id,
            'nome' => (string) $brand->nome,
            'ativo' => (bool) $brand->ativo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapType(EquipmentType $type): array
    {
        return [
            'id' => (int) $type->id,
            'nome' => (string) $type->nome,
            'ativo' => (bool) $type->ativo,
        ];
    }
}
