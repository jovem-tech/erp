<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\FileManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class FileManagerController extends DesktopController
{
    public function __construct(private readonly FileManagerService $files) {}

    public function index(Request $request): View|RedirectResponse
    {
        $viewMode = in_array((string) $request->query('view', 'grid'), ['grid', 'list'], true)
            ? (string) $request->query('view', 'grid')
            : 'grid';
        $auditOnly = (string) $request->query('lifecycle_status', '') === 'audit';
        $filters = $request->only([
            'q',
            'category',
            'lifecycle_status',
            'integrity_status',
            'security_status',
            'migration_status',
            'created_from',
            'created_to',
            'page',
        ]);
        if ($auditOnly) {
            unset($filters['lifecycle_status'], $filters['integrity_status']);
            $filters['audit_only'] = true;
        } elseif (! $request->has('lifecycle_status')) {
            $filters['lifecycle_status'] = 'active';
        }
        $filters['per_page'] = $viewMode === 'grid' ? 24 : 50;

        try {
            $dashboard = $this->files->dashboard();
            $catalog = $this->files->catalog($filters);
            $findings = $this->files->findings();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }

        return view('files.index', [
            'pageTitle' => 'Gerenciador de Arquivos',
            'dashboard' => $dashboard,
            'files' => $catalog['items'],
            'pagination' => $catalog['pagination'],
            'findings' => $findings,
            'filters' => $filters,
            'viewMode' => $viewMode,
        ]);
    }

    public function show(string $fileUuid): View|RedirectResponse
    {
        try {
            $file = $this->files->file($fileUuid);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('files.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('files.index')->with('error', $exception->getMessage());
        }

        return view('files.show', [
            'pageTitle' => 'Detalhes do arquivo',
            'file' => $file,
        ]);
    }

    public function synchronize(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $result = $this->files->requestSynchronization();
        } catch (ApiAuthenticationException $exception) {
            return $this->actionError($request, $exception->getMessage(), 401, true);
        } catch (ApiAuthorizationException $exception) {
            return $this->actionError($request, $exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            $status = in_array($exception->statusCode(), [409, 429, 503], true)
                ? $exception->statusCode()
                : 500;

            $message = $status === 429
                ? 'Muitas solicitações em sequência. Aguarde até um minuto antes de sincronizar novamente.'
                : $exception->getMessage();

            return $this->actionError($request, $message, $status);
        }

        $message = (bool) ($result['queued'] ?? false)
            ? 'Sincronização solicitada. O processamento começa em até um minuto.'
            : 'Já existe uma solicitação de sincronização aguardando processamento.';
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message, 'result' => $result]);
        }

        return redirect()->back()->with('success', $message);
    }

    public function download(string $fileUuid): Response|RedirectResponse
    {
        return $this->deliver($fileUuid, false);
    }

    public function downloadBatch(Request $request): Response|RedirectResponse
    {
        $selection = $this->validatedSelection($request, 50);

        try {
            $download = $this->files->downloadBatch($selection);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('files.index')->with('error', $exception->getMessage());
        }

        $response = response($download['body'], $download['status']);
        foreach (['Content-Type', 'Content-Disposition', 'Cache-Control'] as $header) {
            if (isset($download['headers'][$header])) {
                $response->headers->set($header, $download['headers'][$header]);
            }
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function preview(string $fileUuid): Response|RedirectResponse
    {
        return $this->deliver($fileUuid, true);
    }

    public function thumbnail(string $fileUuid): Response
    {
        try {
            $thumbnail = $this->files->thumbnail($fileUuid);
        } catch (ApiAuthenticationException) {
            return response('', 401, ['Cache-Control' => 'private, no-store']);
        } catch (ApiAuthorizationException) {
            return response('', 404, ['Cache-Control' => 'private, no-store']);
        } catch (ApiRequestException $exception) {
            $status = in_array($exception->statusCode(), [404, 409, 415, 429, 503], true)
                ? $exception->statusCode()
                : 503;

            return response('', $status, ['Cache-Control' => 'private, no-store']);
        }

        return $this->binaryResponse($thumbnail);
    }

    public function archive(Request $request, string $fileUuid): JsonResponse|RedirectResponse
    {
        return $this->performAction($request, $fileUuid, 'archive');
    }

    public function restore(Request $request, string $fileUuid): JsonResponse|RedirectResponse
    {
        return $this->performAction($request, $fileUuid, 'restore');
    }

    public function quarantine(Request $request, string $fileUuid): JsonResponse|RedirectResponse
    {
        return $this->performAction($request, $fileUuid, 'quarantine');
    }

    public function releaseQuarantine(Request $request, string $fileUuid): JsonResponse|RedirectResponse
    {
        return $this->performAction($request, $fileUuid, 'release-quarantine');
    }

    public function trashBatch(Request $request): JsonResponse|RedirectResponse
    {
        $selection = $this->validatedSelection($request, 100);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'max:200'],
        ]);

        try {
            $result = $this->files->trashBatch(
                $selection,
                [
                    'reason' => trim((string) $validated['reason']),
                    'admin_email' => trim((string) $validated['admin_email']),
                    'admin_password' => (string) $validated['admin_password'],
                ]
            );
        } catch (ApiAuthenticationException $exception) {
            return $this->actionError($request, $exception->getMessage(), 401, true);
        } catch (ApiAuthorizationException $exception) {
            return $this->actionError($request, $exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            $status = in_array($exception->statusCode(), [409, 422, 429], true)
                ? $exception->statusCode()
                : 500;

            return $this->actionError($request, $exception->getMessage(), $status);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Arquivos movidos para a lixeira.',
                'result' => $result,
            ]);
        }

        return redirect()->route('files.index')->with('success', 'Arquivos movidos para a lixeira.');
    }

    public function restoreBatch(Request $request): JsonResponse|RedirectResponse
    {
        return $this->performTrashBatchAction($request, 'restore');
    }

    public function purgeBatch(Request $request): JsonResponse|RedirectResponse
    {
        return $this->performTrashBatchAction($request, 'purge');
    }

    public function updateTrashRetention(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'in:0,7,30,90'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'max:200'],
        ]);

        try {
            $settings = $this->files->updateTrashRetention([
                'days' => (int) $validated['days'],
                'reason' => trim((string) $validated['reason']),
                'admin_email' => trim((string) $validated['admin_email']),
                'admin_password' => (string) $validated['admin_password'],
            ]);
        } catch (ApiAuthenticationException $exception) {
            return $this->actionError($request, $exception->getMessage(), 401, true);
        } catch (ApiAuthorizationException $exception) {
            return $this->actionError($request, $exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            $status = in_array($exception->statusCode(), [409, 422, 429], true)
                ? $exception->statusCode()
                : 500;

            return $this->actionError($request, $exception->getMessage(), $status);
        }

        $message = ((int) ($settings['days'] ?? 0)) === 0
            ? 'Exclusão automática da lixeira desativada.'
            : 'Retenção da lixeira atualizada para '.(int) $settings['days'].' dias.';

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message, 'settings' => $settings]);
        }

        return redirect()->back()->with('success', $message);
    }

    private function deliver(string $fileUuid, bool $preview): Response|RedirectResponse
    {
        try {
            $download = $this->files->download($fileUuid, $preview);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException|ApiRequestException $exception) {
            return redirect()->route('files.show', $fileUuid)->with('error', $exception->getMessage());
        }

        return $this->binaryResponse($download);
    }

    private function performTrashBatchAction(Request $request, string $action): JsonResponse|RedirectResponse
    {
        $selection = $this->validatedSelection($request, $action === 'purge' ? 50 : 100);
        $rules = [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'max:200'],
        ];
        if ($action === 'purge') {
            $rules['confirmation'] = ['required', 'string', 'in:EXCLUIR'];
        }
        $validated = $request->validate($rules);
        $payload = [
            'reason' => trim((string) $validated['reason']),
            'admin_email' => trim((string) $validated['admin_email']),
            'admin_password' => (string) $validated['admin_password'],
        ];
        if ($action === 'purge') {
            $payload['confirmation'] = (string) $validated['confirmation'];
        }

        try {
            $result = $action === 'purge'
                ? $this->files->purgeBatch($selection, $payload)
                : $this->files->restoreBatch($selection, $payload);
        } catch (ApiAuthenticationException $exception) {
            return $this->actionError($request, $exception->getMessage(), 401, true);
        } catch (ApiAuthorizationException $exception) {
            return $this->actionError($request, $exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            $status = in_array($exception->statusCode(), [409, 422, 429], true)
                ? $exception->statusCode()
                : 500;

            return $this->actionError($request, $exception->getMessage(), $status);
        }

        $message = $action === 'purge'
            ? ((int) ($result['failed_count'] ?? 0) > 0
                ? 'A exclusão definitiva terminou com falhas em alguns arquivos.'
                : 'Arquivos excluídos definitivamente; os eventos de auditoria foram preservados.')
            : 'Arquivos restaurados com sucesso.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'partial' => (int) ($result['failed_count'] ?? 0) > 0,
                'message' => $message,
                'result' => $result,
            ]);
        }

        return redirect()->back()->with(
            (int) ($result['failed_count'] ?? 0) > 0 ? 'error' : 'success',
            $message
        );
    }

    /**
     * Alvo de uma ação em lote: os UUIDs marcados na página ou, com select_all,
     * todos os arquivos do filtro atual. Nesse modo o backend resolve o conjunto
     * e recusa a ação se o total mudou desde que a página foi exibida.
     *
     * @return array<string, mixed>
     */
    private function validatedSelection(Request $request, int $maxExplicit): array
    {
        if (! $request->boolean('select_all')) {
            $validated = $request->validate([
                'file_uuids' => ['required', 'array', 'min:1', 'max:'.$maxExplicit],
                'file_uuids.*' => ['required', 'uuid', 'distinct'],
            ]);

            return ['file_uuids' => array_values((array) $validated['file_uuids'])];
        }

        $validated = $request->validate([
            'filters' => ['required', 'array'],
            'filters.q' => ['nullable', 'string', 'max:200'],
            'filters.category' => ['nullable', 'string', 'max:64'],
            'filters.lifecycle_status' => ['required', 'string', 'in:active,archived,trashed'],
            'filters.integrity_status' => ['nullable', 'string', 'max:32'],
            'filters.security_status' => ['nullable', 'string', 'max:32'],
            'filters.migration_status' => ['nullable', 'string', 'max:32'],
            'filters.created_from' => ['nullable', 'date_format:Y-m-d'],
            'filters.created_to' => ['nullable', 'date_format:Y-m-d'],
            'expected_total' => ['required', 'integer', 'min:1'],
        ]);

        return [
            'select_all' => true,
            'filters' => array_filter(
                (array) $validated['filters'],
                static fn (mixed $value): bool => $value !== null && $value !== ''
            ),
            'expected_total' => (int) $validated['expected_total'],
        ];
    }

    /**
     * @param  array{body: string, headers: array<string, string>, status: int}  $download
     */
    private function binaryResponse(array $download): Response
    {
        $response = response($download['body'], $download['status']);
        foreach (['Content-Type', 'Content-Disposition', 'Cache-Control', 'Last-Modified', 'ETag'] as $header) {
            if (isset($download['headers'][$header])) {
                $response->headers->set($header, $download['headers'][$header]);
            }
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function performAction(Request $request, string $fileUuid, string $action): JsonResponse|RedirectResponse
    {
        $rules = [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'max:200'],
        ];
        if ($action === 'release-quarantine') {
            $rules['validation_reference'] = ['required', 'string', 'min:3', 'max:120'];
        }
        $validated = $request->validate($rules);

        try {
            $file = $this->files->mutate($fileUuid, $action, array_map(
                static fn ($value): string => trim((string) $value),
                $validated
            ));
        } catch (ApiAuthenticationException $exception) {
            return $this->actionError($request, $exception->getMessage(), 401, true);
        } catch (ApiAuthorizationException $exception) {
            return $this->actionError($request, $exception->getMessage(), 403);
        } catch (ApiRequestException $exception) {
            $status = in_array($exception->statusCode(), [409, 422, 429], true)
                ? $exception->statusCode()
                : 500;

            return $this->actionError($request, $exception->getMessage(), $status);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Estado do arquivo atualizado e auditado.',
                'file' => $file,
            ]);
        }

        return redirect()->route('files.show', $fileUuid)
            ->with('success', 'Estado do arquivo atualizado e auditado.');
    }

    private function actionError(
        Request $request,
        string $message,
        int $status,
        bool $redirectToLogin = false
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        if ($redirectToLogin) {
            return redirect()->route('login')->with('error', $message);
        }

        // Sem withInput(): credenciais de step-up nunca entram no old input.
        return redirect()->back()->with('error', $message);
    }
}
