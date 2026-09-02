# Fontes do DANFSe

A NT-008 (item 2.4) exige **Arial** nos títulos (labels) e **Microsoft Sans Serif**
nos conteúdos. As duas são fontes proprietárias da Microsoft e não podem ser
redistribuídas com este sistema.

O que vai aqui é **Liberation Sans** (SIL Open Font License 1.1), que é
*metricamente compatível com Arial*: mesma largura de avanço glifo a glifo. Isso
importa mais do que parece num documento fiscal — os tamanhos de campo do item
2.4.5 da nota técnica (77, 167, 1297 caracteres...) pressupõem a métrica da
Arial, e uma fonte com métrica diferente quebraria linha em outro lugar.

## Para usar as fontes exatas da norma

Se a empresa tiver licença das fontes da Microsoft, basta colocar os arquivos
neste diretório com estes nomes — o renderizador os prefere automaticamente e
cai na Liberation Sans só quando não existem:

| Arquivo esperado             | Papel no DANFSe                   |
|------------------------------|-----------------------------------|
| `Arial.ttf`                  | títulos (labels), peso normal     |
| `Arial-Bold.ttf`             | títulos (labels), negrito         |
| `MicrosoftSansSerif.ttf`     | conteúdo dos campos, peso normal  |
| `MicrosoftSansSerif-Bold.ttf`| conteúdo dos campos, negrito      |

Nada mais precisa mudar: `App\Services\Pdf\NfseDanfseRenderer::fontes()` resolve
os caminhos e o Blade escreve as regras `@font-face` com o que existir.

## Por que os arquivos ficam no repositório

O DANFSe tem de sair igual em qualquer servidor. Depender da fonte instalada no
sistema operacional faria o mesmo documento quebrar linha de um jeito na máquina
de desenvolvimento e de outro na VPS — e o que muda de lugar é texto de documento
fiscal.
