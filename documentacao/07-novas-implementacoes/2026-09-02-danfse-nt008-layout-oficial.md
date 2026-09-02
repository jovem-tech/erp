# DANFSe conforme a NT-008: o documento passa a ser o documento (2026-09-02)

**Norma:** Nota Técnica nº 008 — SE/CGNFS-e, 05/05/2026 (Especificações Técnicas do DANFSe)
**Spec:** `specs/041-emissao-fiscal-nfse/spec.md`
**Tipo:** funcionalidade nova (MINOR) — v5.70.0.0

## O problema

O `GET /api/v1/fiscal/documentos/{id}/danfse` gerava um PDF que **não era um
DANFSe**. Era um resumo da nota com cara de documento fiscal.

Confrontado campo a campo com a NT-008, o que existia tinha **6 dos 13 blocos
obrigatórios** e nenhum dos elementos de verificação:

| NT-008 | Estado anterior |
|---|---|
| Cabeçalho com logomarca, "DANFSe v2.0", município e ambiente (2.4.3) | Título livre, sem logo, sem ambiente gerador |
| **QR Code da consulta pública** (2.4.3) | **Ausente** |
| Chave de acesso em bloco único de 50 dígitos (2.1.1) | Presente |
| Prestador: IM, telefone, e-mail, IBGE/CEP, Simples Nacional, regime SN (2.1.3) | Só nome, CNPJ, endereço da *empresa cadastrada* |
| Tomador: IM, telefone, município, IBGE/CEP, endereço, e-mail (2.1.4) | Só nome e CNPJ |
| Destinatário da operação (2.1.5) | **Bloco inexistente** |
| Intermediário da operação (2.1.6) | **Bloco inexistente** |
| Tributação Municipal — ISSQN (2.1.8) | **Bloco inexistente** |
| Tributação Federal exceto CBS (2.1.9) | **Bloco inexistente** |
| Tributação IBS/CBS (2.1.10) | **Bloco inexistente** |
| Valor total com descontos e retenções (2.1.11) | Só valor do serviço e líquido |
| Informações Complementares + totais de tributos (2.1.12, nota 10) | **Bloco inexistente** |
| Canhoto (2.1.13) | **Bloco inexistente** |
| Marca d'água de cancelada/substituída (2.5) | Ausente |

E imprimia coisas que a norma **proíbe**: um aviso amarelo de "representação
interna" e um rodapé "gerado por…" — o item 2.1 diz que "não poderão ser
impressas informações que não constem do arquivo da NFS-e".

### O ponto que muda a leitura do problema

