# Miniaturas autenticadas das fotos da OS no PWA mobile

- data: `2026-07-26`;
- versão: `5.19.2.0`;
- módulo: `frontends/mobile`;
- natureza: correção funcional, visual e de segurança.

## Problema

O detalhe da OS mostrava um SVG textual com o tipo e o nome do arquivo no lugar
da foto real. O mesmo placeholder era aplicado a fotos e documentos porque
ambos possuem o campo `tipo_label`.

## Solução

- fotos visíveis carregam o arquivo privado pela API autenticada;
- a miniatura usa `object-fit: contain`, preservando a foto inteira sem corte;
- tocar na miniatura abre o mesmo painel de visualização ampliada do botão
  `Abrir`;
- documentos continuam com fallback textual, sem tentativa de transformá-los
  em imagem;
- falhas de carregamento mantêm um cartão identificável com a mensagem
  `Foto indisponível`.

## Arquitetura, segurança e performance

- as rotas são construídas exclusivamente com os IDs numéricos da OS e do
  anexo: `/orders/{order}/photos/{photo}` ou
  `/orders/{order}/documents/{document}`;
- URLs absolutas recebidas no payload não são reutilizadas, impedindo o envio
  acidental do Bearer token para outra origem;
- o MIME precisa começar com `image/` antes da criação da miniatura;
- o placeholder passou a ser JSX escapado pelo React, removendo a interpolação
  de metadados em SVG;
- `IntersectionObserver` carrega somente fotos próximas da área visível,
  evitando baixar galerias inteiras antecipadamente;
- cada `blob:` URL é revogada na troca do item ou desmontagem do componente,
  evitando retenção de memória;
- não houve alteração de banco, rota, payload ou contrato OpenAPI.

## Validação

- 17 arquivos de teste e 91 testes aprovados;
- testes cobrem miniatura privada, rota interna derivada por IDs, descarte da
  URL absoluta não confiável, documento textual e fallback de erro;
- lint e build Next.js aprovados;
- composição visual revisada no viewport `390 x 844`.
