<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class StockController extends DesktopController
{
    public function __construct(
        private readonly StockService $stockService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'active' => $request->query('active', ''),
            'categoria' => trim((string) $request->query('categoria', '')),
            'tipo_equipamento' => trim((string) $request->query('tipo_equipamento', '')),
            'status' => trim((string) $request->query('status', '')),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->stockService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        return view('estoque.index', [
            'pageTitle' => 'Estoque de Peças',
            'parts' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    public function help(): View
    {
        return view('estoque.help', [
            'pageTitle' => 'Ajuda de estoque',
        ]);
    }

    public function create(): View
    {
        return view('estoque.form', [
            'pageTitle' => 'Nova peça',
            'part' => $this->partFormDefaults(),
            'formData' => $this->stockService->formData(),
            'mode' => 'create',
        ]);
    }

    public function edit(int $part): View|RedirectResponse
    {
        try {
            $partData = $this->stockService->find($part);
            $formData = $this->stockService->formData();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        if ($partData === []) {
            abort(404);
        }

        return view('estoque.form', [
            'pageTitle' => 'Editar peça',
            'part' => array_merge($this->partFormDefaults(), $partData),
            'formData' => $formData,
            'mode' => 'edit',
        ]);
    }

    public function movements(int $part): View|RedirectResponse
    {
        try {
            $result = $this->stockService->movements($part);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        $partData = $result['part'] ?? [];

        if ($partData === []) {
            abort(404);
        }

        return view('estoque.movimentacoes', [
            'pageTitle' => 'Movimentações da peça',
            'part' => $partData,
            'movements' => $result['movements'] ?? [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedPartPayload($request);

        try {
            $part = $this->stockService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos da peça.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível cadastrar a peça agora. Tente novamente.');
        }

        return redirect()
            ->route('estoque.index', ['search' => trim((string) ($part['nome'] ?? ''))])
            ->with('success', 'Peça cadastrada com sucesso.');
    }

    public function update(Request $request, int $part): RedirectResponse
    {
        $payload = $this->validatedPartPayload($request);

        try {
            $updated = $this->stockService->update($part, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos da peça.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível atualizar a peça agora. Tente novamente.');
        }

        return redirect()
            ->route('estoque.index', ['search' => trim((string) ($updated['nome'] ?? ''))])
            ->with('success', 'Peça atualizada com sucesso.');
    }

    public function storeMovement(Request $request, int $part): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'tipo' => ['required', 'string', 'in:entrada,saida,ajuste'],
                'quantidade' => ['required', 'integer', 'min:1'],
                'motivo' => ['nullable', 'string', 'max:255'],
                'os_id' => ['nullable', 'integer'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos da movimentação.');
        }

        try {
            $this->stockService->createMovement($part, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível registrar a movimentação agora.');
        }

        return redirect()
            ->route('estoque.movements', $part)
            ->with('success', 'Movimentação registrada com sucesso.');
    }

    public function close(int $part): RedirectResponse
    {
        try {
            $this->stockService->close($part);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('estoque.index')
            ->with('success', 'Peça encerrada com sucesso.');
    }

    public function destroy(int $part): RedirectResponse
    {
        try {
            $this->stockService->destroy($part);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('estoque.index')
            ->with('success', 'Peça desativada com sucesso.');
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
     */
    public function exportCsv()
    {
        try {
            $download = $this->stockService->exportCsv();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        return response()->streamDownload(static function () use ($download): void {
            echo $download['body'];
        }, 'estoque_pecas.csv', $download['headers']);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
     */
    public function downloadCsvTemplate()
    {
        try {
            $download = $this->stockService->downloadCsvTemplate();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        }

        return response()->streamDownload(static function () use ($download): void {
            echo $download['body'];
        }, 'modelo_importacao_estoque.csv', $download['headers']);
    }

    public function importCsv(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'arquivo' => ['required', 'file', 'mimes:csv,txt'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Selecione um arquivo CSV válido.');
        }

        try {
            $this->stockService->importCsv($validated['arquivo']);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('estoque.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível importar o arquivo agora.');
        }

        return redirect()
            ->route('estoque.index')
            ->with('success', 'Arquivo importado com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function partFormDefaults(): array
    {
        return [
            'id' => null,
            'codigo' => '',
            'codigo_fabricante' => '',
            'nome' => '',
            'categoria' => '',
            'tipo_equipamento' => '',
            'modelos_compativeis' => '',
            'fornecedor' => '',
            'localizacao' => '',
            'preco_custo' => 0,
            'preco_venda' => 0,
            'quantidade_atual' => 0,
            'estoque_minimo' => 0,
            'estoque_maximo' => 0,
            'status' => 'ativo',
            'observacoes' => '',
            'ativo' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPartPayload(Request $request): array
    {
        $validated = $request->validate([
            'codigo' => ['nullable', 'string', 'max:120'],
            'codigo_fabricante' => ['nullable', 'string', 'max:120'],
            'nome' => ['required', 'string', 'max:160'],
            'categoria' => ['nullable', 'string', 'max:120'],
            'tipo_equipamento' => ['nullable', 'string', 'max:120'],
            'modelos_compativeis' => ['nullable', 'string'],
            'fornecedor' => ['nullable', 'string', 'max:120'],
            'localizacao' => ['nullable', 'string', 'max:120'],
            'preco_custo' => ['nullable', 'numeric', 'min:0'],
            'preco_venda' => ['nullable', 'numeric', 'min:0'],
            'quantidade_atual' => ['nullable', 'integer', 'min:0'],
            'estoque_minimo' => ['nullable', 'integer', 'min:0'],
            'estoque_maximo' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'string', 'in:ativo,encerrado,inativo'],
            'observacoes' => ['nullable', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        return [
            'codigo' => trim((string) ($validated['codigo'] ?? '')),
            'codigo_fabricante' => trim((string) ($validated['codigo_fabricante'] ?? '')),
            'nome' => trim((string) ($validated['nome'] ?? '')),
            'categoria' => trim((string) ($validated['categoria'] ?? '')),
            'tipo_equipamento' => trim((string) ($validated['tipo_equipamento'] ?? '')),
            'modelos_compativeis' => trim((string) ($validated['modelos_compativeis'] ?? '')),
            'fornecedor' => trim((string) ($validated['fornecedor'] ?? '')),
            'localizacao' => trim((string) ($validated['localizacao'] ?? '')),
            'preco_custo' => (float) ($validated['preco_custo'] ?? 0),
            'preco_venda' => (float) ($validated['preco_venda'] ?? 0),
            'quantidade_atual' => (int) ($validated['quantidade_atual'] ?? 0),
            'estoque_minimo' => (int) ($validated['estoque_minimo'] ?? 0),
            'estoque_maximo' => (int) ($validated['estoque_maximo'] ?? 0),
            'status' => trim((string) ($validated['status'] ?? 'ativo')),
            'observacoes' => trim((string) ($validated['observacoes'] ?? '')),
            'ativo' => $request->boolean('ativo', true),
        ];
    }

    /**
     * @param array<string, mixed> $errors
     * @return array<string, string>
     */
    private function formatApiErrors(ApiRequestException $exception): array
    {
        $details = $exception->details();
        if (! is_array($details)) {
            return ['erro' => $exception->getMessage()];
        }

        $messages = [];

        foreach ($details as $field => $value) {
            if (is_array($value)) {
                $messages[(string) $field] = implode(' ', array_map('strval', $value));
                continue;
            }

            $messages[(string) $field] = (string) $value;
        }

        return $messages;
    }
}
