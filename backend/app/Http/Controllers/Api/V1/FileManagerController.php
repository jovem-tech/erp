<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Files\FileCategory;
use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileLifecycleStatus;
use App\Enums\Files\FileMigrationStatus;
use App\Enums\Files\FileSecurityStatus;
use App\Http\Requests\Api\V1\FileAdminStepUpRequest;
use App\Models\Files\FileScanFinding;
use App\Models\Files\FileScanRun;
use App\Models\Files\ManagedFile;
use App\Models\User;
use App\Services\Auth\AdminCredentialVerifier;
use App\Services\Auth\RbacAuthorizationService;
use App\Services\Files\AutomaticFileSyncService;
use App\Services\Files\FileAuthorizationRegistry;
use App\Services\Files\FileManagerMetrics;
use App\Services\Files\FileStateMachine;
use App\Services\Files\ManagedFileArchiveService;
use App\Services\Files\ManagedFileDeliveryService;
use App\Services\Files\PdfThumbnailService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileManagerController extends BaseApiController
{
    public function __construct(
        private readonly RbacAuthorizationService $rbac,
        private readonly FileAuthorizationRegistry $authorizers,
        private readonly AutomaticFileSyncService $synchronizer,
        private readonly ManagedFileDeliveryService $delivery,
        private readonly PdfThumbnailService $pdfThumbnails,
        private readonly ManagedFileArchiveService $archives,
        private readonly FileStateMachine $states,
        private readonly AdminCredentialVerifier $adminVerifier,
        private readonly FileManagerMetrics $metrics
    ) {}

    public function requestSynchronization(Request $request): JsonResponse
    {
        $this->authorize('arquivos:administrar');
        if (! (bool) config('file-manager.automatic_sync.enabled', false)) {
            return $this->error(
                'Sincronização de arquivos desabilitada.',
                409,
                'FILE_SYNC_DISABLED',
                request: $request
            );
        }

        try {
            $result = $this->synchronizer->requestManualSynchronization((int) $this->actor($request)->id);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error(
                'Não foi possível solicitar a sincronização.',
                503,
                'FILE_SYNC_REQUEST_FAILED',
                request: $request
            );
        }

        return $this->success(
            $result,
            $result['queued'] ? 202 : 200,
            request: $request
        );
    }

    public function dashboard(Request $request): JsonResponse
    {
        $this->authorize('arquivos:listar');

        $mode = (string) config('file-manager.mode', 'off');
        $enabledCategories = array_values(array_filter(
            (array) config('file-manager.enabled_categories', []),
            static fn (mixed $category): bool => is_string($category) && $category !== ''
        ));
        $allowWrites = (bool) config('file-manager.kill_switches.allow_writes', false);

        $byCategory = ManagedFile::query()
            ->selectRaw('category, COUNT(*) AS file_count, COALESCE(SUM(size_bytes), 0) AS total_bytes')
            ->groupBy('category')
            ->orderBy('category')
            ->get()
            ->map(static fn (ManagedFile $row): array => [
                'category' => (string) $row->category,
                'file_count' => (int) $row->file_count,
                'total_bytes' => (int) $row->total_bytes,
            ])
            ->values()
            ->all();

        $metricSnapshot = [];
        foreach (FileCategory::cases() as $category) {
            $metricSnapshot[$category->value] = $this->metrics->snapshot($category);
        }

        $lastRun = FileScanRun::query()->latest('id')->first();
        $lastAutomaticSync = FileScanRun::query()
            ->whereIn('process_name', ['automatic_sync', 'manual_sync'])
            ->latest('id')
            ->first();

        return $this->success([
            'totals' => [
                'files' => ManagedFile::query()->count(),
                'bytes' => (int) ManagedFile::query()->sum('size_bytes'),
                'quarantined' => ManagedFile::query()->where('security_status', FileSecurityStatus::Quarantined->value)->count(),
                'trashed' => ManagedFile::query()->where('lifecycle_status', FileLifecycleStatus::Trashed->value)->count(),
                'integrity_issues' => ManagedFile::query()->whereIn('integrity_status', [FileIntegrityStatus::Missing->value, FileIntegrityStatus::Corrupted->value])->count(),
                'open_findings' => FileScanFinding::query()->where('resolution_status', 'open')->count(),
            ],
            'by_category' => $byCategory,
            'metrics_today' => $metricSnapshot,
            'operation' => [
                'mode' => $mode,
                'enabled_categories' => $enabledCategories,
                'observing' => in_array($mode, ['observe', 'shadow', 'hybrid'], true),
                'cataloging_new_files' => in_array($mode, ['shadow', 'hybrid'], true) && $enabledCategories !== [],
                'central_writes_enabled' => $mode === 'hybrid' && $allowWrites && $enabledCategories !== [],
                'scanner_enabled' => (bool) config('file-manager.kill_switches.allow_scanner', false),
                'automatic_sync_enabled' => (bool) config('file-manager.automatic_sync.enabled', false),
                'automatic_sync_interval_minutes' => (int) config('file-manager.automatic_sync.interval_minutes', 5),
            ],
            'state_mutations_enabled' => (bool) config('file-manager.kill_switches.allow_admin_state_mutations', false),
            'last_scan_run' => $lastRun === null ? null : $this->mapScanRun($lastRun),
            'last_automatic_sync' => $lastAutomaticSync === null ? null : $this->mapScanRun($lastAutomaticSync),
        ], request: $request);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('arquivos:listar');
        $validated = $request->validate($this->indexRules());
        $perPage = min(100, max(1, (int) ($validated['per_page'] ?? 25)));

        $query = ManagedFile::query()
            ->with([
                'links' => static fn ($query) => $query
                    ->whereNull('unlinked_at')
                    ->orderByDesc('is_current')
                    ->orderByDesc('id'),
            ])
            ->withCount([
                'links as active_links_count' => static fn (Builder $query): Builder => $query->whereNull('unlinked_at'),
            ]);
        foreach (['category', 'lifecycle_status', 'integrity_status', 'security_status', 'migration_status'] as $filter) {
            if (isset($validated[$filter]) && $validated[$filter] !== '') {
                $query->where($filter, $validated[$filter]);
            }
        }
        if (! empty($validated['created_from'])) {
            $query->whereDate('created_at', '>=', $validated['created_from']);
        }
        if (! empty($validated['created_to'])) {
            $query->whereDate('created_at', '<=', $validated['created_to']);
        }
        if (! empty($validated['q'])) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $validated['q']));
            $query->where(static function (Builder $query) use ($search): void {
                $query->where('uuid', $search)
                    ->orWhere('safe_download_name', 'like', '%'.$search.'%');
            });
        }

        $paginator = $query->orderByDesc('id')->paginate($perPage);

        $actor = $this->actor($request);
        $linkedClients = $this->resolveLinkedClients($paginator->getCollection(), $actor);

        return $this->success(
            $paginator->getCollection()->map(fn (ManagedFile $file): array => $this->summary(
                $file,
                $linkedClients[(int) $file->id] ?? null
            ))->all(),
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function show(Request $request, string $fileUuid): JsonResponse
    {
        $this->authorize('arquivos:metadados');
        $file = $this->findFile($fileUuid);
        $file->load([
            'links' => static fn ($query) => $query->whereNull('unlinked_at')->orderByDesc('id')->limit(100),
            'events' => static fn ($query) => $query->orderByDesc('id')->limit(100),
            'findings' => static fn ($query) => $query->orderByDesc('id')->limit(100),
        ]);

        return $this->success($this->detail($file, $this->actor($request)), request: $request);
    }

    public function download(Request $request, string $fileUuid): StreamedResponse|JsonResponse
    {
        $this->authorize('arquivos:baixar');

        return $this->stream($request, $fileUuid, false);
    }

    public function preview(Request $request, string $fileUuid): StreamedResponse|JsonResponse
    {
        $this->authorize('arquivos:baixar');

        return $this->stream($request, $fileUuid, true);
    }

    public function thumbnail(Request $request, string $fileUuid): BinaryFileResponse|JsonResponse
    {
        $this->authorize('arquivos:baixar');
        $file = $this->findFile($fileUuid);
        if (! $this->authorizers->allows($this->actor($request), $file, 'download')) {
            abort(404);
        }

        try {
            $thumbnail = $this->pdfThumbnails->firstPage($file);
        } catch (\UnexpectedValueException $exception) {
            return $this->error($exception->getMessage(), 415, 'FILE_THUMBNAIL_NOT_SUPPORTED', request: $request);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), 409, 'FILE_THUMBNAIL_BLOCKED', request: $request);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error(
                'Miniatura temporariamente indisponivel.',
                503,
                'FILE_THUMBNAIL_UNAVAILABLE',
                request: $request
            );
        }

        $response = response()->file($thumbnail['absolute_path'], [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="pagina-1.png"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge($thumbnail['cache_seconds']);
        $response->setEtag($thumbnail['etag']);

        return $response;
    }

    public function downloadBatch(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->authorize('arquivos:baixar');
        $validated = $request->validate([
            'file_uuids' => ['required', 'array', 'min:1', 'max:'.(int) config('file-manager.batch_download.max_files', 50)],
            'file_uuids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $files = $this->findFiles((array) $validated['file_uuids']);
        $actor = $this->actor($request);
        foreach ($files as $file) {
            if (! $this->authorizers->allows($actor, $file, 'download')) {
                abort(404);
            }
        }

        try {
            $archive = $this->archives->build($files);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), 422, 'FILE_BATCH_DOWNLOAD_INVALID', request: $request);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error('Não foi possível preparar o pacote de arquivos.', 500, 'FILE_BATCH_DOWNLOAD_FAILED', request: $request);
        }

        return response()->download(
            $archive['absolute_path'],
            $archive['file_name'],
            [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]
        )->deleteFileAfterSend(true);
    }

    public function archive(FileAdminStepUpRequest $request, string $fileUuid): JsonResponse
    {
        return $this->mutate($request, $fileUuid, 'archive');
    }

    public function restore(FileAdminStepUpRequest $request, string $fileUuid): JsonResponse
    {
        return $this->mutate($request, $fileUuid, 'restore');
    }

    public function quarantine(FileAdminStepUpRequest $request, string $fileUuid): JsonResponse
    {
        return $this->mutate($request, $fileUuid, 'quarantine');
    }

    public function releaseQuarantine(FileAdminStepUpRequest $request, string $fileUuid): JsonResponse
    {
        return $this->mutate($request, $fileUuid, 'release');
    }

    public function trashBatch(FileAdminStepUpRequest $request): JsonResponse
    {
        $this->authorize('arquivos:excluir');
        if (! (bool) config('file-manager.kill_switches.allow_admin_state_mutations', false)) {
            return $this->error('Ações de estado estão desabilitadas pelo kill switch.', 409, 'FILE_STATE_MUTATIONS_DISABLED', request: $request);
        }

        $actionData = $request->validate([
            'file_uuids' => ['required', 'array', 'min:1', 'max:100'],
            'file_uuids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $files = $this->findFiles((array) $actionData['file_uuids']);
        $actor = $this->actor($request);
        foreach ($files as $file) {
            if (! $this->authorizers->allows($actor, $file, 'trash')) {
                abort(404);
            }
        }

        $credentials = $request->validated();
        $verification = $this->adminVerifier->verify(
            (string) $credentials['admin_email'],
            (string) $credentials['admin_password'],
            'file-manager-trash-admin-auth',
            (string) ($request->ip() ?: 'unknown')
        );
        if ($response = $this->respondToAdminVerification(
            $verification,
            $request,
            'FILE_ADMIN_AUTH_RATE_LIMITED',
            'FILE_ADMIN_AUTH_INVALID'
        )) {
            return $response;
        }

        /** @var User $admin */
        $admin = $verification['admin'];
        $reason = trim((string) $actionData['reason']);

        try {
            $trashed = DB::transaction(function () use ($files, $actor, $admin, $reason): array {
                return $files->map(fn (ManagedFile $file): string => (string) $this->states
                    ->trash($file, (int) $actor->id, $reason, (int) $admin->id)
                    ->uuid)
                    ->all();
            });
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), 409, 'FILE_STATE_CONFLICT', request: $request);
        }

        return $this->success([
            'trashed_count' => count($trashed),
            'file_uuids' => $trashed,
        ], request: $request);
    }

    public function scanRuns(Request $request): JsonResponse
    {
        $this->authorize('arquivos:metadados');
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', Rule::in(['pending', 'running', 'interrupted', 'completed', 'completed_with_errors', 'failed'])],
        ]);
        $query = FileScanRun::query();
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        $paginator = $query->latest('id')->paginate((int) ($validated['per_page'] ?? 25));

        return $this->success(
            $paginator->getCollection()->map(fn (FileScanRun $run): array => $this->mapScanRun($run))->all(),
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function findings(Request $request): JsonResponse
    {
        $this->authorize('arquivos:metadados');
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['nullable', 'string', 'max:50'],
            'severity' => ['nullable', Rule::in(['info', 'low', 'medium', 'high', 'critical'])],
            'resolution_status' => ['nullable', Rule::in(['open', 'acknowledged', 'resolved', 'false_positive'])],
        ]);
        $query = FileScanFinding::query()->with('file:id,uuid');
        foreach (['severity', 'resolution_status'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        if (! empty($validated['type'])) {
            $query->where('finding_type', $validated['type']);
        }
        $paginator = $query->latest('id')->paginate((int) ($validated['per_page'] ?? 25));

        return $this->success(
            $paginator->getCollection()->map(fn (FileScanFinding $finding): array => $this->mapFinding($finding))->all(),
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    /** @return array<string, array<int, mixed>> */
    private function indexRules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', Rule::enum(FileCategory::class)],
            'lifecycle_status' => ['nullable', Rule::enum(FileLifecycleStatus::class)],
            'integrity_status' => ['nullable', Rule::enum(FileIntegrityStatus::class)],
            'security_status' => ['nullable', Rule::enum(FileSecurityStatus::class)],
            'migration_status' => ['nullable', Rule::enum(FileMigrationStatus::class)],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
        ];
    }

    private function stream(Request $request, string $fileUuid, bool $preview): StreamedResponse|JsonResponse
    {
        $file = $this->findFile($fileUuid);
        $actor = $this->actor($request);
        if (! $this->authorizers->allows($actor, $file, 'download')) {
            abort(404);
        }

        try {
            $opened = $this->delivery->open($file, $preview);
        } catch (\UnexpectedValueException $exception) {
            return $this->error($exception->getMessage(), 415, 'FILE_PREVIEW_NOT_ALLOWED', request: $request);
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), 409, 'FILE_DELIVERY_BLOCKED', request: $request);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->error('Arquivo indisponível para entrega.', 404, 'FILE_NOT_FOUND', request: $request);
        }

        $stream = $opened['stream'];
        $disposition = $opened['inline'] ? 'inline' : 'attachment';
        $fileName = str_replace(['"', "\r", "\n"], '_', $opened['file_name']);

        return response()->stream(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $opened['mime_type'],
            'Content-Disposition' => $disposition.'; filename="'.$fileName.'"',
            'Content-Length' => (string) $file->size_bytes,
            'Cache-Control' => $preview ? 'private, no-store' : 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function mutate(FileAdminStepUpRequest $request, string $fileUuid, string $action): JsonResponse
    {
        $permission = match ($action) {
            'restore' => 'restaurar',
            'quarantine' => 'quarentenar',
            default => 'administrar',
        };
        $this->authorize('arquivos:'.$permission);
        if (! (bool) config('file-manager.kill_switches.allow_admin_state_mutations', false)) {
            return $this->error('Ações de estado estão desabilitadas pelo kill switch.', 409, 'FILE_STATE_MUTATIONS_DISABLED', request: $request);
        }

        $rules = ['reason' => ['required', 'string', 'min:10', 'max:500']];
        if ($action === 'release') {
            $rules['validation_reference'] = ['required', 'string', 'min:3', 'max:120'];
        }
        $actionData = $request->validate($rules);
        $actor = $this->actor($request);
        $file = $this->findFile($fileUuid);
        if (! $this->authorizers->allows($actor, $file, $action)) {
            abort(404);
        }

        $credentials = $request->validated();
        $verification = $this->adminVerifier->verify(
            (string) $credentials['admin_email'],
            (string) $credentials['admin_password'],
            'file-manager-'.$action.'-admin-auth',
            (string) ($request->ip() ?: 'unknown')
        );
        if ($response = $this->respondToAdminVerification(
            $verification,
            $request,
            'FILE_ADMIN_AUTH_RATE_LIMITED',
            'FILE_ADMIN_AUTH_INVALID'
        )) {
            return $response;
        }

        /** @var User $admin */
        $admin = $verification['admin'];
        $reason = trim((string) $actionData['reason']);

        try {
            $updated = match ($action) {
                'archive' => $this->states->archive($file, (int) $actor->id, $reason, (int) $admin->id),
                'restore' => $this->states->restore($file, (int) $actor->id, $reason, (int) $admin->id),
                'quarantine' => $this->states->quarantine($file, $reason, (int) $actor->id, (int) $admin->id),
                'release' => $this->states->releaseFromQuarantine(
                    $file,
                    (int) $actor->id,
                    $reason,
                    trim((string) $actionData['validation_reference']),
                    (int) $admin->id
                ),
            };
        } catch (\DomainException $exception) {
            return $this->error($exception->getMessage(), 409, 'FILE_STATE_CONFLICT', request: $request);
        }

        $updated->load(['links' => static fn ($query) => $query->whereNull('unlinked_at')]);

        return $this->success($this->detail($updated, $actor), request: $request);
    }

    private function findFile(string $uuid): ManagedFile
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) !== 1) {
            abort(404);
        }

        return ManagedFile::query()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @param  array<int, mixed>  $uuids
     * @return EloquentCollection<int, ManagedFile>
     */
    private function findFiles(array $uuids): EloquentCollection
    {
        $orderedUuids = array_values(array_map(static fn (mixed $uuid): string => (string) $uuid, $uuids));
        $files = ManagedFile::query()
            ->with('links')
            ->whereIn('uuid', $orderedUuids)
            ->get()
            ->keyBy('uuid');
        if ($files->count() !== count($orderedUuids)) {
            abort(404);
        }

        return new EloquentCollection(array_map(
            static fn (string $uuid): ManagedFile => $files->get($uuid),
            $orderedUuids
        ));
    }

    private function actor(Request $request): User
    {
        $actor = $this->authenticatedUser($request);
        if (! $actor instanceof User) {
            abort(401);
        }

        return $actor;
    }

    /** @return array<string, mixed> */
    private function summary(ManagedFile $file, ?array $linkedClient = null): array
    {
        return [
            'uuid' => (string) $file->uuid,
            'safe_download_name' => (string) $file->safe_download_name,
            'detected_mime_type' => (string) $file->detected_mime_type,
            'size_bytes' => (int) $file->size_bytes,
            'category' => (string) $file->category,
            'origin' => $file->origin->value,
            'lifecycle_status' => $file->lifecycle_status->value,
            'integrity_status' => $file->integrity_status->value,
            'security_status' => $file->security_status->value,
            'migration_status' => $file->migration_status->value,
            'confidentiality' => (string) $file->confidentiality,
            'active_links_count' => (int) ($file->active_links_count ?? $file->links->whereNull('unlinked_at')->count()),
            'created_at' => $file->created_at?->toIso8601String(),
            'document_created_at' => $this->documentCreatedAt($file),
            'linked_client' => $linkedClient,
        ];
    }

    private function documentCreatedAt(ManagedFile $file): ?string
    {
        $dates = [];
        foreach ($file->links->whereNull('unlinked_at') as $link) {
            $sourceDate = trim((string) data_get($link->metadata_json, 'source_created_at', ''));
            if ($sourceDate === '') {
                continue;
            }

            try {
                $dates[] = CarbonImmutable::parse($sourceDate);
            } catch (\Throwable) {
                // Metadado legado invalido nao deve derrubar a listagem.
            }
        }

        if ($dates === []) {
            return $file->created_at?->toIso8601String();
        }

        usort($dates, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp());

        return $dates[0]->toIso8601String();
    }

    /** @return array<string, mixed> */
    private function detail(ManagedFile $file, User $actor): array
    {
        $linkedClients = $this->resolveLinkedClients(new EloquentCollection([$file]), $actor);

        return array_merge($this->summary($file, $linkedClients[(int) $file->id] ?? null), [
            'sha256' => (string) $file->sha256,
            'original_name' => (string) $file->original_name,
            'links' => $file->links->whereNull('unlinked_at')->map(static fn ($link): array => [
                'subject_type' => (string) $link->subject_type,
                'subject_id' => (int) $link->subject_id,
                'relation' => (string) $link->relation,
                'is_current' => (bool) $link->is_current,
            ])->values()->all(),
            'events' => $file->relationLoaded('events')
                ? $file->events->map(static fn ($event): array => [
                    'uuid' => (string) $event->event_uuid,
                    'action' => $event->action->value,
                    'result' => (string) $event->result,
                    'actor_id' => $event->actor_id !== null ? (int) $event->actor_id : null,
                    'context' => (array) ($event->context_json ?? []),
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values()->all()
                : [],
            'findings' => $file->relationLoaded('findings')
                ? $file->findings->map(fn (FileScanFinding $finding): array => $this->mapFinding($finding))->values()->all()
                : [],
            'capabilities' => $this->capabilities($actor, $file),
        ]);
    }

    /**
     * Resolve clientes em lote para evitar N+1 na grade. O nome somente é
     * incluído quando o usuário também pode visualizar o domínio de origem.
     *
     * @param  EloquentCollection<int, ManagedFile>  $files
     * @return array<int, array{id: int, name: string}>
     */
    private function resolveLinkedClients(EloquentCollection $files, User $actor): array
    {
        if ($files->isEmpty()) {
            return [];
        }

        $files->loadMissing([
            'links' => static fn ($query) => $query
                ->whereNull('unlinked_at')
                ->orderByDesc('is_current')
                ->orderByDesc('id'),
        ]);

        $subjectIds = [
            'order' => [],
            'equipment' => [],
            'client' => [],
        ];

        foreach ($files as $file) {
            foreach ($file->links->whereNull('unlinked_at') as $link) {
                $subjectType = (string) $link->subject_type;
                $subjectId = (int) $link->subject_id;
                if ($subjectId > 0 && array_key_exists($subjectType, $subjectIds)) {
                    $subjectIds[$subjectType][$subjectId] = $subjectId;
                }
            }
        }

        $clientBySubject = [];
        if ($subjectIds['order'] !== [] && $this->rbac->allows($actor, 'os', 'visualizar')) {
            $orders = DB::table('os')
                ->join('clientes', 'clientes.id', '=', 'os.cliente_id')
                ->whereIn('os.id', array_values($subjectIds['order']))
                ->when(
                    mb_strtolower(trim((string) ($actor->perfil ?? ''))) === 'tecnico',
                    static fn ($query) => $query->where('os.tecnico_id', (int) $actor->id)
                )
                ->get([
                    'os.id as subject_id',
                    'clientes.id as client_id',
                    'clientes.nome_razao as client_name',
                ]);

            foreach ($orders as $order) {
                $clientBySubject['order:'.(int) $order->subject_id] = $this->linkedClientPayload(
                    (int) $order->client_id,
                    (string) $order->client_name
                );
            }
        }

        if ($subjectIds['equipment'] !== [] && $this->rbac->allows($actor, 'equipamentos', 'visualizar')) {
            $equipments = DB::table('equipamentos')
                ->join('clientes', 'clientes.id', '=', 'equipamentos.cliente_id')
                ->whereIn('equipamentos.id', array_values($subjectIds['equipment']))
                ->get([
                    'equipamentos.id as subject_id',
                    'clientes.id as client_id',
                    'clientes.nome_razao as client_name',
                ]);

            foreach ($equipments as $equipment) {
                $clientBySubject['equipment:'.(int) $equipment->subject_id] = $this->linkedClientPayload(
                    (int) $equipment->client_id,
                    (string) $equipment->client_name
                );
            }
        }

        if ($subjectIds['client'] !== [] && $this->rbac->allows($actor, 'clientes', 'visualizar')) {
            $clients = DB::table('clientes')
                ->whereIn('id', array_values($subjectIds['client']))
                ->get(['id', 'nome_razao']);

            foreach ($clients as $client) {
                $clientBySubject['client:'.(int) $client->id] = $this->linkedClientPayload(
                    (int) $client->id,
                    (string) $client->nome_razao
                );
            }
        }

        $resolved = [];
        foreach ($files as $file) {
            foreach ($file->links->whereNull('unlinked_at') as $link) {
                $key = (string) $link->subject_type.':'.(int) $link->subject_id;
                if (isset($clientBySubject[$key])) {
                    $resolved[(int) $file->id] = $clientBySubject[$key];
                    break;
                }
            }
        }

        return $resolved;
    }

    /** @return array{id: int, name: string} */
    private function linkedClientPayload(int $clientId, string $clientName): array
    {
        $name = trim($clientName);

        return [
            'id' => $clientId,
            'name' => $name !== '' ? $name : 'Cliente #'.$clientId,
        ];
    }

    /** @return array<string, bool> */
    private function capabilities(User $actor, ManagedFile $file): array
    {
        $enabled = (bool) config('file-manager.kill_switches.allow_admin_state_mutations', false);

        return [
            'download' => $this->rbac->allows($actor, 'arquivos', 'baixar') && $this->authorizers->allows($actor, $file, 'download'),
            'archive' => $enabled && $this->rbac->allows($actor, 'arquivos', 'administrar') && $this->authorizers->allows($actor, $file, 'archive'),
            'trash' => $enabled && $this->rbac->allows($actor, 'arquivos', 'excluir') && $this->authorizers->allows($actor, $file, 'trash'),
            'restore' => $enabled && $this->rbac->allows($actor, 'arquivos', 'restaurar') && $this->authorizers->allows($actor, $file, 'restore'),
            'quarantine' => $enabled && $this->rbac->allows($actor, 'arquivos', 'quarentenar') && $this->authorizers->allows($actor, $file, 'quarantine'),
            'release' => $enabled && $this->rbac->allows($actor, 'arquivos', 'administrar') && $this->authorizers->allows($actor, $file, 'release'),
        ];
    }

    /** @return array<string, mixed> */
    private function mapScanRun(FileScanRun $run): array
    {
        return [
            'uuid' => (string) $run->uuid,
            'process_name' => (string) $run->process_name,
            'mode' => (string) $run->mode,
            'status' => (string) $run->status,
            'processed_count' => (int) $run->processed_count,
            'skipped_count' => (int) $run->skipped_count,
            'finding_count' => (int) $run->finding_count,
            'failed_count' => (int) $run->failed_count,
            'created_at' => $run->created_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function mapFinding(FileScanFinding $finding): array
    {
        $fileUuid = $finding->relationLoaded('file') ? $finding->file?->uuid : null;

        return [
            'id' => (int) $finding->id,
            'finding_type' => (string) $finding->finding_type,
            'severity' => (string) $finding->severity,
            'resolution_status' => (string) $finding->resolution_status,
            'file_uuid' => $fileUuid !== null ? (string) $fileUuid : null,
            'restricted_path_hint' => $finding->path_hash !== null ? 'hash:'.substr((string) $finding->path_hash, 0, 12) : null,
            'created_at' => $finding->created_at?->toIso8601String(),
        ];
    }
}
