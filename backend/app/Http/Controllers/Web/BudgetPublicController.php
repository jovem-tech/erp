<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Budgets\BudgetApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BudgetPublicController extends Controller
{
    public function __construct(
        private readonly BudgetApprovalService $budgetApprovalService
    ) {
    }

    public function show(string $token): View
    {
        $result = $this->budgetApprovalService->publicViewData($token);

        if (($result['result'] ?? 'not_found') === 'expired') {
            abort(410, 'Este link de orçamento expirou. Solicite um novo envio à assistência.');
        }

        if (($result['result'] ?? 'not_found') !== 'ok') {
            abort(404);
        }

        return view('budgets.public.show', [
            'budget' => $result['budget'],
        ]);
    }

    public function approve(Request $request, string $token): RedirectResponse
    {
        $response = $this->budgetApprovalService->approveByToken(
            $token,
            $this->normalizeOptionalText($request->input('resposta_cliente')),
            $this->budgetApprovalService->responseIp($request->ip()),
            $request->userAgent()
        );

        return $this->redirectWithResult($token, $response, 'Orçamento aprovado com sucesso.');
    }

    public function reject(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'motivo_rejeicao' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'motivo_rejeicao' => 'motivo da rejeição',
        ]);

        $response = $this->budgetApprovalService->rejectByToken(
            $token,
            $this->normalizeOptionalText($validated['motivo_rejeicao'] ?? null),
            $this->budgetApprovalService->responseIp($request->ip()),
            $request->userAgent()
        );

        return $this->redirectWithResult($token, $response, 'Rejeição registrada com sucesso.');
    }

    public function pdf(string $token): StreamedResponse|RedirectResponse
    {
        $result = $this->budgetApprovalService->regeneratePdfByToken($token);

        if (($result['result'] ?? '') === 'expired') {
            abort(410, 'Este link de orçamento expirou. Solicite um novo envio à assistência.');
        }

        if (! ($result['ok'] ?? false)) {
            return redirect()
                ->route('budgets.public.show', ['token' => $token])
                ->with('warning', (string) ($result['message'] ?? 'Não foi possível gerar o PDF desta proposta agora.'));
        }

        $relativePath = trim((string) ($result['relative_path'] ?? ''));
        if ($relativePath === '' || ! Storage::disk('local')->exists($relativePath)) {
            return redirect()
                ->route('budgets.public.show', ['token' => $token])
                ->with('warning', 'O PDF da proposta não está disponível neste momento.');
        }

        return Storage::disk('local')->download(
            $relativePath,
            (string) ($result['file_name'] ?? 'orcamento.pdf'),
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function redirectWithResult(string $token, array $result, string $successFallback): RedirectResponse
    {
        $flashType = ($result['result'] ?? 'error') === 'ok' ? 'success' : 'warning';
        $message = trim((string) ($result['message'] ?? ''));

        return redirect()
            ->route('budgets.public.show', ['token' => $token])
            ->with($flashType, $message !== '' ? $message : $successFallback);
    }

    private function normalizeOptionalText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
