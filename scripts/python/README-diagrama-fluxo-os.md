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

## Como regenerar

```bash
python3 scripts/python/diagrama_fluxo_os_organizado.py          # só o SVG
python3 scripts/python/diagrama_fluxo_os_organizado.py --png    # SVG + PNG 2x (requer Chrome/Chromium)
```

## Os três mecanismos do fluxo (como ler o diagrama)

1. **Fluxo normal** (setas azuis e cinzas): mudanças feitas por "Alterar
   status" na OS. São validadas contra o catálogo `os_status_transicoes`
   (`OrderWorkflowService::updateStatus` recusa destino fora do catálogo com
   `invalid_transition`). Azul grosso = caminho feliz; cinza = alternativas;
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

O script embute as 69 transições reais (`REAL_TRANSITIONS`) e **falha na
geração** se o conjunto de setas desenhadas divergir do conjunto de
transições utilizáveis. Se o catálogo mudar (tela Conhecimento > Fluxo da
OS), re-extraia as transições e atualize `REAL_TRANSITIONS` + o roteamento:

```bash
cd backend && php artisan tinker --execute="
\$map = DB::table('os_status')->pluck('codigo','id')->all();
DB::table('os_status_transicoes')->where('ativo',1)->orderBy('status_origem_id')->get()
    ->each(fn (\$t) => print(\$map[\$t->status_origem_id].' -> '.\$map[\$t->status_destino_id].PHP_EOL));
"
```

## Lacunas reais de processo detectadas (no sistema, não no desenho)

Levantadas em 2026-07-16; o diagrama as retrata honestamente:

1. **`cumprimento_garantia` é beco sem saída** — nenhuma transição de saída
   cadastrada; uma OS ali só sai pela baixa. Provavelmente deveria poder ir
   para `garantia_concluida` e/ou `testes_operacionais` (anotado no card).
2. **Teste reprovado não alcança `retrabalho`** — `testes_operacionais` só
   sai para `aguardando_peca`/`testes_finais`, e `testes_finais` só para
   `reparo_concluido`. `retrabalho` só é alcançável de `reparo_execucao` e
   `aguardando_avaliacao`.

Ambas se corrigem cadastrando as transições na tela Conhecimento > Fluxo da
OS — e, depois, atualizando `REAL_TRANSITIONS` aqui.
