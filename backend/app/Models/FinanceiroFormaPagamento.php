<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Catálogo gerenciável de formas de pagamento.
 *
 * Substitui a antiga lista fixa `Financeiro::FORMAS_PAGAMENTO`, que continua no
 * código apenas como semente/fallback (ver `fallbackCodes()`), para o sistema não
 * ficar sem opções caso a tabela ainda não exista no ambiente.
 */
class FinanceiroFormaPagamento extends Model
{
    protected $table = 'financeiro_formas_pagamento';

    protected $guarded = [];

    protected $casts = [
        'is_cartao' => 'boolean',
        'sistema' => 'boolean',
        'resumo_enum' => 'boolean',
        'ordem_exibicao' => 'integer',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeAtivo(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    public function scopeOrdenado(Builder $query): Builder
    {
        return $query->orderBy('ordem_exibicao')->orderBy('nome');
    }

    /**
     * Códigos aceitos nas colunas de detalhe (varchar): movimentos, recebimentos
     * de OS e formas padrão de conta. Inclui formas personalizadas.
     *
     * @return array<int, string>
     */
    public static function validCodes(): array
    {
        $codes = self::catalog()
            ->where('ativo', true)
            ->pluck('codigo')
            ->all();

        return $codes !== [] ? $codes : self::fallbackCodes();
    }

    /**
     * Códigos aceitos pela coluna-resumo legada `financeiro.forma_pagamento`,
     * que é um ENUM restrito no banco real e não é alterada por este módulo.
     *
     * @return array<int, string>
     */
    public static function summaryCodes(): array
    {
        $codes = self::catalog()
            ->where('resumo_enum', true)
            ->pluck('codigo')
            ->all();

        return $codes !== [] ? $codes : self::fallbackCodes();
    }

    /**
     * Opções para os selects do sistema.
     *
     * @return array<int, array{value: string, label: string, is_cartao: bool}>
     */
    public static function options(): array
    {
        $options = self::catalog()
            ->where('ativo', true)
            ->map(static fn (self $forma): array => [
                'value' => (string) $forma->codigo,
                'label' => (string) $forma->nome,
                'is_cartao' => (bool) $forma->is_cartao,
            ])
            ->values()
            ->all();

        if ($options !== []) {
            return $options;
        }

        return array_map(static fn (string $code): array => [
            'value' => $code,
            'label' => ucfirst(str_replace('_', ' ', $code)),
            'is_cartao' => str_contains($code, 'cartao'),
        ], self::fallbackCodes());
    }

    /**
     * A forma dispara o fluxo de cartão (operadora/bandeira/parcelas/taxas)?
     * Mantém a heurística legada por nome como fallback para códigos que ainda
     * não estejam catalogados.
     */
    public static function isCardCode(?string $codigo): bool
    {
        $codigo = trim((string) $codigo);
        if ($codigo === '') {
            return false;
        }

        $forma = self::catalog()->firstWhere('codigo', $codigo);

        if ($forma instanceof self) {
            return (bool) $forma->is_cartao;
        }

        return str_contains(mb_strtolower($codigo), 'cartao');
    }

    /** @var \Illuminate\Support\Collection<int, self>|null */
    private static ?\Illuminate\Support\Collection $catalogCache = null;

    /**
     * Catálogo carregado uma vez por request. Devolve coleção vazia quando a
     * tabela ainda não existe (ambiente sem a migration aplicada).
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function catalog(): \Illuminate\Support\Collection
    {
        if (self::$catalogCache instanceof \Illuminate\Support\Collection) {
            return self::$catalogCache;
        }

        try {
            if (! Schema::hasTable('financeiro_formas_pagamento')) {
                return self::$catalogCache = collect();
            }

            return self::$catalogCache = self::query()->ordenado()->get();
        } catch (Throwable) {
            return self::$catalogCache = collect();
        }
    }

    /**
     * Limpa o cache estático — necessário após qualquer escrita no catálogo e
     * entre testes que criam formas dentro da mesma request.
     */
    public static function flushCatalog(): void
    {
        self::$catalogCache = null;
    }

    protected static function booted(): void
    {
        static::saved(static function (): void {
            self::flushCatalog();
        });

        static::deleted(static function (): void {
            self::flushCatalog();
        });
    }

    /**
     * Semente/fallback: a lista fixa histórica. Só entra em cena se a tabela do
     * catálogo ainda não existir ou estiver vazia, para o sistema nunca ficar
     * sem nenhuma forma de pagamento disponível.
     *
     * @return array<int, string>
     */
    private static function fallbackCodes(): array
    {
        return Financeiro::FORMAS_PAGAMENTO;
    }
}
