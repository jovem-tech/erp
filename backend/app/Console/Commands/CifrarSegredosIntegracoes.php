<?php

namespace App\Console\Commands;

use App\Models\Configuration;
use App\Services\Integrations\EmailIntegrationSettingsService;
use App\Services\Integrations\GoogleIntegrationSettingsService;
use App\Services\Integrations\IntegrationSettingsService;
use App\Services\Integrations\PaymentIntegrationSettingsService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use ReflectionClass;

/**
 * Cifra em repouso os segredos de integracao que ficaram em texto puro.
 *
 * Ate' a cifragem passar a valer no upsert dos *SettingsService, o valor era
 * gravado cru em `configuracoes` — e portanto tambem no dump diario do banco,
 * que e' gzip sem cifra. A leitura ja tolera legado (App\Support\SecretSettings
 * ::decrypt), entao o sistema funciona sem rodar isto; o que este comando faz e'
 * TIRAR o texto puro do banco em vez de esperar o proximo save da tela.
 *
 * Idempotente: valor que ja decifra e' pulado.
 */
class CifrarSegredosIntegracoes extends Command
{
    protected $signature = 'integracoes:cifrar-segredos {--dry-run : Apenas relata, sem gravar}';

    protected $description = 'Cifra em repouso os segredos de integracao ainda gravados em texto puro.';

    /** Servicos que declaram SECRET_KEYS e gravam em `configuracoes`. */
    private const SERVICES = [
        IntegrationSettingsService::class,
        PaymentIntegrationSettingsService::class,
        EmailIntegrationSettingsService::class,
        GoogleIntegrationSettingsService::class,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keys = $this->secretKeys();

        $this->info(sprintf('Chaves secretas conhecidas: %d%s', count($keys), $dryRun ? ' (dry-run)' : ''));

        $cifradas = 0;
        $jaCifradas = 0;
        $vazias = 0;

        foreach ($keys as $key) {
            $row = Configuration::query()->where('chave', $key)->first();

            if ($row === null || trim((string) $row->valor) === '') {
                $vazias++;

                continue;
            }

            $valor = (string) $row->valor;

            try {
                Crypt::decryptString($valor);
                $jaCifradas++;

                continue;
            } catch (DecryptException) {
                // Texto puro: e' exatamente o que viemos corrigir.
            }

            $this->line(sprintf('  %-45s %d chars em texto puro', $key, strlen($valor)));

            if (! $dryRun) {
                Configuration::query()
                    ->where('chave', $key)
                    ->update(['valor' => Crypt::encryptString($valor), 'updated_at' => now()]);
            }

            $cifradas++;
        }

        $this->info(sprintf(
            '%s: %d | ja cifradas: %d | vazias/ausentes: %d',
            $dryRun ? 'A cifrar' : 'Cifradas',
            $cifradas,
            $jaCifradas,
            $vazias
        ));

        if ($dryRun && $cifradas > 0) {
            $this->warn('Rode sem --dry-run para aplicar.');
        }

        return self::SUCCESS;
    }

    /**
     * Le as SECRET_KEYS de cada servico em vez de duplicar a lista aqui: uma
     * chave nova declarada no servico entra neste comando sozinha.
     *
     * @return array<int, string>
     */
    private function secretKeys(): array
    {
        $keys = [];

        foreach (self::SERVICES as $service) {
            $constants = (new ReflectionClass($service))->getConstants();

            foreach ((array) ($constants['SECRET_KEYS'] ?? []) as $key) {
                $keys[] = (string) $key;
            }
        }

        return array_values(array_unique($keys));
    }
}
