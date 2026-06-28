# 🧪 GUIA DE VALIDAÇÃO DE CORREÇÕES DE SEGURANÇA

## Verificar se as Correções foram Implementadas Corretamente

---

## 1️⃣ Validar Mass Assignment Fix

### Teste Manual
```bash
cd c:\xampp\htdocs\sistema-erp\backend

php artisan tinker

# Tentar atribuir token_recuperacao diretamente (deve FALHAR):
$user = User::find(1);
$user->update(['token_recuperacao' => 'malicious_token_123']);

# Saída esperada:
# Illuminate\Database\Eloquent\MassAssignmentException: 
# Add [token_recuperacao] to fillable property to allow mass assignment on ...
```

✅ Se retornar erro MassAssignmentException = **SUCESSO**
❌ Se atualizar sem erro = **FALHA**

### Verificar Código
```bash
# Verificar que token_recuperacao NÃO está em $fillable
grep -A 15 "protected \$fillable" app/Models/User.php

# Deve mostrar:
# protected $fillable = [
#     'nome',
#     'email',
#     ...
#     'ultimo_acesso',
#     'remember_token_hash',
#     'remember_token_expires_at',
# ];
# (SEM token_recuperacao ou token_expiracao)
```

---

## 2️⃣ Validar Força da Senha

### Teste: Senha Muito Curta (deve rejeitar)
```bash
cd c:\xampp\htdocs\sistema-erp\backend

php artisan tinker

# Importar validator
use Illuminate\Support\Facades\Validator;

# Testar senha de 6 caracteres (DEVE FALHAR):
$rules = ['password' => ['required', 'string', 'min:8', 'max:255']];
$data = ['password' => '123456'];
$validator = Validator::make($data, $rules);

# Resultado:
# echo $validator->fails();  # true
# echo $validator->errors()->first('password');  # "The password field must be at least 8 characters."
```

✅ Se rejeitar = **SUCESSO**

### Teste: Senha com 8+ caracteres (deve aceitar)
```bash
php artisan tinker

$rules = ['password' => ['required', 'string', 'min:8', 'max:255']];
$data = ['password' => 'SenhaForte123'];
$validator = Validator::make($data, $rules);

echo $validator->passes();  # true
```

✅ Se aceitar = **SUCESSO**

---

## 3️⃣ Validar Token Duration

### Verificar Código
```bash
cd c:\xampp\htdocs\sistema-erp\backend

# Procurar por addDay (sem "s") ao invés de addDays
grep -n "addDay" app/Http/Controllers/Api/V1/AuthController.php

# Deve encontrar:
# Line 37: $expiresAt = now()->addDay();

# NÃO deve encontrar:
# addDays(7)
```

### Teste Prático (após fazer login)
```bash
php artisan tinker

# Obter último token criado:
$token = Laravel\Sanctum\PersonalAccessToken::latest()->first();

# Verificar expiração:
echo $token->expires_at;  
# Deve mostrar: data de hoje + 1 dia

# Comparar:
echo now()->addDay()->toDateString();  
# Deve ser similar
```

✅ Se a data for +1 dia = **SUCESSO**

---

## 4️⃣ Validar Rate Limiting (10 tentativas)

### Teste: Fazer mais de 10 login attempts em 60 segundos

```powershell
# PowerShell - Simular 12 tentativas de login
$url = "http://127.0.0.1:8000/api/v1/auth/login"
$body = @{
    email = "teste@example.com"
    password = "senha123"
} | ConvertTo-Json

for ($i = 1; $i -le 12; $i++) {
    try {
        $response = Invoke-WebRequest -Uri $url -Method Post -Body $body -ContentType "application/json" -ErrorAction SilentlyContinue
        $statusCode = $response.StatusCode
    } catch {
        $statusCode = $_.Exception.Response.StatusCode.Value__
    }
    
    Write-Host "Tentativa $i`: Status $statusCode"
}
```

✅ Esperado:
- Tentativas 1-10: Status 401/422 (credenciais inválidas)
- Tentativa 11-12: Status **429** (Too Many Requests - SUCESSO!)

---

## 5️⃣ Validar Rate Limiting Refresh

### Teste: Fazer mais de 60 refresh em 60 segundos

```bash
cd c:\xampp\htdocs\sistema-erp\backend

# Primeiro fazer login para obter token
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"senha123"}' | jq -r '.data.access_token')

# Fazer 65 refresh attempts
for i in {1..65}; do
  curl -s -X POST http://127.0.0.1:8000/api/v1/auth/refresh \
    -H "Authorization: Bearer $TOKEN" \
    -w "\nAttempt $i: %{http_code}\n" | head -1
done
```

✅ Esperado: Últimas requisições retornam **429 Too Many Requests**

---

## 6️⃣ Validar .env no .gitignore

### Verificar se .env foi adicionado ao .gitignore
```bash
cd c:\xampp\htdocs\sistema-erp\backend

# Ver conteúdo do .gitignore:
cat .gitignore | grep -E "\.env"

# Deve mostrar:
# .env
# .env.backup
# .env.production
# .env.local
# .env.*.local
```

✅ Se conter .env = **SUCESSO**

### Verificar se .env está sendo rastreado (deve NOT ser)
```bash
cd c:\xampp\htdocs\sistema-erp

git status

