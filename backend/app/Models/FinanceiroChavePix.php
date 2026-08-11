<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Chaves Pix da empresa, cadastradas na forma de pagamento "Pix" das
 * configurações financeiras e exibidas nas condições comerciais do orçamento
 * sempre que o Pix estiver entre as formas aceitas.
 */
class FinanceiroChavePix extends Model
{
    protected $table = 'financeiro_chaves_pix';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'principal' => 'boolean',
        'ativo' => 'boolean',
        'ordem_exibicao' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Tipos aceitos, na ordem em que aparecem no cadastro.
     *
     * @var array<string, string>
     */
    public const TIPOS = [
        'cpf' => 'CPF',
        'cnpj' => 'CNPJ',
        'email' => 'E-mail',
        'telefone' => 'Telefone',
        'aleatoria' => 'Chave aleatória',
    ];

    public static function tipoLabel(?string $tipo): string
    {
        $tipo = strtolower(trim((string) $tipo));

        return self::TIPOS[$tipo] ?? 'Chave aleatória';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function tipoOptions(): array
    {
        return array_map(
            static fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys(self::TIPOS),
            array_values(self::TIPOS)
        );
    }

    public function scopeAtivo(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    /**
     * A principal primeiro, depois a ordem definida no cadastro.
     */
    public function scopeOrdenado(Builder $query): Builder
    {
        return $query->orderByDesc('principal')->orderBy('ordem_exibicao')->orderBy('id');
    }

    /**
     * Chaves ativas para exibição em documentos. Devolve coleção vazia quando a
     * tabela ainda não existe (ambiente sem a migration aplicada), para nunca
     * derrubar a geração de um orçamento por causa disso.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function ativasParaDocumento(): \Illuminate\Support\Collection
    {
        try {
            if (! Schema::hasTable('financeiro_chaves_pix')) {
                return collect();
            }

            return self::query()->ativo()->ordenado()->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * Rótulo pronto para documento: "CNPJ 12.345.678/0001-90 — Jovem Tech".
     */
    public function rotuloCompleto(): string
    {
        $partes = [self::tipoLabel($this->tipo).' '.trim((string) $this->chave)];

        $titular = trim((string) ($this->titular ?? ''));
        if ($titular !== '') {
            $partes[] = $titular;
        }

        $instituicao = trim((string) ($this->instituicao ?? ''));
        if ($instituicao !== '') {
            $partes[] = $instituicao;
        }

        return implode(' — ', $partes);
    }
}
