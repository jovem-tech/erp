<?php

namespace App\Console\Commands\Fiscal;

use App\Services\Fiscal\SequenciaDps;
use Illuminate\Console\Command;

/**
 * Mostra e reposiciona o contador de `nDPS`.
 *
 * Existe por causa de um caso que so' aparece na virada: a empresa ja' emitia
 * NFS-e pelo portal do gov.br antes de ligar a emissao automatica. Aquelas DPS
 * consumiram numeros que este banco nunca viu — comecar do 1 produziria um `Id`
 * de DPS ja' usado, e o Ambiente Nacional recusaria.
 *
 * O numero a informar e' o da ULTIMA DPS emitida na serie, lido no Emissor
 * Nacional. O proximo envio usa esse valor + 1.
 */
class SequenciaDpsCommand extends Command
{
    protected $signature = 'fiscal:sequencia-dps
        {--serie= : Serie da DPS (padrao: a configurada em fiscal.nfse.serie)}
        {--definir= : Reposiciona o contador para este numero (o ultimo JA usado)}';

    protected $description = 'Mostra ou reposiciona o contador de nDPS por serie.';

    public function handle(SequenciaDps $sequencia): int
    {
        $serie = trim((string) ($this->option('serie') ?: config('fiscal.nfse.serie', '00001')));
        $definir = $this->option('definir');

        if ($definir !== null) {
            if (! is_numeric($definir) || (int) $definir < 0) {
                $this->error('O valor de --definir precisa ser um numero inteiro nao negativo.');

                return self::FAILURE;
            }

            $anterior = $sequencia->atual($serie);
            $sequencia->definir($serie, (int) $definir);

            $this->info(sprintf(
                'Serie %s: contador ajustado de %d para %d. A proxima DPS sai com nDPS %d.',
                $serie,
                $anterior,
                (int) $definir,
                (int) $definir + 1
            ));

            if ((int) $definir < $anterior) {
                // Nao bloqueamos: pode ser correcao legitima de um contador que
                // avancou a toa. Mas quem faz isso precisa saber o que arrisca.
                $this->warn('Atencao: o contador ANDOU PARA TRAS. Se aqueles numeros ja foram transmitidos, '
                    .'as proximas emissoes serao recusadas por Id de DPS repetido.');
            }

            return self::SUCCESS;
        }

        $atual = $sequencia->atual($serie);

        $this->info(sprintf(
            'Serie %s: ultima DPS emitida por este sistema = %d. A proxima sai com nDPS %d.',
            $serie,
            $atual,
            $atual + 1
        ));

        if ($atual === 0) {
            $this->warn('O contador esta em zero. Se a empresa ja emitiu NFS-e pelo portal do gov.br, '
                .'confira no Emissor Nacional qual foi a ultima DPS desta serie e ajuste com --definir '
                .'ANTES de emitir em producao.');
        }

        return self::SUCCESS;
    }
}
