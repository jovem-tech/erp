# Foto obrigatoria e destaque visual em equipamentos

## Contexto

- versao: `3.1.12`
- data: `2026-06-25`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

- `POST /api/v1/equipments` passou a exigir de 1 a 4 fotos no cadastro inicial do equipamento
- o desktop bloqueia o submit sem foto antes de chamar a API central, mudando automaticamente para a aba `Fotos`
- o backend passou a expor `primary_photo_id` e `primary_photo_url` na listagem e no detalhe de equipamentos
- a listagem operacional agora renderiza a miniatura principal na primeira coluna da tabela
- o detalhe do equipamento agora destaca a foto principal no canto superior direito do contexto do ativo
- o desktop reescreve o acesso da foto para a rota same-origin `/equipamentos/{equipment}/fotos/{photo}`, preservando autenticacao server-side e evitando acesso direto do browser a URL privada da API central
- a aba `Informações` do cadastro em `/equipamentos/novo` foi reorganizada em um único bloco operacional, mantendo `Tipo`, `Marca`, `Modelo` e `Nº Série ou IMEI` na mesma linha lógica e deixando `Acessórios`, `Estado físico` e `Observações` em sequência mais previsível para atendimento
- o CSS responsivo do desktop foi ajustado para que os campos inline com botão de ação rápida não percam largura útil em viewport móvel, especialmente nos seletores com Select2

## Impactos

- contratos atualizados: `specs/008-cadastro-equipamentos-desktop/spec.md`, `specs/008-cadastro-equipamentos-desktop/contracts/equipment-create-api.md`, `backend/openapi.yaml`, `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`
- modulos alterados: `backend` (validacao e payload de equipamentos) e `frontends/desktop` (criacao, listagem e detalhe)
- banco: sem nova migration; reaproveita `equipamentos_fotos` e a marcacao `is_principal`
- deploy: sem dependencia extra; comportamento continua compativel com Ubuntu VPS e storage privado autenticado
- seguranca: a imagem continua saindo apenas por endpoint autenticado, com proxy same-origin no desktop para nao expor token nem URL privada do backend central
- integridade: equipamentos legados sem foto nao quebram listagem/detalhe e mostram placeholder seguro
- escalabilidade: a foto principal passa a ser resolvida no backend e reutilizada pelos canais consumidores sem inferencia duplicada no frontend

## Validacao

- `php -l backend/app/Http/Requests/Api/V1/StoreEquipmentRequest.php`
- `php -l backend/app/Http/Controllers/Api/V1/EquipmentController.php`
- `php -l frontends/desktop/app/Http/Controllers/EquipmentController.php`
- `node --check frontends/desktop/public/assets/js/equipments-create.js`
- `php artisan test tests/Feature/Api/V1/EquipmentCreationTest.php` em `backend/`
- `php artisan test tests/Feature/Desktop/DesktopFrontendTest.php --filter=equipment` em `frontends/desktop/`
