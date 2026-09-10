<?php

namespace App\Services\Fiscal;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fala com o Ambiente Nacional da NFS-e (SEFIN Nacional) por mTLS.
 *
 * O certificado A1 nao vai em header nem em token: ele E' a credencial,
 * apresentada no proprio handshake TLS. Quem tem o `.pfx` da empresa emite em
 * nome dela — por isso o material temporario e' 0600 e some no `finally`
 * (`CertificadoPemTemporario`).
 *
 * O corpo trafega comprimido nos dois sentidos: a DPS assinada vai
 * gzip+base64, e a NFS-e autorizada volta pelo mesmo caminho. Os nomes dos
 * campos e o path vivem em `config/fiscal.php` porque o Swagger oficial exige
 * certificado para abrir — se o contrato divergir, o conserto e' `.env`.
 *
 * ⚠️ **O POST de emissao nao tem retry, e isso e' deliberado.** Repetir um POST
 * que talvez tenha sido processado emite a nota duas vezes, e nota fiscal
 * duplicada se resolve com pedido de cancelamento, nao com `Ctrl+Z`. Quando a
 * chamada morre sem resposta, quem decide o que fazer e' o `EmissaoNfseService`
 * — consultando antes de reenviar. So' o GET repete sozinho.
 */
class AmbienteNacionalClient
{
    public function __construct(
        private readonly CertificadoPemTemporario $material,
        private readonly AmbienteFiscal $ambiente,
    ) {}

    /**
     * Transmite a DPS assinada e devolve o XML da NFS-e autorizada.
     *
     * @throws NfseException
     */
    public function emitir(string $dpsXmlAssinado): string
    {
        $config = $this->config();
        $url = $this->baseUrl().$config['path_emissao'];

        $response = $this->material->comOpcoesTls(
            fn (array $tls): Response => $this->enviar(
                fn () => Http::acceptJson()
                    ->withOptions($tls)
                    ->timeout($config['timeout'])
                    ->connectTimeout($config['connect_timeout'])
                    // Sem ->retry(): ver o aviso na doc da classe.
                    ->post($url, [$config['campo_dps'] => $this->comprimir($dpsXmlAssinado)]),
                'POST',
                $config['path_emissao']
            )
        );

        if (! $response->successful()) {
            $this->estourar($response, 'POST', $config['path_emissao']);
        }

        $xml = $this->extrairXml($response, $config['campo_resposta']);

        Log::channel('fiscal')->info('[NFSE] Nota autorizada pelo Ambiente Nacional.', [
            'status' => $response->status(),
            'bytes_xml' => strlen($xml),
        ]);

        return $xml;
    }

    /**
     * A NFS-e que o ADN gerou para esta DPS, se ja' gerou.
     *
     * Existe para uma situacao especifica e perigosa: a transmissao caiu depois
     * que o ADN processou. Sem esta consulta, a segunda tentativa emitiria uma
     * nota duplicada; com ela, o sistema descobre que a nota ja' existe e so'
     * registra o que ja' esta' la'.
     *
     * `null` = o ADN nao conhece essa DPS, entao e' seguro transmitir.
     *
     * @throws NfseException
     */
    public function consultarPorIdDps(string $idDps): ?string
    {
        $config = $this->config();
        $path = str_replace('{idDps}', rawurlencode($idDps), $config['path_consulta_dps']);
        $url = $this->baseUrl().$path;

        $response = $this->material->comOpcoesTls(
            fn (array $tls): Response => $this->enviar(
                fn () => Http::acceptJson()
                    ->withOptions($tls)
                    ->timeout($config['timeout'])
                    ->connectTimeout($config['connect_timeout'])
                    // Consulta e' idempotente: repetir e' seguro e desejavel.
                    ->retry(3, 500, throw: false)
                    ->get($url),
                'GET',
                $path
            )
        );

        // 404 aqui e' resposta, nao erro: significa "essa DPS nao virou nota".
        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            $this->estourar($response, 'GET', $path);
        }

        // A consulta por DPS devolve so' a chave de acesso — nao o XML. O
        // documento em si vem num segundo passo, por `/nfse/{chave}`. Tratar
        // esta resposta como se trouxesse o XML fazia a consulta "falhar" e o
        // sistema seguir para o envio, colhendo E0014 (DPS ja' existe) — ou
        // seja, a protecao contra duplicidade nao protegia nada.
        $json = $response->json();
        $chave = is_array($json) ? $this->chaveDaResposta($json) : null;

