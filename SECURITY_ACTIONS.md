# 🔐 Script de Correção de Segurança - Ações Pendentes

Este script PowerShell automatiza as ações críticas pendentes de segurança.

## ⚠️ ANTES DE EXECUTAR

1. **Fazer backup completo do projeto**:
```powershell
Copy-Item "c:\xampp\htdocs\sistema-erp" -Destination "c:\xampp\htdocs\sistema-erp.backup.$(Get-Date -Format 'yyyyMMdd-HHmmss')" -Recurse
```

2. **Verificar se está em desenvolvimento local ou produção**
   - DESENVOLVIMENTO: `APP_DEBUG=true` é aceitável
   - PRODUÇÃO: DEVE ser `false`

---

## 🚀 Ação 1: Desabilitar APP_DEBUG

```bash
# Editar .env manualmente ou:
# Abra c:\xampp\htdocs\sistema-erp\backend\.env
# Procure por: APP_DEBUG=true
# Mude para: APP_DEBUG=false
```

**Verificação**:
```bash
cd c:\xampp\htdocs\sistema-erp\backend
php artisan tinker
# config('app.debug')  # Deve retornar false
exit
```

---

## 🚀 Ação 2: Regenerar APP_KEY

**⚠️ CUIDADO: Isso invalidará todas as sessões existentes**

```bash
cd c:\xampp\htdocs\sistema-erp\backend

# Regenerar a chave
php artisan key:generate

# Verificar se foi gerada
php artisan tinker
# config('app.key')  # Deve mostrar a nova chave
exit
```

**O que foi gerado**:
- Nova chave em `.env` como `APP_KEY=base64:...`
- Guardar em local seguro (nunca commitar)

---

## 🚀 Ação 3: Configurar Senha no Banco de Dados MySQL

### Passo 1: Criar Usuário Específico do ERP

```sql
-- Abrir MySQL via phpMyAdmin ou command line:
mysql -u root -p

-- Criar usuário com permissões limitadas:
CREATE USER 'erp_app'@'127.0.0.1' IDENTIFIED BY 'SenhaForte@2024!Secure';

-- Conceder apenas permissões necessárias:
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON sistema_hml.* TO 'erp_app'@'127.0.0.1';

-- Remover permissões perigosas:
REVOKE FILE ON *.* FROM 'erp_app'@'127.0.0.1';
REVOKE GRANT OPTION ON *.* FROM 'erp_app'@'127.0.0.1';

-- Aplicar mudanças:
FLUSH PRIVILEGES;

-- Verificar:
SHOW GRANTS FOR 'erp_app'@'127.0.0.1';

-- Sair:
EXIT;
```

### Passo 2: Atualizar .env

Edite `c:\xampp\htdocs\sistema-erp\backend\.env`:

```env
# ANTES:
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sistema_hml
DB_USERNAME=root
DB_PASSWORD=

# DEPOIS:
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sistema_hml
DB_USERNAME=erp_app
DB_PASSWORD=SenhaForte@2024!Secure
```

### Passo 3: Testar Conexão

```bash
cd c:\xampp\htdocs\sistema-erp\backend

php artisan tinker

# Testar conexão:
# DB::connection()->getPdo();  # Não deve retornar erro

# Ver usuário da conexão:
# DB::select('SELECT USER()')[0]  # Deve mostrar erp_app@127.0.0.1

exit
```

---

## 🚀 Ação 4: Habilitar HTTPS em Produção

### Passo 1: Gerar Certificado SSL (Let's Encrypt)

```bash
# No servidor de produção:
certbot certonly --webroot -w /var/www/html -d example.com -d www.example.com
```

### Passo 2: Configurar Nginx (exemplo)

```nginx
# /etc/nginx/sites-available/sistema-erp

server {
    listen 80;
    server_name example.com www.example.com;
    
    # Redirecionar HTTP para HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name example.com www.example.com;
    
    # Certificados SSL
    ssl_certificate /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
    
    # HSTS - Force HTTPS por 1 ano
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    
    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;
    
    root /var/www/sistema-erp/public;
    index index.php;
    
    # Laravel config
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### Passo 3: Atualizar .env

```env
APP_URL=https://example.com
```

---

## 🚀 Ação 5: Configurar CORS

### Editar `.env`:

```env
SANCTUM_STATEFUL_DOMAINS=example.com,example.com:3000,app.example.com
SESSION_DOMAIN=.example.com
```

### Verificar `config/sanctum.php`:

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
    '%s%s',
    'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
    Sanctum::currentApplicationUrlWithPort(),
))),
```

