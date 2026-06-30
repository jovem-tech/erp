# Nova OS: dropdown de equipamento com foto e fallback por marca/modelo

Data: 2026-06-29

## Contexto

A tela de criação de OS do desktop precisava exibir o equipamento do cliente selecionado de forma mais legivel no campo de busca, mantendo coerencia com o resumo lateral da pagina.

## O que mudou

- A busca de equipamentos passou a retornar `brand_name` e `model_name` no payload do desktop.
- Quando `resumo_tecnico` estiver vazio, o item exibido usa `marca / modelo` como label.
- O dropdown do Select2 agora mostra miniatura do equipamento quando houver foto privada disponivel, usando a rota autenticada do desktop em vez da URL bruta da API central.
- O equipamento selecionado continua atualizando o resumo lateral e a foto principal.

## Impacto

- Melhora a identificacao visual dos equipamentos em clientes com varios cadastros.
- Evita label vazio quando o resumo tecnico ainda nao foi preenchido.
- Mantem o fluxo seguro, sem acesso direto ao banco pelo frontend desktop.

## Validacao

- Cobertura automatizada atualizada no frontend desktop para o retorno compactado da busca e para o prefill da pagina de criacao da OS.
- O contrato continua filtrando por `client_id`, reduzindo ruido na pesquisa.
