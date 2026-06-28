# 🎯 AÇÕES PARA MELHORAR CONSISTÊNCIA FRONTEND-BACKEND

## Sistema ERP - Plano de Execução | 2026-06-23

---

## 📋 RESUMO EXECUTIVO

| Item | Situação | Prioridade | Esforço |
|------|----------|-----------|--------|
| Token storage (Mobile) | localStorage ⚠️ | 🔴 ALTA | 4h |
| OpenAPI documentation | Não existe ❌ | 🔴 ALTA | 8h |
| Shared types (Mobile) | types.ts isolado ⚠️ | 🟠 MÉDIA | 6h |
| Auto-refresh tokens | Desktop não | 🟠 MÉDIA | 3h |
| Retry logic (Desktop) | Não existe ❌ | 🟠 MÉDIA | 4h |
| HTTPS enforcement | Desenvolvimento ⚠️ | 🔴 ALTA | 2h |
| Error handling i18n | Não existe ❌ | 🟡 BAIXA | 6h |

**Esforço Total**: ~33 horas de desenvolvimento

---

## 🔴 AÇÃO 1: OpenAPI Specification (CRÍTICA)

**Por quê:** 
- Single source of truth para API
- Gerar tipos TypeScript automaticamente
- Documentação interativa (Swagger UI)
- Facilita testes (Postman, etc)

**Como:**

### Passo 1: Instalar dependências no backend

```bash
cd c:\xampp\htdocs\sistema-erp\backend

# Adicionar Laravel OpenAPI package
composer require dedoc/laravel-openapi --dev

# Publicar configuração
php artisan vendor:publish --provider="Dedoc\\LaravelOpenApi\\OpenApiServiceProvider"
```

### Passo 2: Criar arquivo `openapi.yaml`

```bash
# Criar em: backend/openapi.yaml

php artisan openapi:generate
```

### Passo 3: Estrutura inicial (manual, se necessário)

**Arquivo:** `backend/openapi.yaml`
```yaml
openapi: 3.0.0
info:
  title: Sistema ERP API
  version: 1.0.0
  description: API Central do Sistema ERP
  contact:
    name: Equipe Dev
    email: dev@example.com

servers:
  - url: http://localhost:8000/api/v1
    description: Desenvolvimento
  - url: https://api.example.com/api/v1
    description: Produção

components:
  securitySchemes:
    BearerAuth:
      type: http
      scheme: bearer
      bearerFormat: JWT

paths:
  /auth/login:
    post:
      summary: Autenticação
      tags:
        - Autenticação
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/LoginRequest'
      responses:
        '200':
          description: Login bem-sucedido
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/LoginResponse'
        '401':
          description: Credenciais inválidas

  /orders:
    get:
      summary: Listar Ordens de Serviço
      tags:
        - Ordens
      security:
        - BearerAuth: []
      parameters:
        - name: search
          in: query
          schema:
            type: string
      responses:
        '200':
          description: Lista de OS
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/OrderListResponse'

components:
  schemas:
    LoginRequest:
      type: object
      properties:
        email:
          type: string
          format: email
        password:
          type: string
          format: password
        device_name:
          type: string
      required:
        - email
        - password

    LoginResponse:
      type: object
      properties:
        status:
          type: string
          enum: [success]
        data:
          type: object
          properties:
            access_token:
              type: string
            token_type:
              type: string
            expires_at:
              type: string
              format: date-time
            user:
              $ref: '#/components/schemas/User'

    User:
      type: object
      properties:
        id:
          type: integer
        nome:
          type: string
        email:
          type: string
        perfil:
          type: string

    Order:
      type: object
      properties:
        id:
          type: integer
        numero_os:
          type: string
        cliente_id:
          type: integer
        status:
          type: string

    OrderListResponse:
      type: object
      properties:
        status:
          type: string
        data:
          type: array
          items:
            $ref: '#/components/schemas/Order'
```

### Passo 4: Gerar tipos TypeScript

