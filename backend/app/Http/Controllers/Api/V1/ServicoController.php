<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\EquipmentType;
use App\Models\Servico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ServicoController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('servicos:visualizar');

        $search = trim((string) $request->query('search', $request->query('q', '')));
        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));
        $status = trim((string) $request->query('status', ''));
        $tipoEquipamento = trim((string) $request->query('tipo_equipamento', ''));

        $query = Servico::query();

        if ($search !== '') {
            $query->search($search);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($tipoEquipamento !== '') {
            $query->where('tipo_equipamento', $tipoEquipamento);
        }

        $paginator = $query
            ->orderBy('nome')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Servico $servico): array => $this->mapServicoSummary($servico))
        );

        return $this->success(
            ['servicos' => $paginator->items()],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function formData(Request $request): JsonResponse
    {
        $this->authorize('servicos:visualizar');

        return $this->success([
            'form' => [
                'tipos_equipamento' => Servico::tiposEquipamentoAtivos(),
                'status_options' => [
                    ['value' => 'ativo', 'label' => 'Ativo'],
                    ['value' => 'encerrado', 'label' => 'Encerrado'],
                ],
            ],
        ], request: $request);
    }

    public function show(Request $request, int $servico): JsonResponse
    {
        $this->authorize('servicos:visualizar');

        $service = Servico::query()->find($servico);

        if (! $service instanceof Servico) {
            return $this->error(
                'Serviço não encontrado.',
                404,
                'SERVICE_NOT_FOUND',
                null,
                request: $request
            );
        }

        return $this->success([
            'servico' => $this->mapServicoDetail($service),
        ], request: $request);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('servicos:criar');

        $payload = $this->validatedPayload($request);

        $service = Servico::query()->create($payload);

        return $this->success([
            'servico' => $this->mapServicoDetail($service->fresh() ?? $service),
        ], 201, request: $request);
    }

    public function update(Request $request, int $servico): JsonResponse
    {
        $this->authorize('servicos:editar');

        $service = Servico::query()->find($servico);

        if (! $service instanceof Servico) {
            return $this->error(
                'Serviço não encontrado.',
                404,
                'SERVICE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $service->fill($this->validatedPayload($request));
        $service->save();

        return $this->success([
            'servico' => $this->mapServicoDetail($service->fresh() ?? $service),
        ], request: $request);
    }

    public function close(Request $request, int $servico): JsonResponse
    {
        $this->authorize('servicos:encerrar');

        $service = Servico::query()->find($servico);

        if (! $service instanceof Servico) {
            return $this->error(
                'Serviço não encontrado.',
                404,
                'SERVICE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $service->forceFill([
            'status' => 'encerrado',
            'encerrado_em' => now(),
        ])->save();

        return $this->success([
            'servico' => $this->mapServicoDetail($service->fresh() ?? $service),
        ], request: $request);
    }

    public function destroy(Request $request, int $servico): JsonResponse
    {
        $this->authorize('servicos:excluir');

        $service = Servico::query()->find($servico);

        if (! $service instanceof Servico) {
            return $this->error(
                'Serviço não encontrado.',
                404,
                'SERVICE_NOT_FOUND',
                null,
                request: $request
            );
        }

        $service->delete();

        return $this->success([
            'deleted' => true,
            'servico_id' => $servico,
        ], request: $request);
    }

    public function exportCsv(Request $request)
    {
        $this->authorize('servicos:exportar');

        $services = Servico::query()
            ->orderBy('nome')
            ->get();

        $filename = 'servicos_' . now()->format('Y-m-d_H-i') . '.csv';

        return response()->streamDownload(function () use ($services): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['nome', 'descricao', 'tipo_equipamento', 'valor', 'tempo_padrao_horas', 'custo_direto_padrao', 'status', 'encerrado_em'], ';');

            foreach ($services as $service) {
                fputcsv($handle, [
                    (string) ($service->nome ?? ''),
                    (string) ($service->descricao ?? ''),
                    (string) ($service->tipo_equipamento ?? ''),
                    number_format((float) ($service->valor ?? 0), 2, ',', '.'),
                    number_format((float) ($service->tempo_padrao_horas ?? 0), 2, ',', '.'),
                    number_format((float) ($service->custo_direto_padrao ?? 0), 2, ',', '.'),
                    (string) ($service->status ?? ''),
                    $this->formatDateTime($service->encerrado_em) ?? '',
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function downloadCsvTemplate(Request $request)
    {
        $this->authorize('servicos:importar');

        $filename = 'modelo_importacao_servicos.csv';

        return response()->streamDownload(static function (): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['nome', 'descricao', 'tipo_equipamento', 'valor', 'tempo_padrao_horas', 'custo_direto_padrao', 'status'], ';');
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function importCsv(Request $request): JsonResponse
    {
        $this->authorize('servicos:importar');

        $validated = $request->validate([
            'arquivo' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['arquivo'];
        $imported = $this->importFromFile($file);

        return $this->success([
            'imported' => $imported,
        ], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request): array
    {
        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'descricao' => ['nullable', 'string'],
            'tipo_equipamento' => ['nullable', 'string', 'max:120'],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'tempo_padrao_horas' => ['nullable', 'numeric', 'min:0'],
            'custo_direto_padrao' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        return [
            'nome' => $this->normalizeText($validated['nome'] ?? ''),
            'descricao' => $this->normalizeText($validated['descricao'] ?? null),
            'tipo_equipamento' => $this->normalizeText($validated['tipo_equipamento'] ?? null),
            'valor' => $this->normalizeDecimal($validated['valor'] ?? 0),
            'tempo_padrao_horas' => $this->normalizeDecimal($validated['tempo_padrao_horas'] ?? 0),
            'custo_direto_padrao' => $this->normalizeDecimal($validated['custo_direto_padrao'] ?? 0),
            'status' => $this->normalizeStatus($validated['status'] ?? null),
        ];
    }

    private function importFromFile(UploadedFile $file): int
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return 0;
        }

        $headers = [];
        $imported = 0;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if ($headers === []) {
                $headers = array_map(static function ($value): string {
                    $header = (string) $value;
                    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

                    return mb_strtolower(trim($header));
                }, $row);
                continue;
            }

            $data = [];

            foreach ($headers as $index => $header) {
                $data[$header] = $row[$index] ?? null;
            }

            $payload = [
                'nome' => $this->normalizeText($data['nome'] ?? ''),
                'descricao' => $this->normalizeText($data['descricao'] ?? null),
                'tipo_equipamento' => $this->normalizeText($data['tipo_equipamento'] ?? null),
                'valor' => $this->normalizeDecimal($data['valor'] ?? 0),
                'tempo_padrao_horas' => $this->normalizeDecimal($data['tempo_padrao_horas'] ?? 0),
                'custo_direto_padrao' => $this->normalizeDecimal($data['custo_direto_padrao'] ?? 0),
                'status' => $this->normalizeStatus($data['status'] ?? null),
            ];

            if ($payload['nome'] === '') {
                continue;
            }

            Servico::query()->create($payload);
            $imported++;
        }

        fclose($handle);

        return $imported;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapServicoSummary(Servico $servico): array
    {
        return [
            'id' => (int) $servico->id,
            'nome' => (string) ($servico->nome ?? ''),
            'descricao' => (string) ($servico->descricao ?? ''),
            'tipo_equipamento' => (string) ($servico->tipo_equipamento ?? ''),
            'valor' => (float) ($servico->valor ?? 0),
            'tempo_padrao_horas' => (float) ($servico->tempo_padrao_horas ?? 0),
            'custo_direto_padrao' => (float) ($servico->custo_direto_padrao ?? 0),
            'status' => (string) ($servico->status ?? 'ativo'),
            'encerrado_em' => $this->formatDateTime($servico->encerrado_em),
            'created_at' => $this->formatDateTime($servico->created_at),
            'updated_at' => $this->formatDateTime($servico->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapServicoDetail(Servico $servico): array
    {
        return $this->mapServicoSummary($servico);
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return $value === null ? null : trim((string) $value);
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeDecimal(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = str_replace(['.', ','], ['', '.'], (string) $value);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = mb_strtolower(trim((string) $value));

        return in_array($status, ['encerrado', 'inativo', 'ativo'], true) ? $status : 'ativo';
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (Throwable) {
                return $value;
            }
        }

        return null;
    }
}
