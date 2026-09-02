# Esquemas XSD da NFS-e Nacional — v1.01 (2026-02-09)

Baixados de:
https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/nfse-esquemas_xsd-v1-01-20260209.zip

Versionados aqui para `DpsXmlBuilderTest` validar o XML gerado contra o schema
oficial sem depender de rede.

## Defeito conhecido no pacote oficial

`TSSerieDPS` (em `tiposSimples_v1.01.xsd`) declara:

    <xs:pattern value="^0{0,4}\d{1,5}$"/>
    <xs:maxLength value="5"/>

Em XSD 1.0 o `pattern` é implicitamente ancorado e `^`/`$` são caracteres
LITERAIS — não âncoras. Combinado ao `maxLength=5`, o único valor que satisfaz
o padrão é a string `^1$`. Nenhum número de série real passa.

Verificado com libxml: `00001`, `1`, `12345` e `00000` são todos recusados;
`^1$` é aceito.

Conclusão: é defeito do schema publicado, não do nosso XML. O validador do ADN
necessariamente é mais permissivo (ou não aplica este facet), senão ninguém
emitiria. `DpsXmlBuilderTest` valida contra o XSD e tolera **apenas** este erro,
falhando em qualquer outro — se o pacote for corrigido, o teste avisa.
