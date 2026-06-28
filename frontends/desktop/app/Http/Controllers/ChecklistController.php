<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiAuthenticationException;
use App\Exceptions\ApiAuthorizationException;
use App\Exceptions\ApiRequestException;
use App\Services\ChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ChecklistController extends DesktopController
{
    private const ALLOWED_TIPOS = ['entrada', 'manutencao', 'controle_qualidade', 'saida'];

    public function __construct(
        private readonly ChecklistService $checklistService
    ) {
    }

    public function index(Request $request, string $tipo): View
    {
        if (! in_array($tipo, self::ALLOWED_TIPOS, true)) {
            abort(404);
        }

        try {
            $result = $this->checklistService->listModelos($tipo);
        } catch (ApiAuthenticationException $exception) {
            abort(401, $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            throw $exception;
        }

        return view('knowledge.checklists.index', [
            'pageTitle' => $this->tipoLabel($tipo),
            'tipo' => $tipo,
            'checklistTipo' => $result['checklist_tipo'],
            'equipmentTypes' => $result['equipment_types'],
        ]);
    }

    public function showModelo(Request $request, string $tipo, int $tipoEquipamento): View
    {
        if (! in_array($tipo, self::ALLOWED_TIPOS, true)) {
            abort(404);
        }

        try {
            $result = $this->checklistService->findModelo($tipo, $tipoEquipamento);
        } catch (ApiAuthenticationException $exception) {
            abort(401, $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            abort(403, $exception->getMessage());
        } catch (ApiRequestException $exception) {
            if ($exception->statusCode() === 404) {
                abort(404);
            }

            throw $exception;
        }

        return view('knowledge.checklists.modelo', array_merge([
            'pageTitle' => $this->tipoLabel($tipo),
            'tipo' => $tipo,
            'tipoEquipamento' => $tipoEquipamento,
        ], $result));
    }

    public function storeModelo(Request $request, string $tipo, int $tipoEquipamento): RedirectResponse
    {
        if (! in_array($tipo, self::ALLOWED_TIPOS, true)) {
            abort(404);
        }

        try {
            $validated = $request->validate([
                'nome' => ['nullable', 'string', 'max:160'],
                'descricao' => ['nullable', 'string'],
                'ordem' => ['nullable', 'integer'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do modelo de checklist.');
        }

        try {
            $this->checklistService->createModelo($tipo, $tipoEquipamento, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route($this->indexRouteName($tipo))->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível criar o modelo de checklist agora. Tente novamente.');
        }

        return redirect()
            ->route('knowledge.checklists.modelo.show', ['tipo' => $tipo, 'tipoEquipamento' => $tipoEquipamento])
            ->with('success', 'Modelo de checklist criado com sucesso.');
    }

    public function updateModelo(Request $request, string $tipo, int $modelo): RedirectResponse
    {
        if (! in_array($tipo, self::ALLOWED_TIPOS, true)) {
            abort(404);
        }

        try {
            $validated = $request->validate([
                'nome' => ['required', 'string', 'max:160'],
                'descricao' => ['nullable', 'string'],
                'ordem' => ['nullable', 'integer'],
                'ativo' => ['nullable', 'boolean'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique os campos do modelo de checklist.');
        }

        $validated['ativo'] = $request->boolean('ativo', false);

        try {
            $modeloData = $this->checklistService->updateModelo($tipo, $modelo, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route($this->indexRouteName($tipo))->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível atualizar o modelo de checklist agora. Tente novamente.');
        }

        return $this->redirectToModelo($tipo, $modeloData)
            ->with('success', 'Modelo de checklist atualizado com sucesso.');
    }

    public function destroyModelo(Request $request, string $tipo, int $modelo): RedirectResponse
    {
        if (! in_array($tipo, self::ALLOWED_TIPOS, true)) {
            abort(404);
        }

        try {
            $this->checklistService->destroyModelo($tipo, $modelo);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route($this->indexRouteName($tipo))->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return redirect()->route($this->indexRouteName($tipo))->with('error', $exception->getMessage());
        }

        return redirect()
            ->route($this->indexRouteName($tipo))
            ->with('success', 'Modelo de checklist removido.');
    }

    public function storeItem(Request $request, string $tipo, int $modelo): RedirectResponse
    {
        if (! in_array($tipo, self::ALLOWED_TIPOS, true)) {
            abort(404);
        }

        try {
            $validated = $request->validate([
                'descricao' => ['required', 'string', 'max:255'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique o campo do item.');
        }

        try {
            $modeloData = $this->checklistService->addItem($tipo, $modelo, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route($this->indexRouteName($tipo))->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível adicionar o item agora.');
        }

        return $this->redirectToModelo($tipo, $modeloData)
            ->with('success', 'Item adicionado.');
    }

    public function updateItem(Request $request, string $tipo, int $modelo, int $item): RedirectResponse
    {
        if (! in_array($tipo, self::ALLOWED_TIPOS, true)) {
            abort(404);
        }

        try {
            $validated = $request->validate([
                'descricao' => ['required', 'string', 'max:255'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Verifique o campo do item.');
        }

        try {
            $modeloData = $this->checklistService->updateItem($tipo, $modelo, $item, $validated);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route($this->indexRouteName($tipo))->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Não foi possível atualizar o item agora.');
        }

        return $this->redirectToModelo($tipo, $modeloData)
            ->with('success', 'Item atualizado.');
    }

    public function destroyItem(Request $request, string $tipo, int $modelo, int $item): RedirectResponse
    {
        if (! in_array($tipo, self::ALLOWED_TIPOS, true)) {
            abort(404);
        }

        try {
            $modeloData = $this->checklistService->destroyItem($tipo, $modelo, $item);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route($this->indexRouteName($tipo))->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->redirectToModelo($tipo, $modeloData)
            ->with('success', 'Item removido.');
    }

    public function moveItem(Request $request, string $tipo, int $modelo, int $item): RedirectResponse
    {
        if (! in_array($tipo, self::ALLOWED_TIPOS, true)) {
            abort(404);
        }

        try {
            $validated = $request->validate([
                'direction' => ['required', 'string', 'in:up,down'],
            ]);
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors())
                ->with('error', 'Direção de movimentação inválida.');
        }

        try {
            $modeloData = $this->checklistService->moveItem($tipo, $modelo, $item, $validated['direction']);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route($this->indexRouteName($tipo))->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->redirectToModelo($tipo, $modeloData)
            ->with('success', 'Ordem atualizada.');
    }

    public function toggleItemActive(Request $request, string $tipo, int $modelo, int $item): RedirectResponse
    {
        if (! in_array($tipo, self::ALLOWED_TIPOS, true)) {
            abort(404);
        }

        try {
            $modeloData = $this->checklistService->toggleItemActive($tipo, $modelo, $item);
        } catch (ApiAuthenticationException $exception) {
            return redirect()->route('login')->with('error', $exception->getMessage());
        } catch (ApiAuthorizationException $exception) {
            return redirect()->route($this->indexRouteName($tipo))->with('error', $exception->getMessage());
        } catch (ApiRequestException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->redirectToModelo($tipo, $modeloData)
            ->with('success', 'Status do item atualizado.');
    }

    private function tipoLabel(string $tipo): string
    {
        return match ($tipo) {
            'entrada' => 'Checklist de Entrada',
            'manutencao' => 'Checklist de Manutenção',
            'controle_qualidade' => 'Checklist Controle de Qualidade',
            'saida' => 'Checklist de Saída',
            default => 'Checklist',
        };
    }

    private function indexRouteName(string $tipo): string
    {
        return match ($tipo) {
            'entrada' => 'knowledge.checklists.entrada',
            'manutencao' => 'knowledge.checklists.manutencao',
            'controle_qualidade' => 'knowledge.checklists.controle-qualidade',
            'saida' => 'knowledge.checklists.saida',
            default => 'knowledge.checklists.entrada',
        };
    }

    /**
     * @param array<string, mixed> $modeloData
     */
    private function redirectToModelo(string $tipo, array $modeloData): RedirectResponse
    {
        return redirect()->route('knowledge.checklists.modelo.show', [
            'tipo' => $tipo,
            'tipoEquipamento' => $modeloData['tipo_equipamento_id'] ?? 0,
        ]);
    }
}
