# Implementation Plan: Cadastro rápido de serviços e peças no orçamento desktop

**Branch**: `017-cadastro-rapido-servicos-pecas-orcamento-desktop` | **Spec**: [spec.md](./spec.md)

## Summary

Adicionar ao orçamento desktop um cadastro rápido para serviços e peças, acessível diretamente da linha do item. A implementação deve manter o backend central como fonte de verdade, usar rotas JSON no desktop, e atualizar o select da linha sem navegação fora do fluxo.

## Technical Context

- Stack desktop: Laravel/Blade + JavaScript vanilla + Bootstrap 5 + Select2.
- Stack backend: Laravel central via serviços HTTP do desktop.
- Segurança: RBAC no desktop, validação server-side e respostas JSON para o modal.
- UX: modal único, campos mínimos, feedback inline e seleção automática do item criado.

## Structure Decision

- Reutilizar os serviços já existentes de `Serviço` e `Estoque`.
- Criar endpoints JSON específicos no desktop para cadastro rápido.
- Manter a atualização do select do orçamento em memória, sem reload.

## Validation Strategy

- `php artisan test` no desktop para renderização e endpoints rápidos.
- `node --check` no JavaScript do orçamento.
- Verificação manual do modal no orçamento com item recém-criado.