```bash
# Instalar gerador
npm install -g openapi-typescript

# Gerar types
cd c:\xampp\htdocs\sistema-erp\frontends\mobile
openapi-typescript ../../backend/openapi.yaml -o src/lib/api-types.ts
```

### Passo 5: Publicar Swagger UI

```bash
# No backend, adicionar rota
# routes/api.php
Route::get('/docs', function () {
    // Servir Swagger UI
    return file_get_contents(resource_path('swagger-ui.html'));
});

# Acessar em: http://localhost:8000/api/docs
```

**Benefício:**
- ✅ Tipos TypeScript sincronizados com backend
- ✅ Documentação interativa
- ✅ Facilita onboarding de novos devs
- ✅ Detecção automática de breaking changes

---

## 🔴 AÇÃO 2: Token Storage Migration (CRÍTICA)

**Por quê:** 
- localStorage é vulnerável a XSS
- HttpOnly cookies são mais seguros
- Ambos os frontends devem usar o mesmo padrão

**Como:**

### Para Desktop (já seguro):
- ✅ Manter como está (session PHP server-side)

### Para Mobile (migração necessária):

#### Passo 1: Adicionar suporte a HttpOnly cookies no backend

**Arquivo:** `backend/config/sanctum.php`
```php
'guard' => ['web'],

// Adicionar configuração de cookie:
'cookie' => [
    'name' => 'erp_token',
    'httpOnly' => true,
    'secure' => env('SANCTUM_SECURE_COOKIES', false), // true em produção
    'sameSite' => 'lax',
    'path' => '/',
    'domain' => env('SESSION_DOMAIN', null),
],
```

#### Passo 2: Modificar resposta do login

**Arquivo:** `backend/app/Http/Controllers/Api/V1/AuthController.php`
```php
public function login(AuthLoginRequest $request): JsonResponse
{
    // ... código existente ...

    $token = $user->createToken($deviceName, ['*'], $expiresAt);
    
    // Set HttpOnly cookie
    Cookie::queue('erp_token', $token->plainTextToken, 
        $expiresAt->diffInMinutes(now()),
        '/',
        config('session.domain'),
        config('app.env') === 'production', // secure
        true, // httpOnly
        false,
        'lax' // sameSite
    );

    return $this->success([
        'access_token' => $token->plainTextToken,  // Apenas para debug em dev
        'token_type' => 'Bearer',
        'expires_at' => $expiresAt->toIso8601String(),
        'user' => $this->userPayload($user),
    ], request: $request);
}
```

#### Passo 3: Atualizar Mobile para usar cookies

**Arquivo:** `frontends/mobile/src/lib/api.ts`
```typescript
async function requestRaw(
  path: string,
  init: RequestInit = {},
  includeAuth = true
): Promise<Response> {
  const response = await fetch(buildApiUrl(path), {
    ...init,
    cache: 'no-store',
    credentials: 'include', // Enviar cookies
    headers: buildHeaders(init, includeAuth),
  });

  if (includeAuth && response.status === 401) {
    clearStoredSession();
  }

  return response;
}

function buildHeaders(init: RequestInit | undefined, includeAuth: boolean): Headers {
  const headers = new Headers(init?.headers ?? {});
  headers.set('Accept', headers.get('Accept') ?? 'application/json');
  
  // Se usando cookies, não precisa de header Authorization
  // Cookie é enviado automaticamente com credentials: 'include'
  // withAuthHeaders(headers, includeAuth);  // REMOVER

  if (init?.body && !headers.has('Content-Type') && !(init.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json');
  }

  return headers;
}
```

#### Passo 4: Configurar CORS para aceitar cookies

**Arquivo:** `backend/config/cors.php` (ou criar, se não existir)
```php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3001')),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,  // ← IMPORTANTE
];
```

**Benefício:**
- ✅ Token seguro contra XSS
- ✅ Enviado automaticamente pelo navegador
- ✅ Não acessível via JavaScript
- ✅ Padrão de indústria

---

## 🟠 AÇÃO 3: Auto-Refresh de Tokens (MÉDIA)

