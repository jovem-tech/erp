# 📊 Análise de Consistência Frontend-Backend

## Sistema ERP - Divisão de Responsabilidades | 2026-06-23

---

## 🏗️ ARQUITETURA GERAL

```
┌─────────────────────────────────────────────────────────────┐
│                    BACKEND CENTRAL (API)                    │
│           Laravel 13.0 - Porta 8000 - /api/v1               │
│  ┌─────────────────┐  ┌──────────────┐  ┌────────────────┐  │
│  │  Controllers    │  │   Services   │  │  Sanctum Auth  │  │
│  │  (V1)           │  │  (Business)  │  │  (Bearer)      │  │
│  └─────────────────┘  └──────────────┘  └────────────────┘  │
│  ┌─────────────────┐  ┌──────────────┐  ┌────────────────┐  │
│  │  Models         │  │   RBAC       │  │  Middleware    │  │
│  │  (Eloquent)     │  │  (Group-     │  │  (Auth)        │  │
│  └─────────────────┘  │   based)     │  └────────────────┘  │
│                       └──────────────┘                       │
│         ↓ (Sanctum Bearer Token - Expiração 1 dia)          │
└─────────────────────────────────────────────────────────────┘
         ↑                         ↑
         │                         │
    ┌────┴─────────────────────────┴────┐
    │                                   │
    │                                   │
┌───┴──────────────────┐    ┌──────────┴───────────────────┐
│  DESKTOP             │    │  MOBILE                       │
│  Porta 8080          │    │  Porta 3001                   │
│  Laravel Blade       │    │  Next.js 15.3 TypeScript      │
│                      │    │                               │
│ ┌──────────────────┐ │    │ ┌──────────────────────────┐ │
│ │  Controllers     │ │    │ │  Pages                   │ │
│ │  (View Logic)    │ │    │ │  (Next.js App Router)    │ │
│ └──────────────────┘ │    │ └──────────────────────────┘ │
│ ┌──────────────────┐ │    │ ┌──────────────────────────┐ │
│ │  Services        │ │    │ │  Components              │ │
│ │  (API Client)    │ │    │ │  (React)                 │ │
│ └──────────────────┘ │    │ └──────────────────────────┘ │
│ ┌──────────────────┐ │    │ ┌──────────────────────────┐ │
│ │  Session         │ │    │ │  Lib (API, Types, Util) │ │
│ │  (PHP Server)    │ │    │ │                          │ │
│ └──────────────────┘ │    │ └──────────────────────────┘ │
└──────────────────────┘    └──────────────────────────────┘
```

---

## 📋 DIVISÃO DE RESPONSABILIDADES

### ✅ Backend Central (API)

**Responsabilidades:**
- Lógica de negócio principal
- Autenticação e Autorização (RBAC)
- Persistência de dados
- Validação de entrada
- Geração de tokens (Sanctum)
- Rate limiting

**O que NÃO faz:**
- Renderização de HTML
- Lógica de UI
- Estado de sessão do usuário (no client)

**Endpoints principais:**
```
POST   /api/v1/auth/login
GET    /api/v1/auth/me
POST   /api/v1/auth/logout
POST   /api/v1/auth/refresh
POST   /api/v1/auth/password/forgot
POST   /api/v1/auth/password/reset

GET    /api/v1/orders          (Bearer required)
POST   /api/v1/orders
PATCH  /api/v1/orders/{id}

GET    /api/v1/clients         (Bearer required)
GET    /api/v1/users
GET    /api/v1/groups
GET    /api/v1/dashboard/summary
```

**Formato de resposta padronizado:**
```json
{
  "status": "success|error",
  "data": { /* payload */ },
  "error": {
    "code": "ERROR_CODE",
    "message": "Descrição do erro",
    "details": null
  },
  "meta": {
    "timestamp": "2026-06-23T10:30:00Z",
    "request_id": "req_uuid"
  }
}
```

---

### 🖥️ Frontend Desktop

**Responsabilidades:**
- Renderização server-side (Blade)
- Gerenciamento de sessão (PHP Server-side)
- Roteamento de páginas
- Proteção de rotas baseada em RBAC
- Consumir API backend via Services

**Stack:**
- Framework: Laravel 13.0
- Template engine: Blade
- Build tool: Vite
- Storage de token: Sessão PHP (`session('token')`)
- Autenticação: Bearer token (renovado a cada request)

**Arquitetura de pastas:**
```
frontends/desktop/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php
│   │   │   ├── OrderController.php
│   │   │   ├── ClientController.php
│   │   │   ├── UserController.php
│   │   │   └── ...
│   │   └── Middleware/
│   │       ├── EnsureBackendToken.php
│   │       └── Authorize.php
│   ├── Services/
│   │   ├── ApiClient.php          (HTTP client wrapper)
│   │   ├── OrderService.php        (Business logic)
│   │   ├── ClientService.php
│   │   └── ...
│   ├── Support/
│   │   └── DesktopSession.php     (Token management)
│   └── Models/ (VAZIO - propositalmente)
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   ├── components/
│   │   └── pages/
│   ├── js/
│   └── css/
└── routes/
    └── web.php
```

