<?php

namespace App\Support\Knowledge;

class PlaceholderCatalog
{
    /**
     * @return array<int, array{token: string, label: string}>
     */
    public static function all(): array
    {
        return [
            ['token' => 'numero_os', 'label' => 'Numero da OS'],
            ['token' => 'cliente_nome', 'label' => 'Nome do cliente'],
            ['token' => 'equipamento', 'label' => 'Descricao do equipamento'],
            ['token' => 'status_atual', 'label' => 'Status atual da OS'],
            ['token' => 'data_abertura', 'label' => 'Data de abertura da OS'],
            ['token' => 'data_entrega', 'label' => 'Data de entrega prevista/realizada'],
            ['token' => 'valor_final', 'label' => 'Valor final do orcamento/OS'],
            ['token' => 'tecnico_nome', 'label' => 'Nome do tecnico responsavel'],
        ];
    }
}
