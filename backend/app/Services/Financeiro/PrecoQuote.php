<?php

namespace App\Services\Financeiro;

use App\Support\FaixaMargem;
use App\Support\VisibilidadeCusto;

/**
 * Cotacao de um item, pronta para virar payload.
 *
 * Existe por um motivo especifico: a REDACAO por visibilidade mora aqui, num
 * lugar so. Enquanto o motor de precificacao servia apenas a tela de
 * configuracao, custo e margem podiam viajar livres no JSON — quem abria a
 * tela ja era quem podia ver. A partir do momento em que a cotacao entra no
 * PDV, no orcamento e no cadastro (specs/037), o mesmo payload passa a ser
 * servido para o tecnico de bancada.
 *
 * Esconder na view NAO resolve: `@if` remove o pixel e deixa o numero no
 * devtools. Por isso toArray() apaga a chave.
 */
final class PrecoQuote
{
    /**
     * @param array<string, float|int|string|null> $composicao detalhamento sensivel
     */
    private function __construct(
        public readonly float $valorRecomendado,
        public readonly float $precoMinimo,
        public readonly ?float $custoUnitario,
        public readonly ?float $margemPercentual,
        public readonly string $faixa,
        public readonly bool $abaixoDoPiso,
        public readonly ?string $aviso,
        public readonly array $composicao,
    ) {
    }

    /**
     * @param array<string, mixed> $composicao
     */
    public static function criar(
        float $valorRecomendado,
        float $precoMinimo,
        ?float $custoUnitario,
        float $valorCobrado,
        array $composicao = [],
        ?string $aviso = null,
        float $limiteVerde = FaixaMargem::VERDE_PADRAO,
        float $limiteAmarelo = FaixaMargem::AMARELO_PADRAO,
    ): self {
        // Custo desconhecido (item avulso, peca sem preco_custo) nao vira
        // margem de 100%: vira `null`, que o semaforo classifica como
        // INDEFINIDO. Ver FaixaMargem::INDEFINIDO para o porque.
        $margemPercentual = null;
        if ($custoUnitario !== null && $valorCobrado > 0) {
            $margemPercentual = round((($valorCobrado - $custoUnitario) / $valorCobrado) * 100, 2);
        }

        $abaixoDoPiso = $precoMinimo > 0 && $valorCobrado > 0 && $valorCobrado < $precoMinimo;

        return new self(
            round($valorRecomendado, 2),
            round($precoMinimo, 2),
            $custoUnitario !== null ? round($custoUnitario, 2) : null,
            $margemPercentual,
            FaixaMargem::classificar($margemPercentual, $abaixoDoPiso, $limiteVerde, $limiteAmarelo),
            $abaixoDoPiso,
            $aviso,
            $composicao,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?string $visibilidade): array
    {
        $visibilidade = VisibilidadeCusto::normalizar($visibilidade);

        if ($visibilidade === VisibilidadeCusto::NENHUM) {
            return [];
        }

        // Base indicativa: o que orienta a decisao sem revelar quanto custou.
        // `preco_minimo` fica aqui de proposito — e o piso, e quem vende
        // precisa saber que passou dele.
        $payload = [
            'valor_recomendado' => $this->valorRecomendado,
            'preco_minimo' => $this->precoMinimo,
            'faixa' => $this->faixa,
            'faixa_label' => FaixaMargem::label($this->faixa),
            'faixa_cor' => FaixaMargem::cor($this->faixa),
            'abaixo_do_piso' => $this->abaixoDoPiso,
            'visibilidade' => $visibilidade,
        ];

        if ($this->aviso !== null) {
            $payload['aviso'] = $this->aviso;
        }

        if ($visibilidade !== VisibilidadeCusto::COMPLETO) {
            return $payload;
        }

        return $payload + [
            'custo_unitario' => $this->custoUnitario,
            'margem_percentual' => $this->margemPercentual,
            'composicao' => $this->composicao,
        ];
    }
}
