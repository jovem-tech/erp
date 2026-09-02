<?php

namespace App\Console\Commands\Fiscal;

use App\Services\Fiscal\CertificadoA1;
use Illuminate\Console\Command;

/**
 * Avisa antes de o certificado A1 vencer.
 *
 * Mesma razão de existir do `inter:verificar-certificado`: o A1 vale um ano e
 * expira em SILÊNCIO — a emissão simplesmente para de autenticar, e o primeiro
 * sinal costuma ser uma nota que não sai no fim do mês. Rodar isso diariamente
 * troca essa descoberta pelo aviso antecipado.
 */
class VerificarCertificadoFiscal extends Command
{
    protected $signature = 'fiscal:verificar-certificado {--json : Retorna o diagnostico como JSON}';

    protected $description = 'Verifica validade e legibilidade do certificado A1 usado na emissao fiscal.';

    public function handle(CertificadoA1 $certificado): int
    {
        $problemas = $certificado->problemas();
        $dias = $certificado->diasAteVencimento();
        $expiraEm = $certificado->expiraEm();
        $alertaDias = (int) config('fiscal.certificado.alerta_dias', 30);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'configurado' => $certificado->existe(),
                'usavel' => $problemas === [],
                'problemas' => $problemas,
                'titular' => $certificado->nomeTitular(),
                'documento_titular' => $certificado->documentoTitular(),
                'expira_em' => $expiraEm?->toDateString(),
                'dias_ate_vencimento' => $dias,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (! $certificado->existe()) {
            $this->line('Certificado A1 nao configurado — a emissao continua no modo assistido (portal gov.br).');
            $this->line('Para configurar: FISCAL_CERT_PFX_PATH e FISCAL_CERT_SENHA no .env.');

            return self::SUCCESS;
        }

        $this->components->info('Certificado A1');
        $this->table(['Campo', 'Valor'], [
            ['Titular', $certificado->nomeTitular() ?? '—'],
            ['CNPJ/CPF', $certificado->documentoTitular() ?? '—'],
            ['Expira em', $expiraEm?->format('d/m/Y') ?? 'nao foi possivel ler'],
            ['Dias restantes', $dias ?? '—'],
        ]);

        if ($problemas !== []) {
            foreach ($problemas as $problema) {
                $this->components->error($problema);
            }

            return self::FAILURE;
        }

        if ($dias !== null && $dias <= $alertaDias) {
            $this->components->warn(sprintf(
                'Certificado vence em %d dia(s). Renove antes: vencido, a emissao para de autenticar sem aviso.',
                $dias
            ));

            return self::SUCCESS;
        }

        $this->components->info('Certificado usavel.');

        return self::SUCCESS;
    }
}
