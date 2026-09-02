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
    ],
];
