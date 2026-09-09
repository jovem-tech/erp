<?php

namespace App\Services\Fiscal;

use Illuminate\Support\Facades\DB;

/**
 * Numerador do `nDPS`, por serie.
 *
 * O numero da DPS e' de responsabilidade do contribuinte e entra no `Id`
 * assinado (`DPS` + municipio + CNPJ + serie + nDPS). Repetir um par
 * serie/numero ja' transmitido faz o Ambiente Nacional recusar — o que, vale
 * dizer, e' o modo de falha BOM: recusa e' visivel e reversivel, ao contrario
 * de emitir duas notas validas para o mesmo servico.
 *
 * A alocacao trava a linha (`lockForUpdate`) porque dois operadores encerrando
 * OS ao mesmo tempo pegariam o mesmo numero sem isso — e o segundo so'
 * descobriria ao ser recusado pelo ADN, ja' com a DPS assinada.
 *
 * **Numero alocado e' numero gasto.** Se a transmissao falhar, nao se devolve o
 * numero ao contador: a DPS pode ter chegado. Buraco na numeracao de DPS e'
 * inofensivo (a numeracao fiscal que importa e' a da NFS-e, que o ADN atribui);
 * numero reaproveitado, nao. Quem retransmite reusa o numero JA' GRAVADO no
 * documento, sem passar por aqui — ver `EmissaoNfseService`.
 */
class SequenciaDps
{
    /**
     * Aloca o proximo numero da serie e ja' o marca como usado.
     */
    public function proximo(string $serie): int
    {
        $serie = $this->normalizar($serie);

        return DB::transaction(function () use ($serie): int {
            $linha = DB::table('fiscal_sequencias')
                ->where('serie', $serie)
                ->lockForUpdate()
                ->first();

            if ($linha === null) {
                // Primeira DPS desta serie NESTE sistema. Zero e' o ponto de
                // partida so' quando ninguem emitiu pelo portal antes — ver o
                // aviso na migration e `fiscal:sequencia-dps`.
                DB::table('fiscal_sequencias')->insert([
                    'serie' => $serie,
                    'ultimo_numero' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            }

            $proximo = ((int) $linha->ultimo_numero) + 1;

            DB::table('fiscal_sequencias')
                ->where('serie', $serie)
                ->update(['ultimo_numero' => $proximo, 'updated_at' => now()]);

            return $proximo;
        });
    }

    /**
     * Ultimo numero usado — sem alocar nada.
     */
    public function atual(string $serie): int
    {
        $linha = DB::table('fiscal_sequencias')
            ->where('serie', $this->normalizar($serie))
            ->first();

        return $linha === null ? 0 : (int) $linha->ultimo_numero;
    }

    /**
     * Reposiciona o contador — usado ao ligar a automacao numa empresa que ja'
     * emitia pelo portal.
     */
    public function definir(string $serie, int $ultimoNumero): void
    {
        $serie = $this->normalizar($serie);
        $valor = max(0, $ultimoNumero);

        DB::table('fiscal_sequencias')->updateOrInsert(
            ['serie' => $serie],
            ['ultimo_numero' => $valor, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function normalizar(string $serie): string
    {
        $limpo = trim($serie);

        return $limpo === '' ? '00001' : $limpo;
    }
}
