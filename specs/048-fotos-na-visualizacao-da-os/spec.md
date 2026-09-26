# Especificação 048 - Fotos direto na visualização da OS

## Problema

Para anexar uma foto a uma OS já aberta, o técnico precisava sair da tela de
detalhe, abrir **Editar OS**, ir até a aba Fotos, escolher o arquivo, passar por
um editor de recorte obrigatório para cada imagem e salvar o formulário inteiro da
OS (cliente, equipamento, relato, checklist) só para gravar a foto. Isso na tela
que ele mais usa durante o atendimento.

Além disso, só existia uma origem de imagem: o seletor de arquivos. Não havia como
colar um print, fotografar pela webcam/celular direto na OS nem arrastar imagens, e
toda foto era gravada como `recepcao`, embora a tela de detalhe já agrupasse as
fotos em Recepção, Diagnóstico e Entrega — os dois últimos grupos nunca recebiam
nada.

## Objetivo

Anexar fotos à OS a partir da própria visualização, em poucos toques, por qualquer
origem, sem editar a OS e sem etapa obrigatória de recorte.

## Requisitos funcionais

- O quadro **Fotos** do detalhe da OS oferece, para quem pode editar a OS
  (`os:editar`):
  - **Câmera**: no computador abre a webcam num modal com prévia ao vivo, permite
    várias capturas seguidas e troca de câmera quando houver mais de uma; em
    celular/tablet abre a câmera nativa do aparelho;
  - **Computador / galeria**: seletor de arquivos com seleção múltipla (no
    celular, a galeria);
  - **Colar**: lê imagem da área de transferência pelo botão e aceita `Ctrl+V`
    (`⌘V` no Mac) em qualquer ponto da página fora de campos de texto — inclusive
    arquivos copiados no Explorador de Arquivos;
  - **Arrastar e soltar** imagens no quadro.
- Toda imagem entra numa fila de envio com miniatura, tamanho e botão de remover.
  Nada é gravado antes do clique em **Enviar** (não existe exclusão de foto de OS
  no sistema, então um Ctrl+V acidental não pode virar foto permanente).
- A fila tem uma **categoria** (Recepção, Diagnóstico ou Entrega) aplicada ao
  lote. A sugestão vem da fase do status atual (`status_grupo_macro`): recepção →
  Recepção; concluído, finalizado sem reparo, encerrado ou cancelado → Entrega;
  demais fases → Diagnóstico.
- O envio acontece em lotes de até 4 fotos (limite do backend por requisição),
  sequenciais, com barra de progresso. Após cada lote a galeria é atualizada sem
  recarregar a página e as fotos novas ficam destacadas.
- Falha em um lote interrompe os seguintes, mantém na fila o que não foi enviado e
  mostra a mensagem do backend; o que já foi enviado continua gravado.
- Fotos grandes são reduzidas no navegador para no máximo 2560 px (o mesmo teto do
  backend) antes do envio, apenas como economia de tráfego; HEIC/HEIF e qualquer
  imagem que o navegador não consiga decodificar seguem originais.
- Sair da página com fotos na fila pede confirmação (sonda de trabalho não salvo,
  `specs/035`).
- Cada envio gera o evento `fotos_adicionadas` na linha do tempo da OS, com a
  categoria no texto e no payload e o usuário que enviou.
- A busca de funcionalidades encontra "Adicionar fotos à OS".

## Requisitos de segurança

- Novo endpoint `POST /api/v1/orders/{order}/photos` exige `os:editar`, respeita o
  escopo do técnico (`canAccessOrder`: técnico só na OS atribuída a ele), aplica
  `OperationalPhotoUpload` (conteúdo real x extensão), máximo de 4 arquivos de
  20 MB e o throttle de 8 envios com foto por minuto por usuário+IP.
- O otimizador central (`specs/046`) continua sendo a autoridade: a redução no
  navegador não substitui validação nem otimização no servidor.
- A categoria é validada contra a lista fechada do `enum` de `os_fotos.tipo`.

## Compatibilidade

- A edição completa da OS continua aceitando `fotos[]` como antes.
- Nenhuma migração: `os_fotos.tipo` já é `enum('recepcao','diagnostico','entrega')`.
- Usuário só com `os:visualizar` continua vendo a galeria sem os controles.

## Fora de escopo

- Excluir ou recategorizar fotos já gravadas.
- Mesma funcionalidade no frontend mobile (Next.js), que também só anexa fotos na
  criação da OS.
- Editor de recorte opcional na fila.