**Por quê:**
- Desktop não implementa refresh
- Evita logout inesperado
- Melhora UX

**Implementação:**

### Desktop:

**Arquivo:** `backend/app/Services/ApiClient.php`
```php
private function authenticatedRequest(
    string $method, 
    string $uri, 
    array $payload = [], 
    array $query = []
): Response {
    $token = DesktopSession::token();

    if ($token === null) {
        throw new ApiAuthenticationException('Sua sessao expirou. Faca login novamente.');
    }

    try {
        $response = $this->baseRequest()
            ->withToken($token)
            ->send(strtoupper($method), $this->url($uri), [
                'json' => $payload,
                'query' => $query,
            ]);

        // Se receber 401, tentar refresh
        if ($response->status() === 401) {
            $refreshResponse = $this->tryRefreshToken();
            
            if ($refreshResponse['success']) {
                // Retry request com novo token
                $token = DesktopSession::token();
                return $this->baseRequest()
                    ->withToken($token)
                    ->send(strtoupper($method), $this->url($uri), [
                        'json' => $payload,
                        'query' => $query,
                    ]);
            }
        }

        return $response;

    } catch (ConnectionException) {
        throw new ApiRequestException('Nao foi possivel conectar ao backend central.');
    }
}

private function tryRefreshToken(): array
{
    try {
        $response = $this->baseRequest()
            ->post($this->url('/auth/refresh'));

        if ($response->successful()) {
            $data = $response->json('data');
            DesktopSession::storeToken($data['access_token']);
            DesktopSession::storeExpiresAt($data['expires_at']);
            return ['success' => true];
        }
    } catch (Exception) {
        // Ignorar erro
    }

    return ['success' => false];
}
```

### Mobile:

**Arquivo:** `frontends/mobile/src/lib/session.ts`
```typescript
let refreshTimer: NodeJS.Timeout | null = null;

export function storeSession(session: MobileSession): void {
  localStorage.setItem(SESSION_KEY, JSON.stringify(session));
  scheduleTokenRefresh(session);
}

export function scheduleTokenRefresh(session: MobileSession): void {
  if (refreshTimer) clearTimeout(refreshTimer);

  const expiresAt = new Date(session.expiresAt);
  const now = new Date();
  const msUntilExpire = expiresAt.getTime() - now.getTime();
  
  // Refresh 5 minutos antes de expirar
  const msUntilRefresh = msUntilExpire - (5 * 60 * 1000);

  if (msUntilRefresh > 0) {
    refreshTimer = setTimeout(() => {
      refreshAccessToken().catch(() => {
        clearStoredSession();
      });
    }, msUntilRefresh);
  }
}

async function refreshAccessToken(): Promise<void> {
  const response = await requestRaw('/auth/refresh', {
    method: 'POST',
  });

  if (response.ok) {
    const data = await response.json();
    storeSession(data.data);
  } else if (response.status === 401) {
    clearStoredSession();
  }
}
```

---

## 🟠 AÇÃO 4: Retry Logic (MÉDIA)

**Implementar em Desktop:**

**Arquivo:** `backend/app/Services/ApiClient.php`
```php
private function retry<T>(
    callable $request,
    int $maxAttempts = 3,
    int $delayMs = 1000
): mixed {
    $lastException = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            return $request();
        } catch (ConnectionException $e) {
            $lastException = $e;

            if ($attempt < $maxAttempts) {
                usleep($delayMs * (2 ** ($attempt - 1)) * 1000);
            }
        }
    }

    throw $lastException ?? new ApiRequestException('Falha ao conectar ao backend.');
}

public function get(string $uri, array $query = []): array
{
    $response = $this->retry(function () use ($uri, $query) {
        return $this->authenticatedRequest('get', $uri, [], $query);
    });

    return $this->parseResponse($response);
}
```

---

## 🔴 AÇÃO 5: HTTPS Enforcement (CRÍTICA)

**Para Produção:**

### Backend:

**Arquivo:** `backend/config/session.php`
```php
'secure' => env('SESSION_SECURE_COOKIES', true),  // true em produção
'same_site' => 'lax',
```

