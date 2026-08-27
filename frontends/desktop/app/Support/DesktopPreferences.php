<?php

namespace App\Support;

use App\Models\UserPreference;
use Throwable;

/**
 * Preferencias pessoais do desktop (tema, modo de navegacao e favoritos).
 *
 * Todas moram na mesma linha de `user_preferences`, chaveada pelo id do usuario
 * na API central — o desktop nao tem tabela local de usuarios. O padrao e' o
 * mesmo ja usado pelo tema desde 2026-07-02: uma leitura por sessao, o resto
 * servido da sessao, e escrita simultanea em sessao + banco para sobreviver ao
 * logout.
 */
class DesktopPreferences
{
    public const NAV_MODE_FIXED = 'fixed';

    public const NAV_MODE_DRAWER = 'drawer';

    /**
     * Acima disso o dropdown deixa de ser um atalho e vira uma segunda sidebar
     * — que e' exatamente o que os favoritos existem para evitar.
     */
    public const MAX_FAVORITES = 12;

    /**
     * Sentinel proprio, e nao a chave do tema: sessoes ja abertas em producao
     * tem `desktop_theme` gravado, entao reaproveitar aquela chave deixaria
     * navegacao e favoritos sem hidratar ate' o usuario deslogar.
     */
    private const LOADED_KEY = 'desktop_prefs_loaded';

    private const THEME_KEY = 'desktop_theme';

    private const NAV_MODE_KEY = 'desktop_nav_mode';

    private const FAVORITES_KEY = 'desktop_favorites';

    /**
     * @return array<int, string>
     */
    public static function navigationModes(): array
    {
        return [self::NAV_MODE_FIXED, self::NAV_MODE_DRAWER];
    }

    /**
     * Carrega a linha do usuario para a sessao, uma vez por sessao.
     */
    public static function hydrateSession(): void
    {
        if (session()->has(self::LOADED_KEY)) {
            return;
        }

        $userId = self::userId();

        if ($userId <= 0) {
            return;
        }

        try {
            $preference = UserPreference::where('api_user_id', $userId)->first();
        } catch (Throwable) {
            // Banco local indisponivel nao pode derrubar a navegacao: sem
            // sentinel gravado, a proxima requisicao tenta de novo.
            return;
        }

        session()->put(self::THEME_KEY, (string) ($preference->desktop_theme ?? 'default'));
        session()->put(self::NAV_MODE_KEY, self::sanitizeNavigationMode($preference->navigation_mode ?? null));
        session()->put(self::FAVORITES_KEY, self::sanitizeFavorites($preference->favorites ?? []));
        session()->put(self::LOADED_KEY, true);
    }

    public static function forgetSession(): void
    {
        session()->forget([
            self::LOADED_KEY,
            self::THEME_KEY,
            self::NAV_MODE_KEY,
            self::FAVORITES_KEY,
        ]);
    }

    public static function navigationMode(): string
    {
        return self::sanitizeNavigationMode(session(self::NAV_MODE_KEY));
    }

    public static function storeNavigationMode(string $mode): string
    {
        $mode = self::sanitizeNavigationMode($mode);

        session()->put(self::NAV_MODE_KEY, $mode);
        self::persist(['navigation_mode' => $mode]);

        return $mode;
    }

    /**
     * Nomes de rota como estao gravados, sem filtro de permissao.
     *
     * @return array<int, string>
     */
    public static function favoriteRoutes(): array
    {
        return self::sanitizeFavorites(session(self::FAVORITES_KEY, []));
    }

    /**
     * Favoritos prontos para render: resolvidos em rotulo/icone/URL e filtrados
     * pelo que este usuario pode ver agora.
     *
     * O filtro e' so' de leitura — a linha no banco continua intacta, para que
     * um favorito volte sozinho se a permissao for devolvida ou se a rota for
     * reintroduzida.
     *
     * @return array<int, array<string, string>>
     */
    public static function favorites(): array
    {
        $favoritable = DesktopNavigation::favoritableItems();
        $resolved = [];

        foreach (self::favoriteRoutes() as $routeName) {
            if (! isset($favoritable[$routeName])) {
                continue;
            }

            $resolved[] = $favoritable[$routeName] + ['url' => route($routeName)];
        }

        return $resolved;
    }

    public static function isFavorite(mixed $routeName): bool
    {
        return is_string($routeName)
            && $routeName !== ''
            && in_array($routeName, self::favoriteRoutes(), true);
    }

    /**
     * Alterna um favorito. Retorna o estado final e, quando recusado, o motivo.
     *
     * @return array{ok: bool, favorito: bool, mensagem: string}
     */
    public static function toggleFavorite(string $routeName): array
    {
        if (DesktopNavigation::findFavoritable($routeName) === null) {
            return [
                'ok' => false,
                'favorito' => false,
                'mensagem' => 'Esta pagina nao pode ser favoritada.',
            ];
        }

        $favorites = self::favoriteRoutes();
        $index = array_search($routeName, $favorites, true);

        if ($index !== false) {
            array_splice($favorites, (int) $index, 1);
            self::storeFavorites($favorites);

            return [
                'ok' => true,
                'favorito' => false,
                'mensagem' => 'Pagina removida dos favoritos.',
            ];
        }

        if (count($favorites) >= self::MAX_FAVORITES) {
            // Recusa explicita em vez de descartar o mais antigo em silencio:
            // o usuario escolheu cada um desses itens.
            return [
                'ok' => false,
                'favorito' => false,
                'mensagem' => 'Voce ja tem ' . self::MAX_FAVORITES . ' favoritos. Remova um antes de adicionar outro.',
            ];
        }

        $favorites[] = $routeName;
        self::storeFavorites($favorites);

        return [
            'ok' => true,
            'favorito' => true,
            'mensagem' => 'Pagina adicionada aos favoritos.',
        ];
    }

    /**
     * @param  array<int, string>  $favorites
     */
    private static function storeFavorites(array $favorites): void
    {
        $favorites = self::sanitizeFavorites($favorites);

        session()->put(self::FAVORITES_KEY, $favorites);
        self::persist(['favorites' => $favorites]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function persist(array $attributes): void
    {
        $userId = self::userId();

        if ($userId <= 0) {
            return;
        }

        UserPreference::updateOrCreate(['api_user_id' => $userId], $attributes);
    }

    private static function sanitizeNavigationMode(mixed $mode): string
    {
        return is_string($mode) && in_array($mode, self::navigationModes(), true)
            ? $mode
            : self::NAV_MODE_FIXED;
    }

    /**
     * @return array<int, string>
     */
    private static function sanitizeFavorites(mixed $favorites): array
    {
        if (is_string($favorites)) {
            $favorites = json_decode($favorites, true);
        }

        if (! is_array($favorites)) {
            return [];
        }

        $clean = [];

        foreach ($favorites as $routeName) {
            if (! is_string($routeName) || $routeName === '' || in_array($routeName, $clean, true)) {
                continue;
            }

            $clean[] = $routeName;
        }

        return array_slice($clean, 0, self::MAX_FAVORITES);
    }

    private static function userId(): int
    {
        return (int) (DesktopSession::user()['id'] ?? 0);
    }
}
