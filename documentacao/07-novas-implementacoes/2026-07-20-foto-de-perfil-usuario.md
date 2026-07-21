# Foto de perfil do usuário

**Data:** 20/07/2026
**Versão:** `5.3.0.0`
**Status:** ativo no ambiente de desenvolvimento LAN; não publicado na VPS de produção

## Objetivo

Permitir que cada usuário cadastre a própria foto de perfil em **Perfil > Configurações**, exibida no lugar da inicial na navbar. A foto é gerenciada pelo Gerenciador de Arquivos central, na categoria `user_profile_photo` já prevista desde `specs/022-gerenciador-central-arquivos/` (até então alimentada só pelo scanner legado).

## Arquitetura

- Reaproveita a coluna `usuarios.foto` (já existente, sem uso ativo) para guardar o caminho do arquivo atual — sem migration nova.
- `ProfilePhotoImageService` (`backend/app/Services/Profile/`): valida a imagem enviada (PNG/JPG/WebP, até 4 MB), recorta para quadrado e normaliza para JPEG 512x512, grava em `private/usuarios/{id}/foto-perfil-{aleatorio}.jpg` no disco `local` e apaga o arquivo anterior ao substituir/remover.
- `private/usuarios` foi registrado em `config('file-manager.scanner.roots')` (mesmo padrão de `private/assinaturas`, `private/equipamentos` etc.) — sem isso, `ManagedFileDeliveryService::assertPathContained()` recusa qualquer preview/download/miniatura do arquivo com "Arquivo fora dos namespaces autorizados", mesmo com o blob presente e a autorização OK.
- Ao substituir ou remover a foto, o `ManagedFile` correspondente ao arquivo anterior é movido para `trashed` via `FileStateMachine::trash()` — sem isso o card antigo continuava "Ativo" na listagem principal do gerenciador mesmo com o binário já apagado do disco.
- Cada upload é catalogado via `LegacyCompatibleFileAdapter::synchronizeExisting()` com `subject_type=user`, `subject_id=<id do usuário>`, `relation=profile_photo` — mesmo padrão já usado por assinatura (`user_signature`) e fotos de equipamento.
- Novo `subject_type` `user` adicionado a `config/file-manager.php` e novo `UserProfilePhotoFileAuthorizer`: o próprio dono sempre pode ver/baixar sua foto; qualquer outra ação (arquivar, excluir, etc.) exige `arquivos:administrar`.
- `UserPhotoController` (`POST/DELETE /api/v1/auth/photo`, `GET /api/v1/auth/photo/image`) segue o mesmo formato de `UserSignatureController`.
- `AuthController::userPayload()` passa a expor `foto_url` (caminho absoluto da API central, útil para consumidores diretos como o app mobile) somente quando o arquivo referenciado em `usuarios.foto` realmente existe no disco — evita expor um link quebrado para os poucos usuários com resíduo do scanner legado (nome de arquivo antigo em `uploads/usuarios/`, fora do layout novo).

## Fluxo no desktop

Em **Perfil > Configurações**, um novo bloco "Foto de perfil" (dentro do card já existente de nome/dados) mostra a foto atual (ou um ícone de placeholder), um botão **Escolher foto** que envia e recarrega a tela automaticamente ao selecionar o arquivo, e um botão **Remover** quando já existe foto.

**Importante:** como o desktop (`frontends/desktop`) e o backend central rodam em domínios/portas distintos, a `<img>` da navbar e do card **não** aponta direto para `foto_url` da API central — usa a rota própria do desktop `profile.photo.image` (`GET /perfil/foto/imagem`), que faz proxy autenticado do binário via `ProfileController::photoImage()`/`ProfileService::photoImage()`, no mesmo padrão já usado pela assinatura (`profile.signature.image`). `foto_url` cru só é apropriado para quem consome a API central diretamente (ex.: app mobile).

Diferente da assinatura, o upload de foto **não exige confirmação de senha atual** — é uma decisão deliberada: a foto de perfil não tem o mesmo peso legal/probatório de uma assinatura vinculada a documentos.

## Correções pós-lançamento (v5.4.1.0)

Reportado pelo Otávio após testar em produção-LAN: nome de arquivo ilegível no gerenciador, foto antiga ainda "Ativa" após substituir, miniatura quebrada, e logoff forçado após trocar a foto. Causas e correções:

- **Logoff forçado / troca não dinâmica:** `profile-photo.js` chamava `form.submit()` para reenviar o formulário assim que o usuário escolhia o arquivo. `form.submit()` **não dispara o evento `submit`** do DOM (diferente de `form.requestSubmit()`) — e o guard de sessão "navegador fechado e reaberto" do `layouts/app.blade.php` depende exatamente desse evento para saber que a saída da página é navegação legítima. Sem o evento, o `pagehide` marcava a aba como "fechada" e o guard forçava logoff na carga seguinte. Trocado para `form.requestSubmit()`.
- **Miniatura quebrada:** `private/usuarios` nunca tinha sido registrado como root autorizado em `config('file-manager.scanner.roots')` — toda tentativa de preview/thumbnail caía em `RuntimeException: Arquivo fora dos namespaces autorizados` (`ManagedFileDeliveryService::assertPathContained()`), mesmo com o arquivo intacto no disco e a autorização OK. Corrigido registrando `private/usuarios` no mesmo padrão de `private/assinaturas`/`private/equipamentos`.
- **Foto antiga continuava "Ativa":** ao trocar/remover a foto, o binário era apagado do disco mas o registro `ManagedFile` correspondente nunca mudava de lifecycle — ficava "Ativo" indefinidamente, com miniatura quebrada (arquivo já não existe). `ProfilePhotoImageService` agora chama `FileStateMachine::trash()` no registro anterior sempre que substitui ou remove a foto atual.
- **Nome ilegível:** o nome exibido no gerenciador vem de `basename()` do caminho físico do arquivo — como o arquivo era salvo com um UUID puro (`{uuid}.jpg`), aparecia ilegível na listagem. Corrigido para `foto-perfil-{10 caracteres aleatórios}.jpg`.
- Dados de produção-LAN já afetados foram corrigidos manualmente: o registro órfão da foto substituída do Otávio foi movido para `trashed` e o nome do registro da foto atual foi atualizado para `foto-perfil-otavio.jpg`.
