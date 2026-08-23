<?php

namespace App\Services\Agenda\Sources;

use RuntimeException;

/**
 * Catalogo das fontes ativas. Populado por tag no AppServiceProvider - ver
 * a tag 'agenda.sources'.
 */
class AgendaSourceRegistry
{
    /** @var array<string, AgendaSource> */
    private array $sources = [];

    /** @param iterable<int, AgendaSource> $sources */
    public function __construct(iterable $sources = [])
    {
        foreach ($sources as $source) {
            $this->register($source);
        }
    }

    public function register(AgendaSource $source): void
    {
        $key = trim($source->key());

        if ($key === '') {
            throw new RuntimeException('Uma AgendaSource precisa de uma chave não vazia.');
        }

        // Duas fontes com a mesma chave disputariam as mesmas linhas na
        // reconciliacao, cada rodada desfazendo a anterior.
        if (isset($this->sources[$key]) && $this->sources[$key]::class !== $source::class) {
            throw new RuntimeException(sprintf(
                'Chave de AgendaSource duplicada: "%s" já pertence a %s.',
                $key,
                $this->sources[$key]::class
            ));
        }

        $this->sources[$key] = $source;
    }

    /** @return array<string, AgendaSource> */
    public function all(): array
    {
        return $this->sources;
    }

    public function get(string $key): ?AgendaSource
    {
        return $this->sources[$key] ?? null;
    }

    /**
     * Rotulos para os filtros da tela, ja incluindo os tipos que nao vem de
     * fonte alguma.
     *
     * @return array<int, array<string, string>>
     */
    public function options(): array
    {
        $options = [[
            'key' => 'manual',
            'label' => 'Meus lembretes',
            'icon' => 'bi-bookmark-star',
        ]];

        foreach ($this->sources as $source) {
            $options[] = [
                'key' => $source->key(),
                'label' => $source->label(),
                'icon' => $source->icon(),
            ];
        }

        return $options;
    }
}
