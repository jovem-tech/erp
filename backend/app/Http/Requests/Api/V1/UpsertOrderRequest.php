<?php

namespace App\Http\Requests\Api\V1;

use App\Models\OrderStatus;
use Illuminate\Validation\Rule;

class UpsertOrderRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('post') ? 'required' : 'sometimes';
        // Criação atômica: no POST, cliente/equipamento podem vir como registro
        // existente (cliente_id/equipamento_id) OU como cadastro novo, capturado
        // no formulário e só persistido junto com a OS (novo_cliente/novo_equipamento).
        $clientRule = $this->isMethod('post') ? 'required_without:novo_cliente.nome_razao' : 'sometimes';
        $equipmentRule = $this->isMethod('post') ? 'required_without:novo_equipamento.tipo_id' : 'sometimes';

        return [
            'idempotency_key' => [$this->isMethod('post') ? 'nullable' : 'prohibited', 'uuid'],
            'cliente_id' => [$clientRule, 'nullable', 'integer', 'min:1', Rule::exists('clientes', 'id')],
            'equipamento_id' => [$equipmentRule, 'nullable', 'integer', 'min:1', Rule::exists('equipamentos', 'id')],
            // Cadastro novo de cliente/equipamento (criação diferida, só no POST).
            'novo_cliente' => ['nullable', 'array'],
            'novo_cliente.nome_razao' => ['nullable', 'required_with:novo_cliente', 'string', 'max:100'],
            'novo_cliente.telefone1' => ['nullable', 'required_with:novo_cliente', 'string', 'max:20'],
            'novo_cliente.email' => ['nullable', 'email', 'max:100'],
            'novo_equipamento' => ['nullable', 'array'],
            'novo_equipamento.tipo_id' => ['nullable', 'required_with:novo_equipamento', 'integer', 'min:1', Rule::exists('equipamentos_tipos', 'id')],
            // Vínculo opcional de um orçamento avulso aprovado a ser convertido nesta OS.
            'orcamento_id' => ['nullable', 'integer', 'min:1', Rule::exists('orcamentos', 'id')],
            'tecnico_id' => ['nullable', 'integer', 'min:1', Rule::exists('usuarios', 'id')],
            'fotos' => ['nullable', 'array', 'max:4'],
            'fotos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            // Fotos do equipamento novo (criação diferida na abertura de OS).
            'novo_equipamento_fotos' => ['nullable', 'array', 'max:4'],
            'novo_equipamento_fotos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'status' => [$this->isMethod('post') ? 'nullable' : 'sometimes', 'string', 'max:80', Rule::in(OrderStatus::activeCodes())],
            'estado_fluxo' => ['nullable', 'string', 'max:40'],
            'prioridade' => ['nullable', 'string', Rule::in(['baixa', 'normal', 'alta', 'urgente'])],
            'enviar_pdf_cliente' => ['nullable', 'boolean'],
            'relato_cliente' => [$requiredOrSometimes, 'string'],
            'diagnostico_tecnico' => ['nullable', 'string'],
            'solucao_aplicada' => ['nullable', 'string'],
            'procedimentos_executados' => ['nullable', 'string'],
            'acessorios' => ['nullable', 'string', 'max:2000'],
            'forma_pagamento' => ['nullable', 'string', 'max:30'],
            'data_abertura' => ['nullable', 'date'],
            'data_entrada' => ['nullable', 'date'],
            'data_previsao' => ['nullable', 'date'],
            'data_conclusao' => ['nullable', 'date'],
            'data_entrega' => ['nullable', 'date'],
            'baixa_tecnica_em' => ['nullable', 'date'],
            'baixa_tecnica_por' => ['nullable', 'integer', 'min:1', Rule::exists('usuarios', 'id')],
            'valor_mao_obra' => ['nullable', 'numeric', 'min:0'],
            'valor_pecas' => ['nullable', 'numeric', 'min:0'],
            'valor_total' => ['nullable', 'numeric', 'min:0'],
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'valor_final' => ['nullable', 'numeric', 'min:0'],
            'orcamento_aprovado' => ['nullable', 'boolean'],
            'data_aprovacao' => ['nullable', 'date'],
            'orcamento_pdf' => ['nullable', 'string', 'max:255'],
            'garantia_dias' => ['nullable', 'integer', 'min:0'],
            'garantia_validade' => ['nullable', 'date'],
            'observacoes_internas' => ['nullable', 'string'],
            'observacoes_cliente' => ['nullable', 'string'],
            'checklist_entrada' => ['nullable', 'array'],
            'checklist_entrada.observacoes_estado' => ['nullable', 'string', 'max:2000'],
            'checklist_entrada.respostas' => ['nullable', 'array', 'max:100'],
            'checklist_entrada.respostas.*.checklist_item_id' => ['required', 'integer', 'min:1'],
            'checklist_entrada.respostas.*.status' => ['required', 'string', Rule::in(['ok', 'discrepancia', 'nao_verificado'])],
            'checklist_entrada.respostas.*.observacao' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
