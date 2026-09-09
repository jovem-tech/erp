<?php

/*
|--------------------------------------------------------------------------
| Emissão fiscal — certificado A1 (specs/041-emissao-fiscal-nfse, fase 043)
|--------------------------------------------------------------------------
|
| O A1 e' ARQUIVO (.pfx/.p12), nao token: e' o que serve para automacao. O A3
| e' cartao/token fisico e exigiria alguem plugar o dispositivo a cada lote.
|
| O CONTEUDO do certificado (o `.pfx`, que e' a chave privada) nunca vai para o
| banco: o dump diario e' gzip sem cifra e o backup de configuracao carrega o
| APP_KEY junto (ver ConfigSnapshotService), entao guardar a chave privada no
| banco — mesmo cifrada — colocaria chave e segredo no mesmo pacote. O arquivo
| mora no disco, fora do webroot, com 0600.
|
| A SENHA, essa sim, vai para `configuracoes` cifrada em repouso pelo
| `SecretSettings` (mesmo mecanismo do Inter e do SMTP), e tem precedencia
| sobre o `FISCAL_CERT_SENHA` daqui — que continua valendo como fallback e
| onde ela ficaria em texto puro. Ver `CertificadoA1Installer`.
|
| Consequencia pratica para quem comprar o sistema: trocar o certificado e'
| pela TELA (Configuracoes > Integracoes), sem terminal. O upload pelo processo
| web ainda resolve de graca o dono do arquivo — `www-data` —, que e' a
| armadilha que ja' mordeu o cache de view e os logs deste servidor.
|
*/

