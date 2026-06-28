# Quickstart - Cadastro Completo de Equipamentos no Desktop

## Pré-requisitos

- backend em execução com acesso ao banco `sistema_hml`
- desktop em execução via Apache/XAMPP em `http://127.0.0.1:8080`
- usuário com permissão `equipamentos:criar`

## Validação end-to-end

1. Acesse `http://127.0.0.1:8080/equipamentos/novo`.
2. Verifique as abas `Informações`, `Cor` e `Fotos`.
3. Cadastre um cliente rápido pelo modal interno e confirme que o modal fecha, o cliente é selecionado no formulário e o botão responde com loading ao salvar.
4. Cadastre uma marca e um modelo rápidos.
5. Preencha um equipamento manual com série, senha, acessórios e observações.
6. Adicione pelo menos uma foto por galeria e defina a principal.
7. Envie o formulário.
8. Confirme o redirecionamento para o detalhe do equipamento.
9. Abra a foto retornada pelo endpoint autenticado do backend.

## Validação do coletor local

1. Garanta que exista um snapshot em `C:\JovemTechBenchCollector\last-snapshot.json` ou que o executável publicado esteja disponível para coleta local.
2. Na tela de novo equipamento, clique em `Buscar do agente (C:\)`.
3. Valide o preenchimento dos campos técnicos e o caminho exibido no cartão do coletor.
4. Se a execução automática não estiver disponível, use `Ler último snapshot` e confirme o fallback seguro.

## Validação do coletor remoto de apoio

1. Gere um código de pareamento somente se estiver validando o modo remoto.
2. Faça `POST /api/v1/collector/snapshots` com `X-Collector-Token` válido e `pairing_code`.
3. Consulte o pareamento e valide o snapshot normalizado.

## Validação manual de fallback

- sem câmera disponível, verificar que a aba de fotos continua funcional por upload;
- sem sugestão externa, verificar que o cadastro manual continua funcionando;
- com pareamento expirado, verificar mensagem clara e continuidade do fluxo manual;
- sem executável publicado, verificar fallback para o último snapshot local disponível.

## Diagnóstico rápido de modal sem resposta

Se o modal de cliente abrir, mas o botão `Cadastrar cliente` não fizer nada:

1. verificar se `frontends/desktop/resources/views/layouts/app.blade.php` ainda renderiza `@stack('modals')` antes de `@yield('scripts')`;
2. verificar se `frontends/desktop/public/assets/js/equipments-create.js` está fazendo bind nos elementos do modal depois de o DOM existir;
3. verificar o teste `test_equipment_create_page_renders_tabs_quick_actions_and_collector_flow`, que protege a ordem do HTML renderizado;
4. consultar `documentacao/04-governanca-ai/playbooks/modal-desktop-sem-resposta.md` antes de assumir problema de `z-index`.
