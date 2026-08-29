<?php

namespace App\Support;

/**
 * Quanto de custo e margem cada usuario pode ver nas telas operacionais.
 *
 * Decisao do dono (specs/037): quem tem permissao financeira ve o numero em
 * reais; os demais veem um semaforo e o aviso de piso. O tecnico para de
 * vender no prejuizo sem que a tabela de custo da empresa fique exposta para
 * quem atende o balcao.
 *
 * A redacao acontece no DTO (PrecoQuote::toArray), NAO na view: a chave nunca
 * entra no JSON, logo nunca entra no DOM. Um `@if` em Blade esconde o pixel e
 * deixa o dado no devtools — isso nao e protecao.
 */
final class VisibilidadeCusto
{
    /** Ve custo, margem e composicao em reais. */
    public const COMPLETO = 'completo';

    /** Ve so o semaforo, o piso e o aviso de "abaixo do piso". */
    public const INDICATIVO = 'indicativo';

    /** Nao ve nada de precificacao. */
    public const NENHUM = 'nenhum';

    /**
     * Campos removidos do payload quando a visibilidade e INDICATIVO.
     *
     * @var array<int, string>
     */
    public const CAMPOS_SENSIVEIS = [
        'preco_custo_referencia',
        'custo_unitario',
        'custo_total',
        'custo_mao_obra',
        'custo_direto_total',
        'custo_hora_produtiva',
        'valor_encargos',
        'valor_margem',
        'percentual_margem',
        'valor_risco',
    ];

    public static function normalizar(?string $valor): string
    {
        $valor = strtolower(trim((string) $valor));

        return in_array($valor, [self::COMPLETO, self::INDICATIVO, self::NENHUM], true)
            ? $valor
            : self::NENHUM;
    }

    /**
     * Resolve o que este usuario pode ver.
     *
     * Aceita `financeiro:visualizar` OU `precificacao:visualizar`: a oficina
     * que deu so `precificacao` ao gerente criaria, de outro modo, um gerente
     * que edita as regras de margem e nao enxerga custo nenhum.
     *
     * ATENCAO: o RBAC cacheia permissao por 5 minutos
     * (RbacAuthorizationService). Promover alguem nao revela custo na hora.
     */
    public static function paraUsuario(mixed $user): string
    {
        if ($user === null || ! method_exists($user, 'can')) {
            return self::NENHUM;
        }

        if ($user->can('financeiro:visualizar') || $user->can('precificacao:visualizar')) {
            return self::COMPLETO;
        }

        return self::INDICATIVO;
    }

    public static function mostraNumero(?string $visibilidade): bool
    {
        return self::normalizar($visibilidade) === self::COMPLETO;
    }

    public static function mostraSemaforo(?string $visibilidade): bool
    {
        return self::normalizar($visibilidade) !== self::NENHUM;
    }
}
