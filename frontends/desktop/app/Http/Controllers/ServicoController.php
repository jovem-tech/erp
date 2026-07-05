<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\ServicoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ServicoController extends DesktopController
{
    public function __construct(
        private readonly ServicoService $servicoService
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => trim((string) $request->query('status', '')),
            'tipo_equipamento' => trim((string) $request->query('tipo_equipamento', '')),
            'page' => (int) $request->query('page', 1),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->servicoService->paginate(array_filter(
            $filters,
            static fn ($value): bool => $value !== '' && $value !== 0
        ));

        return view('servicos.index', [
            'pageTitle' => 'Serviços',
            'services' => $result['items'],
            'pagination' => $result['pagination'],
            'filters' => $filters,
        ]);
    }

    public function help(): View
    {
        return view('servicos.help', [
            'pageTitle' => 'Ajuda de serviços',
        ]);
    }

    public function create(): View
    {
        return view('servicos.form', [
            'pageTitle' => 'Novo serviço',
            'service' => $this->serviceFormDefaults(),
            'formData' => $this->servicoService->formData(),
            'mode' => 'create',
        ]);
    }

    public function edit(int $service): View|RedirectResponse
    {
        try {
            $serviceData = $this->servicoService->find($service);
            $formData = $this->servicoService->formData();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        }

        if ($serviceData === []) {
            abort(404);
        }

        return view('servicos.form', [
            'pageTitle' => 'Editar serviço',
            'service' => array_merge($this->serviceFormDefaults(), $serviceData),
            'formData' => $formData,
            'mode' => 'edit',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedServicePayload($request);

        try {
            $service = $this->servicoService->create($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do serviço.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível cadastrar o serviço agora. Tente novamente.');
        }

        return redirect()
            ->route('servicos.index', ['search' => trim((string) ($service['nome'] ?? ''))])
            ->with('success', 'Serviço cadastrado com sucesso.');
    }

    public function quickStore(Request $request): JsonResponse
    {
        try {
            $service = $this->servicoService->create($this->validatedServicePayload($request));
        } catch (ApiAuthenticationException $exception) {
            return $this->jsonFailure($exception->getMessage() ?: 'Sua sessão expirou. Faça login novamente.', 401);
        } catch (ApiAuthorizationException $exception) {
            return $this->jsonFailure($exception->getMessage() ?: 'Você não tem permissão para executar esta ação.', 403);
        } catch (ApiRequestException $exception) {
            return $this->jsonFailure(
                $exception->getMessage() ?: 'Não foi possível cadastrar o serviço.',
                $exception->statusCode() > 0 ? $exception->statusCode() : 422,
                $exception->details() ?? []
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Verifique os campos do serviço.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return $this->jsonFailure('Não foi possível cadastrar o serviço agora. Tente novamente.', 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Serviço cadastrado com sucesso.',
            'service' => $service,
        ], 201);
    }

    public function update(Request $request, int $service): RedirectResponse
    {
        $payload = $this->validatedServicePayload($request);

        try {
            $updated = $this->servicoService->update($service, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput()
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do serviço.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Não foi possível atualizar o serviço agora. Tente novamente.');
        }

        return redirect()
            ->route('servicos.index', ['search' => trim((string) ($updated['nome'] ?? ''))])
            ->with('success', 'Serviço atualizado com sucesso.');
    }

    public function close(int $service): RedirectResponse
    {
        try {
            $this->servicoService->close($service);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('servicos.index')
            ->with('success', 'Serviço encerrado com sucesso.');
    }

    public function destroy(int $service): RedirectResponse
    {
        try {
            $this->servicoService->destroy($service);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('servicos.index')
            ->with('success', 'Serviço excluído com sucesso.');
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
     */
    public function exportCsv()
    {
        try {
            $download = $this->servicoService->exportCsv();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        }

        return response()->streamDownload(static function () use ($download): void {
            echo $download['body'];
        }, 'servicos.csv', $download['headers']);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
     */
    public function downloadCsvTemplate()
    {
        try {
            $download = $this->servicoService->downloadCsvTemplate();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        }

        return response()->streamDownload(static function () use ($download): void {
            echo $download['body'];
        }, 'modelo_importacao_servicos.csv', $download['headers']);
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
            $this->servicoService->importCsv($validated['arquivo']);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('servicos.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível importar o arquivo agora.');
        }

        return redirect()
            ->route('servicos.index')
            ->with('success', 'Arquivo importado com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceFormDefaults(): array
    {
        return [
            'id' => null,
            'nome' => '',
            'descricao' => '',
            'tipo_equipamento' => '',
            'valor' => 0,
            'tempo_padrao_horas' => 0,
            'custo_direto_padrao' => 0,
            'status' => 'ativo',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedServicePayload(Request $request): array
    {
        $request->replace($this->normalizeMoneyPayload(
            $request->all(),
            ['valor', 'tempo_padrao_horas', 'custo_direto_padrao']
        ));

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'descricao' => ['nullable', 'string'],
            'tipo_equipamento' => ['nullable', 'string', 'max:120'],
            'valor' => ['nullable', 'numeric', 'min:0'],
            'tempo_padrao_horas' => ['nullable', 'numeric', 'min:0'],
            'custo_direto_padrao' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', 'in:ativo,encerrado,inativo'],
        ]);

        return [
            'nome' => trim((string) ($validated['nome'] ?? '')),
            'descricao' => trim((string) ($validated['descricao'] ?? '')),
            'tipo_equipamento' => trim((string) ($validated['tipo_equipamento'] ?? '')),
            'valor' => (float) ($validated['valor'] ?? 0),
            'tempo_padrao_horas' => (float) ($validated['tempo_padrao_horas'] ?? 0),
            'custo_direto_padrao' => (float) ($validated['custo_direto_padrao'] ?? 0),
            'status' => trim((string) ($validated['status'] ?? 'ativo')),
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

    /**
     * @param array<string, mixed>|null $details
     */
    private function jsonFailure(string $message, int $status, ?array $details = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $details ?? [],
        ], $status);
    }
}
