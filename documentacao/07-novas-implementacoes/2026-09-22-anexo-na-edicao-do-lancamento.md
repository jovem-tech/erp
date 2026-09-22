# Anexar arquivo na edição do lançamento financeiro (2026-09-22)

**Tipo:** alteração em telas e controller existentes, sem migration nem rota nova (PATCH)
**Complementa:** [Anexos financeiros no Gerenciador de Arquivos](2026-09-11-anexos-financeiros-gerenciador-arquivos.md)
(ciclo de vida, storage e reconciliação — esta nota cobre só a interface do desktop).

## O problema

A tela **Editar lançamento** (`/financeiro/{id}/editar`) era o único ponto do
fluxo do título em que não dava para anexar nada. O operador que abria uma
despesa para corrigir vencimento ou descrição e queria guardar o boleto junto
tinha de salvar, voltar à listagem e usar "Anexar arquivo" no menu da linha — ou
abrir os detalhes. Duas telas para um gesto só.

## O que muda

A seção **ANEXO**, que só existia em *Novo lançamento*, passa a existir também
na edição, com nome **ANEXOS**:

- lista o que já está anexado (nome ou descrição, tamanho, quem enviou, data),
  com botão de **visualizar** (preview inline de PDF/imagem, o mesmo modal do
  detalhe) e **abrir em nova aba**;
- abaixo, o campo **Arquivo (PDF ou foto)** e **Descrição do anexo**, iguais aos
  da criação; ao clicar em *Salvar alterações* o arquivo sobe junto.

O anexo é permitido **mesmo com baixa registrada**: o título pago é justamente
o que recebe o comprovante depois. A trava de "já possui baixa" continua
valendo só para tipo, cancelamento e redução de valor.

Os erros de validação de `anexo` e `anexo_descricao` agora aparecem abaixo dos
próprios campos, nas duas telas (criação e edição) — antes só iam para a
faixa geral de erros.

## Onde é possível anexar (mapa completo)

| Tela | Como | Observação |
|------|------|------------|
| Lançamentos (listagem) | menu da linha → **Anexar arquivo** / **Ver anexos** | modal único compartilhado entre as linhas |
| Novo lançamento | seção **ANEXO** no fim do formulário | sobe depois de o título ser criado |
| Editar lançamento | seção **ANEXOS** no fim do formulário | **novo nesta entrega** |
| Detalhes do lançamento | card **Anexos** → *Anexar arquivo* | único lugar com **excluir** (lixeira) |

Regras comuns a todos: PDF, JPG, PNG ou WebP até 20 MB; permissão
`financeiro.editar` para anexar/excluir e `financeiro.visualizar` para ver e
baixar.

## Decisões

**Upload depois de salvar, nunca antes.** `FinanceiroController::update()`
segue a mesma regra do `store()`: valida o arquivo antes de chamar a API,
mas só o envia (`POST /financeiro/{id}/anexos`) depois de o `PATCH` da edição
ser aceito. Se o upload falhar, as alterações já gravadas não se perdem — a
mensagem de sucesso avisa: *"As alterações foram salvas, mas o anexo não pôde
ser salvo — anexe novamente pela tela de detalhes."* Arquivo inválido (tipo ou
tamanho) é recusado antes de qualquer chamada ao backend.

**Excluir continua só no detalhe.** O botão de lixeira é um `<form method="DELETE">`
próprio, e HTML não permite formulário aninhado dentro do formulário de edição.
Em vez de gambiarra (JS reescrevendo o form, `form=` attribute), a seção diz em
uma linha onde excluir. O teste garante que nenhuma `action` de exclusão é
renderizada dentro da edição.

**Upload quebrado no servidor ≠ arquivo ausente.** A guarda
`recusarUploadQuebrado()` (já usada na criação) distingue "nenhum arquivo
escolhido" de "o arquivo chegou e o PHP falhou" (tmp indisponível, limite do
pool, disco cheio), para a mensagem não culpar o operador.

## Arquivos

- `frontends/desktop/resources/views/financeiro/form.blade.php` — seção ANEXO/ANEXOS
  nas duas telas, lista dos existentes, erros por campo, modal de preview via `@push('modals')`.
- `frontends/desktop/resources/views/financeiro/edit.blade.php` — carrega
  `financeiro-anexos.js` quando há anexos (preview).
- `frontends/desktop/app/Http/Controllers/FinanceiroController.php` — `update()`
  valida e envia o anexo após a edição.
- `frontends/desktop/tests/Feature/Desktop/FinanceiroAnexoTest.php` — 3 testes novos.

## Validação automatizada

`php artisan test tests/Feature/Desktop/FinanceiroAnexoTest.php` (19 testes):

- `PUT /financeiro/187` com PDF → `PATCH` no backend e depois `POST .../anexos`
  multipart; mensagem contém "Anexo salvo.";
- `.docx` na edição → erro em `anexo`, nenhuma chamada ao backend;
- `GET /financeiro/187/editar` de um título **já baixado** com um anexo → renderiza
  "ANEXOS", o arquivo existente, o link de download, o modal de preview, o JS e o
  campo `name="anexo"` em formulário `multipart/form-data`; não renderiza `action`
  de exclusão.

`FinanceiroTest` e `FinanceiroCartaoCreditoTest` (85 testes) seguem verdes.

## Como conferir na interface

1. Abrir um lançamento pago em `/financeiro/{id}/editar`.
2. Conferir a seção **ANEXOS** com os arquivos já existentes e o botão-olho abrindo o preview.
3. Escolher um PDF, preencher a descrição e clicar em **Salvar alterações**.
4. A mensagem deve terminar com "Anexo salvo." e o detalhe do lançamento deve listar o novo arquivo.
