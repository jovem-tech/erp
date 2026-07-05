# Padronizacao de nome e telefone no cadastro de cliente (desktop)

**Data:** 2026-07-05
**Versao:** 3.7.3
**Modulo:** `frontends/desktop`

## Contexto

No cadastro de cliente do desktop (modal "Cadastro rapido de cliente" da Nova OS e o
formulario completo), os dados entravam exatamente como digitados — nome com
maiusculas/minusculas inconsistentes (dependendo do Caps Lock) e telefone sem mascara.
O objetivo foi impor um **padrao fixo, sempre aplicado**.

## Entrega

- **Nome** em Title Case pt-BR: `robert aparecido cosmo carneiro` →
  `Robert Aparecido Cosmo Carneiro`; conectores `de/da/do/dos/das/e` em minusculo
  (`joao da silva` → `Joao da Silva`). Aplicado **apenas a pessoa fisica** — razao social
  de empresa (juridica) fica como digitada. Nome de contato tambem e' padronizado.
- **Telefone** com mascara brasileira (DDD entre parenteses): celular (11 digitos)
  `(21) 98061-4757`, fixo (10 digitos) `(22) 2627-4120`. Codigo de pais `55` excedente e'
  removido; numero incompleto/invalido fica como esta.

## Arquitetura (duas camadas, so no desktop)

- **UX ao vivo** — `frontends/desktop/public/assets/js/clients-form.js`: mascara de
  telefone enquanto digita (evento `input`) e Title Case do nome ao sair do campo
  (`blur`). Roda em todas as telas de cliente (rapido e completo).
- **Autoritativa** — `frontends/desktop/app/Http/Controllers/ClientController.php`:
  metodos `formatPersonName()` e `formatBrazilPhone()` aplicados nos dois payloads
  (`validatedQuickClientPayload` e `validatedClientPayload`) antes de enviar a API.
  Garante o padrao mesmo se o JS for burlado; no formulario completo o nome so recebe
  Title Case quando `tipo_pessoa === 'fisica'`.

## Decisoes

- armazenar o telefone **com mascara** e' seguro: todo consumidor (link WhatsApp,
  normalizacao do backend) ja reduz a digitos via `preg_replace('/\D+/', '', ...)`;
- escopo limitado ao desktop (pedido do usuario); se o mobile passar a cadastrar clientes,
  as mesmas duas funcoes devem ser portadas para o backend (fonte unica de verdade);
- nao ha reprocessamento dos dados ja existentes — vale para novos cadastros e edicoes
  feitas pelo desktop.

## Validacao

- `php -l` no controller e `node --check` no JS;
- teste isolado dos formatadores PHP com os exemplos e casos de borda (Caps Lock, `+55`,
  codigo de pais, numero curto invalido) — todos corretos;
- verificacao funcional no navegador: digitar nome minusculo e telefone so com numeros na
  Nova OS → "Novo cliente" e confirmar a formatacao ao vivo e o valor persistido.
