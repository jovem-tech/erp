<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\FinanceiroAnexoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FinanceiroAnexoController extends DesktopController
{
    public function __construct(private readonly FinanceiroAnexoService $anexos)
    {
    }

    /**
     * Lista os anexos de um lançamento em JSON — consumida via fetch() pelo
     * "Ver anexos" da listagem (financeiro-anexos.js), para o operador
     * conferir/abrir um arquivo sem precisar sair para a tela de detalhe.
     */
    public function index(int $financeiro): JsonResponse
    {
        try {
            $anexos = $this->anexos->listar($financeiro);
        } catch (ApiAuthenticationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 401);
        } catch (ApiAuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (ApiRequestException $e) {
            $status = $e->statusCode() > 0 ? $e->statusCode() : 422;
            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }

        $itens = array_map(function (array $anexo) use ($financeiro): array {
            $id = (int) ($anexo['id'] ?? 0);
            $criadoEm = trim((string) ($anexo['created_at'] ?? ''));

            return [
                'id' => $id,
                'nome' => trim((string) ($anexo['descricao'] ?? '')) !== ''
                    ? $anexo['descricao']
                    : ($anexo['nome_original'] ?? 'Arquivo'),
                'mime' => (string) ($anexo['mime'] ?? ''),
                'tamanho_kb' => number_format(((int) ($anexo['tamanho_bytes'] ?? 0)) / 1024, 0, ',', '.'),
                'uploaded_by' => trim((string) ($anexo['uploaded_by']['nome'] ?? '')) !== ''
                    ? $anexo['uploaded_by']['nome']
                    : 'Usuário removido',
                'data' => $criadoEm !== '' ? Carbon::parse($criadoEm)->format('d/m/Y H:i') : '—',
                'url' => route('financeiro.anexos.download', [$financeiro, $id]),
                'management_status' => (string) ($anexo['management_status'] ?? 'pending'),
            ];
        }, $anexos);

        return response()->json(['success' => true, 'anexos' => $itens]);
    }

    public function store(Request $request, int $financeiro): RedirectResponse
    {
        $this->recusarUploadQuebrado($request, 'arquivo');

        $validated = $request->validate([
            'arquivo' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp'],
            'descricao' => ['nullable', 'string', 'max:190'],
        ], [
            'arquivo.required' => 'Escolha o boleto/comprovante antes de anexar.',
            'arquivo.mimes' => 'Envie um PDF ou uma foto (jpg, png ou webp) do documento.',
        ], ['arquivo' => 'arquivo', 'descricao' => 'descrição']);

        $this->anexos->anexar($financeiro, $request->file('arquivo'), $validated['descricao'] ?? null);

        return redirect()->back()->with('success', 'Arquivo anexado.');
    }

    public function download(int $financeiro, int $anexo): Response
    {
        $arquivo = $this->anexos->baixar($financeiro, $anexo);

        return response($arquivo['body'], $arquivo['status'])->withHeaders($arquivo['headers']);
    }

    public function destroy(int $financeiro, int $anexo): RedirectResponse
    {
        $this->anexos->excluir($financeiro, $anexo);

        return redirect()->back()->with('success', 'Anexo movido para a lixeira.');
    }

    /**
     * Distingue "nenhum arquivo escolhido" de "o upload chegou e falhou no
     * servidor" (tmp indisponível, limite do pool, disco cheio) — sem isso o
     * Laravel trata os dois casos como arquivo ausente e a mensagem de erro
     * engana o operador. Mesma guarda de DocumentoFiscalController.
     */
    private function recusarUploadQuebrado(Request $request, string $campo): void
    {
        $arquivo = $request->file($campo);

        if ($arquivo === null || $arquivo->isValid()) {
            return;
        }

        throw ValidationException::withMessages([
            $campo => sprintf(
                'O arquivo chegou ao servidor, mas o envio falhou: %s (código %d). '
                .'Não é problema do arquivo — é configuração do servidor.',
                $arquivo->getErrorMessage(),
                $arquivo->getError()
            ),
        ]);
    }
}
