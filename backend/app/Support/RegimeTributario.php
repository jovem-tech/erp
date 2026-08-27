<?php

namespace App\Support;

/**
 * Regime tributário da empresa — decide se o imposto é custo VARIÁVEL ou FIXO.
 *
 * A distinção não é burocrática, é o que faz a margem de contribuição
 * significar alguma coisa:
 *
 *  - No MEI o DAS é um valor FIXO mensal. Não muda se você fizer 10 ou 100 OS,
 *    então não pode ser descontado de cada venda: ele é custo fixo, entra
 *    ABAIXO da linha da margem e pertence ao cálculo do ponto de equilíbrio.
 *    Descontá-lo por OS subestimaria a margem e, pior, faria o ponto de
 *    equilíbrio ignorar uma despesa que existe todo mês.
 *
 *  - No Simples (e demais regimes sobre faturamento) o imposto É proporcional
 *    à receita: some se você não vender, cresce se vender mais. Aí sim é custo
 *    variável e desconta da margem de cada OS.
 *
 * Guardado como configuração, e não como constante, porque a assistência pode
 * crescer e mudar de regime — trocar de MEI para Simples deve ser um ajuste de
 * tela, nunca um deploy.
 */
final class RegimeTributario
{
    public const CHAVE = 'regime_tributario';

    public const MEI = 'mei';

    public const SIMPLES = 'simples';

    public const OUTRO = 'outro';

    public const PADRAO = self::MEI;

    /**
     * Regimes em que o imposto varia com o faturamento e, portanto, entra na
     * margem de contribuição. O MEI fica de fora de propósito.
     *
     * @var array<int, string>
     */
    private const COM_IMPOSTO_VARIAVEL = [self::SIMPLES, self::OUTRO];

    /**
     * @return array<string, array<string, string>>
     */
    public static function catalogo(): array
    {
        return [
            self::MEI => [
                'label' => 'MEI',
                'descricao' => 'DAS é valor fixo mensal — não desconta da margem. '
                    . 'Lance o DAS como despesa fixa para ele entrar no ponto de equilíbrio.',
            ],
            self::SIMPLES => [
                'label' => 'Simples Nacional',
                'descricao' => 'Imposto proporcional ao faturamento — desconta da margem de cada OS. '
                    . 'Use a alíquota EFETIVA do seu último DAS, não a nominal da tabela.',
            ],
            self::OUTRO => [
                'label' => 'Outro (Lucro Presumido/Real)',
                'descricao' => 'Informe a carga efetiva sobre a receita de serviços.',
            ],
        ];
    }

    public static function normalizar(?string $valor): string
    {
        $valor = strtolower(trim((string) $valor));

        return array_key_exists($valor, self::catalogo()) ? $valor : self::PADRAO;
    }

    /**
     * O imposto deste regime varia com a venda?
     */
    public static function temImpostoVariavel(?string $regime): bool
    {
        return in_array(self::normalizar($regime), self::COM_IMPOSTO_VARIAVEL, true);
    }

    /**
     * @return array<int, string>
     */
    public static function codigos(): array
    {
        return array_keys(self::catalogo());
    }
}
