# Playbook - Upload multipart com header JSON no desktop

## Objetivo

Orientar futuras IAs quando um formulário do `frontends/desktop` salva normalmente sem arquivos, mas passa a falhar com validações `required` aparentemente sem sentido ao anexar imagem ou documento.

## Incidente de referência

- tela: `http://127.0.0.1:8080/equipamentos/novo`
- fluxo: criação de equipamento com foto
- sintoma reportado: formulário preenchido, mas backend devolvendo `validation.required`
- mensagem observada: `Falha na validação dos dados enviados.`

## Causa raiz provada

O desktop montava o corpo da requisição como `multipart/form-data`, mas mantinha o cabeçalho `Content-Type: application/json`.

Isso aconteceu porque `frontends/desktop/app/Services/ApiClient.php` reutilizava `baseRequest()` com `->asJson()` também para `authenticatedMultipartRequest()`.

Efeito prático:

- o corpo real saía com boundary de multipart;
- o header HTTP anunciava JSON;
- o backend não parseava corretamente os campos de texto;
- os campos obrigatórios como `cliente_id` e `tipo_id` chegavam como ausentes;
- a API devolvia erro de validação que parecia erro de preenchimento, mas era erro de transporte.

## Como diagnosticar

Quando um formulário com upload começa a falhar:

1. comparar o comportamento sem arquivo e com arquivo;
2. inspecionar o `Content-Type` real da requisição enviada pelo cliente HTTP do desktop;
3. verificar se existe `->asJson()` ou configuração herdada de JSON no caminho multipart;
4. confirmar se o corpo contém boundary de multipart, mas o header continua `application/json`;
5. validar se os campos `required` que falham são justamente os campos de texto do formulário.

## Correção aplicada

Arquivo corrigido:

- `frontends/desktop/app/Services/ApiClient.php`

Estratégia:

- manter `baseRequest()` para JSON;
- criar `baseMultipartRequest()` sem `->asJson()`;
- usar `baseMultipartRequest()` dentro de `authenticatedMultipartRequest()` e no retry com refresh de token.

## Regra permanente

No desktop:

- requisição JSON usa `baseRequest()` com `->asJson()`;
- requisição com upload usa request dedicado sem `->asJson()`;
- nunca misturar `attach()` com cliente que já força `Content-Type: application/json`.

## Testes de proteção

- `test_equipment_create_submission_redirects_to_detail_after_backend_success`
- `test_equipment_create_submission_with_photo_uses_multipart_without_json_content_type`

## Arquivos de referência

- `frontends/desktop/app/Services/ApiClient.php`
- `frontends/desktop/app/Services/EquipmentService.php`
- `frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php`