**Arquivo:** `backend/app/Http/Middleware/TrustProxies.php`
```php
protected $proxies = '*';
protected $headers = Request::HEADER_X_FORWARDED_FOR |
    Request::HEADER_X_FORWARDED_HOST |
    Request::HEADER_X_FORWARDED_PROTO |
    Request::HEADER_X_FORWARDED_AWS_ELB;
```

### Nginx (exemplo):

```nginx
server {
    listen 80;
    server_name example.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name example.com;

    ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
    
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;

    root /var/www/backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## 🟡 AÇÃO 6: Error Handling com i18n (BAIXA)

**Centralizar mensagens:**

**Arquivo:** `backend/resources/lang/pt_BR/messages.php`
```php
return [
    'auth' => [
        'unauthorized' => 'Não autenticado.',
        'invalid_credentials' => 'Email ou senha inválidos.',
        'session_expired' => 'Sua sessão expirou. Faça login novamente.',
    ],
    'api' => [
        'connection_error' => 'Não foi possível conectar ao servidor.',
        'server_error' => 'Erro interno do servidor.',
        'not_found' => 'Recurso não encontrado.',
    ],
    // ...
];
```

**Mobile:**

**Arquivo:** `frontends/mobile/src/lib/i18n.ts`
```typescript
const messages = {
  pt_BR: {
    auth: {
      unauthorized: 'Não autenticado.',
      invalid_credentials: 'Email ou senha inválidos.',
      session_expired: 'Sua sessão expirou. Faça login novamente.',
    },
    // ...
  },
};

export function t(key: string, lang = 'pt_BR'): string {
  const parts = key.split('.');
  let value: any = messages[lang];
  
  for (const part of parts) {
    value = value?.[part];
  }
  
  return value ?? key;
}
```

---

## 📊 PRIORIDADE DE IMPLEMENTAÇÃO

```
FASE 1 (SEMANA 1) - 🔴 CRÍTICA
├── Ação 5: HTTPS enforcement ................... 2h
├── Ação 1: OpenAPI specification .............. 8h
└── Ação 2: HttpOnly cookies ................... 4h
  SUBTOTAL: 14h

FASE 2 (SEMANA 2) - 🟠 MÉDIA
├── Ação 3: Auto-refresh tokens ................ 3h
├── Ação 4: Retry logic ........................ 4h
└── Ação 6: Centralizar validadores ........... 4h
  SUBTOTAL: 11h

FASE 3 (SEMANA 3) - 🟡 BAIXA
├── Error handling i18n ........................ 6h
└── Documentação Postman/Swagger ............... 2h
  SUBTOTAL: 8h

TOTAL: 33h (aprox 5-6 dias de trabalho)
```

---

## ✅ CHECKLIST DE IMPLEMENTAÇÃO

```
FASE 1:
- [ ] Criar openapi.yaml
- [ ] Gerar Swagger UI
- [ ] Gerar types.ts automáticos
- [ ] Revisar CORS configuration
- [ ] Configurar HttpOnly cookies backend
- [ ] Testar login com cookies
- [ ] Atualizar Mobile API client
- [ ] Testar cross-origin requests

FASE 2:
- [ ] Implementar auto-refresh Desktop
- [ ] Implementar auto-refresh Mobile
- [ ] Adicionar retry logic Desktop
- [ ] Testar retry com erro de conexão
- [ ] Centralizar validadores
- [ ] Testar validação em ambos frontends

FASE 3:
- [ ] Criar i18n file
- [ ] Traduzir mensagens comuns
- [ ] Integrar em error handling
- [ ] Criar Postman collection
- [ ] Documentar endpoints
- [ ] Testar completo end-to-end

VALIDAÇÃO FINAL:
- [ ] Testes de segurança
- [ ] Performance benchmark
- [ ] Compatibilidade navegadores
- [ ] Review de código
- [ ] Deploy staging
```

---

**Última atualização**: 2026-06-23
**Status**: Pronto para Implementação
