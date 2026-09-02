<?php

namespace App\Console\Commands\Fiscal;

use App\Services\Fiscal\ProntidaoFiscalService;
use Illuminate\Console\Command;

/**
 * Conta o que falta no cadastro para conseguir emitir nota fiscal.
 *
 * Mesmo diagnóstico que a tela do desktop mostra — serviço único, duas saídas.
 * O `--json` existe pelo mesmo motivo do `file-manager:diagnose`: dá para
 * acompanhar a evolução do número sem abrir o navegador.
 */
class DiagnoseProntidaoFiscal extends Command
{
    protected $signature = 'fiscal:prontidao {--json : Retorna o diagnostico como JSON}';

    protected $description = 'Conta clientes sem CPF/CNPJ ou com documento invalido, medindo o quanto falta para emitir nota.';

    public function handle(ProntidaoFiscalService $servico): int
    {
        $resultado = $servico->verificar();

        if ($this->option('json')) {
            $this->line((string) json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $areas = $resultado['areas'];

        $this->components->info('Prontidao fiscal');
        $this->table(
            ['Area', 'Total', 'Pendentes', 'Prontos', '%'],
            [
                ['Empresa (campos)', $areas['empresa']['total'], $areas['empresa']['pendencias'], $areas['empresa']['prontos'], $areas['empresa']['percentual_pronto']],
                ['Clientes', $areas['clientes']['total'], $areas['clientes']['pendencias'], $areas['clientes']['prontos'], $areas['clientes']['percentual_pronto']],
                ['Servicos ativos', $areas['servicos']['total'], $areas['servicos']['pendencias'], $areas['servicos']['prontos'], $areas['servicos']['percentual_pronto']],
                ['Pecas ativas', $areas['pecas']['total'], $areas['pecas']['pendencias'], $areas['pecas']['prontos'], $areas['pecas']['percentual_pronto']],
            ]
        );

        if ($areas['clientes']['documento_invalido'] > 0) {
            $this->components->warn(sprintf(
                '%d cliente(s) com CPF/CNPJ invalido ja gravado — erro de digitacao, nao dado ausente.',
                $areas['clientes']['documento_invalido']
            ));
        }

        if ($areas['empresa']['campos_faltando'] !== []) {
            $this->components->warn('Empresa, campos faltando: '.implode(', ', $areas['empresa']['campos_faltando']));
        }

        if ($resultado['pronto']) {
            $this->components->info('Nenhuma pendencia de cadastro.');

            return self::SUCCESS;
        }

        $this->components->warn(sprintf(
            '%d pendencia(s) no total. A NFS-e exige identificar o tomador e classificar o que foi vendido.',
            $resultado['pendencias_totais']
        ));

        // Sai 0 de proposito: pendencia de cadastro nao e' falha de execucao, e
        // um exit code diferente de zero quebraria qualquer agendamento que
        // rodasse isto junto de outras verificacoes.
        return self::SUCCESS;
    }
}
