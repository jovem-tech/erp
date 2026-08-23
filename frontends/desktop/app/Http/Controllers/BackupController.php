<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BackupController extends DesktopController
{
    public function __construct(private readonly BackupService $backupService) {}

    /** Alimenta o painel e o polling de progresso. */
    public function data(Request $request): JsonResponse
    {
        return $this->proxy(function () use ($request): array {
            $catalog = $this->backupService->catalog([
                'per_page' => (int) $request->query('per_page', 20),
                'origem' => trim((string) $request->query('origem', '')),
                'conteudo' => trim((string) $request->query('conteudo', '')),
            ]);

            return [
                'resumo' => $this->backupService->summary(),
                'backups' => $catalog['items'],
                'paginacao' => $catalog['pagination'],
            ];
        });
    }

    public function generate(): JsonResponse
    {
        return $this->proxy(fn (): array => $this->backupService->generate());
    }

    public function scan(): JsonResponse
    {
        return $this->proxy(fn (): array => $this->backupService->scan());
    }

    public function verify(string $uuid): JsonResponse
    {
        return $this->proxy(fn (): array => $this->backupService->verify($uuid));
    }

    /**
     * Devolve o link assinado; o navegador busca o arquivo direto do backend.
     *
     * Os bytes nunca passam por aqui de propósito: ApiClient::download() faz
     * $response->body(), uma string inteira em memória, e o pacote tem ~130 MB.
     */
    public function downloadLink(string $uuid): JsonResponse
    {
        return $this->proxy(fn (): array => $this->backupService->downloadLink($uuid));
    }

    public function destroy(string $uuid): JsonResponse
    {
        return $this->proxy(fn (): array => $this->backupService->destroy($uuid));
    }

    public function pin(Request $request, string $uuid): JsonResponse
    {
        $protegido = $request->boolean('protegido', true);

        return $this->proxy(fn (): array => $this->backupService->pin($uuid, $protegido));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $payload = $request->only([
            'backup_agendado_habilitado',
            'backup_horario',
            'backup_retencao_diarios',
            'backup_retencao_semanais',
            'backup_retencao_mensais',
            'backup_retencao_minimo_copias',
            'backup_incluir_banco_chat',
            'backup_incluir_legado',
            'backup_incluir_config',
        ]);

        return $this->proxy(fn (): array => $this->backupService->updateSettings($payload));
    }

    public function definePassphrase(Request $request): JsonResponse
    {
        $payload = $request->only(['frase', 'frase_confirmation', 'modo']);

        return $this->proxy(fn (): array => $this->backupService->definePassphrase($payload));
    }

    /** @param callable(): array<string, mixed> $callback */
    private function proxy(callable $callback): JsonResponse
    {
        try {
            return response()->json(['ok' => true, 'data' => $callback()]);
        } catch (ApiAuthenticationException) {
            return response()->json([
                'ok' => false,
                'mensagem' => 'Sua sessão expirou. Entre novamente.',
            ], 401);
        } catch (ApiAuthorizationException) {
            return response()->json([
                'ok' => false,
                'mensagem' => 'Você não tem permissão para esta ação.',
            ], 403);
        } catch (ApiRequestException $exception) {
            return response()->json([
                'ok' => false,
                'mensagem' => $exception->getMessage(),
            ], max(400, min(499, $exception->getCode() ?: 422)));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'mensagem' => 'Não foi possível concluir a operação de backup.',
            ], 500);
        }
    }
}
