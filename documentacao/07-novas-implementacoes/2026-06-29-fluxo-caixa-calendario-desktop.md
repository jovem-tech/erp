# Fluxo de caixa com visualização em calendário no desktop

## Contexto

- versao: `3.4.2`
- data: `2026-06-29`
- ambiente-alvo: `Ubuntu VPS`

## Entrega

- o relatorio de fluxo de caixa do desktop ganhou alternancia entre lista diaria e calendario mensal;
- a mesma query de mes continua sendo usada para os dois modos, preservando a navegacao atual e o mesmo payload do backend central;
- o calendario foi montado no Blade a partir das linhas diarias ja devolvidas pelo relatorio, com destaque para dias fora do mes e dias com movimento;
- a documentacao do desktop, o historico de versoes e a versao global do sistema foram atualizados para registrar a nova visualizacao.

## Impactos

- nenhum contrato da API central foi alterado;
- o impacto ficou restrito ao frontend desktop Laravel/Blade e aos arquivos de documentacao de release;
- nao houve mudanca de schema, storage ou permissao;
- a navegação por mes continua segura para Windows/XAMPP e Ubuntu VPS.

## Validacao

- `php artisan test tests/Feature/Desktop/FinanceiroReportTest.php`
- `php ./scripts/php/sync-agent-docs.php`
- revisao visual do fluxo de caixa em lista e em calendario no desktop