**Fluxo de autenticação:**
```
1. User submits login form
2. AuthController calls AuthService->login()
3. AuthService calls ApiClient->login() [POST /api/v1/auth/login]
4. Backend returns Bearer token
5. Token stored in session: session(['token' => $token])
6. User redirected to /dashboard
7. All subsequent requests attach token via ApiClient->authenticatedRequest()
```

**Características:**
- ✅ Token armazenado no servidor (seguro)
- ✅ Session CSRF protection automática do Laravel
- ✅ Camada Service obrigatória entre Controller e HTTP
- ✅ No Models de negócio (dados vêm apenas do backend)
- ✅ Middleware de proteção de rota por permissão

**Exemplo de Controller + Service:**
```php
// Controller
public function show(Request $request, $id)
{
    $this->authorize('os:visualizar');
    return view('orders.show', [
        'order' => $this->orderService->show($id)
    ]);
}

// Service
public function show(int $orderId): array
{
    return $this->apiClient->get("/orders/$orderId");
}

// ApiClient (abstrai HTTP)
public function get(string $uri, array $query = []): array
{
    $token = DesktopSession::token();
    return Http::withToken($token)->get($this->url($uri), $query);
}
```

---

### 📱 Frontend Mobile

**Responsabilidades:**
- Renderização client-side (React)
- Gerenciamento de estado (localStorage)
- Roteamento de páginas
- PWA capabilities
- Consumir API backend via lib

**Stack:**
- Framework: Next.js 15.3
- Linguagem: TypeScript
- UI Library: React 19
- Storage de token: localStorage
- Autenticação: Bearer token (client-stored)

**Arquitetura de pastas:**
```
frontends/mobile/
├── src/
│   ├── app/
│   │   ├── layout.tsx           (Root layout)
│   │   ├── page.tsx             (Home)
│   │   ├── login/
│   │   ├── os/
│   │   │   ├── page.tsx         (List)
│   │   │   └── [id]/
│   │   │       └── page.tsx     (Detail)
│   │   └── manifest.ts          (PWA)
│   ├── components/
│   │   ├── auth-guard.tsx       (Protected routes)
│   │   ├── session-provider.tsx (Auth state)
│   │   ├── orders/
│   │   └── ...
│   ├── lib/
│   │   ├── api.ts               (HTTP client)
│   │   ├── session.ts           (Token management)
│   │   ├── types.ts             (TypeScript interfaces)
│   │   ├── format.ts            (Utils)
│   │   └── orders.ts            (Domain logic)
│   └── pages/                   (Legacy - deprecated)
├── public/
│   ├── icon.png
│   └── manifest.json
└── next.config.ts
```

**Fluxo de autenticação:**
```
1. User enters credentials in login form
2. Page calls api.post('auth/login', credentials)
3. Backend returns { accessToken, expiresAt, user }
4. Token stored in localStorage via storeSession()
5. User redirected to /os
6. All API calls include: headers['Authorization'] = 'Bearer token'
7. On 401: clearStoredSession() + redirect to /login
```

**Características:**
- ✅ TypeScript (type-safe)
- ✅ Autenticação por Bearer token
- ✅ PWA (offline capability, installable)
- ✅ Next.js App Router (modern architecture)
- ✅ Tipos compartilhados em `types.ts`
- ⚠️ Token em localStorage (risco XSS - mitigado por CSP)

**Exemplo de API call + Component:**
```typescript
// lib/api.ts
export async function fetchOrders(search?: string): Promise<Order[]> {
  const data = await requestJson<OrderListPayload>('/orders', {
    method: 'GET',
    headers: { 'X-Search': search ?? '' }
  });
  return data.items;
}

// components/orders-list.tsx
export async function OrdersList() {
  const orders = await fetchOrders();
  return (
    <ul>
      {orders.map(o => (
        <li key={o.id}>{o.numero_os}</li>
      ))}
    </ul>
  );
}
```

---

## ✅ CONSISTÊNCIAS IDENTIFICADAS

### 1. **Autenticação Unificada**
```
✅ Ambos usam Sanctum Bearer token
✅ Endpoint /api/v1/auth/login centralizado
✅ Expiração 1 dia (recentemente reduzida de 7)
✅ Logout revoga token no servidor
✅ Token refresh disponível
```

### 2. **Camada de Serviço Abstrata**
```
✅ Desktop: app/Services + ApiClient
✅ Mobile:  src/lib + api.ts
✅ Ambos abstraem chamadas HTTP
✅ Ambos implementam error handling
```

