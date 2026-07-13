<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Orders\OrderDocumentCenterService;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OrderDocumentPublicController extends Controller
{
    public function __construct(
        private readonly OrderDocumentCenterService $orderDocumentCenterService
    ) {
    }

    public function show(Request $request, string $token): Response
    {
        $result = $this->orderDocumentCenterService->publicLinkView($token, $request->ip());

        if (($result['result'] ?? 'error') === 'expired' || ($result['result'] ?? 'error') === 'revoked') {
            abort(410, 'Este link documental expirou ou foi revogado. Solicite um novo envio à assistência.');
        }

        if (($result['result'] ?? 'error') !== 'ok') {
            abort(404);
        }

        return response()
            ->view('order-documents.public', [
                'order' => $result['order'] ?? [],
                'link' => $result['link'] ?? [],
                'documents' => $result['documents'] ?? [],
            ])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, noarchive');
    }

    public function file(Request $request, string $token, int $document, string $format): BinaryFileResponse
    {
        $result = $this->orderDocumentCenterService->resolvePublicFile($token, $document, $format);

        if (($result['result'] ?? 'error') === 'expired' || ($result['result'] ?? 'error') === 'revoked') {
            abort(410, 'Este link documental expirou ou foi revogado. Solicite um novo envio à assistência.');
        }

        if (($result['result'] ?? 'error') !== 'ok') {
            abort(404);
        }

        $file = $result['file'];

        return response()->file($file['absolute_path'], [
            'Content-Type' => $file['mime_type'],
            'Content-Disposition' => 'inline; filename="' . $file['filename'] . '"',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, noarchive',
        ]);
    }
}
