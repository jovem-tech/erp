<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\DevolucaoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

/**
 * Devolução e troca de venda — specs/029-devolucao-troca/spec.md.
 *
 * Devolver é ação do módulo `vendas`: não há permissão própria.
 */
class DevolucaoController extends DesktopController
{
    public function __construct(
        private readonly DevolucaoService $devolucaoService
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'data_inicio' => trim((string) $request->query('data_inicio', '')),
            'data_fim' => trim((string) $request->query('data_fim', '')),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        try {
            $result = $this->devolucaoService->paginate(array_filter(
                $filters,
                static fn ($value): bool => $value !== '' && $value !== 0
            ));
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            $result = ['items' => [], 'pagination' => []];
        }

        return view('devolucoes.index', [
            'pageTitle' => 'Devoluções',
            'devolucoes' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    /**
     * Tela de devolução de uma venda: mostra o saldo devolvível por item.
     */
    public function create(int $venda): View|RedirectResponse
    {
        try {
            $data = $this->devolucaoService->returnableItems($venda);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('vendas.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('vendas.show', $venda)->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('vendas.show', $venda)
                ->with('error', 'Não foi possível abrir a devolução agora.');
        }

        if (($data['venda']['cancelada'] ?? false) === true) {
            return redirect()
                ->route('vendas.show', $venda)
                ->with('error', 'Esta venda foi cancelada; não há o que devolver.');
        }

        return view('devolucoes.create', [
            'pageTitle' => 'Devolver venda '.($data['venda']['numero'] ?? ''),
            'venda' => $data['venda'] ?? [],
            'itens' => $data['itens'] ?? [],
            'exigeAutorizacao' => (bool) ($data['exige_autorizacao'] ?? false),
            'prazoLivreDias' => (int) ($data['prazo_livre_dias'] ?? 7),
        ]);
    }

    public function store(Request $request, int $venda): RedirectResponse
    {
        $validated = $request->validate([
            'creation_request_id' => ['nullable', 'uuid'],
            'motivo' => ['required', 'string', 'min:3', 'max:2000'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.venda_item_id' => ['required', 'integer', 'min:1'],
            'itens.*.quantidade' => ['required', 'string', 'max:20'],
            'admin_email' => ['nullable', 'string', 'email', 'max:255'],
            'admin_password' => ['nullable', 'string', 'max:255'],
        ], [
            'motivo.required' => 'Descreva o motivo da devolução.',
            'itens.required' => 'Selecione ao menos um item devolvido.',
        ], [
            'motivo' => 'motivo da devolução',
            'itens' => 'itens devolvidos',
        ]);

        // Linhas com quantidade zero são só checkbox desmarcado no formulário.
        $payload = $this->normalizeDecimalPayload($validated, [], ['itens' => ['quantidade']]);
        $payload['itens'] = array_values(array_filter(
            $payload['itens'],
            static fn (array $item): bool => (float) ($item['quantidade'] ?? 0) > 0
        ));

        if ($payload['itens'] === []) {
            return back()
                ->withInput()
                ->with('error', 'Informe a quantidade devolvida de ao menos um item.');
        }

        try {
            $result = $this->devolucaoService->create($venda, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('vendas.show', $venda)->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível registrar a devolução agora. Tente novamente.');
        }

        $devolucao = is_array($result['devolucao'] ?? null) ? $result['devolucao'] : [];
        $devolucaoId = (int) ($devolucao['id'] ?? 0);

        if ($devolucaoId <= 0) {
            return redirect()->route('vendas.show', $venda)->with('success', 'Devolução registrada.');
        }

        return redirect()
            ->route('devolucoes.show', $devolucaoId)
            ->with('success', 'Devolução '.($devolucao['numero'] ?? '').' registrada.');
    }

    public function show(int $devolucao): View|RedirectResponse
    {
        try {
            $data = $this->devolucaoService->find($devolucao);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()->route('devolucoes.index')->with('error', 'Não foi possível carregar esta devolução.');
        }

        if ((int) ($data['id'] ?? 0) <= 0) {
            return redirect()->route('devolucoes.index')->with('error', 'Devolução não encontrada.');
        }

        return view('devolucoes.show', [
            'pageTitle' => 'Devolução '.($data['numero'] ?? ''),
            'devolucao' => $data,
        ]);
    }

    public function receipt(Request $request, int $devolucao): Response|RedirectResponse
    {
        $format = (string) $request->query('formato', '80mm');
        $format = in_array($format, ['80mm', 'a4'], true) ? $format : '80mm';

        try {
            $download = $this->devolucaoService->receipt($devolucao, $format);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('devolucoes.show', $devolucao)
                ->with('error', 'Não foi possível gerar o comprovante.');
        }

        return response($download['body'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="devolucao-'.$devolucao.'.pdf"',
        ]);
    }

    public function help(): View
    {
        return view('devolucoes.help', ['pageTitle' => 'Ajuda de devoluções']);
    }
}
