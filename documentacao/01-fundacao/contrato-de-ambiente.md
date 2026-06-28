# Contrato de Ambiente

Este contrato define os parâmetros base para desenvolvimento em Windows/XAMPP e implantação em VPS Linux (Ubuntu).

## Backend

> Observação: a porta `8000` é o padrão local do backend central publicado pelo Apache do XAMPP em Windows. Se o backend for publicado por vhost Apache/Nginx em outro host, a URL base deve refletir o hostname real do ambiente.

| Variável | Windows / XAMPP | VPS Linux (Ubuntu) | Finalidade |
| --- | --- | --- | --- |
| `APP_ENV` | `local` | `production` | Modo de execução |
| `APP_URL` | `http://127.0.0.1:8000` | `https://api.seudominio.com` | URL base do backend |
| `API_PREFIX` | `/api/v1` | `/api/v1` | Prefixo oficial da API |
| `FRONTEND_DESKTOP_URL` | `http://127.0.0.1:8080` | `https://desktop.seudominio.com` | URL aprovada para links de redefinição de senha do canal desktop |
| `FRONTEND_SISTEMA_HML_URL` | `http://127.0.0.1:8090` | `https://sistema.seudominio.com` | URL aprovada para links de redefinição de senha do BFF `frontend/sistema-hml` |
| `SANCTUM_EXPIRATION` | `10080` | `10080` | Expiração base do token em minutos (7 dias) |
| `DB_HOST` | `127.0.0.1` | `127.0.0.1` ou host gerenciado | Banco dedicado do backend central |
| `DB_DATABASE` | `sistema_hml` | `sistema_hml` | Banco do backend central |
| `DB_PORT` | `3306` | `3306` | Porta do MySQL/MariaDB |
| `PRIVATE_FILES_PATH` | `C:/xampp/htdocs/sistema-erp/backend/storage/app/private` | `/var/www/sistema-erp/backend/storage/app/private` | Arquivos privados |
| `LEGACY_PUBLIC_PATH` | `C:/xampp/htdocs/sistema-hml/public` | `/var/www/sistema-hml/public` ou caminho equivalente do legado | Ponte de leitura controlada para fotos e PDFs herdados do `sistema-hml` |
| `LOGS_PATH` | `C:/xampp/htdocs/sistema-erp/backend/storage/logs` | `/var/www/sistema-erp/backend/storage/logs` | Logs internos |

## Frontend mobile

| Variável | Windows / XAMPP | VPS Linux (Ubuntu) | Finalidade |
| --- | --- | --- | --- |
| `NEXT_PUBLIC_APP_URL` | `http://127.0.0.1:3001` | `https://mobile.seudominio.com` | URL pública preferencial do canal mobile em dev local |
| `NEXT_PUBLIC_APP_NAME` | `Sistema ERP Mobile` | `Sistema ERP Mobile` | Nome exibido no canal mobile |
| `NEXT_PUBLIC_API_BASE_URL` | `http://127.0.0.1:8000/api/v1` | `https://api.seudominio.com/api/v1` | Consumo da API |
| `NEXT_PUBLIC_CHANNEL` | `mobile` | `mobile` | Canal da aplicação |

## Frontend desktop

| Variável | Windows / XAMPP | VPS Linux (Ubuntu) | Finalidade |
| --- | --- | --- | --- |
| `NEXT_PUBLIC_API_BASE_URL` | `http://127.0.0.1:8000/api/v1` | `https://api.seudominio.com/api/v1` | Consumo da API |
| `NEXT_PUBLIC_CHANNEL` | `desktop` | `desktop` | Canal da aplicação |

## Observações

- O backend é o único ponto autorizado para acessar o banco do `sistema_hml`, arquivos privados e logs.
- O frontend não deve montar caminho direto para fotos ou PDFs privados.
- Links de redefinição de senha devem usar URLs aprovadas por configuração do backend, nunca URL livre enviada pelo navegador.
- A raiz `LEGACY_PUBLIC_PATH` é uma ponte transitória de leitura controlada para anexos herdados do legado `sistema_hml`; o frontend ainda consome apenas endpoint do backend.
- Em desenvolvimento e produção, o servidor web deve publicar apenas `backend/public` e nunca a raiz do projeto.
- Em produção, qualquer arquivo sensível deve ser entregue por stream autorizado ou URL assinada.
- No código Laravel, o acesso a arquivos deve preferir `storage_path()` e `Storage::disk()`; os caminhos absolutos deste contrato servem como referência operacional do ambiente.
- Em Windows/XAMPP, o banco local deve subir como serviço `mysql` controlado pelo painel do XAMPP. Processos `mysqld.exe` iniciados manualmente podem resolver temporariamente, mas não substituem o serviço correto para o ambiente oficial de desenvolvimento.
