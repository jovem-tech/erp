<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\EquipmentBrand;
use App\Models\EquipmentCollectorPairing;
use App\Models\EquipmentModel;
use App\Models\EquipmentPhoto;
use App\Models\EquipmentType;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class EquipmentWorkflowService
{
    private const BRAND_SCOPE_ANCHOR_MODEL_NAME = '__CATALOG_BRAND_SCOPE__';

    /**
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $defaults = $this->ensureDesktopMountedCatalogDefaults();

        return [
            'clients' => Client::query()
                ->orderBy('nome_razao')
                ->get(['id', 'nome_razao', 'cpf_cnpj', 'telefone1', 'telefone2', 'nome_contato', 'telefone_contato', 'email'])
                ->map(static function (Client $client): array {
                    return [
                        'id' => (int) $client->id,
                        'nome_razao' => trim((string) ($client->nome_razao ?? '')),
                        'cpf_cnpj' => trim((string) ($client->cpf_cnpj ?? '')),
                        'telefone1' => trim((string) ($client->telefone1 ?? '')),
                        'telefone2' => trim((string) ($client->telefone2 ?? '')),
                        'nome_contato' => trim((string) ($client->nome_contato ?? '')),
                        'telefone_contato' => trim((string) ($client->telefone_contato ?? '')),
                        'email' => trim((string) ($client->email ?? '')),
                    ];
                })
                ->values()
                ->all(),
            'types' => EquipmentType::query()
                ->where('ativo', 1)
                ->orderBy('nome')
                ->get()
                ->map(static function (EquipmentType $type): array {
                    $name = trim((string) ($type->nome ?? ''));

                    return [
                        'id' => (int) $type->id,
                        'nome' => $name,
                        'slug' => Str::slug($name, '_'),
                        'family' => self::resolveTypeFamily($name),
                    ];
                })
                ->values()
                ->all(),
            'brands' => EquipmentBrand::query()
                ->where('ativo', 1)
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(static fn (EquipmentBrand $brand): array => [
                    'id' => (int) $brand->id,
                    'nome' => trim((string) ($brand->nome ?? '')),
                ])
                ->values()
                ->all(),
            'models' => EquipmentModel::query()
                ->where('ativo', 1)
                ->orderBy('nome')
                ->get(['id', 'marca_id', 'nome'])
                ->map(static fn (EquipmentModel $model): array => [
                    'id' => (int) $model->id,
                    'marca_id' => (int) ($model->marca_id ?? 0),
                    'nome' => trim((string) ($model->nome ?? '')),
                ])
                ->values()
                ->all(),
            'catalog_relations' => $this->catalogRelations(),
            'desktop_defaults' => $defaults,
            'password_modes' => [
                ['value' => 'desenho', 'label' => 'Desenho'],
                ['value' => 'texto', 'label' => 'Texto'],
            ],
            'max_photos' => 4,
            'collector' => [
                'pairing_ttl_minutes' => (int) config('services.collector.pairing_ttl_minutes', 30),
                'local_root' => $this->getCollectorLocalRootPath(),
                'supports_local_collection' => $this->supportsLocalCollector(),
                // Base URL que o coletor rodando na maquina do cliente usa para
                // enviar o check-in pela rede (--erp-base-url do script/exe) —
                // e o proprio endereco publico deste backend.
                'erp_base_url' => rtrim((string) config('app.url'), '/'),
                'download_url_linux' => rtrim((string) config('app.url'), '/')
                    . '/assets/agents/bench-collector/linux-x64/jovemtech-bench-collector.sh',
                // O .exe Windows compilado (sem fonte no repo) usa um fluxo
                // antigo por numero de OS/e-mail, incompativel com o
                // endpoint de pareamento — por isso o link aqui aponta pro
                // script .ps1 novo (mesmo protocolo --pairing-code do build
                // Linux), nao pro .exe.
                'download_url_windows' => rtrim((string) config('app.url'), '/')
                    . '/assets/agents/bench-collector/win-x64/jovemtech-bench-collector.ps1',
            ],
        ];
    }

    public function createBrand(string $name, int $typeId): EquipmentBrand
    {
        $normalized = $this->normalizeCatalogName($name);

        if ($normalized === '') {
            throw new RuntimeException('Informe um nome de marca valido.');
        }

        $brand = EquipmentBrand::query()->firstOrNew(['nome' => $normalized]);
        $brand->nome = $normalized;
        $brand->ativo = true;
        if (! $brand->exists) {
            $brand->created_at = now();
        }
        $brand->updated_at = now();
        $brand->save();

        $this->ensureBrandTypeCatalogScope($typeId, (int) $brand->id);

        return $brand->fresh() ?? $brand;
    }

    public function createModel(int $brandId, string $name, int $typeId): EquipmentModel
    {
        if ($brandId <= 0) {
            throw new RuntimeException('Selecione uma marca valida para o modelo.');
        }

        $brand = EquipmentBrand::query()->find($brandId);
        if (! $brand instanceof EquipmentBrand) {
            throw new RuntimeException('Marca informada nao foi encontrada.');
        }

        $normalized = $this->normalizeCatalogName($name);
        if ($normalized === '') {
            throw new RuntimeException('Informe um nome de modelo valido.');
        }

        $model = EquipmentModel::query()->firstOrNew([
            'marca_id' => $brandId,
            'nome' => $normalized,
        ]);

        $model->marca_id = $brandId;
        $model->nome = $normalized;
        $model->ativo = true;
        if (! $model->exists) {
            $model->created_at = now();
        }
        $model->updated_at = now();
        $model->save();

        $this->ensureModelTypeCatalogScope($typeId, $brandId, (int) $model->id);

        return $model->fresh() ?? $model;
    }

    /**
     * @return array<int, array{tipo_id:int,marca_id:int,modelo_id:int}>
     */
    private function catalogRelations(): array
    {
        if (! $this->hasCatalogRelationsTable()) {
            return [];
        }

        return DB::table('equipamentos_catalogo_relacoes')
            ->where('ativo', 1)
            ->orderBy('tipo_id')
            ->orderBy('marca_id')
            ->orderBy('modelo_id')
            ->get(['tipo_id', 'marca_id', 'modelo_id'])
            ->map(static fn (object $relation): array => [
                'tipo_id' => (int) ($relation->tipo_id ?? 0),
                'marca_id' => (int) ($relation->marca_id ?? 0),
                'modelo_id' => (int) ($relation->modelo_id ?? 0),
            ])
            ->values()
            ->all();
    }

    private function ensureBrandTypeCatalogScope(int $typeId, int $brandId): void
    {
        if ($typeId <= 0 || $brandId <= 0 || ! $this->hasCatalogRelationsTable()) {
            return;
        }

        if (! EquipmentType::query()->whereKey($typeId)->exists()) {
            throw new RuntimeException('Tipo informado nao foi encontrado.');
        }

        if (! EquipmentBrand::query()->whereKey($brandId)->exists()) {
            throw new RuntimeException('Marca informada nao foi encontrada.');
        }

        // A tabela legada exige `modelo_id` nao nulo. Mantemos uma ancora inativa
        // para registrar o escopo `tipo -> marca` sem expor um modelo falso ao usuario.
        $anchorModel = $this->ensureBrandScopeAnchorModel($brandId);

        $this->ensureCatalogRelationRecord($typeId, $brandId, (int) $anchorModel->id);
    }

    private function ensureModelTypeCatalogScope(int $typeId, int $brandId, int $modelId): void
    {
        if ($typeId <= 0 || $brandId <= 0 || $modelId <= 0 || ! $this->hasCatalogRelationsTable()) {
            return;
        }

        if (! EquipmentType::query()->whereKey($typeId)->exists()) {
            throw new RuntimeException('Tipo informado nao foi encontrado.');
        }

        $model = EquipmentModel::query()->find($modelId);
        if (! $model instanceof EquipmentModel || (int) $model->marca_id !== $brandId) {
            throw new RuntimeException('Modelo informado nao pertence a marca selecionada.');
        }

        $this->ensureCatalogRelationRecord($typeId, $brandId, $modelId);
    }

    private function ensureCatalogRelationRecord(int $typeId, int $brandId, int $modelId): void
    {
        $existing = DB::table('equipamentos_catalogo_relacoes')
            ->where('tipo_id', $typeId)
            ->where('marca_id', $brandId)
            ->where('modelo_id', $modelId)
            ->first();

        if ($existing !== null) {
            DB::table('equipamentos_catalogo_relacoes')
                ->where('id', $existing->id)
                ->update([
                    'ativo' => 1,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('equipamentos_catalogo_relacoes')->insert([
            'tipo_id' => $typeId,
            'marca_id' => $brandId,
            'modelo_id' => $modelId,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureBrandScopeAnchorModel(int $brandId): EquipmentModel
    {
        $anchor = EquipmentModel::query()->firstOrNew([
            'marca_id' => $brandId,
            'nome' => self::BRAND_SCOPE_ANCHOR_MODEL_NAME,
        ]);

        $anchor->marca_id = $brandId;
        $anchor->nome = self::BRAND_SCOPE_ANCHOR_MODEL_NAME;
        $anchor->ativo = false;
        if (! $anchor->exists) {
            $anchor->created_at = now();
        }
        $anchor->updated_at = now();
        $anchor->save();

        return $anchor->fresh() ?? $anchor;
    }

    private function hasCatalogRelationsTable(): bool
    {
        return DB::getSchemaBuilder()->hasTable('equipamentos_catalogo_relacoes');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggestModels(string $query, string $brandName = '', string $typeName = ''): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 3) {
            return [];
        }

        $cacheKey = 'equipment_model_suggestions_' . md5($query . '|' . $brandName . '|' . $typeName);

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($query, $brandName, $typeName): array {
            $parts = array_values(array_filter([$this->normalizeTypeHint($typeName), $brandName, $query]));
            $searchQuery = implode(' ', $parts);

            try {
                $response = Http::acceptJson()
                    ->timeout((int) config('services.collector.suggestions_timeout', 5))
                    ->withHeaders([
                        'User-Agent' => 'SistemaERP/1.0',
                        'Accept-Language' => 'pt-BR,pt;q=0.9',
                    ])
                    ->get('https://suggestqueries.google.com/complete/search', [
                        'client' => 'chrome',
                        'hl' => 'pt-BR',
                        'oe' => 'utf8',
                        'q' => $searchQuery,
                    ]);
            } catch (ConnectionException) {
                return [];
            }

            if (! $response->successful()) {
                return [];
            }

            $payload = $response->json();
            $items = is_array($payload[1] ?? null) ? $payload[1] : [];
            $suggestions = [];
            $seen = [];

            foreach ($items as $item) {
                $candidate = $this->extractModelSuggestion((string) $item, $brandName, $typeName);
                if ($candidate === '') {
                    continue;
                }

                $fingerprint = mb_strtolower($candidate, 'UTF-8');
                if (in_array($fingerprint, $seen, true)) {
                    continue;
                }

                $seen[] = $fingerprint;
                $suggestions[] = [
                    'id' => 'EXT|' . substr(md5($candidate), 0, 12),
                    'nome' => mb_convert_case($candidate, MB_CASE_TITLE, 'UTF-8'),
                    'source' => 'google',
                ];

                if (count($suggestions) >= 5) {
                    break;
                }
            }

            return $suggestions;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function readLocalCollectorSnapshot(): array
    {
        if (! $this->supportsLocalCollector()) {
            throw new RuntimeException('A leitura do snapshot local do coletor so esta disponivel quando o ERP estiver rodando na mesma maquina Windows ou Linux da bancada.', 422);
        }

        $snapshotData = $this->readLocalCollectorSnapshotPayload();

        return $this->buildLocalCollectorResponsePayload($snapshotData);
    }

    /**
     * @return array<string, mixed>
     */
    public function collectLocalCollectorSnapshot(): array
    {
        $run = $this->runLocalCollectorCapture();
        $snapshotData = $this->readLocalCollectorSnapshotPayload();

        return $this->buildLocalCollectorResponsePayload($snapshotData, [
            'message' => isset($run['warning'])
                ? 'Ultimo snapshot local importado com aviso.'
                : 'Coleta local concluida com sucesso.',
            'collector' => $run,
        ]);
    }

    public function createCollectorPairing(?User $user = null): EquipmentCollectorPairing
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (EquipmentCollectorPairing::query()->where('code', $code)->exists());

        return EquipmentCollectorPairing::query()->create([
            'user_id' => $user?->id,
            'code' => $code,
            // Token de uso unico pra este pareamento — e' o que autoriza o
            // POST /api/v1/collector/snapshots (ver storeCollectorSnapshot),
            // no lugar do antigo segredo global compartilhado por todo
            // mundo pra sempre. Cada codigo tem o seu, some quando expira.
            'submission_token' => bin2hex(random_bytes(24)),
            'expires_at' => now()->addMinutes((int) config('services.collector.pairing_ttl_minutes', 30)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function storeCollectorSnapshot(array $payload, string $submissionToken = ''): EquipmentCollectorPairing
    {
        $pairing = EquipmentCollectorPairing::query()
            ->where('code', strtoupper(trim((string) ($payload['pairing_code'] ?? ''))))
            ->first();

        if (! $pairing instanceof EquipmentCollectorPairing) {
            throw new RuntimeException('Codigo de pareamento nao encontrado.');
        }

        if ($pairing->consumed_at !== null) {
            throw new RuntimeException('Este codigo de pareamento ja foi consumido.');
        }

        if ($pairing->expires_at === null || $pairing->expires_at->isPast()) {
            throw new RuntimeException('Codigo de pareamento expirado.');
        }

        // Token de uso unico por pareamento (ver createCollectorPairing) —
        // substitui o antigo segredo global; sem ele nenhum arquivo/comando
        // que sai da empresa carrega um segredo valido pra sempre.
        $expectedToken = (string) ($pairing->submission_token ?? '');
        $providedToken = trim($submissionToken);
        if ($expectedToken === '' || $providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            throw new RuntimeException('Token do coletor invalido para este pareamento.');
        }

        $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : [];

        $pairing->snapshot_payload = $snapshot;
        $pairing->snapshot_normalized = $this->normalizeCollectorSnapshot($snapshot);
        $pairing->source = $this->nullableString($payload['source'] ?? null);
        $pairing->agent_version = $this->nullableString($payload['agent_version'] ?? null);
        $pairing->hostname = $this->nullableString($payload['hostname'] ?? null);
        $pairing->snapshot_received_at = now();
        $pairing->updated_at = now();
        $pairing->save();

        return $pairing->fresh() ?? $pairing;
    }

    public function getCollectorPairing(string $code): ?EquipmentCollectorPairing
    {
        $pairing = EquipmentCollectorPairing::query()
            ->where('code', strtoupper(trim($code)))
            ->first();

        if (! $pairing instanceof EquipmentCollectorPairing) {
            return null;
        }

        return $pairing;
    }

    /**
     * Gera um zip com o coletor Windows ja personalizado pro pareamento
     * (codigo, URL do ERP e token embutidos no .ps1) + um .bat que so' da'
     * duplo-clique nele — o cliente baixa e roda sem digitar comando
     * nenhum. Ver bloco JOVEMTECH_PAIRING_DEFAULTS no topo do .ps1.
     *
     * @return array{filename: string, mime: string, content: string}
     */
    public function buildWindowsCollectorDownloadPackage(string $pairingCode): array
    {
        $pairing = EquipmentCollectorPairing::query()
            ->where('code', strtoupper(trim($pairingCode)))
            ->first();

        if (! $pairing instanceof EquipmentCollectorPairing) {
            throw new RuntimeException('Codigo de pareamento nao encontrado.');
        }

        if ($pairing->expires_at === null || $pairing->expires_at->isPast()) {
            throw new RuntimeException('Codigo de pareamento expirado.');
        }

        $templatePath = public_path('assets/agents/bench-collector/win-x64/jovemtech-bench-collector.ps1');
        if (! is_file($templatePath)) {
            throw new RuntimeException('Modelo do coletor Windows nao encontrado.', 500);
        }

        $template = (string) file_get_contents($templatePath);
        $erpBaseUrl = rtrim((string) config('app.url'), '/');
        $token = (string) ($pairing->submission_token ?? '');

        // Aspas simples do PowerShell escapam dobrando a aspa — mesma regra
        // usada em qualquer string literal 'single-quoted' do PS.
        $psEscape = static fn (string $value): string => str_replace("'", "''", $value);

        $personalized = str_replace(
            [
                "\$DefaultPairingCode = ''",
                "\$DefaultErpBaseUrl = ''",
                "\$DefaultCollectorToken = ''",
            ],
            [
                "\$DefaultPairingCode = '" . $psEscape($pairing->code) . "'",
                "\$DefaultErpBaseUrl = '" . $psEscape($erpBaseUrl) . "'",
                "\$DefaultCollectorToken = '" . $psEscape($token) . "'",
            ],
            $template,
            $replacements
        );

        if ($replacements < 3) {
            // Marcadores nao encontrados (modelo mudou) — nao publica um
            // pacote sem os valores embutidos, silenciosamente incompleto.
            throw new RuntimeException('Nao foi possivel personalizar o coletor (modelo desatualizado).', 500);
        }

        $batLauncher = <<<'BAT'
            @echo off
            title Coletor Jovem Tech
            powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0jovemtech-bench-collector.ps1"
            echo.
            pause
            BAT;

        $toCrlf = static fn (string $value): string => str_replace("\n", "\r\n", str_replace("\r\n", "\n", $value));

        $zipPath = tempnam(sys_get_temp_dir(), 'jtcollector_');
        if ($zipPath === false) {
            throw new RuntimeException('Nao foi possivel gerar o pacote do coletor.', 500);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Nao foi possivel gerar o pacote do coletor.', 500);
        }

        $zip->addFromString('jovemtech-bench-collector.ps1', $toCrlf($personalized));
        $zip->addFromString('Rodar coletor.bat', $toCrlf($batLauncher));
        $zip->close();

        $content = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        return [
            'filename' => 'coletor-' . $pairing->code . '.zip',
            'mime' => 'application/zip',
            'content' => $content,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, UploadedFile> $uploadedFiles
     */
    public function createEquipment(array $payload, array $uploadedFiles = []): Equipment
    {
        $pairing = null;
        $pairingCode = trim((string) ($payload['collector_pairing_code'] ?? ''));
        if ($pairingCode !== '') {
            $pairing = $this->getCollectorPairing($pairingCode);
            if (! $pairing instanceof EquipmentCollectorPairing || $pairing->consumed_at !== null || $pairing->expires_at?->isPast()) {
                throw new RuntimeException('Codigo do coletor invalido ou expirado.');
            }
        }

        $normalizedPayload = $this->normalizePayload($payload);
        $normalizedPayload = $this->applyDesktopDefaults($normalizedPayload);
        $normalizedPayload = $this->normalizePasswordPayload($normalizedPayload);
        $primaryPhotoIndex = (int) ($normalizedPayload['foto_principal_index'] ?? 0);
        unset($normalizedPayload['foto_principal_index']);
        unset($normalizedPayload['senha_tipo'], $normalizedPayload['senha_desenho']);
        $normalizedPayload['resumo_tecnico'] = $this->buildTechnicalSummary($normalizedPayload);
        $normalizedPayload['created_at'] = now();
        $normalizedPayload['updated_at'] = now();

        $equipment = Equipment::query()->create($normalizedPayload);

        $this->storePhotos($equipment, $uploadedFiles, $primaryPhotoIndex);

        if ($pairing instanceof EquipmentCollectorPairing) {
            $pairing->consumed_at = now();
            $pairing->updated_at = now();
            $pairing->save();
        }

        /** @var Equipment $fresh */
        $fresh = Equipment::query()
            ->with(['client', 'type', 'brand', 'model', 'photos'])
            ->withCount('orders')
            ->findOrFail($equipment->id);

        return $fresh;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, UploadedFile> $uploadedFiles
     */
    public function updateEquipment(int $equipmentId, array $payload, array $uploadedFiles = []): Equipment
    {
        $equipment = Equipment::query()
            ->with('photos')
            ->find($equipmentId);

        if (! $equipment instanceof Equipment) {
            throw new RuntimeException('Equipamento nao encontrado.');
        }

        $pairing = null;
        $pairingCode = trim((string) ($payload['collector_pairing_code'] ?? ''));
        if ($pairingCode !== '') {
            $pairing = $this->getCollectorPairing($pairingCode);
            if (! $pairing instanceof EquipmentCollectorPairing || $pairing->consumed_at !== null || $pairing->expires_at?->isPast()) {
                throw new RuntimeException('Codigo do coletor invalido ou expirado.');
            }
        }

        $shouldUpdatePassword = $this->shouldUpdatePassword($payload);
        $normalizedPayload = $this->normalizePayload($payload);
        $normalizedPayload = $this->applyDesktopDefaults($normalizedPayload);
        $normalizedPayload = $this->normalizePasswordPayload($normalizedPayload);
        $normalizedPayload['resumo_tecnico'] = $this->buildTechnicalSummary($normalizedPayload);
        $normalizedPayload['updated_at'] = now();

        if (! $shouldUpdatePassword) {
            unset($normalizedPayload['senha_acesso']);
        }

        unset(
            $normalizedPayload['foto_principal_index'],
            $normalizedPayload['foto_principal_existente_id'],
            $normalizedPayload['existing_photo_ids'],
            $normalizedPayload['existing_photo_sync'],
            $normalizedPayload['senha_tipo'],
            $normalizedPayload['senha_desenho']
        );

        $equipment->fill($normalizedPayload);
        $equipment->save();

        $this->syncEquipmentPhotos($equipment, $payload, $uploadedFiles);

        if ($pairing instanceof EquipmentCollectorPairing) {
            $pairing->consumed_at = now();
            $pairing->updated_at = now();
            $pairing->save();
        }

        /** @var Equipment $fresh */
        $fresh = Equipment::query()
            ->with(['client', 'type', 'brand', 'model', 'photos'])
            ->withCount('orders')
            ->findOrFail($equipment->id);

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePhotoAccess(int $equipmentId, int $photoId): array
    {
        $photo = EquipmentPhoto::query()
            ->whereKey($photoId)
            ->where('equipamento_id', $equipmentId)
            ->first();

        if (! $photo instanceof EquipmentPhoto) {
            return ['result' => 'not_found'];
        }

        $relativePath = $this->normalizeStoredPath((string) ($photo->arquivo ?? ''));
        $absolutePath = Storage::disk('local')->path($relativePath);

        if (! is_file($absolutePath)) {
            // Equipamentos importados do legado tem o registro em equipamentos_fotos,
            // mas o arquivo fisico nunca foi copiado para o storage privado novo —
            // cai para o mesmo diretorio publico do sistema-hml usado para esses casos.
            $legacyAbsolutePath = $this->legacyEquipmentPhotoPath($relativePath);

            if ($legacyAbsolutePath === null) {
                return ['result' => 'missing_file'];
            }

            $absolutePath = $legacyAbsolutePath;
        }

        return [
            'result' => 'ok',
            'file' => [
                'absolute_path' => $absolutePath,
                'mime_type' => mime_content_type($absolutePath) ?: 'application/octet-stream',
                'filename' => basename($absolutePath),
            ],
        ];
    }

    private function legacyEquipmentPhotoPath(string $relativePath): ?string
    {
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $root = rtrim((string) config('filesystems.disks.legacy_public.root', ''), '/\\') ?: rtrim(
            dirname(base_path(), 2) . DIRECTORY_SEPARATOR . 'sistema-hml' . DIRECTORY_SEPARATOR . 'public',
            DIRECTORY_SEPARATOR
        );

        $absolutePath = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'equipamentos_perfil'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($absolutePath) ? $absolutePath : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $keys = [
            'cliente_id',
            'tipo_id',
            'marca_id',
            'modelo_id',
            'cor',
            'cor_hex',
            'cor_rgb',
            'numero_serie',
            'imei',
            'senha_tipo',
            'senha_acesso',
            'senha_desenho',
            'estado_fisico',
            'observacoes',
            'desktop_modalidade',
            'gabinete_tipo',
            'gabinete_identificacao_status',
            'gabinete_observacao',
            'placa_mae',
            'chipset',
            'processador',
            'memoria_ram',
            'armazenamento',
            'placa_video',
            'fonte_alimentacao',
            'status_operacional',
            'status',
            'foto_principal_index',
        ];

        $normalized = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $normalized[$key] = is_string($payload[$key]) ? trim($payload[$key]) : $payload[$key];
        }

        $normalized['cliente_id'] = (int) ($normalized['cliente_id'] ?? 0);
        $normalized['tipo_id'] = (int) ($normalized['tipo_id'] ?? 0);
        $normalized['marca_id'] = isset($normalized['marca_id']) && $normalized['marca_id'] !== '' ? (int) $normalized['marca_id'] : null;
        $normalized['modelo_id'] = isset($normalized['modelo_id']) && $normalized['modelo_id'] !== '' ? (int) $normalized['modelo_id'] : null;
        $normalized['cor'] = $this->nullableString($normalized['cor'] ?? null);
        $normalized['cor_hex'] = $this->nullableString($normalized['cor_hex'] ?? null);
        $normalized['cor_rgb'] = $this->nullableString($normalized['cor_rgb'] ?? null);
        $normalized['numero_serie'] = $this->nullableString($normalized['numero_serie'] ?? null);
        $normalized['imei'] = $this->nullableString($normalized['imei'] ?? null);
        $normalized['senha_tipo'] = $this->nullableString($normalized['senha_tipo'] ?? null);
        $normalized['senha_acesso'] = $this->nullableString($normalized['senha_acesso'] ?? null);
        $normalized['senha_desenho'] = $this->nullableString($normalized['senha_desenho'] ?? null);
        $normalized['estado_fisico'] = $this->nullableString($normalized['estado_fisico'] ?? null);
        $normalized['observacoes'] = $this->nullableString($normalized['observacoes'] ?? null);
        $normalized['desktop_modalidade'] = $this->normalizeDesktopMode($normalized['desktop_modalidade'] ?? null);
        $normalized['gabinete_tipo'] = $this->nullableString($normalized['gabinete_tipo'] ?? null);
        $normalized['gabinete_identificacao_status'] = $this->nullableString($normalized['gabinete_identificacao_status'] ?? null) ?? 'a_confirmar';
        $normalized['gabinete_observacao'] = $this->nullableString($normalized['gabinete_observacao'] ?? null);
        $normalized['placa_mae'] = $this->nullableString($normalized['placa_mae'] ?? null);
        $normalized['chipset'] = $this->nullableString($normalized['chipset'] ?? null);
        $normalized['processador'] = $this->nullableString($normalized['processador'] ?? null);
        $normalized['memoria_ram'] = $this->nullableString($normalized['memoria_ram'] ?? null);
        $normalized['armazenamento'] = $this->nullableString($normalized['armazenamento'] ?? null);
        $normalized['placa_video'] = $this->nullableString($normalized['placa_video'] ?? null);
        $normalized['fonte_alimentacao'] = $this->nullableString($normalized['fonte_alimentacao'] ?? null);
        $normalized['status_operacional'] = $this->nullableString($normalized['status_operacional'] ?? null) ?? 'ativo';
        $normalized['status'] = $this->nullableString($normalized['status'] ?? null) ?? 'ativo';
        $normalized['foto_principal_index'] = isset($normalized['foto_principal_index']) && $normalized['foto_principal_index'] !== ''
            ? max(0, min(3, (int) $normalized['foto_principal_index']))
            : 0;

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyDesktopDefaults(array $payload): array
    {
        $typeName = $this->resolveTypeName((int) ($payload['tipo_id'] ?? 0));
        $family = self::resolveTypeFamily($typeName);
        $isDesktopFamily = in_array($family, ['desktop', 'notebook'], true);

        if (! $isDesktopFamily) {
            $payload['desktop_modalidade'] = null;
            return $payload;
        }

        // Notebook nunca é "montado": é sempre OEM/fabricante, independentemente do que
        // foi enviado no payload. Apenas o tipo "Desktop" admite a modalidade "montado".
        if ($family === 'notebook') {
            $payload['desktop_modalidade'] = 'oem';
            return $payload;
        }

        $payload['desktop_modalidade'] = $payload['desktop_modalidade'] ?: 'montado';

        if (($payload['desktop_modalidade'] ?? '') === 'montado') {
            $defaults = $this->ensureDesktopMountedCatalogDefaults();

            if ((int) ($payload['marca_id'] ?? 0) <= 0) {
                $payload['marca_id'] = $defaults['marca_id'];
            }

            if ((int) ($payload['modelo_id'] ?? 0) <= 0) {
                $payload['modelo_id'] = $defaults['modelo_id'];
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePasswordPayload(array $payload): array
    {
        $passwordType = trim((string) ($payload['senha_tipo'] ?? ''));
        $passwordValue = trim((string) ($payload['senha_acesso'] ?? ''));
        $passwordPattern = trim((string) ($payload['senha_desenho'] ?? ''));

        if ($passwordType === 'desenho' && $passwordPattern !== '') {
            $passwordValue = 'desenho_' . ltrim($passwordPattern, '_');
        }

        $payload['senha_acesso'] = $passwordValue !== '' ? $passwordValue : null;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function shouldUpdatePassword(array $payload): bool
    {
        return trim((string) ($payload['senha_acesso'] ?? '')) !== ''
            || trim((string) ($payload['senha_desenho'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildTechnicalSummary(array $payload): string
    {
        $typeName = $this->resolveTypeName((int) ($payload['tipo_id'] ?? 0));
        $family = self::resolveTypeFamily($typeName);
        $parts = [];

        if ($typeName !== '') {
            $parts[] = $typeName;
        } elseif (($payload['desktop_modalidade'] ?? '') === 'montado') {
            $parts[] = 'Desktop montado';
        }

        if (($payload['desktop_modalidade'] ?? '') !== '' && in_array($family, ['desktop', 'notebook'], true)) {
            $parts[] = $this->humanizeDesktopMode((string) $payload['desktop_modalidade']);
        }

        foreach ([
            'gabinete_tipo',
            'placa_mae',
            'chipset',
            'processador',
            'memoria_ram',
            'armazenamento',
            'placa_video',
            'fonte_alimentacao',
        ] as $field) {
            $value = $this->nullableString($payload[$field] ?? null);
            if ($value !== null) {
                $parts[] = $value;
            }
        }

        $brandName = $this->resolveBrandName((int) ($payload['marca_id'] ?? 0));
        if ($brandName !== '') {
            $parts[] = $brandName;
        }

        $modelName = $this->resolveModelName((int) ($payload['modelo_id'] ?? 0));
        if ($modelName !== '') {
            $parts[] = $modelName;
        }

        $parts = array_values(array_unique(array_filter(array_map(static fn ($value): string => trim((string) $value), $parts), static fn (string $value): bool => $value !== '')));

        $summary = $parts !== [] ? implode(' | ', $parts) : 'Equipamento operacional';

        return Str::limit($summary, 255, '');
    }

    /**
     * @return array{marca_id:int,modelo_id:int,marca_nome:string,modelo_nome:string}
     */
    private function ensureDesktopMountedCatalogDefaults(): array
    {
        $brand = EquipmentBrand::query()->firstOrCreate(
            ['nome' => 'Montado'],
            [
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $model = EquipmentModel::query()->firstOrCreate(
            ['marca_id' => (int) $brand->id, 'nome' => 'Desktop montado'],
            [
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return [
            'marca_id' => (int) $brand->id,
            'modelo_id' => (int) $model->id,
            'marca_nome' => 'Montado',
            'modelo_nome' => 'Desktop montado',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, UploadedFile> $uploadedFiles
     */
    private function syncEquipmentPhotos(Equipment $equipment, array $payload, array $uploadedFiles): void
    {
        $currentPhotos = EquipmentPhoto::query()
            ->where('equipamento_id', $equipment->id)
            ->orderBy('id')
            ->get();

        $currentPhotoIds = $currentPhotos
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $syncExistingPhotos = (bool) ($payload['existing_photo_sync'] ?? false);
        $retainedPhotoIds = $syncExistingPhotos
            ? array_values(array_unique(array_map('intval', (array) ($payload['existing_photo_ids'] ?? []))))
            : $currentPhotoIds;

        foreach ($currentPhotos as $photo) {
            if (in_array((int) $photo->id, $retainedPhotoIds, true)) {
                continue;
            }

            $this->deleteEquipmentPhoto($photo);
        }

        $primaryExistingPhotoId = (int) ($payload['foto_principal_existente_id'] ?? 0);
        $newPrimaryIndex = array_key_exists('foto_principal_index', $payload) && $payload['foto_principal_index'] !== null && $payload['foto_principal_index'] !== ''
            ? (int) $payload['foto_principal_index']
            : null;

        if ($primaryExistingPhotoId > 0) {
            EquipmentPhoto::query()
                ->where('equipamento_id', $equipment->id)
                ->update(['is_principal' => 0]);

            $this->storePhotos($equipment, $uploadedFiles, null, false);

            EquipmentPhoto::query()
                ->where('equipamento_id', $equipment->id)
                ->whereKey($primaryExistingPhotoId)
                ->update(['is_principal' => 1]);

            $this->ensureEquipmentPrimaryPhoto($equipment->id);

            return;
        }

        if ($newPrimaryIndex !== null) {
            EquipmentPhoto::query()
                ->where('equipamento_id', $equipment->id)
                ->update(['is_principal' => 0]);

            $this->storePhotos($equipment, $uploadedFiles, $newPrimaryIndex, false);
            $this->ensureEquipmentPrimaryPhoto($equipment->id);

            return;
        }

        $retainedPrimaryExists = EquipmentPhoto::query()
            ->where('equipamento_id', $equipment->id)
            ->where('is_principal', 1)
            ->exists();

        $createdPhotoIds = $this->storePhotos($equipment, $uploadedFiles, null, false);

        if (! $retainedPrimaryExists) {
            if ($retainedPhotoIds !== []) {
                EquipmentPhoto::query()
                    ->where('equipamento_id', $equipment->id)
                    ->whereKey($retainedPhotoIds[0])
                    ->update(['is_principal' => 1]);
            } elseif ($createdPhotoIds !== []) {
                EquipmentPhoto::query()
                    ->where('equipamento_id', $equipment->id)
                    ->whereKey($createdPhotoIds[0])
                    ->update(['is_principal' => 1]);
            }
        }

        $this->ensureEquipmentPrimaryPhoto($equipment->id);
    }

    private function deleteEquipmentPhoto(EquipmentPhoto $photo): void
    {
        $relativePath = $this->normalizeStoredPath((string) ($photo->arquivo ?? ''));

        if ($relativePath !== '') {
            Storage::disk('local')->delete($relativePath);
        }

        $photo->delete();
    }

    private function ensureEquipmentPrimaryPhoto(int $equipmentId): void
    {
        if (EquipmentPhoto::query()->where('equipamento_id', $equipmentId)->where('is_principal', 1)->exists()) {
            return;
        }

        EquipmentPhoto::query()
            ->where('equipamento_id', $equipmentId)
            ->orderBy('id')
            ->limit(1)
            ->update(['is_principal' => 1]);
    }

    /**
     * @param array<int, UploadedFile> $uploadedFiles
     */
    private function storePhotos(Equipment $equipment, array $uploadedFiles, ?int $primaryIndex = 0, bool $ensurePrimary = true): array
    {
        $files = array_values(array_filter(
            $uploadedFiles,
            static fn ($file): bool => $file instanceof UploadedFile && $file->isValid()
        ));

        if ($files === []) {
            return [];
        }

        $directory = 'private/equipamentos/' . $equipment->id;
        $createdPhotoIds = [];

        if ($primaryIndex !== null) {
            $primaryIndex = max(0, min(count($files) - 1, $primaryIndex));
        }

        foreach ($files as $index => $file) {
            $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg'));
            $filename = sprintf(
                'equip_%d_%s_%02d.%s',
                (int) $equipment->id,
                now()->format('YmdHisv'),
                $index + 1,
                $extension
            );

            Storage::disk('local')->putFileAs($directory, $file, $filename);

            $photo = EquipmentPhoto::query()->create([
                'equipamento_id' => $equipment->id,
                'arquivo' => $directory . '/' . $filename,
                'is_principal' => $primaryIndex !== null && $index === $primaryIndex ? 1 : 0,
                'created_at' => now(),
            ]);

            $createdPhotoIds[] = (int) $photo->id;
        }

        if ($ensurePrimary && ! EquipmentPhoto::query()->where('equipamento_id', $equipment->id)->where('is_principal', 1)->exists()) {
            EquipmentPhoto::query()
                ->where('equipamento_id', $equipment->id)
                ->orderBy('id')
                ->limit(1)
                ->update(['is_principal' => 1]);
        }

        return $createdPhotoIds;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function normalizeCollectorSnapshot(array $snapshot): array
    {
        $read = static function (array $source, array $keys): ?string {
            foreach ($keys as $key) {
                $value = data_get($source, $key);
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }

            return null;
        };

        $legacyMapped = $this->mapCollectorSnapshotToEquipmentFields($snapshot);

        return [
            'tipo_nome' => $read($snapshot, ['tipo_nome', 'equipment_type', 'tipo']),
            'marca_nome' => $read($snapshot, ['marca_nome', 'brand', 'marca']) ?? ($legacyMapped['marca_nome'] ?? null),
            'modelo_nome' => $read($snapshot, ['modelo_nome', 'model', 'modelo']) ?? ($legacyMapped['modelo_nome'] ?? null),
            'numero_serie' => $read($snapshot, ['numero_serie', 'serial_number', 'serial']) ?? ($legacyMapped['numero_serie'] ?? null),
            'imei' => $read($snapshot, ['imei']),
            'cor' => $read($snapshot, ['cor', 'color_name']),
            'cor_hex' => $read($snapshot, ['cor_hex', 'color_hex']),
            'cor_rgb' => $read($snapshot, ['cor_rgb', 'color_rgb']),
            'desktop_modalidade' => $this->normalizeDesktopMode($read($snapshot, ['desktop_modalidade', 'desktop_mode', 'mode'])),
            'gabinete_tipo' => $read($snapshot, ['gabinete_tipo', 'case_type']) ?? ($legacyMapped['gabinete_tipo'] ?? null),
            'gabinete_identificacao_status' => $read($snapshot, ['gabinete_identificacao_status']) ?? ($legacyMapped['gabinete_identificacao_status'] ?? null),
            'gabinete_observacao' => $read($snapshot, ['gabinete_observacao', 'case_notes']),
            'placa_mae' => $read($snapshot, ['placa_mae', 'motherboard']) ?? ($legacyMapped['placa_mae'] ?? null),
            'chipset' => $read($snapshot, ['chipset']) ?? ($legacyMapped['chipset'] ?? null),
            'processador' => $read($snapshot, ['processador', 'cpu']) ?? ($legacyMapped['processador'] ?? null),
            'memoria_ram' => $read($snapshot, ['memoria_ram', 'ram']) ?? ($legacyMapped['memoria_ram'] ?? null),
            'armazenamento' => $read($snapshot, ['armazenamento', 'storage']) ?? ($legacyMapped['armazenamento'] ?? null),
            'placa_video' => $read($snapshot, ['placa_video', 'gpu']) ?? ($legacyMapped['placa_video'] ?? null),
            'fonte_alimentacao' => $read($snapshot, ['fonte_alimentacao', 'psu']),
            'resumo_tecnico' => $read($snapshot, ['resumo_tecnico', 'technical_summary']),
            'senha_acesso' => $read($snapshot, ['senha_acesso', 'access_password']),
        ];
    }

    /**
     * @param array<string, mixed> $snapshotData
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function buildLocalCollectorResponsePayload(array $snapshotData, array $extra = []): array
    {
        return array_merge([
            'source_path' => $snapshotData['snapshot_path'],
            'saved_at_utc' => $snapshotData['saved_at_utc'],
            'collected_at_utc' => $snapshotData['collected_at_utc'],
            'document' => $snapshotData['document'],
            'snapshot' => $snapshotData['snapshot'],
            'mapped' => $snapshotData['mapped'],
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function readLocalCollectorSnapshotPayload(): array
    {
        $snapshotPath = $this->resolveLocalCollectorSnapshotPath();
        if ($snapshotPath === null) {
            throw new RuntimeException('Nao encontrei o snapshot local do coletor em ' . $this->getCollectorLocalRootPath() . '.', 404);
        }

        $raw = @file_get_contents($snapshotPath);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException('O arquivo local do coletor foi encontrado, mas esta vazio ou indisponivel para leitura.', 422);
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('O snapshot local do coletor nao esta em JSON valido.', 422);
        }

        $snapshot = $decoded['snapshot'] ?? $decoded;
        if (! is_array($snapshot)) {
            throw new RuntimeException('O snapshot local nao contem um payload reconhecido.', 422);
        }

        return [
            'snapshot_path' => $snapshotPath,
            'document' => $decoded,
            'saved_at_utc' => (string) ($decoded['savedAtUtc'] ?? $snapshot['collectedAtUtc'] ?? ''),
            'collected_at_utc' => (string) ($decoded['collectedAtUtc'] ?? $snapshot['collectedAtUtc'] ?? ''),
            'snapshot' => $snapshot,
            'mapped' => $this->mapCollectorSnapshotToEquipmentFields($snapshot),
        ];
    }

    private function resolveLocalCollectorSnapshotPath(): ?string
    {
        $rootPath = $this->getCollectorLocalRootPath();
        $candidates = [
            $rootPath . DIRECTORY_SEPARATOR . 'last-snapshot.json',
            $rootPath . DIRECTORY_SEPARATOR . 'snapshot.json',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        if (is_dir($rootPath)) {
            $namedSnapshots = glob($rootPath . DIRECTORY_SEPARATOR . 'inf_*.json') ?: [];
            usort($namedSnapshots, static function (string $left, string $right): int {
                return (int) (@filemtime($right) ?: 0) <=> (int) (@filemtime($left) ?: 0);
            });

            foreach ($namedSnapshots as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCollectorSnapshotToEquipmentFields(array $snapshot): array
    {
        $motherboard = trim((string) ($snapshot['motherboard'] ?? ''));
        $chipset = trim((string) ($snapshot['chipset'] ?? ''));
        $cpu = trim((string) ($snapshot['cpu'] ?? ''));
        $gpu = trim((string) ($snapshot['gpu'] ?? ''));
        $storageSummary = trim((string) ($snapshot['storageSummary'] ?? ($snapshot['storage'] ?? '')));
        $memorySummary = trim((string) ($snapshot['memorySummary'] ?? ($snapshot['ram'] ?? '')));
        $ramGb = $snapshot['ramGb'] ?? null;
        $serialNumber = trim((string) ($snapshot['serialNumber'] ?? ($snapshot['serial_number'] ?? '')));
        $manufacturer = trim((string) ($snapshot['manufacturer'] ?? ($snapshot['brand'] ?? '')));
        $model = trim((string) ($snapshot['model'] ?? ($snapshot['modelo'] ?? '')));
        $deviceType = trim((string) ($snapshot['deviceType'] ?? ($snapshot['equipment_type'] ?? '')));
        $chassisType = trim((string) ($snapshot['chassisType'] ?? ''));
        $serialSource = trim((string) ($snapshot['serialSource'] ?? ''));
        $gabineteStatus = '';
        $gabineteTipo = '';
        $catalogModel = $chipset !== '' ? $chipset : $model;

        if ($memorySummary === '' && $ramGb !== null && $ramGb !== '') {
            $memorySummary = rtrim(rtrim(number_format((float) $ramGb, 2, '.', ''), '0'), '.') . ' GB';
        }

        if (strtolower($deviceType) === 'desktop') {
            $gabineteStatus = $chassisType !== '' ? 'detectado' : 'a_confirmar';
            $gabineteTipo = $this->mapChassisTypeToCaseType($chassisType);
        }

        return [
            'numero_serie' => $serialNumber,
            'numero_serie_origem' => $serialSource,
            'placa_mae' => $motherboard,
            'chipset' => $chipset,
            'processador' => $cpu,
            'memoria_ram' => $memorySummary,
            'armazenamento' => $storageSummary,
            'placa_video' => $gpu,
            'gabinete_identificacao_status' => $gabineteStatus,
            'gabinete_tipo' => $gabineteTipo,
            'device_type' => $deviceType,
            'chassis_type' => $chassisType,
            'manufacturer' => $manufacturer,
            'catalog_model' => $catalogModel,
            'model' => $model,
            'marca_nome' => $manufacturer,
            'modelo_nome' => $catalogModel !== '' ? $catalogModel : $model,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runLocalCollectorCapture(): array
    {
        if (! $this->supportsLocalCollector()) {
            throw new RuntimeException('A coleta local do coletor so esta disponivel quando o ERP estiver rodando na mesma maquina Windows ou Linux da bancada.', 422);
        }

        if (! function_exists('exec')) {
            $fallback = $this->buildCollectorFallbackRunResult('O PHP deste ambiente nao permite executar o coletor automaticamente. Foi usado o ultimo snapshot local disponivel.');
            if ($fallback !== null) {
                return $fallback;
            }

            throw new RuntimeException('O PHP deste ambiente nao permite executar o coletor automaticamente.', 500);
        }

        if (! is_file($this->getPublishedCollectorExecutablePath())) {
            $fallback = $this->buildCollectorFallbackRunResult('O executavel publicado do coletor nao foi encontrado. Foi usado o ultimo snapshot local disponivel.');
            if ($fallback !== null) {
                return $fallback;
            }

            throw new RuntimeException('Nao encontrei o executavel publicado do coletor em assets/agents/bench-collector/' . ($this->isLinuxHost() ? 'linux-x64' : 'win-x64') . '.', 500);
        }

        $installInfo = $this->ensureLocalCollectorInstalled();
        $this->clearLocalCollectorSnapshotCache();

        // Windows executa o .exe diretamente; Linux precisa do interpretador
        // bash explicito (o exec bit sozinho as vezes nao basta dependendo de
        // como o arquivo foi copiado/montado).
        $command = $this->isLinuxHost()
            ? 'bash ' . escapeshellarg($installInfo['executable_path']) . ' --dry-run --no-prompt --no-save-config'
            : '"' . $installInfo['executable_path'] . '" --dry-run --no-prompt --no-save-config';
        $output = [];
        $exitCode = 1;
        @exec($command . ' 2>&1', $output, $exitCode);

        $snapshotPath = $this->resolveLocalCollectorSnapshotPath();
        $result = [
            'executable_path' => $installInfo['executable_path'],
            'installed_now' => $installInfo['installed_now'],
            'output' => trim(implode("\n", $output)),
            'exit_code' => $exitCode,
            'cleanup' => [
                'removed_paths' => [],
                'kept_snapshot_path' => $snapshotPath ?? '',
            ],
        ];

        if ($exitCode !== 0) {
            if ($snapshotPath !== null) {
                $result['warning'] = 'O coletor retornou aviso na execucao, mas um snapshot local foi encontrado apos a tentativa.';
                return $result;
            }

            $details = $result['output'] !== '' ? ' Saida do coletor: ' . $result['output'] : '';
            throw new RuntimeException('Nao foi possivel executar o coletor local automaticamente.' . $details, 500);
        }

        if ($snapshotPath === null) {
            throw new RuntimeException('O coletor foi executado, mas nao gerou o snapshot local esperado em ' . $this->getCollectorLocalRootPath() . '.', 422);
        }

        $result['cleanup'] = $this->cleanupLocalCollectorTemporaryArtifacts($snapshotPath);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildCollectorFallbackRunResult(string $warning): ?array
    {
        $snapshotPath = $this->resolveLocalCollectorSnapshotPath();
        if ($snapshotPath === null) {
            return null;
        }

        return [
            'executed' => false,
            'installed_now' => false,
            'output' => '',
            'exit_code' => null,
            'warning' => $warning,
            'cleanup' => [
                'removed_paths' => [],
                'kept_snapshot_path' => $snapshotPath,
            ],
        ];
    }

    /**
     * @return array{root_path:string,executable_path:string,installed_now:bool}
     */
    private function ensureLocalCollectorInstalled(): array
    {
        $rootPath = $this->getCollectorLocalRootPath();
        $sourceExe = $this->getPublishedCollectorExecutablePath();
        $sourceReadme = $this->getPublishedCollectorReadmePath();
        $targetExe = $this->getCollectorLocalExecutablePath();

        if (! is_dir($rootPath) && ! @mkdir($rootPath, 0777, true) && ! is_dir($rootPath)) {
            throw new RuntimeException('Nao foi possivel criar a pasta local ' . $rootPath . ' para o coletor.', 500);
        }

        $targetExists = is_file($targetExe);
        $sourceMtime = @filemtime($sourceExe) ?: 0;
        $targetMtime = $targetExists ? (@filemtime($targetExe) ?: 0) : 0;
        $installedNow = false;

        if (! $targetExists || @filesize($targetExe) !== @filesize($sourceExe) || $sourceMtime > $targetMtime) {
            if (! @copy($sourceExe, $targetExe)) {
                throw new RuntimeException('Nao foi possivel copiar o coletor para ' . $rootPath . '.', 500);
            }

            // copy() nao preserva o bit de execucao — sem isto o script Linux
            // fica sem permissao de rodar mesmo com o exec() correto.
            if ($this->isLinuxHost()) {
                @chmod($targetExe, 0755);
            }

            $installedNow = true;
        }

        if (is_file($sourceReadme)) {
            @copy($sourceReadme, $rootPath . DIRECTORY_SEPARATOR . 'README.md');
        }

        return [
            'root_path' => $rootPath,
            'executable_path' => $targetExe,
            'installed_now' => $installedNow,
        ];
    }

    private function clearLocalCollectorSnapshotCache(): void
    {
        $rootPath = $this->getCollectorLocalRootPath();
        foreach ([
            $rootPath . DIRECTORY_SEPARATOR . 'last-snapshot.json',
            $rootPath . DIRECTORY_SEPARATOR . 'snapshot.json',
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        clearstatcache();
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanupLocalCollectorTemporaryArtifacts(string $keepSnapshotPath = ''): array
    {
        $removed = [];
        $rootPath = $this->getCollectorLocalRootPath();

        foreach ([
            $this->getCollectorLocalExecutablePath(),
            $rootPath . DIRECTORY_SEPARATOR . 'README.md',
        ] as $path) {
            if (is_file($path) && @unlink($path)) {
                $removed[] = $path;
            }
        }

        foreach ([
            $rootPath . DIRECTORY_SEPARATOR . 'last-snapshot.json',
            $rootPath . DIRECTORY_SEPARATOR . 'snapshot.json',
        ] as $path) {
            if ($keepSnapshotPath !== '' && strcasecmp($path, $keepSnapshotPath) === 0) {
                continue;
            }

            if (is_file($path)) {
                @unlink($path);
            }
        }

        clearstatcache();

        return [
            'removed_paths' => $removed,
            'kept_snapshot_path' => $keepSnapshotPath,
        ];
    }

    private function getCollectorLocalRootPath(): string
    {
        if ($this->isLinuxHost()) {
            return trim((string) config('services.collector.local_root_linux', '/home/JovemTechBenchCollector'));
        }

        return trim((string) config('services.collector.local_root', 'C:\\JovemTechBenchCollector'));
    }

    private function getPublishedCollectorRootPath(): string
    {
        $configKey = $this->isLinuxHost() ? 'services.collector.published_root_linux' : 'services.collector.published_root';
        $configured = trim((string) config($configKey, ''));
        if ($configured !== '') {
            return rtrim($configured, '\\/');
        }

        return public_path('assets/agents/bench-collector/' . ($this->isLinuxHost() ? 'linux-x64' : 'win-x64'));
    }

    private function getPublishedCollectorExecutablePath(): string
    {
        return $this->getPublishedCollectorRootPath() . DIRECTORY_SEPARATOR . $this->getCollectorExecutableFilename();
    }

    private function getPublishedCollectorReadmePath(): string
    {
        return $this->getPublishedCollectorRootPath() . DIRECTORY_SEPARATOR . 'README.md';
    }

    private function getCollectorLocalExecutablePath(): string
    {
        return $this->getCollectorLocalRootPath() . DIRECTORY_SEPARATOR . $this->getCollectorExecutableFilename();
    }

    private function getCollectorExecutableFilename(): string
    {
        return $this->isLinuxHost() ? 'jovemtech-bench-collector.sh' : 'JovemTechBenchCollector.exe';
    }

    private function isWindowsHost(): bool
    {
        return strtoupper(substr(PHP_OS_FAMILY, 0, 3)) === 'WIN';
    }

    private function isLinuxHost(): bool
    {
        return strtoupper(substr(PHP_OS_FAMILY, 0, 5)) === 'LINUX';
    }

    /**
     * Coleta local via exec() so faz sentido quando o backend roda na MESMA
     * maquina fisica que esta sendo diagnosticada (o bench da assistencia).
     * Windows usa o .exe publicado; Linux usa o script shell equivalente —
     * ambos produzem o mesmo formato de snapshot consumido por
     * mapCollectorSnapshotToEquipmentFields().
     */
    private function supportsLocalCollector(): bool
    {
        return $this->isWindowsHost() || $this->isLinuxHost();
    }

    private function mapChassisTypeToCaseType(string $chassisType): string
    {
        $label = strtolower(trim($chassisType));
        if ($label === '') {
            return '';
        }

        if (str_contains($label, 'rack')) {
            return 'Rack / Industrial';
        }

        if (str_contains($label, 'mini tower')) {
            return 'Mini Tower';
        }

        if (str_contains($label, 'full tower')) {
            return 'Full Tower';
        }

        if (
            str_contains($label, 'lunch box')
            || str_contains($label, 'mini pc')
            || str_contains($label, 'stick pc')
            || str_contains($label, 'pizza box')
            || str_contains($label, 'sealed case')
            || str_contains($label, 'cube')
        ) {
            return 'Compacto / Cube';
        }

        if (
            str_contains($label, 'low profile')
            || str_contains($label, 'space saving')
            || $label === 'desktop'
        ) {
            return 'Slim / SFF';
        }

        if (str_contains($label, 'tower')) {
            return 'Mid Tower';
        }

        return 'Nao identificado / A confirmar';
    }

    private function normalizeDesktopMode(mixed $value): ?string
    {
        $mode = trim((string) $value);
        if ($mode === '') {
            return null;
        }

        $mode = Str::slug($mode, '_');

        return in_array($mode, ['montado', 'oem'], true) ? $mode : null;
    }

    private function normalizeCatalogName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function normalizeStoredPath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));
        if (
            $normalized === ''
            || str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || (bool) preg_match('/^[a-z]:\//i', $normalized)
            || (bool) preg_match('#(^|/)\.\.(/|$)#', $normalized)
        ) {
            return '';
        }

        $segments = array_values(array_filter(
            explode('/', $normalized),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.'
        ));

        return implode('/', $segments);
    }

    private function resolveTypeName(int $typeId): string
    {
        if ($typeId <= 0) {
            return '';
        }

        return trim((string) (EquipmentType::query()->find($typeId)?->nome ?? ''));
    }

    private function resolveBrandName(int $brandId): string
    {
        if ($brandId <= 0) {
            return '';
        }

        return trim((string) (EquipmentBrand::query()->find($brandId)?->nome ?? ''));
    }

    private function resolveModelName(int $modelId): string
    {
        if ($modelId <= 0) {
            return '';
        }

        return trim((string) (EquipmentModel::query()->find($modelId)?->nome ?? ''));
    }

    private function normalizeTypeHint(string $value): string
    {
        $type = Str::lower(trim($value));

        return match (true) {
            str_contains($type, 'smart') => 'smartphone',
            str_contains($type, 'notebook'), str_contains($type, 'laptop') => 'notebook',
            str_contains($type, 'desktop'), str_contains($type, 'computador'), str_contains($type, 'pc') => 'computador',
            default => $type,
        };
    }

    private function extractModelSuggestion(string $text, string $brandName, string $typeName): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? $text);
        if ($text === '') {
            return '';
        }

        if ((bool) preg_match('#(https?://|www\\.|\\.com|\\.br|mercadolivre|amazon|shopee|magalu)#i', $text)) {
            return '';
        }

        $searchTokens = array_filter([$typeName, $brandName]);
        foreach ($searchTokens as $token) {
            if ($token === '') {
                continue;
            }

            $text = preg_replace('/\b' . preg_quote($token, '/') . '\b/ui', '', $text) ?? $text;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) >= 2 ? $text : '';
    }

    private function humanizeDesktopMode(string $mode): string
    {
        return match ($mode) {
            'oem' => 'OEM / fabricante',
            'montado' => 'Desktop montado',
            default => ucfirst($mode),
        };
    }

    public static function resolveTypeFamily(string $typeName): string
    {
        $slug = Str::slug($typeName, '_');

        return match ($slug) {
            'desktop', 'computador', 'pc' => 'desktop',
            'notebook', 'laptop' => 'notebook',
            default => 'other',
        };
    }
}
