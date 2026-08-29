<?php

namespace Tests\Unit;

use App\Exceptions\ApiRequestException;
use App\Services\ApiClient;
use App\Support\DesktopSession;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guarda a regra que motivou a mudanca: reexecutar comando mutavel e' proibido.
 *
 * O laco antigo repetia QUALQUER 5xx tres vezes, com usleep(1s) e usleep(2s)
 * dentro do worker do PHP-FPM. Num POST isso significava reexecutar um comando
 * que o backend pode ter concluido antes de falhar — e, de quebra, triplicar a
 * carga sobre um backend que ja estava em dificuldade, com o worker do desktop
 * preso o tempo todo (o pool tem pm.max_children pequeno).
 */
class ApiClientRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DesktopSession::store('token-de-teste', ['id' => 1, 'email' => 'op@teste.local']);
    }

    public function test_post_is_not_retried_on_server_error(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'Falha interna'], 500),
        ]);

        try {
            app(ApiClient::class)->post('/orders', ['cliente_id' => 1]);
            $this->fail('Esperava ApiRequestException para HTTP 500.');
        } catch (ApiRequestException) {
            // esperado
        }

        Http::assertSentCount(1);
    }

    public function test_put_patch_and_delete_are_not_retried_on_server_error(): void
    {
        foreach (['put', 'patch', 'delete'] as $verb) {
            Http::fake([
                '*' => Http::response(['message' => 'Falha interna'], 500),
            ]);

            try {
                app(ApiClient::class)->{$verb}('/orders/1', ['status' => 'x']);
            } catch (ApiRequestException) {
                // esperado
            }

            Http::assertSentCount(1);
        }
    }

    public function test_get_is_retried_once_on_server_error(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'Falha interna'], 500),
        ]);

        try {
            app(ApiClient::class)->get('/orders');
        } catch (ApiRequestException) {
            // esperado
        }

        // Duas tentativas, nao tres: leitura pode ser repetida com seguranca,
        // mas cada tentativa extra prende o worker por mais tempo.
        Http::assertSentCount(2);
    }

    public function test_get_stops_retrying_as_soon_as_it_succeeds(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'Falha interna'], 500)
            ->push(['data' => ['items' => []]], 200);

        $result = app(ApiClient::class)->get('/orders');

        $this->assertSame(['items' => []], $result['data']);
        Http::assertSentCount(2);
    }

    /**
     * Regressao: ApiClient::refreshToken() chamava DesktopSession::storeToken()
     * e storeExpiresAt(), que nao existiam. O caminho de SUCESSO da renovacao
     * terminava em Error fatal, entao todo usuario cujo token expirava no meio
     * da sessao levava um 500 em vez de continuar trabalhando.
     */
    public function test_token_refresh_stores_the_new_token_instead_of_fataling(): void
    {
        Http::fake([
            '*/auth/refresh' => Http::response([
                'data' => [
                    'access_token' => 'token-renovado',
                    'expires_at' => '2030-01-01T00:00:00+00:00',
                ],
            ], 200),
        ]);

        app(ApiClient::class)->refreshToken();

        $this->assertSame('token-renovado', DesktopSession::token());
        $this->assertSame('2030-01-01T00:00:00+00:00', DesktopSession::expiresAt());
    }

    public function test_validation_errors_are_never_retried(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'Dados invalidos'], 422),
        ]);

        try {
            app(ApiClient::class)->get('/orders');
        } catch (ApiRequestException) {
            // esperado
        }

        Http::assertSentCount(1);
    }
}
