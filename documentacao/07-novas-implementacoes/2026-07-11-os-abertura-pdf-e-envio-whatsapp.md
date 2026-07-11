# OS — PDF de abertura e envio opcional ao cliente

Data: 2026-07-11

## Objetivo

Restaurar o fluxo documental da abertura da OS no desktop:

- gerar automaticamente o PDF de abertura ao criar a OS;
- vincular o documento em `os_documentos` para aparecer na aba `Documentos`;
- permitir, no momento da criação, escolher se o PDF deve ser enviado ao cliente;
- reutilizar o modelo PDF `abertura` e o template WhatsApp `os_aberta`.

## Regras implementadas

1. Toda criação de OS tenta gerar o PDF de abertura usando o modelo ativo `abertura`.
2. O arquivo é salvo em storage local privado no diretório `private/os_documentos/{os_id}/`.
3. O documento é registrado em `os_documentos` com `tipo_documento=abertura`.
4. A rota autenticada de documentos da OS passou a resolver:
   - documentos privados novos no storage local;
   - documentos legados já existentes em `legacy_public`.
5. Na tela `Nova OS`, o operador pode marcar `Enviar PDF ao cliente`.
6. Quando marcado:
   - o backend tenta enviar o PDF primeiro pelo fluxo de inbox/WhatsApp;
   - se isso falhar, tenta envio direto de mídia pelo provedor configurado;
   - falhas de envio não cancelam a criação da OS.

## Feedback operacional

Após criar a OS, o desktop agora informa separadamente:

- sucesso da criação da OS;
- sucesso da geração do PDF de abertura;
- sucesso ou falha do envio ao cliente.

## Contrato técnico

### Request de criação/edição de OS

Novo campo aceito:

- `enviar_pdf_cliente: boolean`

### Response de criação de OS

O `POST /orders` passa a retornar também:

- `opening_document`
  - `generated`
  - `document_id`
  - `file_name`
  - `message`
  - `skipped`
- `opening_delivery`
  - `requested`
  - `sent`
  - `channel`
  - `message`

## Observações

- A geração do PDF depende do modelo `abertura` estar ativo e com HTML configurado.
- O envio do PDF depende do cliente possuir telefone e do provedor atual suportar envio de documento.
- A criação da OS continua bem-sucedida mesmo se a geração ou o envio do documento falharem.
