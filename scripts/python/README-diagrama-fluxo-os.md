# Diagrama do Fluxo de Status da OS

Diagrama em SVG do ciclo de vida da Ordem de Serviço, **espelho fiel do
sistema real** — não é um desenho aspiracional. Cada seta corresponde a uma
linha ativa de `os_status_transicoes`; cada card, a um status ativo de
`os_status`.

## Arquivos

| Arquivo | Papel |
| --- | --- |
| `diagrama_fluxo_os_organizado.py` | Fonte editável (gera os demais). É aqui que se mantém o diagrama. |
| `diagrama_fluxo_os_organizado.svg` | Diagrama vetorial (documentação, wiki, impressão). |
| `diagrama_fluxo_os_organizado.png` | Versão 2x para apresentação (gerada com `--png`). |
| `frontends/desktop/resources/views/orders/_flow_map_svg.blade.php` | Variante EMBED (gerada com `--embed`): sem título/legenda, com `data-status`/`data-edge`/`data-port` — consumida pela página **Mapa da OS** (`/os/{id}/mapa`). |

## Como regenerar

```bash
python3 scripts/python/diagrama_fluxo_os_organizado.py                  # só o SVG
python3 scripts/python/diagrama_fluxo_os_organizado.py --png           # + PNG 2x (requer Chrome/Chromium)
python3 scripts/python/diagrama_fluxo_os_organizado.py --embed         # + partial Blade do Mapa da OS
python3 scripts/python/diagrama_fluxo_os_organizado.py --embed --png   # tudo
```

Sempre que o catálogo de transições mudar, regenerar **com `--embed`** —
senão a página Mapa da OS fica com o desenho defasado (a clicabilidade não
quebra, pois vem de `proximas_etapas` da API, mas setas novas não aparecem).

## Os três mecanismos do fluxo (como ler o diagrama)

1. **Fluxo normal** (setas azuis e cinzas): mudanças feitas por "Alterar
   status" na OS. São validadas contra o catálogo `os_status_transicoes`
   (`OrderWorkflowService::updateStatus` recusa destino fora do catálogo com
   `invalid_transition`). Azul grosso = caminho feliz — o caso clássico da
   bancada: triagem → diagnóstico → **aguardando avaliação** → orçamento →
   autorização → aguardando reparo → execução → testes operacionais →
   testes finais → reparo concluído → baixa (pago). Cinza = alternativas;
   **tracejado** = retornos (voltar etapa) e a reabertura
   `cancelado → triagem`.

2. **Baixa da OS** (roxo): os 5 status de `grupo_macro='encerrado'`
   (`entregue_reparado_pago`, `entregue_reparado_sem_custo`,
   `entregue_reparado_garantia`, `devolvido_sem_reparo`, `descartado`) são
   **bloqueados** no fluxo normal (`closure_status_requires_baixa_flow`).
   Só entram pela baixa (`OrderClosureService::close`), que parte de
   **qualquer etapa aberta** e ignora o catálogo de transições — por isso o
   diagrama os representa atrás de uma "porta de baixa" única, e não com
   dezenas de setas. Consequência: as 17 linhas do catálogo cujo destino é um
   encerramento são **inertes** (o runtime as recusa) e não viram seta.

3. **Reabertura / cancelamento de baixa**: `cancelado → triagem` existe no
   catálogo (tracejado no topo). Já OS encerradas só saem desse estado por
   `OrderClosureService::cancelClosure` (fora do fluxo normal).

## Auto-verificação (por que dá erro ao mexer)

O script embute as 95 transições reais (`REAL_TRANSITIONS`) e **falha na
geração** se o conjunto de setas desenhadas (73) divergir do conjunto de
transições utilizáveis. Se o catálogo mudar (tela Conhecimento > Fluxo da
OS), re-extraia as transições e atualize `REAL_TRANSITIONS` + o roteamento:

