<?php

namespace Tests\Support;

use App\Services\Backups\Contracts\ProcessRunner;
use App\Services\Backups\ProcessResult;

/**
 * Dublê do executor de processos.
 *
 * backend/phpunit.xml força DB_CONNECTION=sqlite :memory:, então mysqldump
 * nunca pode rodar na suíte. Toda a canalização do backup passa pelo
 * ProcessRunner justamente para que os testes possam substituí-la aqui.
 */
class FakeProcessRunner implements ProcessRunner
{
    /** @var array<int, array{tipo: string, comando: string}> */
    public array $calls = [];

    /** @var array<string, ProcessResult> */
    private array $responses = [];

    private ProcessResult $default;

    public function __construct()
    {
        $this->default = new ProcessResult(0, '', '');
    }

    /** Responde com um resultado próprio quando o comando contiver $needle. */
    public function respondTo(string $needle, int $exitCode, string $output = '', string $error = ''): self
    {
        $this->responses[$needle] = new ProcessResult($exitCode, $output, $error);

        return $this;
    }

    public function runShell(string $script, array $env = [], ?int $timeout = null): ProcessResult
    {
        $this->calls[] = ['tipo' => 'shell', 'comando' => $script];

        return $this->resolve($script);
    }

    public function run(array $command, array $env = [], ?int $timeout = null): ProcessResult
    {
        $joined = implode(' ', $command);
        $this->calls[] = ['tipo' => 'exec', 'comando' => $joined];

        return $this->resolve($joined);
    }

    public function commandsMatching(string $needle): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => str_contains($call['comando'], $needle)
        ));
    }

    private function resolve(string $command): ProcessResult
    {
        foreach ($this->responses as $needle => $response) {
            if (str_contains($command, $needle)) {
                return $response;
            }
        }

        return $this->default;
    }
}
