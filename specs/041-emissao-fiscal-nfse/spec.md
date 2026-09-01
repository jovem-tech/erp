# Emissão fiscal — NFS-e e prontidão de dados

> **Nota de numeração:** a `039` reservou a `040` para "reserva de peça ao
> aprovar orçamento" (ver `039/spec.md`, nota de topo). Essa entrega continua
> não iniciada, e esta spec **não** toma o número dela: a emissão fiscal entra
> como `041` e a `040` segue reservada.

## Problema

A partir de **1º de janeiro de 2027** o MEI é obrigado a emitir documento fiscal
em **todas** as operações, inclusive para consumidor final pessoa física — LC
214/2025, com a exigência para o MEI trazida pela Resolução CGSN 190/2026. Some
a regra do "só se o cliente pedir", que é a regra sob a qual esta assistência
opera hoje. O Espírito Santo já antecipou a obrigação para abril de 2026, e a
penalidade prevista não é só multa: é exclusão retroativa do regime.

O sistema não emite nada hoje, e isso é o **menor** dos problemas. Medido no
banco:

- **1.323 de 1.323 clientes sem `cpf_cnpj`** — a base inteira. A NFS-e exige
  identificar o tomador. Sem esse dado não existe nota a emitir, com ou sem
  integração.
- **1.322 PF contra 1 PJ.** Crédito de IBS/CBS a partir de 2027 não é argumento
  aqui: o "Simples híbrido" existe para quem vende a empresas que tomam crédito.
- **Ticket médio de R$ 120,14** (130 OS entregues em 12 meses, R$ 15.617,74).
- **79% mão de obra / 21% peça** (R$ 11.863,24 contra R$ 3.222,50). Serviço é
  NFS-e; peça é NF-e/NFC-e pela SEFAZ estadual, que **sempre** exige certificado.
  Não existe rota gratuita para a metade da peça.
- **9 peças cadastradas, 9 sem NCM.** Uma tarde de trabalho.
- Das 3.646 OS na base, **3.560 são `legacy_origem = erp`**. O ritmo real do
  sistema novo é de 20 a 36 OS/mês, subindo.

E um número que não é fiscal mas decide o calendário: teto do MEI de R$ 81.000
÷ 12 ÷ R$ 120,14 = **~56 OS/mês**. O volume que justifica integrar é o mesmo
que estoura o teto. Emissão fiscal e saída do MEI são o mesmo projeto.

Metade do terreno já está pronta: a `027` criou `ncm`, `cest`, `cfop_venda`,
`origem_mercadoria`, `cst_icms`, `csosn`, `unidade_tributavel` em `pecas`
declarando no comentário da migration que nascem "sem uso" para preparar a
emissão futura. `os_itens.tipo` e `venda_itens.tipo_item` já separam serviço de
peça, `os` já soma `valor_mao_obra` e `valor_pecas` em separado, e
`RegimeTributario` já trata MEI e Simples como configuração de tela.

## Objetivo

Chegar a janeiro de 2027 **podendo** emitir: com o dado cadastral completo, com
os documentos guardados e rastreáveis, e sem ter apostado em integração que
depende de certificado, de contador e de regra municipal ainda não confirmados.

## Decisões

- **Fase 1 é cadastro, não emissão — e vem primeiro.** Com 100% da base sem CPF,
  qualquer investimento em integração fica ocioso. O dado só entra pela porta da
  OS, uma de cada vez, e por isso é o item de maior prazo de todos: não dá para
  acelerar comprando nada.
- **CPF é pedido, não exigido.** O campo entra em destaque no cadastro rápido da
  Nova OS, mas **não** vira `required`. Travar o cadastro empurraria o operador
  para o caminho de fora do sistema, e o resultado seria a base continuar vazia
  *e* a OS não existir. A obrigatoriedade mora na emissão, onde falhar é barato:
  a nota não sai e o sistema diz qual campo falta.