### 3. **RBAC Centralizado**
```
✅ Ambos consomem /api/v1/auth/me
✅ Resposta inclui user, group, modules, permissions
✅ Roteamento protegido por permissão
✅ Desktop: middleware Authorize
✅ Mobile:  component AuthGuard
```

### 4. **Endpoints API Consistentes**
```
✅ Mesma base: /api/v1
✅ Mesmos recursos: /orders, /clients, /users, etc.
✅ Mesmo padrão de resposta
✅ Mesmos status codes
```

### 5. **Tratamento de Erros Padronizado**
```
✅ Backend: ApiResponse::error() com code + message
✅ Mobile: ApiError exception class
✅ Desktop: Exception classes (ApiException, etc)
✅ 401 → Logout + redirect login
✅ 403 → Mensagem de acesso negado
✅ 5xx → Retry automático ou mensagem de erro
```

---

## ⚠️ INCONSISTÊNCIAS E RECOMENDAÇÕES

### 1. **Token Storage - CRÍTICA**

| Aspecto | Desktop | Mobile | Recomendação |
|---------|---------|--------|--------------|
| Storage | Session PHP (seguro) | localStorage (risco XSS) | Mobile: usar HttpOnly cookie |
| Renovação | Automática por request | Manual via refresh endpoint | Mobile: implementar auto-refresh |
| Revogação | Imediata no logout | Imediata no logout | ✅ Consistente |

**Ação:** 
- Mobile: Considerar migrar para HttpOnly cookie com `Secure` flag
- Ou: Implementar Content Security Policy (CSP) rigorosa

### 2. **Tipagem de Dados - MÉDIO**

| Aspecto | Desktop | Mobile | Recomendação |
|---------|---------|--------|--------------|
| Tipos | PHP (dinâmico) | TypeScript (estático) | Criar OpenAPI/Swagger |
| Documentação | README Blade | types.ts comentado | Gerar tipos do backend |
| Validação | FormRequest | Custom validators | Compartilhar schemas |

**Ação:**
```bash
# Gerar tipos TypeScript do backend automaticamente
# Usar OpenAPI 3.0 com ferramentas como:
# - Laravel OpenAPI (PHP to OpenAPI)
# - openapi-typescript (gerar tipos)
```

**Arquivo proposto:** `backend/openapi.yaml` ou `openapi.json`

### 3. **Documentação de API - MÉDIO**

| Aspecto | Status | Recomendação |
|---------|--------|--------------|
| Specs | Em `specs/` | Excelente |
| OpenAPI | Não existe | Criar |
| Postman collection | Não existe | Criar |
| Type definitions | types.ts (mobile) | Centralizar |

**Ação:**
```bash
# Criar em backend/
openapi.yaml        # Definição OpenAPI 3.0
swagger-ui/         # UI interativa

# Gerar tipos:
npx openapi-typescript ./openapi.yaml -o types.ts
```

### 4. **Versionamento de API - MÉDIO**

| Aspecto | Status | Recomendação |
|---------|--------|--------------|
| Versão | `/api/v1` | ✅ Bom |
| Backward compat | Não documentado | Documentar |
| Breaking changes | Não documentado | Documentar |
| Deprecação | Não existe | Implementar |

**Ação:**
- Documentar política de versionamento
- Definir timeline para deprecação de endpoints
- Usar header `X-API-Version` para tracking

### 5. **Validação - MÉDIO**

| Aspecto | Desktop | Mobile | Recomendação |
|---------|---------|--------|--------------|
| Client-side | Blade HTML5 | React form validation | Compartilhar regras |
| Server-side | FormRequest | Backend only | ✅ Correto |
| Feedback | Blade flash | JSON error | Padronizar formato |

**Ação:**
```typescript
// Mobile: centralizar em lib/validators.ts
export const orderValidationRules = {
  numero_os: { required: true, minLength: 3 },
  cliente_id: { required: true, type: 'number' },
  // ...
};
```

### 6. **Error Handling - BAIXO**

| Aspecto | Status | Recomendação |
|---------|--------|--------------|
| Format padronizado | ✅ Sim | Manter |
| Mensagens i18n | ❌ Não | Implementar |
| Retry logic | Mobile: sim, Desktop: não | Padronizar |
| Logging | Backend sim, Frontend não | Implementar |

**Ação:**
```typescript
// Mobile: implementar retry com backoff exponencial
async function requestWithRetry<T>(
  path: string,
  init?: RequestInit,
  maxRetries = 3
): Promise<T> {
  for (let i = 0; i < maxRetries; i++) {
    try {
      return await requestJson<T>(path, init);
    } catch (err) {
      if (i < maxRetries - 1 && shouldRetry(err)) {
        await sleep(Math.pow(2, i) * 1000);
        continue;
      }
      throw err;
    }
  }
}
```

