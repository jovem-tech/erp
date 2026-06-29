# Configurações do sistema separadas e precificação no financeiro

## Contexto

- versao: `3.4.3`
- data: `2026-06-29`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

- a pagina de integracoes do desktop passou a exibir somente os blocos de integracao;
- foi criado o menu `Configuracoes do Sistema` no sidebar do desktop, com os blocos de Aparencia, Dados da Empresa e Sessao e Seguranca;
- a precificacao foi movida para o modulo Financeiro, com pagina propria, simuladores e contrato novo no backend central;
- o backend central ganhou tabelas, servico e rotas para precificacao, inclusive simulacao de peca e servico;
- a documentacao da API, o historico de versoes e a versao global foram atualizados.

## Impactos

- o desktop ganhou nova navegacao sem alterar os contratos das integracoes existentes;
- a precificacao agora usa a API central como fonte de verdade;
- o contrato novo ficou documentado em `backend/openapi.yaml`;
- a separacao das paginas reduz confusao visual sem abrir mao da seguranca.

## Validacao

- testes de desktop para `Configuracoes do Sistema` e `Precificacao`;
- testes de API para `FinanceiroPrecificacaoController`;
- revisao do `backend/openapi.yaml`;
- commit com as mudancas consolidadas.