```bash
cd backend && php artisan tinker --execute="
\$map = DB::table('os_status')->pluck('codigo','id')->all();
DB::table('os_status_transicoes')->where('ativo',1)->orderBy('status_origem_id')->get()
    ->each(fn (\$t) => print(\$map[\$t->status_origem_id].' -> '.\$map[\$t->status_destino_id].PHP_EOL));
"
```

## Lacunas de processo — RESOLVIDAS em 2026-07-16

As duas lacunas detectadas na primeira versão deste diagrama foram fechadas
pela migration `2026_07_16_000001_add_missing_os_status_transitions`
(8 transições novas, 69 → 77):

1. ~~`cumprimento_garantia` é beco sem saída~~ → ganhou saídas para
   `garantia_concluida` e `irreparavel`.
2. ~~Teste reprovado não alcança `retrabalho`~~ → `testes_finais → retrabalho`
   e `testes_operacionais → irreparavel`; o ciclo também ganhou
   `retrabalho → aguardando_reparo`.

A mesma migration formalizou o fluxo de peça encomendada com sinal:
`aguardando_peca → pagamento_pendente`, `pagamento_pendente →
aguardando_reparo` e `aguardando_peca → aguardando_reparo`.

## Ajustes manuais em 2026-07-17 (77 → 87 transições)

O usuário cadastrou mais 10 transições direto na tela Conhecimento > Fluxo
da OS, roteadas manualmente neste script logo em seguida:

- **`testes_operacionais`** ganhou 5 saídas novas: `verificacao_garantia` e
  `aguardando_orcamento` (retorno — o teste revelou algo que precisa
  reavaliar garantia ou reorçar), `reparo_concluido` e `garantia_concluida`
  (atalho — pula `testes_finais` quando já dá pra concluir direto) e
  `cancelado`.
- **`irreparavel`** deixou de ser (quase) definitivo: ganhou volta pra
  `diagnostico`, `aguardando_orcamento`, `aguardando_reparo`,
  `reparo_execucao` e `retrabalho` — uma OS marcada irreparável pode ser
  reavaliada e voltar a qualquer um desses pontos.

Nenhuma das 10 aponta para um encerramento, então todas viraram seta
(nenhuma passa pela porta de baixa).

## Ajustes manuais em 2026-07-17 (87 → 95 transições)

O usuário cadastrou mais 8 transições direto na tela Conhecimento > Fluxo da
OS, em duas levas:

**3 de volta pra `retrabalho`** a partir de etapas da raia G3 · CONCLUÍDO:
`reparo_concluido → retrabalho`, `reparado_disponivel_loja → retrabalho` e
`garantia_concluida → retrabalho`. Cobrem o caso de a OS já ter chegado
perto do fim (reparo concluído, disponível na loja ou até garantia
concluída) e precisar voltar pra retrabalho antes de entregar. Roteadas
como retorno (tracejado), saindo pelo lado esquerdo de cada card de origem
e entrando pelo lado direito de `retrabalho`, em três novos corredores
verticais (x=836/848/860) paralelos aos já existentes da região
(x=872/884/896/908).

**5 apontando para um encerramento** (`grupo_macro='encerrado'`):
`garantia_concluida → entregue_reparado_garantia`,
`irreparavel_disponivel_loja → descartado`, `reparado_disponivel_loja →
entregue_reparado_sem_custo`, `reparo_concluido → entregue_reparado_sem_custo`
e `reparo_recusado → descartado`. Assim como as 17 linhas equivalentes já
existentes no catálogo, são **inertes** no fluxo normal (bloqueadas por
`closure_status_requires_baixa_flow` — só a baixa da OS aplica um
encerramento) e por isso não viram seta; entram em `REAL_TRANSITIONS` só
para o script continuar espelhando o banco fielmente.

Nenhuma das 3 primeiras aponta para um encerramento, então viraram seta; as
outras 5 não.