- **Nada de `cpf_cnpj` NOT NULL por migration.** São 1.323 linhas existentes que
  passariam a violar a restrição. A cobrança é do relatório de prontidão, não do
  schema.
- **Normalizar não é `preg_replace('/\D+/')`.** Desde **06/07/2026** o CNPJ
  alfanumérico está em produção: as 12 primeiras posições aceitam letras
  maiúsculas, e só os 2 verificadores continuam numéricos. O módulo 11 não
  mudou — mudou o valor de cada caractere, que virou `ord($c) - 48` ('A' = 17).
  Uma normalização que apague não-dígitos transforma um CNPJ novo em lixo, em
  silêncio. A forma canônica de armazenamento passa a ser `[0-9A-Z]`, maiúsculo.
- **`documentos_fiscais` é tabela nova, não coluna em `os`.** Uma OS gera mais de
  um documento — NFS-e do serviço e NF-e da peça — e um documento cancelado é
  substituído por outro. `os.numero_nfse` quebraria no primeiro cancelamento e
  perderia o histórico que a fiscalização pede.
- **Vínculo pelo padrão da família, sem FK.** `os_id` / `venda_id` como
  `movimentacoes` já faz com `os_id`/`venda_id`/`venda_item_id`/`financeiro_id`.
  Não usar `origem_tipo`/`origem_id`: apesar do nome, `origem_id` em `financeiro`
  é um `belongsTo(FinanceiroMovimento)` e gravar outra coisa ali carrega registro
  alheio de mesmo id, em silêncio — a `039` já registrou essa armadilha.
- **Modo assistido antes da API.** Na `042` o ERP monta a discriminação e os
  dados do tomador, o operador emite no portal do gov.br e devolve número, chave
  e PDF, que ficam anexados à OS. Isso cumpre 2027 sem certificado, sem contrato
  com gateway e sem depender da regra municipal de material — e é o degrau que a
  integração reusa, não trabalho jogado fora.
- **Uma normalização de CPF/CNPJ só, e ela já existe.**
  `UpsertOrderRequest::prepareForValidation()` já reduz `cpf_cnpj` a dígitos para
  `novo_cliente` e `cliente_atualizacao`, e já aplica `Rule::unique`. O CRUD de
  cliente (`ClientController`, `'cpf_cnpj' => ['nullable','string','max:20']`)
  **não** normaliza nada. Hoje o mesmo documento entra como `123.456.789-00` por
  uma porta e `12345678900` pela outra — e o `unique` não vê a duplicata, porque
  as strings diferem. A regra passa a morar em `App\Support\Documento`, no
  backend, e as duas portas do backend chamam a mesma coisa.
  **O desktop não recebe cópia.** Backend e desktop são dois apps Laravel com
  autoloads separados (`shared/` tem só `version.php`), e nenhuma classe de
  domínio do backend está duplicada lá. O desktop é BFF: encaminha e deixa o
  backend recusar. O feedback instantâneo do formulário é JavaScript — UX, não
  autoridade —, porque uma segunda cópia da regra em PHP divergiria da primeira
  com o tempo.
- **Campos fiscais de `servicos` espelham os de `pecas`.** Mesma decisão da `027`,
  mesmo formato: nullable, numa aba "Fiscal", sem uso imediato. O serviço é 79%
  do faturamento e foi o lado que ficou de fora naquela entrega.
- **Guarda de XML pelo Gerenciador Central de Arquivos (`022`).** Retenção de 5
  anos, integridade por SHA-256 e catálogo já existem ali. Diretório novo em
  `storage/` repetiria o problema de propriedade de arquivo que já mordeu view
  cache e logs.
- **`RegimeTributario` continua a fonte do regime.** Nada de constante MEI no
  código de emissão: o regime é `Configuration`, e a mudança para Simples tem de
  ser ajuste de tela, como a classe já promete no próprio comentário.
