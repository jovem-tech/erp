<?php

namespace App\Http\Controllers;

use App\Models\Backups\Backup;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BackupDownloadController extends Controller
{
    /**
     * Entrega o pacote por URL assinada de curta duração.
     *
     * Rota sem sessão de propósito: o arquivo tem ~130 MB e não pode passar
     * pelo BFF do desktop, cujo ApiClient::download() carrega o corpo inteiro
     * em memória (limite de 256M) com timeout de 15s. A assinatura temporária
     * é o que substitui a sessão como prova de autorização.
     */
    public function __invoke(Request $request, string $uuid): BinaryFileResponse
    {
        $backup = Backup::query()->where('uuid', $uuid)->first();

        if ($backup === null) {
            throw new NotFoundHttpException('Backup não encontrado.');
        }

        $path = (string) $backup->arquivo_caminho;

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new NotFoundHttpException('O arquivo deste backup não está disponível no servidor.');
        }

        return response()
            ->download($path, (string) $backup->arquivo_nome, [
                'Content-Type' => 'application/octet-stream',
                // O pacote carrega segredos: nenhum intermediario deve guardar copia.
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }
}
