# 2026-06-26 - Configurações > Integrações no desktop ERP

## Resumo

O desktop Laravel recebeu o primeiro painel operacional de `Configurações > Integrações`, conectando a interface administrativa do canal desktop ao contrato central de WhatsApp e webhook sem acesso direto ao banco de dados.

## O que entrou

- painel `Configurações > Integrações` no desktop;
- suporte a WhatsApp, Evolution API, gateway local e gateway Linux;
- configuração de webhook de entrada;
- ações operacionais para testar conexão, enviar mensagem de teste, consultar status, gerar QR code, reiniciar, fazer logout e iniciar o gateway;
- self-check inbound com retorno estruturado no padrão `data.result`;
- ajuda local dedicada ao módulo de integrações;
- atualização do shell visual do desktop para expor o novo módulo na sidebar de forma coerente com a navegação do legado.

## Pontos de segurança e contrato

- o desktop continua sem acesso direto ao banco;
- as permissões seguem sendo decididas no backend central;
- a leitura do painel exige `configuracoes:visualizar`;
- a gravação e as ações operacionais exigem `configuracoes:editar`;
- o webhook de WhatsApp é inbound autenticado por `X-Webhook-Token`;
- os demais retornos seguem o envelope padrão da API central.

## Documentação atualizada

- `frontends/desktop/README.md`
- `documentacao/03-arquitetura-tecnica/frontend-desktop-laravel.md`
- `documentacao/03-arquitetura-tecnica/contrato-api-backend-central.md`
- `documentacao/07-novas-implementacoes/historico-de-versoes.md`

## Validação

- `php artisan test --filter ConfigurationIntegrationsTest` no backend central;
- `php artisan test --filter ConfigurationIntegrationsTest` no desktop Laravel;
- verificação de sintaxe do JS do painel de integrações.