- **Recusado: emitir automático na baixa da OS já na `042`.** Sem o relatório de
  prontidão zerado, emissão automática vira rejeição em massa e o operador perde
  a confiança no recurso logo no primeiro mês.

## Escopo (Fase 1)

Chaves fiscais em `Configuration` e campos na tela de perfil da empresa
(`UpdateCompanyProfileRequest`, `CompanyProfileService`,
`CompanyContextProvider`): endereço **estruturado** — hoje `empresa_endereco` é
um `string(255)` só, e NFS-e exige logradouro, número, bairro, CEP e código IBGE
separados —, inscrição municipal, CNAE e código de tributação nacional ·
`clientes.codigo_ibge_municipio` e validação real de CPF/CNPJ (dígito
verificador, não `max:20`) · CPF em destaque no cadastro rápido da Nova OS ·
campos fiscais em `servicos` · NCM nas 9 peças · **relatório de prontidão
fiscal**: quantos clientes sem CPF, peças sem NCM, serviços sem código, e o que
falta no cadastro da empresa — mesmo padrão de diagnóstico que a `038` usou para
achar as 2.187 OS com CMV zerado.

## Fases seguintes

- **`042` — `documentos_fiscais` e modo assistido.** Tabela (tipo, série, número,
  chave, status, XML, PDF, motivo de rejeição, cancelamento), guarda pelo `022`,
  registro manual do retorno do portal.
- **`043` — NFS-e pela API** com certificado A1, homologação antes de produção,
  fila e reprocessamento de rejeição.
- **`044` — NF-e/NFC-e de peça** pela SEFAZ estadual, destaque de IBS/CBS e
  adaptação ao split payment.

## Fora de escopo

- **Emitir qualquer documento nesta entrega.** A `041` não fala com o governo.
- **Certificado digital, contador e regra municipal de material.** São
  pré-requisitos de fora do sistema (Fase 0 do estudo). Em particular: se o
  município permitir peça aplicada como material dedutível na própria NFS-e, o
  volume de documentos muda e a `044` encolhe — perguntar antes de desenhá-la.
- **Escolha de gateway fiscal.** Decisão comercial, e prematura enquanto a base
  não tiver CPF.
- **Backfill automático de CPF.** Não há de onde tirar. O relatório mede; o
  balcão preenche.
- **Mudança de regime tributário.** O simulador de precificação já roda o cenário
  Simples; a decisão é do dono com o contador, não do sistema.

## Riscos

- **Coluna nova que não for espelhada em `BuildsLegacyErpSchema` some nos
  testes.** O trait recria as tabelas do zero depois das migrations — é a mesma
  armadilha anotada na própria migration da `027`.
- **`artisan migrate` aborta por causa do banco de chat.** O erro de grant em
  `sistema_erp_chat` derruba todas as migrations pendentes; aplicar as novas com
  `--path` e espelhar o schema no trait.
- **Cache de config e de rota mascara o resultado.** Rota nova dá "Route não
  definida" mesmo presente no arquivo, e teste de desktop vira 302, com o cache
  quente. `config:clear` **e** `route:clear` antes de testar, recache depois.
- **PDF e XML gerados como `administrador` quebram em runtime.** Tudo sob
  `storage/` roda como `www-data`; é o mesmo defeito que já apareceu no view
  cache e nos logs. Mais um motivo para a guarda passar pelo `022`.
- **A suíte de desktop é instável.** Tirar baseline com `git stash` antes de
  atribuir falha à entrega.
- **Pode haver duplicata latente de CPF entre as duas portas.** A base está
  vazia hoje, então o risco é de frente, não de trás: se o CRUD começar a
  normalizar antes do wizard, ou vice-versa, nasce a divergência que a decisão
  acima existe para impedir. As duas portas mudam na mesma entrega.
- **Validar CPF com dígito verificador vai reprovar dado que hoje entra.** Como
  a base está 100% vazia, não há retrabalho de migração — mas o cadastro rápido
  precisa da mensagem certa, ou o operador contorna digitando qualquer coisa.
