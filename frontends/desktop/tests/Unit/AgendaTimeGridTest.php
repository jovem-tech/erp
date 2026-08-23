<?php

namespace Tests\Unit;

use App\Support\AgendaTimeGrid;
use PHPUnit\Framework\TestCase;

class AgendaTimeGridTest extends TestCase
{
    /** @return array<string, mixed> */
    private function item(string $id, string $inicio, ?string $fim = null, bool $diaInteiro = false): array
    {
        return [
            'id' => $id,
            'titulo' => $id,
            'inicio_em' => '2026-09-10T'.$inicio.':00-03:00',
            'fim_em' => $fim !== null ? '2026-09-10T'.$fim.':00-03:00' : null,
            'hora' => $diaInteiro ? null : $inicio,
            'dia_inteiro' => $diaInteiro,
        ];
    }

    public function test_separa_dia_inteiro_de_hora_marcada(): void
    {
        $result = AgendaTimeGrid::forDay([
            $this->item('vencimento', '09:00', null, true),
            $this->item('ligacao', '14:00'),
        ]);

        $this->assertCount(1, $result['all_day']);
        $this->assertCount(1, $result['timed']);
        $this->assertSame('vencimento', $result['all_day'][0]['id']);
    }

    public function test_posiciona_pelo_horario(): void
    {
        $result = AgendaTimeGrid::forDay([$this->item('meio-dia', '12:00', '13:00')]);

        // 12:00 = metade do dia; 1 hora = 1/24 da altura.
        $this->assertSame(50.0, $result['timed'][0]['position']['top']);
        $this->assertEqualsWithDelta(4.1667, $result['timed'][0]['position']['height'], 0.001);
    }

    public function test_evento_sozinho_ocupa_a_largura_toda(): void
    {
        $result = AgendaTimeGrid::forDay([$this->item('sozinho', '10:00', '11:00')]);

        $this->assertSame(0.0, $result['timed'][0]['position']['left']);
        $this->assertSame(100.0, $result['timed'][0]['position']['width']);
    }

    public function test_dois_sobrepostos_dividem_a_largura(): void
    {
        $result = AgendaTimeGrid::forDay([
            $this->item('a', '10:00', '11:00'),
            $this->item('b', '10:30', '11:30'),
        ]);

        $byId = collect($result['timed'])->keyBy('id');

        $this->assertSame(0.0, $byId['a']['position']['left']);
        $this->assertSame(50.0, $byId['b']['position']['left']);
        // Metade menos a folga entre colunas.
        $this->assertSame(48.5, $byId['a']['position']['width']);
    }

    public function test_encadeamento_transitivo_usa_a_mesma_largura(): void
    {
        // A e C não se tocam, mas ambos cruzam B. Se o cálculo fosse par a par,
        // C ganharia largura total e cobriria B.
        $result = AgendaTimeGrid::forDay([
            $this->item('a', '09:00', '10:00'),
            $this->item('b', '09:30', '11:00'),
            $this->item('c', '10:30', '12:00'),
        ]);

        $widths = collect($result['timed'])->pluck('position.width')->unique()->values();

        $this->assertCount(1, $widths, 'Todos do cluster precisam da mesma largura.');
        $this->assertSame(48.5, $widths[0]);

        // A e C não se sobrepõem, então podem reusar a mesma coluna.
        $byId = collect($result['timed'])->keyBy('id');
        $this->assertSame($byId['a']['position']['left'], $byId['c']['position']['left']);
    }

    public function test_grupos_separados_no_tempo_nao_dividem_largura(): void
    {
        $result = AgendaTimeGrid::forDay([
            $this->item('manha', '09:00', '10:00'),
            $this->item('tarde', '15:00', '16:00'),
        ]);

        foreach ($result['timed'] as $item) {
            $this->assertSame(100.0, $item['position']['width']);
        }
    }

    public function test_evento_sem_fim_assume_uma_hora(): void
    {
        $result = AgendaTimeGrid::forDay([$this->item('sem-fim', '08:00')]);

        $this->assertEqualsWithDelta(4.1667, $result['timed'][0]['position']['height'], 0.001);
    }

    public function test_evento_curto_recebe_altura_minima_legivel(): void
    {
        $result = AgendaTimeGrid::forDay([$this->item('curto', '08:00', '08:10')]);

        // 10 minutos renderizariam 7px; o piso de 30 min mantém o título legível.
        $this->assertEqualsWithDelta(2.0833, $result['timed'][0]['position']['height'], 0.001);
    }

    public function test_evento_que_vira_o_dia_termina_a_meia_noite(): void
    {
        $item = $this->item('vira', '23:00');
        $item['fim_em'] = '2026-09-11T01:00:00-03:00';

        $result = AgendaTimeGrid::forDay([$item]);
        $position = $result['timed'][0]['position'];

        // Sem tratamento, 01:00 viraria 60 minutos contados do início do dia e
        // a altura ficaria negativa.
        $this->assertEqualsWithDelta(95.8333, $position['top'], 0.001);
        $this->assertEqualsWithDelta(4.1667, $position['height'], 0.001);
    }

    public function test_item_sem_hora_cai_na_faixa_de_dia_inteiro(): void
    {
        // Defensivo: `dia_inteiro` falso mas sem hora não pode ser posicionado
        // em lugar nenhum da coluna de horas.
        $item = $this->item('sem-hora', '00:00');
        $item['hora'] = null;

        $result = AgendaTimeGrid::forDay([$item]);

        $this->assertCount(1, $result['all_day']);
        $this->assertCount(0, $result['timed']);
    }
}
