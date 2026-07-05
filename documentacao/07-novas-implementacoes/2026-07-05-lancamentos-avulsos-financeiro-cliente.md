# Lançamentos avulsos com histórico financeiro do cliente

## Contexto

- data: `2026-07-05`
- ambiente-alvo desta entrega: desenvolvimento Linux em `192.168.1.100`
- contrato: API central `/api/v1/financeiro`

## Entrega

- pagamentos e recebimentos simples podem ser marcados como avulsos e salvos sem OS;
- o cliente é opcional no avulso; quando informado, o título entra no histórico financeiro do cliente;
- a página do cliente mostra os recebíveis associados, com paginação na API e atalho para a listagem financeira filtrada;
- lançamentos originados pelo fechamento da OS e despesas de taxa de cartão são explicitamente não avulsos;
- títulos com movimentos não podem mudar sua classificação avulsa;
- o backend rejeita avulso com OS e cliente divergente do cliente real da OS.

## Arquitetura e decisões

- `financeiro` continua sendo a única fonte do histórico; nenhuma tabela duplicada foi criada;
- `avulso` é booleano com default `false`, preservando registros antigos;
- DRE e fluxo de caixa não receberam ramificações especiais: avulsos obedecem aos mesmos flags de impacto e categorias;
- o frontend apenas orienta e desabilita campos; as invariantes são verificadas novamente pela API.

## Segurança

- a seção no cliente exige `financeiro:visualizar`;
- a API continua aplicando RBAC, mesmo que a sessão do desktop esteja desatualizada;
- o filtro usa query parametrizada do Eloquent;
- a validação do cliente da OS reduz risco de associação indevida e inconsistência de histórico.

## Performance e escalabilidade

- o histórico usa paginação e limite de cinco itens no resumo do cliente;
- o índice composto `cliente_id, tipo, data_vencimento, id` atende o filtro e a ordenação mais frequentes;
- as relações existentes usam eager loading, evitando N+1 na listagem.

## Validação

- backend financeiro e relatórios: 13 testes, 64 asserções;
- desktop financeiro e histórico do cliente: 6 testes, 20 asserções;
- regressão do fechamento de OS: 11 cenários validados; um cenário de log foi repetido com `LOG_CHANNEL=stderr` por permissão preexistente do arquivo de log.

## Operação

- migration aditiva: `2026_07_05_190000_add_avulso_to_financeiro_table.php`;
- rollback remove primeiro o índice composto e depois a coluna;
- promoção para `main` e deploy na VPS não fazem parte desta entrega.
