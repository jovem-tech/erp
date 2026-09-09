<?php

namespace App\Services\Fiscal;

use Throwable;

/**
 * Entrega o certificado A1 ao cURL — que so' aceita CAMINHO DE ARQUIVO.
 *
 * Este e' o unico ponto do sistema onde a chave privada toca o disco em texto
 * claro, e existe porque nao ha' alternativa: `CertificadoA1::pem()` devolve
 * PEM em memoria, e as opcoes `cert`/`ssl_key` do Guzzle sao repassadas ao
 * cURL, que le' de arquivo. A integracao do Inter nao tem esse atrito porque
 * la' o certificado ja' nasce em disco, em `.crt`/`.key` separados.
 *
 * A API e' um callback de proposito. Se esta classe expusesse `caminho()` e
 * `descartar()`, mais cedo ou mais tarde alguem sairia por um `return` ou uma
 * excecao no meio e deixaria a chave privada esquecida em disco. Com
 * `comOpcoesTls()` o `finally` e' nosso, nao de quem chama.
 *
 * O arquivo carrega, nesta ordem: certificado folha, cadeia intermediaria e
 * chave privada — o formato que o cURL espera quando `cert` e `ssl_key`
 * apontam para o mesmo arquivo.
 */
class CertificadoPemTemporario
{
    public function __construct(private readonly CertificadoA1 $certificado) {}

    /**
     * Materializa o PEM, roda o callback com as opcoes de TLS e apaga o
     * arquivo — mesmo que o callback exploda.
     *
     * @template T
     *
     * @param  callable(array<string, string>): T  $callback
     * @return T
     *
     * @throws NfseException quando o certificado nao abre
     */
    public function comOpcoesTls(callable $callback): mixed
    {
        $material = $this->certificado->pemComCadeia();

        if ($material === null) {
            throw NfseException::local(
                'Certificado A1 nao pode ser aberto — confira se esta instalado e se a senha esta correta '
                .'em Configuracoes > Integracoes.'
            );
        }

        $caminho = $this->gravar($material);

        try {
            return $callback(['cert' => $caminho, 'ssl_key' => $caminho]);
        } finally {
            $this->apagar($caminho);
        }
    }

    /**
     * @param  array{cert: string, pkey: string, cadeia: array<int, string>}  $material
     */
    private function gravar(array $material): string
    {
        // `storage/framework/cache` e nao `/tmp`: o temp do sistema e' legivel
        // por todo usuario da maquina, e o que esta aqui dentro e' a identidade
        // juridica da empresa. Mesmo motivo pelo qual o `.pfx` mora fora do
        // webroot com 0600 (ver config/fiscal.php).
        $diretorio = storage_path('framework/cache');

        if (! is_dir($diretorio)) {
            @mkdir($diretorio, 0700, true);
        }

        $caminho = $diretorio.'/nfse-mtls-'.bin2hex(random_bytes(12)).'.pem';

        // Cria com 0600 ANTES de escrever. Criar e depois dar chmod deixa uma
        // janela — curta, mas real — em que o arquivo esta' legivel por outros.
        $handle = @fopen($caminho, 'x');

        if ($handle === false) {
            throw NfseException::local('Nao foi possivel preparar o certificado para a conexao segura.');
        }

        @chmod($caminho, 0600);

        $conteudo = rtrim($material['cert'])."\n";

        foreach ($material['cadeia'] as $intermediario) {
            $conteudo .= rtrim($intermediario)."\n";
        }

        $conteudo .= rtrim($material['pkey'])."\n";

        $escrito = @fwrite($handle, $conteudo);
        @fclose($handle);

        if ($escrito === false || $escrito === 0) {
            $this->apagar($caminho);

            throw NfseException::local('Nao foi possivel preparar o certificado para a conexao segura.');
        }

        return $caminho;
    }

    private function apagar(string $caminho): void
    {
        try {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        } catch (Throwable) {
            // Apagar e' melhor-esforco: falhar aqui nao pode mascarar o erro
            // real da transmissao, que e' o que o operador precisa ler.
        }
    }
}