### 7. **Performance - MÉDIO**

| Aspecto | Desktop | Mobile | Recomendação |
|---------|---------|--------|--------------|
| Caching | Session PHP | localStorage | Implementar |
| Pagination | Query string | Query string | ✅ Consistente |
| Lazy loading | Não | Sim (Next.js) | Desktop: implementar |
| Compression | Gzip (nginx) | Gzip | ✅ Consistente |

**Ação:**
- Desktop: implementar ETag caching
- Mobile: usar SWR ou React Query para cache
- Backend: adicionar Cache-Control headers

### 8. **Segurança - CRÍTICA**

| Aspecto | Status | Recomendação |
|---------|--------|--------------|
| HTTPS | ❌ Desenvolvimento | ✅ Produção obrigatório |
| CSRF | Desktop: token CSRF | Mobile: N/A (SPA) | ✅ Correto |
| XSS | Desktop: escaping | Mobile: React safe | ✅ Bom |
| SQL Injection | Backend parameterizado | ✅ Correto | Manter |
| CORS | Configurado | Revisar | Restringir domínios |
| Rate limiting | Alguns endpoints | Adicionar mais | Implementar global |

**Ação (já documentada em SECURITY_FIXES.md):**
- Implementar HTTPS
- Revisar CORS configuration
- Adicionar rate limiting global

---

## 🎯 ESTRUTURA IDEAL PROPOSTA

### Diretório Compartilhado (novo)

```
sistema-erp/
├── backend/                  # Backend Laravel
├── frontends/                # Frontends (desktop, mobile)
├── shared/                   # NOVO: Código compartilhado
│   ├── types/
│   │   ├── api-types.ts      # Gerado do backend OpenAPI
│   │   ├── models/
│   │   │   ├── Order.ts
│   │   │   ├── Client.ts
│   │   │   └── User.ts
│   │   └── payloads/
│   │       ├── LoginPayload.ts
│   │       ├── OrderListPayload.ts
│   │       └── ...
│   ├── api/
│   │   ├── endpoints.ts      # Centralized URL definitions
│   │   ├── errors.ts         # Common error types
│   │   └── client.ts         # Base HTTP client
│   ├── validators/
│   │   ├── order.validator.ts
│   │   ├── client.validator.ts
│   │   └── ...
│   ├── constants/
│   │   ├── order-status.ts
│   │   ├── permissions.ts
│   │   └── ...
│   └── utils/
│       ├── format.ts
│       └── transform.ts
├── documentacao/
├── specs/
└── README.md
```

**Benefício:**
- Tipagem compartilhada entre frontend e backend
- Validação consistente
- Redução de duplicação
- Evolução arquitetural facilitada

---

## 📊 MATRIZ DE CONSISTÊNCIA

| Critério | Score | Status |
|----------|-------|--------|
| Autenticação | 9/10 | ✅ Excelente |
| Autorização (RBAC) | 9/10 | ✅ Excelente |
| API Design | 8/10 | ✅ Bom |
| Tipagem de dados | 6/10 | ⚠️ Improvável |
| Documentação | 7/10 | ⚠️ Bom mas incompleto |
| Error handling | 8/10 | ✅ Bom |
| Segurança | 7/10 | ⚠️ Bom desenvolvimento, produção pendente |
| Performance | 7/10 | ⚠️ Bom, caching pode melhorar |
| Separação de concerns | 9/10 | ✅ Excelente |

**Score Geral: 7.8/10** ✅ **BOM** (com melhorias recomendadas)

---

## 🚀 ROADMAP DE MELHORIAS

### Sprint 1 (Curto Prazo - 1 semana)
- [ ] Criar `openapi.yaml` com especificação API
- [ ] Gerar tipos TypeScript automáticos
- [ ] Documentar política de versionamento
- [ ] Centralizar endpoints em constantes

### Sprint 2 (Médio Prazo - 2-3 semanas)
- [ ] Implementar HttpOnly cookies em mobile
- [ ] Adicionar auto-refresh de tokens
- [ ] Implementar retry com backoff exponencial
- [ ] Centralizar validadores

### Sprint 3 (Longo Prazo - 1 mês)
- [ ] Migrar token desktop para HttpOnly
- [ ] Implementar OpenAPI UI (Swagger)
- [ ] Adicionar Postman collection
- [ ] Implementar Feature flags para API

---

## 📚 REFERÊNCIAS

- OpenAPI 3.0 Spec: https://swagger.io/specification/
- Next.js 15: https://nextjs.org/docs
- Laravel 13: https://laravel.com/docs/13.x
- Sanctum: https://laravel.com/docs/13.x/sanctum
- TypeScript: https://www.typescriptlang.org/

---

**Última atualização**: 2026-06-23
**Status**: Análise Completa - Pronto para Implementação