O documento anterior se descrevia como um sucedâneo ("o documento com valor
legal é o do portal"). A própria NT-008 desmonta isso na introdução: ela existe
para servir "de base para a geração do DANFSe por meios de softwares de emissão
de NFS-e, ERPs e sistemas fiscais, **motivo pelo qual a API de geração do DANFSe
será sobrestada (suspensa) na data de 1º de julho de 2026**".

Gerar o DANFSe no ERP não é contorno: é o caminho previsto — e a partir de
01/07/2026 é o único que sobra para quem não baixa o PDF a cada nota.

## O que foi entregue

O layout do Anexo I, reproduzido bloco a bloco, com as coordenadas do item 2.4.5
(quatro colunas de 5,09cm num corpo de 20,40cm) e o **QR Code de verificação**.

### QR Code sem dependência de imagem

Item 2.4.3: o QR aponta para
`https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=` + a chave, com no mínimo
1,52 x 1,52cm.

`App\Support\QrCodePng` usa o `bacon/bacon-qr-code` para a parte difícil
(Reed-Solomon, versão, máscara) e **monta o PNG à mão**, em meia dúzia de chunks.
O renderer SVG da biblioteca dependeria do suporte parcial a SVG do dompdf e o de
imagem exigiria Imagick, que não está instalado nos servidores do projeto. Um PNG
em tons de cinza o dompdf desenha sem intermediário — nem Imagick, nem GD.

### A norma mora numa classe, não no template

`App\Services\Fiscal\DanfseLayout` concentra tudo o que a NT-008 exige e o Blade
só posiciona:

- **códigos viram descrição** (`DanfseCodigos`, transcrito do Anexo IV — Leiautes
  RN ADN/SN NFS-e v1.00.02). Código desconhecido volta como código: inventar
  descrição num documento fiscal é pior que imprimir o número, que ao menos é
  verificável contra o XML;
- **campo vazio vira traço** (nota 12);
- **truncamento com reticências** no tamanho de cada campo (77, 167, 1297, 1997…);
- **supressão de bloco**: tomador, destinatário e intermediário não identificados
  viram uma linha com o texto literal da nota 2; destinatário igual ao tomador
  usa o texto da nota 3; linhas de benefício/dedução somem quando vazias (nota 5);
  a linha de PIS/COFINS só sai para competência até 2026 (nota 6);
- **informações complementares** na ordem e com os rótulos da norma, separados
  por pipe, sempre fechando com a linha obrigatória de totais aproximados de
  tributos (nota 10).

### O espaço que sobra vai para onde a norma manda

O DANFSe é de página única (item 2.2) e o canhoto fica no pé da folha. As alturas
de linha do item 2.4.5 estão no CSS e **as mesmas alturas estão em
`DanfseLayout::alturas()`**: o que sobra quando um bloco é suprimido é devolvido
a "Informações Complementares", que é o ajuste prescrito nos itens 2.3.1 a 2.3.3.

Sem essa conta o documento terminaria no meio da folha (quando há blocos
suprimidos) ou passaria para a segunda página (quando a descrição do serviço é
longa) — as duas coisas fora da norma.

### Tabela do IBGE versionada

O XML só traz `cMun`. Para o emitente ainda há `xLocEmi`; para tomador,
destinatário e intermediário **não há nome nenhum no arquivo** — e "Município /
Sigla UF" é campo obrigatório nos três blocos.

`resources/data/municipios-ibge.php` (5.571 municípios, gerado do serviço oficial
do IBGE) resolve o nome; a sigla da UF sai dos dois primeiros dígitos do código,
que é exato e não duplica dado. É arquivo versionado, e não consulta em tempo de
execução, porque emitir DANFSe não pode depender de um serviço externo estar no
ar. Manutenção: `php artisan fiscal:atualizar-municipios-ibge` (tem `--dry-run` e
recusa resposta truncada).

## Arquivos

| Arquivo | Papel |
|---|---|
| `backend/resources/views/pdf/nfse-danfse.blade.php` | Modelo do Anexo I; só posiciona |
| `backend/resources/views/pdf/partials/danfse-pessoa.blade.php` | Bloco de tomador/destinatário/intermediário |
| `backend/app/Services/Fiscal/DanfseLayout.php` | Toda a regra da NT-008 |
| `backend/app/Services/Fiscal/DanfseCodigos.php` | Tabelas de domínio do leiaute |
| `backend/app/Services/Fiscal/NfseXmlImporter.php` | Extração ampliada para os campos da norma |
| `backend/app/Services/Pdf/NfseDanfseRenderer.php` | dompdf + logomarca oficial embutida |
| `backend/app/Support/QrCodePng.php` | QR Code em PNG, sem Imagick nem GD |
| `backend/app/Support/MunicipioIbge.php` | Código IBGE → município / UF |
| `backend/app/Console/Commands/Fiscal/AtualizarMunicipiosIbge.php` | Manutenção da tabela |
| `backend/resources/data/municipios-ibge.php` | Tabela gerada (5.571 municípios) |
| `backend/resources/images/danfse/nfse-logo.png` | Logomarca oficial (item 2.4.3) |
| `frontends/desktop/resources/views/fiscal/nota.blade.php` | Texto da tela, que dizia "representação interna" |

**Dependência nova:** `bacon/bacon-qr-code` ^3.0 (MIT).
**Fontes versionadas:** Liberation Sans Regular e Bold (SIL OFL 1.1), em
`backend/resources/fonts/danfse/`.

## Verificação

`tests/Feature/Fiscal/DanfseNt008Test.php` — 18 testes, cada um citando o item da
norma que prova: endereço do QR, tradução de códigos, traço em campo vazio,
textos literais de supressão de bloco, notas 3/5/6/10, truncamento, resolução do
município pelo IBGE, homologação, marca d'água e **página única**, inclusive com
os dois campos longos no limite.

O PDF renderizado da nota real foi conferido contra o DANFSe que o portal gera
para a mesma nota: mesmos blocos, mesma ordem, mesmos valores.

`DocumentoFiscalTest` passou a conceder `fiscal:visualizar/criar/excluir` ao grupo
de teste, espelhando o que a migration `create_fiscal_rbac_module` semeia em
produção — sem isso os 20 testes do endpoint paravam em 403 e a geração do
DANFSe ficava sem cobertura de ponta a ponta.

Rodada final dos grupos afetados (`Fiscal|Pdf|Danfse|Documento`):
**176 testes, 805 asserções, tudo verde.**

## Conferência física do impresso (itens 2.2 e 2.4)

Estes três pontos não se verificam lendo o XML — só medindo o PDF renderizado.
Foram aferidos rasterizando o documento a 150 dpi e inspecionando os pixels:

| Exigência | Onde | Aferido |
|---|---|---|
| Sombreamento 5% no cabeçalho, nos títulos de bloco, em "Emitente da NFS-e" e em "Valor Líquido da NFS-e + IBS/CBS"; branco no resto | 2.2.3 | **#F2F2F2 exato** (5% de densidade), em 22 faixas, todas nesses quatro lugares e em nenhum outro |
| Borda externa de 1 ponto | 2.2.3 | **0,96 pt** medido (o PDF declara 1pt; a diferença é arredondamento do rasterizador) |
| Margem lateral entre 0,15cm e 0,20cm | 2.2.2 | **0,20cm** nos quatro lados |
| Arial nos títulos, Microsoft Sans Serif nos conteúdos | 2.4 | ver abaixo |

### Fontes: o que foi possível entregar

Arial e Microsoft Sans Serif são proprietárias da Microsoft e não podem ser
redistribuídas com o sistema. O que vai embutido no PDF é a **Liberation Sans**
(SIL OFL 1.1), *metricamente compatível com a Arial* — mesma largura de avanço
glifo a glifo, que é o que preserva os tamanhos de campo do item 2.4.5.

Quem tiver licença das fontes da Microsoft coloca os arquivos em
`backend/resources/fonts/danfse/` com os nomes que o LEIA-ME de lá indica e eles
passam a ter precedência, sem tocar em código.

Antes desta entrega o documento nem chegava perto disso: usava a **Helvetica de
núcleo do PDF**, não embutida. O teste
`o documento embute a fonte em vez de cair na helvetica` prende isso, porque a
queda para a fonte de núcleo é silenciosa e muda onde cada campo quebra linha.

### O que a troca de fonte quebrou, e como foi resolvido

Com a fonte embutida, o dompdf passou a espaçar as linhas pela métrica dela —
0,385cm por linha em 7 pontos, e **ele ignora `line-height` no texto que quebra
sozinho**. O cálculo de espaço do `DanfseLayout` assumia 0,30cm e passou a
mentir: o pior caso (descrição do serviço e informações complementares nos
tamanhos máximos) foi para a segunda página.

A correção não foi afrouxar a conta, foi inverter a dependência: as constantes
`ALTURA_LINHA` e `ENTRELINHA` passaram a ser **medidas no PDF**, arredondadas
para cima, e o texto das informações complementares é **truncado para o quadro
disponível** em vez de o quadro crescer — que é o remédio que a própria norma
usa (reticências do item 2.4.5) e o bloco que o item 2.5.3 aponta como o que
cede espaço.

## O que ficou de fora, e por quê

- **Marca d'água "SUBSTITUÍDA"** (item 2.5.2): o sistema não registra
  substituição. `CANCELADA` sai porque o `DocumentoFiscal` sabe do cancelamento —
  o XML da nota, não (o cancelamento é evento separado no padrão nacional).
- **Descrição de `finNFSe`, `CST`/`cClassTrib` e `cIndOp`**: o grupo IBS/CBS ainda
  não vem preenchido nos XMLs do Emissor Nacional e não há tabela publicada no
  Anexo IV vigente. Os campos existem no documento e imprimem traço; quando o
  grupo chegar, entram em `DanfseCodigos`.
- **`regEspTrib` = 9**: a NT-008 cita "0 a 6 e 9", mas o 9 não consta do Anexo IV
  v1.00.02. Imprime o código cru até haver fonte oficial para a descrição.
