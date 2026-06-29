# Quickstart: Financeiro - Cartões e Taxas no Desktop

## Rodar localmente

1. iniciar Apache/XAMPP;
2. abrir o desktop em `http://127.0.0.1:8080`;
3. autenticar no desktop;
4. abrir `Financeiro > Cartões e Taxas`.

## Verificação manual

- conferir os cards de resumo;
- navegar entre as abas;
- testar a ajuda local;
- simular um recebimento em cartão;
- editar ou desativar um item de catálogo;
- confirmar que todos os selects visíveis estão com Select2.

## Contrato esperado

- `GET /api/v1/financeiro/cartoes`
- `POST /api/v1/financeiro/cartoes/simular`
- rotas de cadastro/edição/desativação dos catálogos financeiros usadas pelo desktop

## Critério de aceite rápido

- a página carrega sem erro;
- o simulador responde com taxa e líquido;
- a ajuda abre;
- os selects visíveis têm comportamento Select2.
