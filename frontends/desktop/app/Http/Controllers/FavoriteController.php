<?php

namespace App\Http\Controllers;

use App\Support\DesktopPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FavoriteController extends DesktopController
{
    /**
     * Alterna a pagina atual nos favoritos do usuario.
     *
     * Sem `desktop.permission`: favoritar e' preferencia pessoal, nao
     * configuracao do sistema — mesmo racional de
     * `configurations.appearance.update`. O que protege o acesso e' a propria
     * validacao de favoritavel, que passa pelo RBAC do modulo em
     * DesktopNavigation::favoritableItems().
     */
    public function toggle(Request $request): JsonResponse|RedirectResponse
    {
        $routeName = trim((string) $request->input('route', ''));

        $result = DesktopPreferences::toggleFavorite($routeName);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $result['ok'],
                'favorito' => $result['favorito'],
                'mensagem' => $result['mensagem'],
                'favoritos' => DesktopPreferences::favorites(),
            ], $result['ok'] ? 200 : 422);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['mensagem']);
    }
}
