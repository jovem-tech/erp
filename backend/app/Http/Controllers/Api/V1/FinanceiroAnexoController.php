<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Financeiro;
use App\Models\FinanceiroAnexo;
use App\Services\Financeiro\FinanceiroAnexoService;
use App\Services\Files\FilePathGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceiroAnexoController extends BaseApiController
{
    public function __construct(private readonly FinanceiroAnexoService $anexos)
    {
    }

    public function index(Request $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        $anexos = $financeiro->anexos()->with(['uploadedBy', 'managedFile'])->get();

        return $this->success(['anexos' => $anexos], request: $request);
    }

    public function store(Request $request, Financeiro $financeiro): JsonResponse
    {
        $this->authorize('financeiro:editar');

        $validated = $request->validate([
            'arquivo' => [
                'required',
                'file',
                'max:20480',
                'mimes:pdf,jpg,jpeg,png,webp',
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
            ],
            'descricao' => ['nullable', 'string', 'max:190'],
        ], [], ['arquivo' => 'arquivo', 'descricao' => 'descrição']);

        $usuario = $this->authenticatedUser($request);

        if ($usuario === null) {
            return $this->unauthenticatedResponse($request);
        }

        $anexo = $this->anexos->anexar(
            $financeiro,
            $request->file('arquivo'),
            $validated['descricao'] ?? null,
            (int) $usuario->id
        );

        return $this->success(['anexo' => $anexo->load(['uploadedBy', 'managedFile'])], 201, request: $request);
    }

    public function download(Request $request, Financeiro $financeiro, FinanceiroAnexo $anexo): StreamedResponse|JsonResponse
    {
        $this->authorize('financeiro:visualizar');

        try {
            $storagePath = FilePathGuard::normalizeRelativePath((string) $anexo->arquivo);
        } catch (\InvalidArgumentException) {
            $storagePath = '';
        }
        $allowedPrefix = 'private/financeiro/'.(int) $financeiro->id;
        if (
            (int) $anexo->financeiro_id !== (int) $financeiro->id
            || $storagePath === ''
            || ($storagePath !== $allowedPrefix && ! str_starts_with($storagePath, $allowedPrefix.'/'))
            || ! Storage::disk('local')->exists($storagePath)
        ) {
            return $this->error('Arquivo não encontrado.', 404, 'ANEXO_AUSENTE', request: $request);
        }
        try {
            $absolutePath = FilePathGuard::assertContainedRegularFile(
                Storage::disk('local'),
                $storagePath,
                $allowedPrefix
            );
            $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
        } catch (\Throwable) {
            return $this->error('Arquivo não encontrado.', 404, 'ANEXO_AUSENTE', request: $request);
        }
        if (! is_string($detectedMime) || ! in_array($detectedMime, [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ], true)) {
            return $this->error('Arquivo não encontrado.', 404, 'ANEXO_AUSENTE', request: $request);
        }
        $safeName = FilePathGuard::safeFileName(
            (string) $anexo->nome_original,
            (string) pathinfo($storagePath, PATHINFO_EXTENSION)
        );

        return Storage::disk('local')->response(
            $storagePath,
            $safeName,
            [
                'Content-Type' => $detectedMime,
                'Cache-Control' => 'private, no-store',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline'
        );
    }

    public function destroy(Request $request, Financeiro $financeiro, FinanceiroAnexo $anexo): JsonResponse
    {
        $this->authorize('financeiro:editar');

        if ((int) $anexo->financeiro_id !== (int) $financeiro->id) {
            return $this->error('Anexo não encontrado.', 404, 'ANEXO_AUSENTE', request: $request);
        }

        $actor = $this->authenticatedUser($request);
        if ($actor === null) {
            return $this->unauthenticatedResponse($request);
        }
        $this->anexos->excluir($anexo, (int) $actor->id);

        return $this->success(['message' => 'Anexo movido para a lixeira.'], request: $request);
    }
}
