<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Backups\BackupOrigin;
use App\Enums\Backups\BackupStatus;
use App\Enums\Backups\BackupType;
use App\Models\Backups\Backup;
use App\Services\Backups\BackupDiscoveryService;
use App\Services\Backups\BackupPassphraseResolver;
use App\Services\Backups\BackupPreflight;
use App\Services\Backups\BackupSettingsService;
use App\Services\Backups\BackupVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class BackupController extends BaseApiController
{
    public function __construct(
        private readonly BackupSettingsService $settings,
        private readonly BackupPassphraseResolver $passphrase,
        private readonly BackupDiscoveryService $discovery,
        private readonly BackupVerificationService $verifier,
        private readonly BackupPreflight $preflight,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('backups:visualizar');

        $query = Backup::query()->orderByDesc('created_at');

        if (($origem = trim((string) $request->query('origem', ''))) !== '') {
            $query->where('origem', $origem);
        }

        if (($conteudo = trim((string) $request->query('conteudo', ''))) !== '') {
            $query->where('conteudo', $conteudo);
        }

        $paginator = $query->paginate(min(100, max(5, (int) $request->query('per_page', 20))));

        return $this->success(
            array_map(fn (Backup $b): array => $this->present($b), $paginator->items()),
            meta: $this->paginationMeta($paginator),
        );
    }

    public function summary(): JsonResponse
    {
        $this->authorize('backups:visualizar');

        $ultimoCompleto = Backup::query()->successful()->where('conteudo', 'completo')->latest('created_at')->first();
        $ultimoQualquer = Backup::query()->successful()->latest('created_at')->first();
        $emAndamento = Backup::query()->running()->latest('id')->first();
        $check = $this->preflight->check();

        return $this->success([
            'ultimo_completo' => $ultimoCompleto === null ? null : $this->present($ultimoCompleto),
            'ultimo_backup' => $ultimoQualquer === null ? null : $this->present($ultimoQualquer),
            'em_andamento' => $emAndamento === null ? null : $this->present($emAndamento),
            'total' => Backup::query()->count(),
            'bytes_ocupados' => (int) Backup::query()->successful()->sum('tamanho_bytes'),
            'agendado_para' => $this->settings->get('backup_horario', '03:15'),
            'agendamento_ativo' => $this->settings->bool('backup_agendado_habilitado'),
            'frase_configurada' => $this->passphrase->isConfigured(),
            'ambiente' => ['ok' => $check['ok'], 'erros' => $check['erros'], 'avisos' => $check['avisos']],
            // Enquanto nao existir um pacote completo, o painel precisa dizer
            // com todas as letras que as imagens e documentos estao a descoberto.
            'alerta_sem_backup_completo' => $ultimoCompleto === null,
        ]);
    }

    public function store(): JsonResponse
    {
        $this->authorize('backups:criar');

        if (! $this->passphrase->isConfigured()) {
            return $this->error(
                'Defina a frase secreta em Configurações → Backup antes de gerar backups.',
                status: 422,
            );
        }

        if (Backup::query()->running()->exists()) {
            return $this->error('Já existe um backup em andamento.', status: 409);
        }

        $check = $this->preflight->check();

        if (! $check['ok']) {
            return $this->error(implode(' ', $check['erros']), status: 422);
        }

        // A API apenas ENFILEIRA. O backup roda pelo scheduler, que ja executa
        // a cada minuto como www-data: o pool PHP-FPM corta em 60s e a fila
        // Redis re-reservaria o job aos 180s, fazendo-o rodar duas vezes.
        $backup = Backup::query()->create([
            'uuid' => (string) Str::uuid(),
            'tipo' => BackupType::Completo->value,
            'origem' => BackupOrigin::Painel->value,
            'status' => BackupStatus::Pendente->value,
            'gerenciado' => true,
            'etapa_atual' => 'Na fila',
            'solicitado_por' => $this->authenticatedUser(request())?->id,
        ]);

        return $this->success($this->present($backup), status: 202);
    }

    public function show(string $uuid): JsonResponse
    {
        $this->authorize('backups:visualizar');

        return $this->success($this->present($this->findOrFail($uuid)));
    }

    public function scan(): JsonResponse
    {
        $this->authorize('backups:visualizar');

        return $this->success($this->discovery->scan());
    }

    public function verify(string $uuid): JsonResponse
    {
        $this->authorize('backups:restaurar');

        $backup = $this->findOrFail($uuid);

        try {
            return $this->success($this->verifier->verify($backup));
        } catch (Throwable $exception) {
            return $this->error('Não foi possível verificar este backup: '.$exception->getMessage(), status: 500);
        }
    }

    /**
     * Devolve uma URL assinada de curta duração em vez dos bytes.
     *
     * ApiClient::download() no desktop faz $response->body() — uma string
     * inteira em memória, com timeout de 15s. Proxiar 130 MB por ali estoura
     * o memory_limit de 256M. O navegador busca direto do backend.
     */
    public function downloadLink(string $uuid): JsonResponse
    {
        $this->authorize('backups:baixar');

        $backup = $this->findOrFail($uuid);

        if (! $backup->isRestorable()) {
            return $this->error('O arquivo deste backup não está disponível no disco.', status: 404);
        }

        return $this->success([
            'url' => URL::temporarySignedRoute('backups.arquivo', now()->addMinutes(10), ['uuid' => $backup->uuid]),
            'nome' => (string) $backup->arquivo_nome,
            'bytes' => (int) $backup->tamanho_bytes,
            'expira_em' => now()->addMinutes(10)->toIso8601String(),
        ]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->authorize('backups:excluir');

        $backup = $this->findOrFail($uuid);

        if (! $backup->canBeDeletedByPanel()) {
            return $this->error(
                $backup->protegido
                    ? 'Este backup está fixado. Remova a proteção antes de excluí-lo.'
                    : 'Este backup foi criado fora do sistema e não pode ser excluído pelo painel. '
                        .'A retenção dele é do cron do servidor.',
                status: 422,
            );
        }

        $path = (string) $backup->arquivo_caminho;

        if (is_file($path)) {
            @unlink($path);
        }

        $backup->forceFill([
            'status' => BackupStatus::Expirado->value,
            'etapa_atual' => 'Excluído pelo painel',
        ])->save();

        return $this->success($this->present($backup->refresh()));
    }

    public function pin(Request $request, string $uuid): JsonResponse
    {
        $this->authorize('backups:excluir');

        $backup = $this->findOrFail($uuid);
        $backup->forceFill(['protegido' => (bool) $request->boolean('protegido', true)])->save();

        return $this->success($this->present($backup->refresh()));
    }

    public function settings(): JsonResponse
    {
        $this->authorize('backups:administrar');

        return $this->success($this->settings->payload());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('backups:administrar');

        $validated = $request->validate([
            'backup_agendado_habilitado' => ['nullable', 'boolean'],
            'backup_horario' => ['nullable', 'date_format:H:i'],
            'backup_retencao_diarios' => ['nullable', 'integer', 'min:0', 'max:365'],
            'backup_retencao_semanais' => ['nullable', 'integer', 'min:0', 'max:104'],
            'backup_retencao_mensais' => ['nullable', 'integer', 'min:0', 'max:120'],
            'backup_retencao_minimo_copias' => ['nullable', 'integer', 'min:1', 'max:50'],
            'backup_incluir_banco_chat' => ['nullable', 'boolean'],
            'backup_incluir_legado' => ['nullable', 'boolean'],
            'backup_incluir_config' => ['nullable', 'boolean'],
        ], [], [
            'backup_horario' => 'horário do backup',
            'backup_retencao_diarios' => 'retenção de diários',
            'backup_retencao_semanais' => 'retenção de semanais',
            'backup_retencao_mensais' => 'retenção de mensais',
            'backup_retencao_minimo_copias' => 'mínimo de cópias',
        ]);

        $payload = [];
        foreach ($validated as $key => $value) {
            if ($value === null) {
                continue;
            }

            $payload[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $this->success($this->settings->save($payload));
    }

    public function definePassphrase(Request $request): JsonResponse
    {
        $this->authorize('backups:administrar');

        $validated = $request->validate([
            'frase' => ['required', 'string', 'min:12', 'max:200', 'confirmed'],
            'modo' => ['nullable', 'in:armazenada,manual'],
        ], [], ['frase' => 'frase secreta']);

        $this->settings->put('backup_passphrase_modo', (string) ($validated['modo'] ?? 'armazenada'));

        try {
            $this->passphrase->define((string) $validated['frase']);
        } catch (Throwable $exception) {
            return $this->error($exception->getMessage(), status: 422);
        }

        return $this->success([
            'fingerprint' => $this->passphrase->storedFingerprint(),
            'aviso' => 'Guarde esta frase fora do servidor. Sem ela, nenhum backup pode ser restaurado.',
        ]);
    }

    private function findOrFail(string $uuid): Backup
    {
        return Backup::query()->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(Backup $backup): array
    {
        // arquivo_caminho JAMAIS sai daqui: caminhos absolutos do servidor nao
        // vao para o navegador (documentacao/01-fundacao/acesso-seguro-a-arquivos.md).
        return [
            'uuid' => (string) $backup->uuid,
            'arquivo_nome' => (string) $backup->arquivo_nome,
            'tipo' => $backup->tipo?->value,
            'tipo_label' => $backup->tipo?->label(),
            'origem' => $backup->origem?->value,
            'origem_label' => $backup->origem?->label(),
            'conteudo' => $backup->conteudo?->value,
            'conteudo_label' => $backup->conteudo?->label(),
            'status' => $backup->status?->value,
            'status_label' => $backup->status?->label(),
            'etapa_atual' => $backup->etapa_atual,
            'progresso_percentual' => (int) $backup->progresso_percentual,
            'tamanho_bytes' => (int) $backup->tamanho_bytes,
            'total_arquivos' => (int) $backup->total_arquivos,
            'duracao_segundos' => $backup->duracao_segundos,
            'versao_sistema' => $backup->versao_sistema,
            'gerenciado' => (bool) $backup->gerenciado,
            'protegido' => (bool) $backup->protegido,
            'pode_excluir' => $backup->canBeDeletedByPanel(),
            'pode_restaurar' => $backup->isRestorable(),
            'avisos' => $backup->warnings(),
            'erro_mensagem' => $backup->erro_mensagem,
            'bancos_incluidos' => $backup->bancos_incluidos ?? [],
            'raizes_incluidas' => $backup->raizes_incluidas ?? [],
            'criado_em' => $backup->created_at?->toIso8601String(),
            'concluido_em' => $backup->concluido_em?->toIso8601String(),
        ];
    }
}
