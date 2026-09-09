# NFS-e real usada como fixture

`nfse-real-mei.xml` é uma NFS-e MEI emitida de verdade pelo Emissor Nacional
(CNPJ 34.129.526/0001-98, São Pedro da Aldeia/RJ), fornecida pelo dono do
sistema. A assinatura do ADN foi removida — o que importa aqui é a estrutura de
dados, e guardar a assinatura de um documento fiscal real num repositório não
tem propósito.

Serve de referência para três coisas que a documentação não deixava claras:

- **`opSimpNac` = 2** para MEI (o padrão do sistema era 1, e estava errado).
- **Assinatura em `rsa-sha256` com `xml-exc-c14n#WithComments`** — o sistema
  usava SHA-1 e, pior, declarava c14n inclusiva enquanto canonicalizava
  exclusiva.
- **`cTribNac` 310102** e **`cNBS` 120018100** para reparo de eletrônicos.

Também mostra a estrutura de aninhamento que o importador precisa atravessar:
`NFSe` → `infNFSe` (dados devolvidos pelo ADN) → `DPS` → `infDPS` (o que o
contribuinte enviou).

⚠️ O arquivo vem do portal com acentuação **duplamente codificada**
(`SÃ£o Pedro`, `ServiÃ§os`). Não é corrupção nossa: é assim que o portal entrega,
e o importador tem de normalizar.

⚠️ Este XML **não** indica cancelamento, embora o DANFSe correspondente esteja
cancelado. No padrão nacional o cancelamento é um evento separado
(`evento_v1.01.xsd` / `pedRegEvento_v1.01.xsd`), não um campo na nota. Não dá
para inferir cancelamento do XML da nota.

---

# `nfse-real-sem-tomador.xml`

Segunda NFS-e real da mesma empresa (CNPJ 34.129.526/0001-98), emitida em
**produção** pelo Emissor Nacional em 05/09/2026 — `cStat` 107 (autorizada),
`tpAmb` 1. Assinatura removida pela mesma razão da fixture acima.

Existe para provar **um** ponto, e é um ponto que o código já tinha errado:

- **`<toma>` simplesmente não existe.** O `infDPS` vai direto de `</prest>`
  para `<serv>`. O sistema recusava emitir sem CPF/CNPJ do tomador
  (`RuntimeException` no `DpsXmlBuilder`), tratando como obrigatório algo que o
  XSD marca `minOccurs="0"` e que o próprio Ambiente Nacional autoriza. Balcão
  de assistência atende quem não quer se identificar, e a nota tem de sair.

  Não é `cNaoNIF`: é ausência. `cNaoNIF` existe para estrangeiro sem NIF e
  exigiria `xNome` junto — inventaria um tomador meia-boca onde o layout já
  oferece a omissão limpa.

Também registra dois valores em uso que divergem dos padrões do sistema:

- **`serie` = 70000, e isso NÃO deve ser copiado.** A série da DPS declara o
  **tipo de emissor**, não a empresa: `00001`-`49999` é aplicativo próprio
  (API), `50000`-`69999` emissor móvel, `70000`-`79999` **emissor web** — que é
  o que gerou esta nota (`verAplic` = `EmissorWeb_1.6.0.0`). Usar 70000 na
  emissão por API devolve rejeição **E0010** do Ambiente Nacional ("a série
  informada na DPS não pertence à faixa definida para o tipo de emissor").
  Aconteceu exatamente isso ao ler esta fixture como se a série fosse da
  empresa. `EmissaoNfseService::conferirFaixaDaSerie()` passou a barrar antes
  de transmitir.
- **`nDPS` = 4 — na série do portal.** Como a numeração é **por série**, e a
  API usa outra faixa, esses números **não** consomem a sequência da emissão
  automática. Não se semeia `fiscal_sequencias` com o último número visto no
  portal: são numerações independentes.

⚠️ **Esta cópia não é byte-exata.** Ela chegou por colagem em conversa, não
como arquivo, e a assinatura original não conferia contra o conteúdo recebido
(espaçamento normalizado no caminho). Como a assinatura seria removida de
qualquer forma, isso não afeta o que a fixture prova — estrutura e valores —,
mas **não a use como amostra de assinatura válida**. Para isso seria preciso o
arquivo original do portal.