        if ($chave === null) {
            Log::channel('fiscal')->error('[NFSE] Consulta por DPS sem chave de acesso.', [
                'corpo' => mb_substr($response->body(), 0, 1000),
            ]);

            throw NfseException::local(
                'A consulta por DPS respondeu sem a chave de acesso da nota.',
                ['status' => $response->status()]
            );
        }

        return $this->consultarPorChave($chave);
    }

    /**
     * XML da NFS-e a partir da chave de acesso.
     *
     * @throws NfseException
     */
    public function consultarPorChave(string $chave): ?string
    {
        $config = $this->config();
        $path = str_replace('{chave}', rawurlencode($chave), $config['path_consulta_nfse']);
        $url = $this->baseUrl().$path;

        $response = $this->material->comOpcoesTls(
            fn (array $tls): Response => $this->enviar(
                fn () => Http::acceptJson()
                    ->withOptions($tls)
                    ->timeout($config['timeout'])
                    ->connectTimeout($config['connect_timeout'])
                    ->retry(3, 500, throw: false)
                    ->get($url),
                'GET',
                $path
            )
        );

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            $this->estourar($response, 'GET', $path);
        }

        return $this->extrairXml($response, $config['campo_resposta']);
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function chaveDaResposta(array $json): ?string
    {
        $json = array_change_key_case($json, CASE_LOWER);

        foreach (['chaveacesso', 'chave', 'chnfse'] as $campo) {
            $valor = trim((string) ($json[$campo] ?? ''));

            if ($valor !== '') {
                return $valor;
            }
        }

        return null;
    }

    /**
     * @param  callable(): Response  $chamada
     *
     * @throws NfseException
     */
    private function enviar(callable $chamada, string $metodo, string $path): Response
    {
        try {
            return $chamada();
        } catch (Throwable $e) {
            Log::channel('fiscal')->error('[NFSE] Falha de transporte.', [
                'metodo' => $metodo,
                'path' => $path,
                'erro' => $e->getMessage(),
            ]);

            // statusHttp null => ehFalhaTemporaria(). O chamador vai consultar
            // antes de tentar de novo, porque "nao respondeu" nao e' o mesmo
            // que "nao processou".
            throw new NfseException(
                'Nao foi possivel falar com o Ambiente Nacional: '.$e->getMessage(),
                null,
                ['metodo' => $metodo, 'path' => $path],
                $e
            );
        }
    }

    /**
     * DPS assinada -> gzip -> base64, o formato que o ADN espera.
     *
     * @throws NfseException
     */
    private function comprimir(string $xml): string
    {
        $gzip = @gzencode($xml, 9);

        if ($gzip === false) {
            throw NfseException::local('Falha ao comprimir a DPS para envio.');
        }

        return base64_encode($gzip);
    }

    /**
     * Caminho inverso: base64 -> gunzip -> XML.
     *
     * @throws NfseException
     */
    private function extrairXml(Response $response, string $campo): string
    {
        $json = $response->json();

        if (! is_array($json) || ! isset($json[$campo]) || ! is_string($json[$campo]) || $json[$campo] === '') {
            Log::channel('fiscal')->error('[NFSE] Resposta sem o campo esperado.', [
                'campo' => $campo,
                'corpo' => mb_substr($response->body(), 0, 1000),
            ]);

            throw NfseException::local(
                "O Ambiente Nacional respondeu num formato inesperado (campo '{$campo}' ausente). "
                .'Confira o contrato da API em config/fiscal.php.',
                ['status' => $response->status()]
            );
        }

        $bruto = base64_decode($json[$campo], true);

        if ($bruto === false) {
            throw NfseException::local('O Ambiente Nacional devolveu um conteudo base64 invalido.');
        }

        $xml = @gzdecode($bruto);

        if ($xml === false) {
            // Tolerancia proposital: se um dia o ADN devolver o XML sem
            // comprimir, nao ha' razao para falhar — o conteudo esta' la'.
            $xml = str_starts_with(ltrim($bruto), '<') ? $bruto : false;
        }

        if ($xml === false || trim((string) $xml) === '') {
            throw NfseException::local('O Ambiente Nacional devolveu um conteudo ilegivel no lugar do XML da nota.');
        }

        $this->conferirChave($json, (string) $xml);

        return (string) $xml;
    }

    /**
     * A resposta traz a chave de acesso ao lado do XML. Se as duas divergirem,
     * alguma coisa esta' muito errada — registrar o par certo importa porque a
     * chave e' o que identifica a nota perante o fisco.
     *
     * @param  array<string, mixed>  $json
     */
    private function conferirChave(array $json, string $xml): void
    {
        foreach (['chaveAcesso', 'chave', 'chNFSe'] as $campo) {
            $chave = trim((string) ($json[$campo] ?? ''));

            if ($chave === '') {
                continue;
            }

            if (! str_contains($xml, $chave)) {
                Log::channel('fiscal')->warning('[NFSE] Chave da resposta nao aparece no XML devolvido.', [
                    'chave_resposta' => $chave,
                ]);
            }

            return;
        }
    }

    /**
     * @throws NfseException
     */
    private function estourar(Response $response, string $metodo, string $path): never
    {
        Log::channel('fiscal')->error('[NFSE] Resposta de erro.', [
            'metodo' => $metodo,
            'path' => $path,
            'status' => $response->status(),
            'corpo' => mb_substr($response->body(), 0, 1000),
        ]);

        throw new NfseException(
            $this->mensagemDeErro($response),
            $response->status(),
            ['metodo' => $metodo, 'path' => $path]
        );
    }

    /**
     * O ADN devolve erro em formatos diferentes conforme a camada que recusou.
     * Vale a pena garimpar: "codigo 1234 - serie ja' utilizada" e' acionavel
     * pelo operador; "HTTP 400" nao e'.
     */
    private function mensagemDeErro(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $json = array_change_key_case($json, CASE_LOWER);

            // Lista de erros de validacao do ADN.
            foreach (['erros', 'errors'] as $lista) {
                if (! is_array($json[$lista] ?? null)) {
                    continue;
                }

                $mensagens = [];

                foreach ($json[$lista] as $erro) {
                    if (is_string($erro)) {
                        $mensagens[] = $erro;

                        continue;
                    }

                    if (! is_array($erro)) {
                        continue;
                    }

                    // O ADN devolve `Codigo`/`Descricao` com inicial
                    // MAIUSCULA. Procurar so' a forma minuscula fazia a
                    // mensagem util ("serie fora da faixa do tipo de emissor")
                    // ser descartada e virar um "HTTP 400" que nao diz nada —
                    // justamente o que este metodo existe para evitar.
                    $erro = array_change_key_case($erro, CASE_LOWER);

                    $codigo = trim((string) ($erro['codigo'] ?? $erro['code'] ?? ''));
                    $texto = trim((string) ($erro['descricao'] ?? $erro['mensagem'] ?? $erro['message'] ?? ''));
                    $junto = trim($codigo !== '' ? $codigo.' - '.$texto : $texto);

                    if ($junto !== '') {
                        $mensagens[] = $junto;
                    }
                }

                if ($mensagens !== []) {
                    return 'Ambiente Nacional recusou: '.implode('; ', $mensagens);
                }
            }

            foreach (['mensagem', 'detail', 'title', 'message', 'error_description', 'error'] as $campo) {
                $valor = trim((string) ($json[$campo] ?? ''));

                if ($valor !== '') {
                    return 'Ambiente Nacional: '.$valor.' (HTTP '.$response->status().')';
                }
            }
        }

        return 'Ambiente Nacional respondeu HTTP '.$response->status().'.';
    }

    private function baseUrl(): string
    {
        // O ambiente vem do servico, nao do `config()` cru: a tela pode
        // te-lo mudado, e o valor do banco tem precedencia sobre o `.env`.
        $ambiente = $this->ambiente->atual();
        $urls = (array) config('fiscal.nfse.transmissao.urls', []);
        $url = trim((string) ($urls[$ambiente] ?? ''));

        if ($url === '') {
            throw NfseException::local("Sem URL configurada para o ambiente fiscal {$ambiente}.");
        }

        return rtrim($url, '/');
    }

    /**
     * @return array{path_emissao: string, path_consulta_dps: string, path_consulta_nfse: string, campo_dps: string, campo_resposta: string, timeout: int, connect_timeout: int}
     */
    private function config(): array
    {
        $c = (array) config('fiscal.nfse.transmissao', []);

        return [
            'path_emissao' => '/'.ltrim((string) ($c['path_emissao'] ?? '/nfse'), '/'),
            'path_consulta_dps' => '/'.ltrim((string) ($c['path_consulta_dps'] ?? '/dps/{idDps}'), '/'),
            'path_consulta_nfse' => '/'.ltrim((string) ($c['path_consulta_nfse'] ?? '/nfse/{chave}'), '/'),
            'campo_dps' => (string) ($c['campo_dps'] ?? 'dpsXmlGZipB64'),
            'campo_resposta' => (string) ($c['campo_resposta'] ?? 'nfseXmlGZipB64'),
            'timeout' => max(1, (int) ($c['timeout'] ?? 60)),
            'connect_timeout' => max(1, (int) ($c['connect_timeout'] ?? 15)),
        ];
    }
}
