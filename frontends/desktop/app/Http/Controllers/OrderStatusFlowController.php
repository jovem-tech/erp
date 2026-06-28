<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\DesktopOrderStatusFlowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class OrderStatusFlowController extends DesktopController
{
    public function __construct(
        private readonly DesktopOrderStatusFlowService $orderStatusFlowService
    ) {
    }

    public function index(Request $request): View|RedirectResponse
    {
        try {
            $result = $this->orderStatusFlowService->index();
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }

        $statuses = $result['statuses'];
        $transitions = $result['transitions'];

        return view('knowledge.os-flow.index', [
            'pageTitle' => 'Fluxo de Trabalho OS',
            'statusesByGroup' => collect($statuses)->groupBy('grupo_macro'),
            'statuses' => $statuses,
            'transitions' => $transitions,
        ]);
    }

    public function storeStatus(Request $request): RedirectResponse
    {
        $payload = $this->validatedStatusPayload($request, true);

        try {
            $this->orderStatusFlowService->createStatus($payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.os-flow.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do status.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->with('error', 'Não foi possível cadastrar o status agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.os-flow.index')
            ->with('success', 'Status criado com sucesso.');
    }

    public function updateStatus(Request $request, int $status): RedirectResponse
    {
        $payload = $this->validatedStatusPayload($request, false);

        try {
            $this->orderStatusFlowService->updateStatus($status, $payload);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.os-flow.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->withErrors($this->formatApiErrors($exception))
                ->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do status.');
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput($request->except(['status_final', 'status_pausa', 'gera_evento_crm', 'ativo']))
                ->with('error', 'Não foi possível atualizar o status agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.os-flow.index')
            ->with('success', 'Status atualizado com sucesso.');
    }

    public function updateTransitions(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'transitions' => ['nullable', 'array'],
            'transitions.*' => ['array'],
            'transitions.*.*' => ['integer'],
        ], [], [
            'transitions' => 'matriz de transições',
        ]);

        try {
            $this->orderStatusFlowService->updateTransitions($validated['transitions'] ?? []);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route('knowledge.os-flow.index')->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route('knowledge.os-flow.index')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('knowledge.os-flow.index')
                ->with('error', 'Não foi possível atualizar a matriz de transições agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.os-flow.index')
            ->with('success', 'Matriz de transições atualizada com sucesso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedStatusPayload(Request $request, bool $includeCodigo): array
    {
        $rules = [
            'nome' => ['required', 'string', 'max:120'],
            'grupo_macro' => ['required', 'string', 'max:60'],
            'icone' => ['nullable', 'string', 'max:60'],
            'cor' => ['nullable', 'string', 'max:30'],
            'ordem_fluxo' => ['nullable', 'integer'],
            'status_final' => ['nullable', 'boolean'],
            'status_pausa' => ['nullable', 'boolean'],
            'gera_evento_crm' => ['nullable', 'boolean'],
            'estado_fluxo_padrao' => ['nullable', 'string', 'max:40'],
            'ativo' => ['nullable', 'boolean'],
        ];

        $attributes = [
            'codigo' => 'código',
            'nome' => 'nome',
            'grupo_macro' => 'grupo macro',
            'icone' => 'ícone',
            'cor' => 'cor',
            'ordem_fluxo' => 'ordem no fluxo',
            'status_final' => 'status final',
            'status_pausa' => 'status de pausa',
            'gera_evento_crm' => 'gera evento CRM',
            'estado_fluxo_padrao' => 'estado de fluxo padrão',
            'ativo' => 'status',
        ];

        if ($includeCodigo) {
            $rules['codigo'] = ['required', 'string', 'max:80'];
        }

        $validated = $request->validate($rules, [], $attributes);

        $payload = [];

        foreach ($validated as $field => $value) {
            if (in_array($field, ['status_final', 'status_pausa', 'gera_evento_crm', 'ativo'], true)) {
                continue;
            }

            $payload[$field] = $this->normalizeValue($value);
        }

        $payload['status_final'] = $request->boolean('status_final', false);
        $payload['status_pausa'] = $request->boolean('status_pausa', false);
        $payload['gera_evento_crm'] = $request->boolean('gera_evento_crm', false);
        $payload['ativo'] = $request->boolean('ativo', false);

        return $payload;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function formatApiErrors(ApiRequestException $exception): array
    {
        $details = $exception->details();

        if (! is_array($details)) {
            return [];
        }

        $errors = [];

        foreach ($details as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $errors[$field] = array_values(array_filter($messages, 'is_string'));
        }

        return $errors;
    }
}
