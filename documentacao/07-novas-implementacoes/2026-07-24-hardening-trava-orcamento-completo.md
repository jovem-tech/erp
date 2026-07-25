# Hardening da trava de completude do novo orçamento

## Resumo

- versão: `5.12.2.0`
- data: `2026-07-24`
- tela: `GET /orcamentos/novo`
- persistência: `POST /orcamentos`
- banco de dados: sem migration
- API central: sem alteração de contrato

Esta entrega consolida a trava visual introduzida na versão `5.12.1.0`.
Enquanto houver campos obrigatórios pendentes, o botão principal permanece
como **Próximo**. Ao clicar, a interface abre a aba correta, posiciona o campo
incompleto e apresenta uma mensagem objetiva. O texto muda para **Salvar
orçamento** somente quando o formulário está completo.

A validação não depende apenas do JavaScript. O controller desktop aplica o
mesmo contrato antes de chamar a API central, impedindo que uma alteração no
DOM, uma chamada manual ou um cliente HTTP ignore a trava visual.

## Contrato de completude

Um orçamento novo pode ser persistido somente quando satisfaz todos os itens:

1. cliente cadastrado ou nome de cliente eventual;
2. telefone de contato com DDD, contendo 10 ou 11 dígitos;
3. e-mail válido, quando informado;
4. quando o orçamento envolve equipamento:
   - uma OS vinculada; ou
   - um equipamento cadastrado; ou
   - tipo, marca e modelo do equipamento eventual;
5. ao menos um item com conteúdo;
6. descrição em todos os itens;
7. quantidade maior que zero em todos os itens;
8. valor unitário maior que zero em todos os itens;
9. total ajustado de cada item maior que zero;
10. total final recalculado maior que zero.

Campos operacionais opcionais, como título, observações, prazo e condições,
continuam opcionais. Orçamentos existentes podem ser editados sob as regras
anteriores para evitar bloqueio retroativo de registros legados.

## Arquitetura e fluxo

### Interface

`orcamentos-form.js` coleta as pendências diretamente do estado atual do
formulário. A ação principal:

- exibe `Próximo` e o ícone de avanço enquanto há pendências;
- sinaliza as abas incompletas;
- abre a aba e foca a primeira pendência;
- exibe `Salvar orçamento` e chama `requestSubmit()` quando tudo está válido;
- executa uma nova verificação no evento `submit`, protegendo chamadas
  programáticas dentro da própria página.

Os campos de telefone, descrição, quantidade e valor unitário também recebem
semântica HTML de obrigatoriedade no modo de criação. Isso melhora
acessibilidade e fornece uma camada adicional de validação nativa.

### Correção da navegação do botão Próximo

Na criação, o botão agora respeita a sequência visual das abas:

1. se a aba atual tiver uma pendência, mantém a aba aberta e foca o primeiro
   campo obrigatório;
2. se a aba atual estiver válida, abre a próxima aba;
3. na última aba, volta à primeira pendência remanescente;
4. quando não houver pendências, muda o texto para `Criar orçamento` e envia o
   formulário.

A navegação deixou de executar o recálculo completo do resumo antes de trocar
de aba. O resumo continua sendo atualizado pelos eventos de entrada e alteração
do formulário, enquanto uma falha isolada nessa integração não pode mais
interromper o clique e deixar o botão aparentemente sem ação.

O manipulador da ação principal é registrado na fase de captura e possui
tratamento de exceção com mensagem visível. Assim, integrações globais da página
não conseguem consumir silenciosamente o clique e qualquer falha inesperada é
registrada de forma sanitizada antes de orientar o operador.

### Controller desktop

`OrcamentoController::store()` chama
`validatedBudgetPayload(requireComplete: true)`. A validação:

- não aceita telefone ausente ou fora do formato mínimo;
- não aceita item vazio, quantidade zero ou valor unitário zero;
- exige contexto mínimo para equipamento eventual;
- rejeita total informado igual ou menor que zero;
- recalcula item por item, incluindo desconto e acréscimo por valor ou
  percentual;
- rejeita ajustes que produzam item ou total final igual ou menor que zero;
- somente depois chama `OrcamentoService::create()`.

O total enviado pelo navegador não é usado como prova de completude. A API
central continua sendo a fonte final de verdade e também recalcula os valores
antes da persistência.

## Segurança

- **Broken validation / mass assignment:** a regra é reexecutada no servidor;
  a interface não é fronteira de confiança.
- **Manipulação financeira:** o servidor recalcula totais em complexidade
  linear e não aceita um `total` positivo forjado quando os itens resultam em
  zero.
- **Injeção e XSS:** os dados continuam passando pela validação Laravel e pela
  saída escapada do Blade; esta entrega não introduz HTML fornecido pelo
  usuário.
- **CSRF e autorização:** permanecem nas rotas e middlewares já existentes.
- **Persistência parcial:** o controller retorna erro de validação antes da
  chamada à API; portanto nenhum orçamento é criado quando o contrato falha.

## Performance e escalabilidade

A validação percorre os itens uma vez no navegador e uma vez no controller,
com complexidade `O(n)` e memória `O(n)` já necessária para o payload. Não há
consulta adicional ao banco, chamada adicional à API, cache ou fila. Para o
limite normal de itens de um orçamento, o custo é desprezível.

## Compatibilidade e trade-offs

- a trava forte é aplicada apenas ao cadastro novo;
- a API central mantém a capacidade de rascunho para consumidores que usem
  esse contrato diretamente;
- a camada desktop escolhe exigir orçamento completo porque esta é a operação
  solicitada para a tela;
- telefone passa a ser obrigatório na criação pela tela, inclusive ao escolher
  “Salvar sem enviar”, garantindo que o orçamento já esteja apto ao contato.

## Testes

Coberturas adicionadas ou atualizadas:

- HTML inicial apresenta `Próximo`, mantendo separadas as legendas de próximo
  e salvar;
- JavaScript contém a coleta, foco de pendência, avanço sequencial entre abas e
  submissão somente quando pronto;
- orçamento completo continua sendo normalizado e encaminhado à API;
- linha placeholder vazia é recusada sem chamada ao backend;
- telefone inválido é recusado sem chamada ao backend;
- total positivo forjado com item zerado por desconto é recusado;
- fluxos de envio para aprovação continuam funcionando com telefone válido.

Validações operacionais:

```bash
node --check frontends/desktop/public/assets/js/orcamentos-form.js
php -l frontends/desktop/app/Http/Controllers/OrcamentoController.php
php -l frontends/desktop/tests/Feature/Desktop/DesktopFrontendTest.php
php artisan test --filter=DesktopFrontendTest
```

## Rollback

O rollback é somente de código. Não existe migration nem alteração destrutiva
de dados. Reverter os arquivos listados no changelog restaura a regra anterior.