return [
    'certificado' => [
        // .pfx / .p12 exportado pela Autoridade Certificadora (ICP-Brasil A1).
        'pfx_path' => (string) env('FISCAL_CERT_PFX_PATH', 'storage/app/private/integracoes/fiscal/certificado.pfx'),
        'senha' => (string) env('FISCAL_CERT_SENHA', ''),
        // Dias de antecedencia do alerta de vencimento. O A1 vale 1 ano, e a
        // falha classica e' expirar em silencio — a integracao simplesmente
        // para de autenticar.
        'alerta_dias' => max(1, (int) env('FISCAL_CERT_ALERTA_DIAS', 30)),
    ],

    'nfse' => [
        // 1 = producao, 2 = homologacao. Nasce em homologacao de proposito:
        // emitir em producao por engano gera documento fiscal de verdade, com
        // obrigacao tributaria real e cancelamento a fazer.
        'ambiente' => (int) env('FISCAL_NFSE_AMBIENTE', 2),
        'versao_aplicativo' => (string) env('FISCAL_NFSE_VERSAO_APP', 'ERP-JT'),
        /*
         | A serie da DPS declara o TIPO DE EMISSOR, nao a empresa. As faixas
         | sao definidas pelo Ambiente Nacional:
         |
         |   00001-49999  aplicativo proprio (API)  <- este sistema
         |   50000-69999  emissor movel
         |   70000-79999  emissor web (o portal do gov.br)
         |   80000-89999  transcricao manual
         |
         | Copiar a serie de uma nota emitida NO PORTAL para emitir por API e'
         | rejeicao E0010 na cara ("a serie informada na DPS nao pertence a
         | faixa definida para o tipo de emissor"). Ja' aconteceu aqui: 70000
         | veio de um XML real da empresa, emitido pelo EmissorWeb.
         |
         | Consequencia pratica: a numeracao de DPS do portal e a da API sao
         | independentes — nao se semeia o contador da API com o ultimo numero
         | visto no portal, porque sao series diferentes.
         */
        'serie' => (string) env('FISCAL_NFSE_SERIE', '00001'),
        // `opSimpNac` no layout da DPS. Um XML real de NFS-e MEI devolvido pelo
        // Ambiente Nacional traz **2** — o padrao anterior (1) faria a DPS
        // declarar regime errado. Confirmar com o contador mesmo assim: o
        // dominio do XSD tem 1, 2 e 3.
        'regime_tributario' => (int) env('FISCAL_NFSE_REGIME', 2),
        // 0 = nenhum regime especial de tributacao.
        'regime_especial' => (int) env('FISCAL_NFSE_REGIME_ESPECIAL', 0),
        // tribISSQN 1..4 e tpRetISSQN 1..3 — dominios do XSD. Os padroes
        // valem para o caso comum (operacao tributavel, sem retencao); o
        // contador confirma se a assistencia foge disso.
        'tributacao_issqn' => (int) env('FISCAL_NFSE_TRIB_ISSQN', 1),
        'retencao_issqn' => (int) env('FISCAL_NFSE_RET_ISSQN', 1),
        // Codigo NBS do servico. Opcional no XSD (`minOccurs=0`), mas o XML
        // real traz. Vazio = nao emite o elemento.
        'cnbs' => trim((string) env('FISCAL_NFSE_CNBS', '')),
        // XML importado do portal precisa vir assinado pelo Ambiente Nacional.
        // Ligado de proposito: sem assinatura conferida, um arquivo montado a
        // mao entra como nota emitida e vira a prova guardada por 5 anos.
        //
        // Desligar so' faz sentido para trabalhar com amostra sem assinatura
        // (a fixture versionada no repo e' uma). Mesmo desligado, XML assinado
        // e ADULTERADO continua sendo recusado — isso nao e' configuravel.
        'exigir_assinatura_xml' => (bool) env('FISCAL_NFSE_EXIGIR_ASSINATURA', true),

        /*
         | Transmissao ao Ambiente Nacional (SEFIN Nacional).
         |
         | O path e os nomes de campo ficam AQUI, e nao em constante, de
         | proposito: o Swagger oficial exige certificado para abrir, entao a
         | conferencia do contrato so' acontece em homologacao, com o
         | certificado da empresa na mao. Se divergir, o conserto e' `.env` —
         | nao deploy.
         |
         | O corpo vai e volta comprimido: DPS assinada -> gzip -> base64 no
         | campo de envio; a resposta traz a NFS-e pronta pelo mesmo caminho,
         | mais a chave de acesso de 50 posicoes.
         */
        'transmissao' => [
            'urls' => [
                // 1 = producao, 2 = homologacao ("producao restrita"),
                // indexado pelo mesmo `ambiente` acima.
                1 => (string) env('FISCAL_NFSE_URL_PRODUCAO', 'https://sefin.nfse.gov.br/SefinNacional'),
                2 => (string) env('FISCAL_NFSE_URL_HOMOLOGACAO', 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional'),
            ],
            'path_emissao' => (string) env('FISCAL_NFSE_PATH_EMISSAO', '/nfse'),
            // A consulta e' em DOIS passos, e isso e' contrato do ADN, nao
            // escolha nossa: `/dps/{idDps}` responde se aquela DPS virou nota
            // e devolve SO' a `chaveAcesso`; o XML vem de `/nfse/{chave}`.
            'path_consulta_dps' => (string) env('FISCAL_NFSE_PATH_CONSULTA_DPS', '/dps/{idDps}'),
            'path_consulta_nfse' => (string) env('FISCAL_NFSE_PATH_CONSULTA_NFSE', '/nfse/{chave}'),
            'campo_dps' => (string) env('FISCAL_NFSE_CAMPO_DPS', 'dpsXmlGZipB64'),
            'campo_resposta' => (string) env('FISCAL_NFSE_CAMPO_RESPOSTA', 'nfseXmlGZipB64'),
            // Emissao sincrona: o ADN monta a nota antes de responder, entao o
            // teto e' generoso de proposito. Cortar cedo demais e' o caminho
            // para o pior caso — nota emitida la', rascunho aqui.
            'timeout' => (int) env('FISCAL_NFSE_TIMEOUT', 60),
            'connect_timeout' => (int) env('FISCAL_NFSE_CONNECT_TIMEOUT', 15),
        ],
    ],
];
