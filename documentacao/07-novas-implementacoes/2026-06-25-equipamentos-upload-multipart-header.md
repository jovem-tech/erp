# 2026-06-25 - Correção do upload multipart no cadastro de equipamentos

## Versão

- Desktop ERP: `v3.1.10`

## Resumo

Foi corrigido um bug no cliente HTTP do desktop que quebrava o cadastro de equipamentos ao anexar fotos.

## Causa raiz

O método multipart de `frontends/desktop/app/Services/ApiClient.php` reaproveitava a mesma base JSON do restante da aplicação. O corpo era montado como multipart por causa do `attach()`, mas o header permanecia `Content-Type: application/json`.

No backend isso se manifestava como falha de validação dos campos obrigatórios, mesmo com a tela preenchida corretamente.

## Correção aplicada

- criação de `baseMultipartRequest()` sem `->asJson()`;
- troca de `baseRequest()` por `baseMultipartRequest()` em `authenticatedMultipartRequest()`;
- cobertura automatizada para submissão com foto, garantindo `multipart/form-data` sem `application/json` no mesmo request.

## Validação executada

- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=equipment_create_submission`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=equipment_create_page`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=quick_client_store`
