<?php

namespace App\Services\Backups;

class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /** Mensagem curta e util para log/UI, sem despejar megabytes de saida. */
    public function failureMessage(): string
    {
        $stderr = trim($this->errorOutput);
        if ($stderr === '') {
            $stderr = trim($this->output);
        }

        $stderr = preg_replace('/\s+/', ' ', $stderr) ?? '';

        return sprintf(
            'código %d%s',
            $this->exitCode,
            $stderr === '' ? '' : ': '.mb_substr($stderr, 0, 500)
        );
    }
}
