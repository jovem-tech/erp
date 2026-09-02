<?php

namespace App\Console\Commands\Fiscal;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Regera `resources/data/municipios-ibge.php` a partir do serviço do IBGE.
 *
 * A tabela existe porque a NT-008 manda o DANFSe imprimir o NOME do município
 * (item 2.4.5, "utilizar a descrição destes códigos") e o XML da NFS-e só traz
 * o código de 7 dígitos.
 *
 * É um arquivo gerado e versionado, e não uma consulta em tempo de execução, de
 * propósito: emitir DANFSe não pode depender de um serviço externo estar no ar,
 * e a lista muda de raro em raro — a última criação de município no Brasil é de
 * 2013. Rodar isto é manutenção ocasional, não rotina.
 */
class AtualizarMunicipiosIbge extends Command
{
    protected $signature = 'fiscal:atualizar-municipios-ibge {--dry-run : Mostra o que mudaria sem gravar}';

    protected $description = 'Regera a tabela de municipios do IBGE usada pelo DANFSe.';

    private const FONTE = 'https://servicodados.ibge.gov.br/api/v1/localidades/municipios';

    public function handle(): int
    {
        $this->components->info('Baixando a lista de municipios do IBGE...');

        $resposta = Http::timeout(120)->acceptJson()->get(self::FONTE);

        if (! $resposta->successful()) {
            $this->components->error('O servico do IBGE respondeu '.$resposta->status().'.');

            return self::FAILURE;
        }

        $municipios = [];

        foreach ((array) $resposta->json() as $municipio) {
            $codigo = (int) ($municipio['id'] ?? 0);
            $nome = trim((string) ($municipio['nome'] ?? ''));

            if ($codigo > 0 && $nome !== '') {
                $municipios[$codigo] = $nome;
            }
        }

        if (count($municipios) < 5000) {
            // Uma resposta truncada gravada por cima da tabela boa apagaria
            // metade dos municipios do pais sem ninguem notar ate' sair um
            // DANFSe com o campo vazio.
            $this->components->error(
                sprintf('Vieram so %d municipios — resposta incompleta. Nada foi gravado.', count($municipios))
            );

            return self::FAILURE;
        }

        ksort($municipios);

        $caminho = resource_path('data/municipios-ibge.php');
        $atual = is_readable($caminho) ? (array) require $caminho : [];

        $this->components->info(sprintf(
            '%d municipios (a tabela atual tem %d).',
            count($municipios),
            count($atual)
        ));

        if ($this->option('dry-run')) {
            foreach (array_diff_key($municipios, $atual) as $codigo => $nome) {
                $this->line(sprintf('  + %d %s', $codigo, $nome));
            }

            foreach (array_diff_key($atual, $municipios) as $codigo => $nome) {
                $this->line(sprintf('  - %d %s', $codigo, $nome));
            }

            return self::SUCCESS;
        }

        file_put_contents($caminho, $this->arquivo($municipios));

        $this->components->info('Tabela regravada em resources/data/municipios-ibge.php.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $municipios
     */
    private function arquivo(array $municipios): string
    {
        $linhas = '';

        foreach ($municipios as $codigo => $nome) {
            $linhas .= sprintf("    %d => '%s',\n", $codigo, addcslashes($nome, "'\\"));
        }

        return <<<PHP
        <?php
        /**
         * Tabela IBGE de municipios (codigo de 7 digitos -> nome), gerada a partir do
         * servico oficial do IBGE:
         * https://servicodados.ibge.gov.br/api/v1/localidades/municipios
         *
         * Existe porque a NT-008 manda imprimir no DANFSe o NOME do municipio ("Utilizar
         * a descricao destes codigos") e o XML da NFS-e so' traz `cMun`. Para o emitente
         * o proprio XML traz `xLocEmi`; para tomador, destinatario e intermediario nao
         * ha nome nenhum no arquivo — sem esta tabela o campo obrigatorio "Municipio /
         * Sigla UF" ficaria em branco para qualquer cliente de fora da cidade.
         *
         * A sigla da UF NAO fica aqui: sai dos dois primeiros digitos do codigo
         * (App\\Support\\MunicipioIbge::uf()), que e' exato e nao duplica dado.
         *
         * Arquivo GERADO — nao editar a mao. Para atualizar:
         *   php artisan fiscal:atualizar-municipios-ibge
         *
         * Gerado em: {$this->hoje()} ({$this->contar($municipios)} municipios)
         */

        return [
        {$linhas}];

        PHP;
    }

    private function hoje(): string
    {
        return now()->format('Y-m-d');
    }

    /**
     * @param  array<int, string>  $municipios
     */
    private function contar(array $municipios): int
    {
        return count($municipios);
    }
}
