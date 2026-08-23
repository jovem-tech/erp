<?php

namespace App\Services\Backups;

use App\Services\Backups\Contracts\ProcessRunner;
use Symfony\Component\Process\Process;

class SymfonyProcessRunner implements ProcessRunner
{
    public function runShell(string $script, array $env = [], ?int $timeout = null): ProcessResult
    {
        // -o pipefail e obrigatorio: sem ele `mysqldump | gzip | openssl`
        // devolve o codigo do ULTIMO comando, e uma falha do mysqldump produz
        // um arquivo truncado que passa em todas as verificacoes seguintes.
        return $this->run(['bash', '-o', 'pipefail', '-c', $script], $env, $timeout);
    }

    public function run(array $command, array $env = [], ?int $timeout = null): ProcessResult
    {
        $process = new Process($command, null, $env === [] ? null : $env);
        $process->setTimeout($timeout);
        $process->run();

        return new ProcessResult(
            (int) $process->getExitCode(),
            $process->getOutput(),
            $process->getErrorOutput(),
        );
    }
}