# NÃO deve mostrar .env na lista de arquivos
# Se mostrar, execute:
# git rm --cached backend/.env
# git commit -m "remove .env from tracking"
```

✅ Se .env não aparecer em status = **SUCESSO**

---

## 7️⃣ Validar Configuração CORS

### Verificar config/sanctum.php
```bash
cd c:\xampp\htdocs\sistema-erp\backend

# Buscar stateful domains:
grep -A 5 "'stateful'" config/sanctum.php

# Verificar que inclui domínios locais
```

### Teste de CORS (se aplicável)
```bash
curl -i -X OPTIONS http://127.0.0.1:8000/api/v1/auth/login \
  -H "Origin: http://localhost:3000" \
  -H "Access-Control-Request-Method: POST"

# Verificar headers:
# Access-Control-Allow-Origin: http://localhost:3000
# Access-Control-Allow-Methods: POST, OPTIONS
```

---

## 8️⃣ Executar Test Suite

### Rodar todos os testes
```bash
cd c:\xampp\htdocs\sistema-erp\backend

# Limpar cache antes
php artisan config:clear
php artisan cache:clear

# Executar testes
php artisan test

# Esperado: Todos os testes passando
```

✅ Se todos passarem = **SUCESSO**

---

## 9️⃣ Verificar Segurança de Dependências

### Executar composer audit
```bash
cd c:\xampp\htdocs\sistema-erp\backend

# Verificar vulnerabilidades conhecidas
composer audit

# Esperado: "No security vulnerability advisories found"
```

✅ Se sem vulnerabilidades = **SUCESSO**

---

## 🔟 Teste de Integração Completo

### Teste End-to-End
```bash
cd c:\xampp\htdocs\sistema-erp\backend

php artisan tinker

# 1. Verificar APP_DEBUG (deve ser false em produção)
echo config('app.debug');  # false

# 2. Verificar APP_KEY existe
echo !empty(config('app.key'));  # true

# 3. Criar usuário de teste
$user = User::create([
    'nome' => 'Teste Security',
    'email' => 'test.security@example.com',
    'senha' => 'SenhaSegura123!',
    'telefone' => '1133334444',
    'ativo' => true
]);

# 4. Verificar que senha foi hasheada
echo Hash::check('SenhaSegura123!', $user->senha);  # true

# 5. Criar token
$token = $user->createToken('test', ['*'], now()->addDay());
echo $token->plainTextToken;  # Copiar este token

# 6. Teste com o token (fora do tinker):
exit
```

### Usar token para testar API
```bash
TOKEN="aqui_colar_o_token"

# Teste 1: Acessar com token válido
curl -H "Authorization: Bearer $TOKEN" \
  http://127.0.0.1:8000/api/v1/auth/me

# Esperado: Retorna dados do usuário (201-299)

# Teste 2: Tentando acessar com token inválido
curl -H "Authorization: Bearer invalid_token" \
  http://127.0.0.1:8000/api/v1/auth/me

# Esperado: 401 Unauthorized
```

✅ Se autenticação funciona = **SUCESSO**

---

## 📋 CHECKLIST DE VALIDAÇÃO

```
IMPLEMENTADAS - Validar todas:
✅ Mass Assignment
   - [ ] Teste: token_recuperacao não é atribuível
   - [ ] Código: Não está em $fillable

✅ Validação de Senha
   - [ ] Teste: Senha < 8 caracteres rejeitada
   - [ ] Teste: Senha >= 8 caracteres aceita
   
✅ Token Duration
   - [ ] Código: addDay() ao invés de addDays(7)
   - [ ] Teste: Token expira em 1 dia

✅ Rate Limiting Login
   - [ ] Código: throttle:10,1 presente
   - [ ] Teste: 11ª tentativa retorna 429

✅ Rate Limiting Refresh
   - [ ] Código: throttle:60,1 presente
   - [ ] Teste: 61ª tentativa retorna 429

✅ .env no .gitignore
   - [ ] Arquivo: .env está listado
   - [ ] Git: .env não aparece em `git status`

TESTES GERAIS:
   - [ ] composer audit (sem vulnerabilidades)
   - [ ] php artisan test (todos passam)
   - [ ] API funcionando (200 OK)
   - [ ] Autenticação funcionando
```

---

## 🆘 Troubleshooting

### Problema: Mass Assignment Exception não aparece

**Solução**: Limpar cache
```bash
php artisan config:clear
php artisan cache:clear
php artisan tinker
```

### Problema: Rate limit não funciona

**Solução**: Verificar driver de cache
```bash
# .env deve ter:
CACHE_DRIVER=file
# (ou redis/memcached)

# Se usar file, verificar:
ls storage/framework/cache/
```

### Problema: Token expira muito rápido

**Solução**: Verificar timezone
```bash
php artisan tinker

echo config('app.timezone');  # America/Sao_Paulo
echo now()->addDay();  # Deve mostrar amanhã
```

---

## ✅ VALIDAÇÃO COMPLETA

Quando todos os testes passarem:

1. ✅ Marcar como **COMPLETO**
2. 📝 Documentar em `SECURITY_STATUS.md`
3. 🚀 Fazer commit: `git commit -m "security: validate all fixes"`
4. ⏳ Proceder com próximas ações pendentes

---

**Última atualização**: 2026-06-23