---

## 🚀 Ação 6: Email Verification

### Editar `app/Models/User.php`:

```php
<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    // ... resto do código
}
```

### Criar migration:

```bash
php artisan make:migration add_email_verified_at_to_usuarios_table
```

**Arquivo migration**:
```php
Schema::table('usuarios', function (Blueprint $table) {
    $table->timestamp('email_verified_at')->nullable();
});
```

### Executar migration:
```bash
php artisan migrate
```

---

## 🚀 Ação 7: Password Hashing Automático

### Editar `app/Models/User.php`:

Adicione no início do arquivo:
```php
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Hash;
```

Dentro da classe User, adicione:
```php
/**
 * Hash da senha automaticamente
 */
protected function senha(): Attribute
{
    return Attribute::make(
        set: fn (string $value) => Hash::make($value),
    );
}
```

### Testar:

```bash
cd c:\xampp\htdocs\sistema-erp\backend

php artisan tinker

# Criar usuário (a senha será hasheada automaticamente):
# $user = User::create([
#     'nome' => 'Teste',
#     'email' => 'teste@example.com',
#     'senha' => 'SenhaPlainText123',
#     'ativo' => true
# ]);

# Verificar se foi hasheada:
# $user->senha  # Deve mostrar algo como $2y$12$...

# Verificar se funciona:
# Hash::check('SenhaPlainText123', $user->senha)  # Deve retornar true

exit
```

---

## 🚀 Ação 8: Limpar .env do Histórico Git

⚠️ **CUIDADO**: Isso reescreve o histórico completo do Git

### Opção A: Git Filter Branch (mais seguro)

```bash
cd c:\xampp\htdocs\sistema-erp

# Remover .env do histórico completamente
git filter-branch --tree-filter 'if [ -f .env ]; then rm .env; fi' -- --all

# Reescrever refs
git reflog expire --expire=now --all
git gc --prune=now --aggressive

# Fazer push forçado (⚠️ CUIDADO - apenas se equipe estiver sincronizada)
git push --force --all
git push --force --tags
```

### Opção B: BFG Repo-Cleaner (recomendado)

```bash
# Baixar BFG:
# https://rtyley.github.io/bfg-repo-cleaner/

# Usar (em uma cópia limpa do repo):
bfg --delete-files .env

# Fazer push forçado
git push --force
```

---

## ✅ Verificação Final

Após executar todas as ações:

```bash
cd c:\xampp\htdocs\sistema-erp\backend

# 1. Limpar cache
php artisan config:clear
php artisan cache:clear

# 2. Executar testes
php artisan test

# 3. Verificar segurança das dependências
composer audit

# 4. Verificar atualizações disponíveis
composer outdated

# 5. Testar aplicação
php artisan serve
```

---

## 📋 Checklist Final

- [ ] APP_DEBUG definido como `false` em produção
- [ ] APP_KEY foi regenerada
- [ ] Senha do banco de dados foi definida
- [ ] Usuário específico do ERP criado em MySQL
- [ ] HTTPS está habilitado em produção
- [ ] CORS está configurado corretamente
- [ ] Email verification foi implementado
- [ ] Password hashing automático está funcionando
- [ ] .env foi removido do Git
- [ ] Testes passando: `php artisan test`
- [ ] Sem erros de security audit: `composer audit`

---

## 🆘 Troubleshooting

### Erro: "SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'"

```bash
# Verificar credenciais em .env
# Testar conexão MySQL diretamente:
mysql -h 127.0.0.1 -u erp_app -p sistema_hml
# Digite a senha quando solicitado
```

### Erro: "Class does not implement required interface"

Verifique se adicionou `implements MustVerifyEmail` em User.php

### APP_KEY com erro na geração

```bash
# Limpar e gerar novamente:
php artisan key:generate --force
```

---

**Data**: 2026-06-23
**Status**: Pronto para implementação
