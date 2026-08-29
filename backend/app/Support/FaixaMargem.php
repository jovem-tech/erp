<?php

namespace App\Support;

/**
 * Semaforo de margem: a leitura que o operador sem permissao financeira tem.
 *
 * A faixa e calculada no SERVIDOR e enviada pronta. O cliente repinta usando
 * os mesmos limites (entregues no payload, nunca hard-coded no JS), para tela
 * e banco nunca discordarem sobre a mesma venda.
 */
final class FaixaMargem
{
    public const VERDE = 'verde';

    public const AMARELO = 'amarelo';

    public const VERMELHO = 'vermelho';

    /**
     * Sem base de comparacao — nao e "ruim", e desconhecido.
     *
     * Item avulso sem custo informado e peca com preco_custo zerado caem aqui.
     * Se caissem em VERDE (margem aritmetica de 100%), o semaforo premiaria
     * justamente o cadastro incompleto: quem esquecesse o custo veria a tela
     * inteira verde.
     */
    public const INDEFINIDO = 'indefinido';

    public const VERDE_PADRAO = 30.0;

    public const AMARELO_PADRAO = 15.0;

    /**
     * @return array<string, array<string, string>>
     */
    public static function catalogo(): array
    {
        return [
            self::VERDE => ['label' => 'Boa margem', 'cor' => 'success'],
            self::AMARELO => ['label' => 'Margem apertada', 'cor' => 'warning'],
            self::VERMELHO => ['label' => 'Abaixo do aceitável', 'cor' => 'danger'],
            self::INDEFINIDO => ['label' => 'Sem custo informado', 'cor' => 'secondary'],
        ];
    }

    /**
     * @param float|null $margemPercentual null = custo desconhecido
     */
    public static function classificar(
        ?float $margemPercentual,
        bool $abaixoDoPiso = false,
        float $limiteVerde = self::VERDE_PADRAO,
        float $limiteAmarelo = self::AMARELO_PADRAO
    ): string {
        if ($margemPercentual === null) {
            return self::INDEFINIDO;
        }

        // O piso vence o percentual: vender abaixo do custo variavel e
        // vermelho mesmo que a conta de margem, por qualquer motivo, dê um
        // numero alto.
        if ($abaixoDoPiso || $margemPercentual < 0) {
            return self::VERMELHO;
        }

        if ($margemPercentual >= $limiteVerde) {
            return self::VERDE;
        }

        return $margemPercentual >= $limiteAmarelo ? self::AMARELO : self::VERMELHO;
    }

    public static function cor(?string $faixa): string
    {
        return self::catalogo()[$faixa ?? self::INDEFINIDO]['cor'] ?? 'secondary';
    }

    public static function label(?string $faixa): string
    {
        return self::catalogo()[$faixa ?? self::INDEFINIDO]['label'] ?? 'Sem custo informado';
    }
}
