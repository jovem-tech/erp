<?php

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\SequenciaDps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contador de nDPS.
 *
 * O que importa aqui e' que dois numeros iguais nunca saiam da mesma serie: o
 * par serie+numero entra no `Id` assinado da DPS, e repetir faz o Ambiente
 * Nacional recusar.
 */
class SequenciaDpsTest extends TestCase
{
    use RefreshDatabase;

    public function test_comeca_em_um_e_avanca(): void
    {
        $sequencia = app(SequenciaDps::class);

        $this->assertSame(0, $sequencia->atual('70000'));
        $this->assertSame(1, $sequencia->proximo('70000'));
        $this->assertSame(2, $sequencia->proximo('70000'));
        $this->assertSame(2, $sequencia->atual('70000'));
    }

    public function test_series_diferentes_tem_contadores_independentes(): void
    {
        $sequencia = app(SequenciaDps::class);

        $sequencia->proximo('70000');
        $sequencia->proximo('70000');

        $this->assertSame(1, $sequencia->proximo('00001'));
        $this->assertSame(2, $sequencia->atual('70000'));
    }

    public function test_nunca_repete_um_numero(): void
    {
        $sequencia = app(SequenciaDps::class);

        $emitidos = [];

        for ($i = 0; $i < 50; $i++) {
            $emitidos[] = $sequencia->proximo('70000');
        }

        $this->assertSame($emitidos, array_unique($emitidos));
        $this->assertSame(range(1, 50), $emitidos);
    }

    public function test_definir_reposiciona_para_quem_ja_emitia_pelo_portal(): void
    {
        // O caso da virada: a empresa ja' gastou ate' o nDPS 4 no Emissor
        // Nacional. Comecar do 1 colidiria com DPS que ja' existem.
        $sequencia = app(SequenciaDps::class);

        $sequencia->definir('70000', 4);

        $this->assertSame(4, $sequencia->atual('70000'));
        $this->assertSame(5, $sequencia->proximo('70000'));
    }

    public function test_definir_sobre_uma_serie_ja_em_uso(): void
    {
        $sequencia = app(SequenciaDps::class);

        $sequencia->proximo('70000');
        $sequencia->definir('70000', 120);

        $this->assertSame(121, $sequencia->proximo('70000'));
    }
}
