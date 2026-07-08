# Exemplo de referência: step-up de admin no "Cancelar baixa" de OS

Implementado e testado em 2026-07-08. Use como template ao replicar o padrão
descrito em `SKILL.md` para qualquer outra ação sensível.

## Backend

`backend/app/Http/Requests/Api/V1/CancelOrderClosureRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

class CancelOrderClosureRequest extends BaseApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'admin_email' => ['required', 'string', 'email'],
            'admin_password' => ['required', 'string'],
        ];
    }
}
```

`backend/app/Http/Controllers/Api/V1/OrderController.php::cancelClosure()` —
estrutura da verificação (adaptar nomes de classes/mensagens para outra ação):

```php
public function cancelClosure(CancelOrderClosureRequest $request, int $order): JsonResponse
{
    // A visibilidade do botão é do módulo (visualizar), não da ação (admin).
    // O gate real de autorização é a verificação de credenciais abaixo.
    $this->authorize('os:visualizar');

    $user = $this->authenticatedUser($request);
    if ($user === null) {
        return $this->unauthenticatedResponse($request);
    }

    $validated = $request->validated();
    $adminEmail = mb_strtolower(trim((string) $validated['admin_email']));
    $adminPassword = (string) $validated['admin_password'];

    $throttleKey = 'os-closure-cancel-admin-auth:' . $adminEmail . '|' . $request->ip();
    if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
        return $this->error(
            'Muitas tentativas de verificação de administrador. Aguarde um pouco e tente novamente.',
            429,
            'ORDER_CLOSURE_CANCEL_ADMIN_AUTH_RATE_LIMITED',
            ['retry_after' => RateLimiter::availableIn($throttleKey)],
            request: $request
        );
    }

    $admin = User::query()->where('email', $adminEmail)->first();

    if (
        ! $admin instanceof User
        || ! (bool) $admin->ativo
        || mb_strtolower(trim((string) ($admin->perfil ?? ''))) !== 'admin'
        || ! Hash::check($adminPassword, (string) $admin->senha)
    ) {
        RateLimiter::hit($throttleKey, 60);

        logger()->warning('[API V1][ORDERS][CLOSURE] Credenciais de administrador inválidas ao cancelar baixa', [
            'order_id' => $order,
            'user_id' => $user->id,
            'admin_email' => $adminEmail,
            'ip' => $request->ip(),
        ]);

        // 422, nao 401: o desktop trata QUALQUER 401 como "sessao do usuario
        // atual expirou" e forca logout (ApiClient::parseResponse). Isso e'
        // uma verificacao de credenciais de um usuario DIFERENTE (admin),
        // nao a sessao de quem esta clicando — nunca pode disparar esse logout.
        return $this->error(
            'Credenciais de administrador inválidas.',
            422,
            'ORDER_CLOSURE_CANCEL_ADMIN_AUTH_INVALID',
            null,
            request: $request
        );
    }

    RateLimiter::clear($throttleKey);

    $result = $this->orderClosureService->cancelClosure($order, $user, $admin);
    // ... match() dos results, igual a qualquer outro endpoint do projeto.
}
```

O service (`OrderClosureService::cancelClosure(int $orderId, User $actor, ?User $verifiedAdmin = null)`)
recebe os dois atores separadamente e registra o admin verificado na
observação do registro de auditoria (`os_status_historico`), nunca substituindo
o autor real da ação.

## `bootstrap/app.php` (backend) — higiene da senha

```php
->withExceptions(function (Exceptions $exceptions): void {
    // Evita que a senha de admin (confirmação de acao sensivel) fique
    // gravada em session('_old_input') se a validação nativa do
    // Request::validate() falhar antes mesmo de chegar na API.
    $exceptions->dontFlash('admin_password');
    // ...
})
```

## Desktop

`frontends/desktop/app/Services/OrderService.php`:

```php
public function cancelClosure(int $id, string $adminEmail, string $adminPassword): array
{
    $response = $this->apiClient->post('/orders/' . $id . '/closure/cancel', [
        'admin_email' => $adminEmail,
        'admin_password' => $adminPassword,
    ]);

    return $response['data'] ?? [];
}
```

`frontends/desktop/app/Http/Controllers/OrderController.php::closureCancel()` —
trata `ApiAuthorizationException`/`ApiRequestException` (o 422 vira exceção
aqui) **sem** `withInput()`:

```php
} catch (ApiAuthorizationException|ApiRequestException $exception) {
    if ($request->wantsJson()) {
        return response()->json(['error' => $exception->getMessage()], 422);
    }
    // Sem withInput() de proposito: nunca refletir a senha do
    // administrador de volta para a sessao/old-input.
    return redirect()->route('orders.show', $order)->with('error', $exception->getMessage());
}
```

Handler global de `ApiRequestException` (mesmo arquivo/classe base de exceção
usada em outros controllers) — excluir o campo de senha do `except()`:

```php
->withInput($request->except(['password', 'admin_password']))
```

`routes/web.php` — middleware de **visualização**, não edição:

```php
Route::post('/os/{order}/baixa/cancelar', [OrderController::class, 'closureCancel'])
    ->name('orders.closure.cancel')
    ->middleware('desktop.permission:os,visualizar');
```

## Modal compartilhado (UI)

`frontends/desktop/resources/views/orders/_cancel_closure_modal.blade.php` —
modal Bootstrap com campos `#cancelClosureAdminEmail`/`#cancelClosureAdminPassword`,
caixa de erro inline `#cancelClosureError` (nunca redireciona em caso de falha),
botão de submit `#cancelClosureSubmit`. Incluído via `@push('modals')` em
qualquer tela que precise do gatilho (lista e detalhe, no caso da OS).

`frontends/desktop/public/assets/js/orders-cancel-closure-modal.js` — lê
`window.__DESKTOP_CANCEL_CLOSURE_MODAL = { cancelUrlTemplate, csrfToken }`; no
`show.bs.modal` lê `data-order-id`/`data-order-numero` de `event.relatedTarget`
e reseta form/erro; no submit faz `fetch` POST com JSON `{admin_email,
admin_password}` + header CSRF; sucesso → `window.location.reload()`; erro →
mostra mensagem em `#cancelClosureError` e reabilita o botão (nunca navega).

Gatilho (botão/link) em qualquer tela, reaproveitando o mesmo modal:

```blade
<button type="button" class="btn btn-outline-danger"
    data-bs-toggle="modal"
    data-bs-target="#cancelClosureModal"
    data-order-id="{{ $order['id'] }}"
    data-order-numero="{{ $order['numero_os'] ?? ('#' . $order['id']) }}">
    Cancelar baixa
</button>
```

## Teste headless de referência

`test-cancelar-baixa-admin.js` (scratchpad da sessão que implementou isso)
cobre o roteiro mínimo que qualquer nova implementação deste padrão deve
repetir:

1. Botão visível para usuário sem precisar ser admin.
2. Modal abre com os dados corretos do registro-alvo.
3. Senha errada → erro inline, sem navegação.
4. Sessão do usuário que clicou **continua ativa** após senha errada (prova de
   que não houve o bug do 401/logout automático).
5. Senha correta → ação executada, UI reflete o novo estado.
