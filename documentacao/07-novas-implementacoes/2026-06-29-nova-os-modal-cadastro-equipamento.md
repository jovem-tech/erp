# Nova OS: modal de cadastro completo de equipamento

Data: 2026-06-29

## Contexto

Quando o equipamento do cliente nao aparece na lista da OS, o operador precisava sair do fluxo para abrir o cadastro de equipamento em outra tela.

## O que mudou

- A tela de criacao de OS ganhou um botao `Novo equipamento`.
- O botao abre um modal grande com iframe apontando para o cadastro completo de equipamento.
- O iframe usa modo embutido, exibindo apenas o formulario de cadastro de equipamento, sem navbar, sidebar, footer, botao de voltar ou botao de ajuda.
- O botao `Cancelar` do formulario embutido fecha o modal pai da OS em vez de navegar para outra pagina dentro do iframe.
- Quando houver cliente selecionado na OS, o modal abre o cadastro ja com `cliente_id` e label preenchidos.
- O modal reutiliza a pagina oficial de `equipamentos/novo`, evitando duplicacao de regras e de interface.

## Impacto

- Reduz troca de contexto e retrabalho operacional.
- Mantem o cadastro de equipamento centralizado na tela oficial, sem criar um formulario paralelo inseguro.
- Preserva o fluxo da OS, que segue aberto enquanto o cadastro acontece em paralelo.

## Validacao

- Cobertura adicionada no frontend desktop para o botao do modal e para o prefill do cadastro de equipamento.
- O modal depende apenas de permissao de criacao em `equipamentos` e do endpoint de cadastro ja existente.
