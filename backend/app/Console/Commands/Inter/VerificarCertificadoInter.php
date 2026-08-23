<?php

namespace App\Console\Commands\Inter;

use App\Services\Integrations\Inter\InterCredentials;
use App\Services\Notifications\OperationalAlertService;
use Illuminate\Console\Command;

/**
 * Avisa antes de o certificado do Banco Inter vencer.
 *
 * Este comando existe por causa da falha classica desta integracao: o
 * certificado expira e a integracao para em SILENCIO — nenhuma cobranca e'
 * emitida, nenhuma baixa acontece, e o primeiro sinal costuma ser um cliente
 * ligando. Rodar isso diariamente troca esse aviso pelo alerta antecipado.
 */
class VerificarCertificadoInter extends Command
{
    protected $signature = 'inter:verificar-certificado {--alertar : Dispara alerta em vez de so relatar}';

    protected $description = 'Verifica a validade do certificado do Banco Inter e alerta antes do vencimento.';

    public function handle(InterCredentials $credentials, OperationalAlertService $alertas): int
    {
        if ($credentials->clientId() === '' && ! is_file($credentials->certPath())) {
            $this->line('Integracao com o Banco Inter nao configurada — nada a verificar.');

            return self::SUCCESS;
        }

        $dias = $credentials->diasAteVencimento();
        $expiraEm = $credentials->certificadoExpiraEm();

        if ($dias === null) {
            // "Nao sei" nao e' "esta valido": arquivo ausente ou ilegivel e'
            // tao grave quanto vencido, porque a integracao nao autentica do
            // mesmo jeito.
            $this->error('Nao foi possivel ler a validade do certificado em '.$credentials->certPath());

            if ($this->option('alertar')) {
                $alertas->urgente(
                    'Certificado do Banco Inter ilegivel',
                    'Nao foi possivel ler a validade do certificado. A integracao nao vai autenticar.',
                    ['caminho' => $credentials->certPath()],
                    'inter:cert:ilegivel'
                );
            }

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Certificado do Inter (%s) expira em %s — %d dia(s).',
            $credentials->ambiente(),
            $expiraEm?->toDateString() ?? '?',
            $dias
        ));

        if ($dias < 0) {
            $this->error('Certificado VENCIDO. A integracao esta fora do ar.');

            if ($this->option('alertar')) {
                $alertas->urgente(
                    'Certificado do Banco Inter VENCIDO',
                    sprintf('Venceu ha %d dia(s). Emissao de cobranca e baixa automatica estao paradas.', abs($dias)),
                    ['expirou_em' => $expiraEm?->toDateString(), 'ambiente' => $credentials->ambiente()],
                    // Dedupe por dia: enquanto nao for renovado, avisa uma vez
                    // por dia em vez de a cada execucao do scheduler.
                    'inter:cert:vencido:'.now()->toDateString()
                );
            }

            return self::FAILURE;
        }

        $limiares = array_map('intval', (array) config('inter.certificado.avisos_dias', [30, 15, 7, 1]));
        sort($limiares);

        foreach ($limiares as $limiar) {
            if ($dias > $limiar) {
                continue;
            }

            $this->warn(sprintf('Vence em %d dia(s) — dentro do limiar de %d.', $dias, $limiar));

            if ($this->option('alertar')) {
                $alertas->urgente(
                    'Certificado do Banco Inter vence em '.$dias.' dia(s)',
                    'Renove no Internet Banking e substitua os arquivos apontados por INTER_CERT_PATH e INTER_KEY_PATH.',
                    [
                        'expira_em' => $expiraEm?->toDateString(),
                        'dias_restantes' => $dias,
                        'ambiente' => $credentials->ambiente(),
                    ],
                    // Uma chave por limiar: D-30, D-15, D-7 e D-1 sao avisos
                    // distintos e cada um deve chegar uma vez.
                    'inter:cert:vence:'.$limiar
                );
            }

            break;
        }

        return self::SUCCESS;
    }
}
