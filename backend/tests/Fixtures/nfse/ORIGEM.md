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
