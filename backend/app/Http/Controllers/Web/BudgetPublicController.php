<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Services\Budgets\BudgetApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class BudgetPublicController extends Controller
{
    public function __construct(
        private readonly BudgetApprovalService $budgetApprovalService
    ) {
    }

    public function show(Request $request, string $token): View
    {
        // Orçamento com níveis de manutenção: `?opcao=N` mostra o orçamento
        // daquela opção (projeção, nada gravado). Fora disso é ignorado.
        $result = $this->budgetApprovalService->publicViewData($token, $this->optionFromRequest($request, 'opcao'));

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
            $request->userAgent(),
            $this->optionFromRequest($request, 'nivel')
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

    public function pdf(Request $request, string $token): Response|RedirectResponse
    {
        $result = $this->budgetApprovalService->regeneratePdfByToken($token, $this->optionFromRequest($request, 'opcao'));

        if (($result['result'] ?? '') === 'expired') {
            abort(410, 'Este link de orçamento expirou. Solicite um novo envio à assistência.');
        }

        if (! ($result['ok'] ?? false)) {
            return redirect()
                ->route('budgets.public.show', ['token' => $token])
                ->with('warning', (string) ($result['message'] ?? 'Não foi possível gerar o PDF desta proposta agora.'));
        }

        // O PDF é renderizado sob demanda (não fica em disco): entrega os
        // bytes direto, com os mesmos cabeçalhos de download de antes.
        $bytes = (string) ($result['bytes'] ?? '');
        if ($bytes === '') {
            return redirect()
                ->route('budgets.public.show', ['token' => $token])
                ->with('warning', 'O PDF da proposta não está disponível neste momento.');
        }

        $fileName = (string) ($result['file_name'] ?? 'orcamento.pdf');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $fileName).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function redirectWithResult(string $token, array $result, string $successFallback): RedirectResponse
    {
        $ok = ($result['result'] ?? 'error') === 'ok';
        $message = trim((string) ($result['message'] ?? ''));

        // Vai na querystring do redirect, nao no flash de sessao: no celular
        // do cliente o cookie de sessao nem sempre sobrevive ao redirect
        // pos-POST (link aberto por navegador in-app, rede movel etc.), o
        // que deixava a faixa de sucesso e o confete mudos mesmo com a
        // decisao ja gravada. Ver comentario equivalente na view.
        return redirect()->route('budgets.public.show', [
            'token' => $token,
            'resultado' => $ok ? 'sucesso' : 'aviso',
            'mensagem' => $message !== '' ? $message : $successFallback,
        ]);
    }

    private function normalizeOptionalText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function optionFromRequest(Request $request, string $field): ?int
    {
        return Budget::normalizeLevel($request->input($field));
    }
}
