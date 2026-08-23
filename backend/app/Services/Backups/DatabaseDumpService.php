<?php

namespace App\Services\Backups;

use App\Services\Backups\Contracts\ProcessRunner;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DatabaseDumpService
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly ArchiveCipher $cipher,
    ) {}

    /**
     * Sonda a conexao antes de qualquer tentativa de dump.
     *
     * Na bancada o `sistema_erp_chat` nao existe e o usuario erp_app nao tem
     * grant para ele. Ausencia precisa virar aviso no manifesto, nunca falha
     * do backup inteiro - senao o sistema nunca produziria backup nenhum.
     *
     * @return array{ok: bool, database: string|null, driver: string|null, reason: string|null, engines: array<string, int>}
     */
    public function probe(string $connection): array
    {
        $config = (array) config('database.connections.'.$connection, []);
        $driver = isset($config['driver']) ? (string) $config['driver'] : null;
        $database = isset($config['database']) ? (string) $config['database'] : null;

        $base = ['ok' => false, 'database' => $database, 'driver' => $driver, 'reason' => null, 'engines' => []];

        if ($config === []) {
            return ['reason' => 'conexão não configurada'] + $base;
        }

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return ['reason' => sprintf('driver "%s" não suportado para dump', (string) $driver)] + $base;
        }

        try {
            $exists = DB::connection($connection)->select(
                'SELECT SCHEMA_NAME FROM information_schema.schemata WHERE SCHEMA_NAME = ? LIMIT 1',
                [$database]
            );

            if ($exists === []) {
                return ['reason' => 'banco inexistente ou sem permissão de acesso'] + $base;
            }

            // Auditoria de engine em tempo de execucao: --single-transaction so
            // garante consistencia em InnoDB. Hoje as 200 tabelas sao InnoDB,
            // mas o banco e legado e compartilhado, entao a checagem fica.
            $engines = [];
            foreach (DB::connection($connection)->select(
                'SELECT engine AS eng, COUNT(*) AS total FROM information_schema.tables '
                .'WHERE table_schema = ? AND engine IS NOT NULL GROUP BY engine',
                [$database]
            ) as $row) {
                $engines[(string) $row->eng] = (int) $row->total;
            }

            return ['ok' => true, 'database' => $database, 'driver' => $driver, 'reason' => null, 'engines' => $engines];
        } catch (Throwable $exception) {
            return ['reason' => $this->summarize($exception->getMessage())] + $base;
        }
    }

    /**
     * Gera o dump cifrado de uma conexao.
     *
     * @return array{caminho: string, bytes: int, sha256: string, tabelas_sem_dados: array<int, string>}
     */
    public function dump(string $connection, string $targetPath, string $passphrase, string $workDir): array
    {
        $config = (array) config('database.connections.'.$connection, []);
        $database = (string) ($config['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException(sprintf('Conexão "%s" não define um banco de dados.', $connection));
        }

        $credentialsFile = $this->writeCredentialsFile($config, $workDir);

        try {
            $structureOnly = $this->existingStructureOnlyTables($connection, $database);
            $options = implode(' ', array_map(
                'escapeshellarg',
                (array) config('backup.databases.mysqldump_options', [])
            ));

            $ignore = '';
            foreach ($structureOnly as $table) {
                $ignore .= ' --ignore-table='.escapeshellarg($database.'.'.$table);
            }

            $dumpData = sprintf(
                'mysqldump --defaults-extra-file=%s %s%s %s',
                escapeshellarg($credentialsFile),
                $options,
                $ignore,
                escapeshellarg($database),
            );

            // As tabelas excluidas acima entram so como estrutura. file_scan_runs
            // sozinha e 72% do banco (320 MB) e e telemetria regeneravel do
            // scanner - carregar isso em toda copia seria desperdicio puro.
            $dumpStructure = '';
            if ($structureOnly !== []) {
                $dumpStructure = sprintf(
                    '; mysqldump --defaults-extra-file=%s --no-data --no-tablespaces --skip-add-drop-table %s %s',
                    escapeshellarg($credentialsFile),
                    escapeshellarg($database),
                    implode(' ', array_map('escapeshellarg', $structureOnly)),
                );
            }

            $script = sprintf(
                '{ %s%s ; } | gzip -6 | %s > %s',
                $dumpData,
                $dumpStructure,
                $this->cipher->encryptFilter(),
                escapeshellarg($targetPath),
            );

            $result = $this->runner->runShell($script, $this->cipher->env($passphrase));

            if (! $result->successful()) {
                throw new RuntimeException(sprintf(
                    'Falha ao gerar o dump de "%s" (%s).',
                    $database,
                    $result->failureMessage(),
                ));
            }

            $this->assertDumpIsComplete($targetPath, $passphrase, $database);

            return [
                'caminho' => $targetPath,
                'bytes' => (int) filesize($targetPath),
                'sha256' => (string) hash_file('sha256', $targetPath),
                'tabelas_sem_dados' => $structureOnly,
            ];
        } finally {
            if (is_file($credentialsFile)) {
                @unlink($credentialsFile);
            }
        }
    }

    /**
     * Verifica que o dump esta INTEIRO, nao apenas que e um gzip valido.
     *
     * Verificado na pratica: um mysqldump que falha deixa um arquivo de 20
     * bytes que passa tranquilamente no `gzip -t`. A unica prova real de que o
     * dump terminou e o rodape "-- Dump completed" que o mysqldump escreve.
     */
    private function assertDumpIsComplete(string $path, string $passphrase, string $database): void
    {
        $script = sprintf(
            '%s -in %s | gunzip | tail -c 400',
            $this->cipher->decryptFilter(),
            escapeshellarg($path),
        );

        $result = $this->runner->runShell($script, $this->cipher->env($passphrase));

        if (! $result->successful()) {
            throw new RuntimeException(sprintf(
                'O dump de "%s" não pôde ser lido de volta (%s).',
                $database,
                $result->failureMessage(),
            ));
        }

        if (! str_contains($result->output, 'Dump completed')) {
            throw new RuntimeException(sprintf(
                'O dump de "%s" está truncado: o rodapé "Dump completed" não foi encontrado. '
                .'O arquivo foi descartado.',
                $database,
            ));
        }
    }

    /** @return array<int, string> */
    private function existingStructureOnlyTables(string $connection, string $database): array
    {
        $configured = array_values(array_filter(
            (array) config('backup.databases.structure_only_tables', []),
            static fn ($table): bool => is_string($table) && $table !== ''
        ));

        if ($configured === []) {
            return [];
        }

        try {
            $rows = DB::connection($connection)->select(
                'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ?',
                [$database]
            );
        } catch (Throwable) {
            return [];
        }

        $present = array_map(static fn ($row): string => (string) $row->t, $rows);

        return array_values(array_intersect($configured, $present));
    }

    /** @param array<string, mixed> $config */
    private function writeCredentialsFile(array $config, string $workDir): string
    {
        $path = $workDir.'/.mysql-'.bin2hex(random_bytes(8)).'.cnf';

        // Criado antes de receber conteudo e restrito de imediato: a senha
        // nunca fica legivel, nem por um instante, para outro usuario.
        if (file_put_contents($path, '') === false) {
            throw new RuntimeException('Não foi possível preparar o arquivo de credenciais do MySQL.');
        }

        chmod($path, 0600);

        $contents = "[client]\n"
            .'user="'.$this->escapeIni((string) ($config['username'] ?? ''))."\"\n"
            .'password="'.$this->escapeIni((string) ($config['password'] ?? ''))."\"\n"
            .'host="'.$this->escapeIni((string) ($config['host'] ?? '127.0.0.1'))."\"\n"
            .'port='.(int) ($config['port'] ?? 3306)."\n";

        file_put_contents($path, $contents);

        return $path;
    }

    private function escapeIni(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    private function summarize(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return mb_substr(trim($message), 0, 200);
    }
}
