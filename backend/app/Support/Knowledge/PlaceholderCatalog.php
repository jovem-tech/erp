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
            ['token' => 'cliente_telefone', 'label' => 'Telefone do cliente'],
            ['token' => 'cliente_email', 'label' => 'E-mail do cliente'],
            ['token' => 'equipamento', 'label' => 'Descricao do equipamento'],
            ['token' => 'equipamento_tipo', 'label' => 'Tipo do equipamento'],
            ['token' => 'equipamento_marca', 'label' => 'Marca do equipamento'],
            ['token' => 'equipamento_modelo', 'label' => 'Modelo do equipamento'],
            ['token' => 'equipamento_serie', 'label' => 'Numero de serie do equipamento'],
            ['token' => 'status_atual', 'label' => 'Status atual da OS'],
            ['token' => 'data_abertura', 'label' => 'Data de abertura da OS'],
            ['token' => 'data_entrega', 'label' => 'Data de entrega prevista/realizada'],
            ['token' => 'valor_final', 'label' => 'Valor final do orcamento/OS'],
            ['token' => 'tecnico_nome', 'label' => 'Nome do tecnico responsavel'],
            ['token' => 'prioridade', 'label' => 'Prioridade da OS'],
            ['token' => 'relato_cliente', 'label' => 'Relato do cliente'],
            ['token' => 'acessorios_html', 'label' => 'Lista HTML de acessorios recebidos'],
            ['token' => 'estado_fisico_html', 'label' => 'Resumo HTML do estado fisico/checklist'],
        ];
    }
}
