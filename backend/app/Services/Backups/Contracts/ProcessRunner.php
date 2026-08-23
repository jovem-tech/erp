<?php

namespace App\Services\Backups\Contracts;

use App\Services\Backups\ProcessResult;

interface ProcessRunner
{
    /**
     * Executa um script de shell.
     *
     * Toda a canalizacao do backup passa por aqui por dois motivos:
     * 1. backend/phpunit.xml forca DB_CONNECTION=sqlite :memory:, entao
     *    mysqldump nunca pode rodar na suite - os testes trocam esta
     *    implementacao por uma falsa.
     * 2. centraliza a garantia de "pipefail": sem ela, `mysqldump | gzip`
     *    devolve o codigo do gzip, e um dump truncado passa no gzip -t.
     *
     * @param  array<string, string>  $env
     */
    public function runShell(string $script, array $env = [], ?int $timeout = null): ProcessResult;

    /** @param array<int, string> $command */
    public function run(array $command, array $env = [], ?int $timeout = null): ProcessResult;
}
