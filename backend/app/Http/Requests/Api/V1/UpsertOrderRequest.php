<?php

namespace App\Http\Requests\Api\V1;

use App\Models\OrderStatus;
use Illuminate\Validation\Rule;

class UpsertOrderRequest extends BaseApiFormRequest
{
    public function rules(): array
    {
        $requiredOrSometimes = $this->isMethod('post') ? 'required' : 'sometimes';
        $createOnlyArray = $this->isMethod('post') ? 'nullable' : 'prohibited';
        $requiresSelectedClient = function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && ! $this->filled('cliente_id')) {
                $fail('Selecione o cliente antes de enviar alterações do cadastro.');
            }
        };
        $requiresSelectedEquipment = function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value !== null && ! $this->filled('equipamento_id')) {
                $fail('Selecione o equipamento antes de enviar alterações do cadastro.');
            }
        };
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
            'cliente_atualizacao' => [$createOnlyArray, 'array', $requiresSelectedClient],
            'cliente_atualizacao.tipo_pessoa' => ['required_with:cliente_atualizacao', 'string', 'max:20'],
            'cliente_atualizacao.nome_razao' => ['required_with:cliente_atualizacao', 'string', 'max:100'],
            'cliente_atualizacao.cpf_cnpj' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('clientes', 'cpf_cnpj')->ignore((int) $this->input('cliente_id', 0)),
            ],
            'cliente_atualizacao.rg_ie' => ['nullable', 'string', 'max:20'],
            'cliente_atualizacao.email' => ['nullable', 'email', 'max:100'],
            'cliente_atualizacao.telefone1' => ['required_with:cliente_atualizacao', 'string', 'max:20'],
            'cliente_atualizacao.telefone2' => ['nullable', 'string', 'max:20'],
            'cliente_atualizacao.nome_contato' => ['nullable', 'string', 'max:100'],
            'cliente_atualizacao.telefone_contato' => ['nullable', 'string', 'max:20'],
            'cliente_atualizacao.cep' => ['nullable', 'string', 'max:10'],
            'cliente_atualizacao.endereco' => ['nullable', 'string', 'max:100'],
            'cliente_atualizacao.numero' => ['nullable', 'string', 'max:10'],
            'cliente_atualizacao.complemento' => ['nullable', 'string', 'max:50'],
            'cliente_atualizacao.referencia' => ['nullable', 'string', 'max:255'],
            'cliente_atualizacao.bairro' => ['nullable', 'string', 'max:50'],
            'cliente_atualizacao.cidade' => ['nullable', 'string', 'max:50'],
            'cliente_atualizacao.uf' => ['nullable', 'string', 'size:2'],
            'cliente_atualizacao.observacoes' => ['nullable', 'string', 'max:5000'],
            'cliente_atualizacao.status_cadastro' => ['required_with:cliente_atualizacao', 'string', 'max:20'],
            'cliente_atualizacao.preferencia_contato' => ['nullable', 'string', 'max:50'],
            'novo_equipamento' => ['nullable', 'array'],
            'novo_equipamento.tipo_id' => ['nullable', 'required_with:novo_equipamento', 'integer', 'min:1', Rule::exists('equipamentos_tipos', 'id')],
            'novo_equipamento.marca_id' => ['nullable', 'required_with:novo_equipamento', 'integer', 'min:1', Rule::exists('equipamentos_marcas', 'id')],
            'novo_equipamento.modelo_id' => ['nullable', 'required_with:novo_equipamento', 'integer', 'min:1', Rule::exists('equipamentos_modelos', 'id')],
            'equipamento_atualizacao' => [$createOnlyArray, 'array', $requiresSelectedEquipment],
            'equipamento_atualizacao.tipo_id' => ['required_with:equipamento_atualizacao', 'integer', 'min:1', Rule::exists('equipamentos_tipos', 'id')],
            'equipamento_atualizacao.marca_id' => ['required_with:equipamento_atualizacao', 'integer', 'min:1', Rule::exists('equipamentos_marcas', 'id')],
            'equipamento_atualizacao.modelo_id' => ['required_with:equipamento_atualizacao', 'integer', 'min:1', Rule::exists('equipamentos_modelos', 'id')],
            'equipamento_atualizacao.cor' => ['nullable', 'string', 'max:50'],
            'equipamento_atualizacao.cor_hex' => ['nullable', 'string', 'max:7'],
            'equipamento_atualizacao.cor_rgb' => ['nullable', 'string', 'max:30'],
            'equipamento_atualizacao.numero_serie' => ['nullable', 'string', 'max:100'],
            'equipamento_atualizacao.imei' => ['nullable', 'string', 'max:20'],
            'equipamento_atualizacao.senha_tipo' => ['nullable', 'string', Rule::in(['desenho', 'texto'])],
            'equipamento_atualizacao.senha_acesso' => ['nullable', 'string', 'max:255'],
            'equipamento_atualizacao.senha_desenho' => ['nullable', 'string', 'max:255'],
            'equipamento_atualizacao.estado_fisico' => ['nullable', 'string', 'max:5000'],
            'equipamento_atualizacao.observacoes' => ['nullable', 'string', 'max:5000'],
            'equipamento_atualizacao.desktop_modalidade' => ['nullable', 'string', Rule::in(['montado', 'oem'])],
            'equipamento_atualizacao.gabinete_tipo' => ['nullable', 'string', 'max:120'],
            'equipamento_atualizacao.gabinete_identificacao_status' => ['nullable', 'string', Rule::in(['a_confirmar', 'manual', 'detectado'])],
            'equipamento_atualizacao.gabinete_observacao' => ['nullable', 'string', 'max:5000'],
            'equipamento_atualizacao.placa_mae' => ['nullable', 'string', 'max:255'],
            'equipamento_atualizacao.chipset' => ['nullable', 'string', 'max:255'],
            'equipamento_atualizacao.processador' => ['nullable', 'string', 'max:255'],
            'equipamento_atualizacao.memoria_ram' => ['nullable', 'string', 'max:255'],
            'equipamento_atualizacao.armazenamento' => ['nullable', 'string', 'max:255'],
            'equipamento_atualizacao.placa_video' => ['nullable', 'string', 'max:255'],
            'equipamento_atualizacao.fonte_alimentacao' => ['nullable', 'string', 'max:255'],
            'equipamento_atualizacao.status_operacional' => ['nullable', 'string', 'max:20'],
            'equipamento_atualizacao.status' => ['nullable', 'string', 'max:20'],
            // Vínculo opcional de orçamento avulso ainda disponível.
            'orcamento_id' => ['nullable', 'integer', 'min:1', Rule::exists('orcamentos', 'id')],
            'tecnico_id' => ['nullable', 'integer', 'min:1', Rule::exists('usuarios', 'id')],
            'fotos' => ['nullable', 'array', 'max:4'],
            'fotos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            // Fotos do equipamento novo (criação diferida na abertura de OS).
            'novo_equipamento_fotos' => ['nullable', 'required_with:novo_equipamento', 'array', 'min:1', 'max:4'],
            'novo_equipamento_fotos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'status' => [$this->isMethod('post') ? 'nullable' : 'sometimes', 'string', 'max:80', Rule::in(OrderStatus::activeCodes())],
            'estado_fluxo' => ['nullable', 'string', 'max:40'],
            'prioridade' => ['nullable', 'string', Rule::in(['baixa', 'normal', 'alta', 'urgente'])],
            'prazo_entrega_dias' => ['nullable', 'integer', Rule::in([1, 3, 7, 15, 30])],
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
            'checklist_entrada.respostas' => ['required_with:checklist_entrada', 'array', 'min:1', 'max:100'],
            'checklist_entrada.respostas.*.checklist_item_id' => ['required', 'integer', 'min:1'],
            'checklist_entrada.respostas.*.status' => ['required', 'string', Rule::in(['ok', 'discrepancia', 'nao_verificado', 'nao_se_aplica'])],
            'checklist_entrada.respostas.*.observacao' => [
                'nullable',
                'required_if:checklist_entrada.respostas.*.status,discrepancia',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'novo_equipamento.marca_id.required_with' => 'Selecione uma marca para o equipamento.',
            'novo_equipamento.modelo_id.required_with' => 'Selecione um modelo para o equipamento.',
            'checklist_entrada.respostas.required_with' => 'Preencha o checklist de entrada.',
            'checklist_entrada.respostas.min' => 'Preencha o checklist de entrada.',
            'checklist_entrada.respostas.*.status.required' => 'Classifique todos os itens do checklist de entrada.',
            'checklist_entrada.respostas.*.status.in' => 'A classificação informada para o checklist é inválida.',
            'checklist_entrada.respostas.*.observacao.required_if' => 'Informe a observação do item classificado como discrepância.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $updates = [];

        foreach (['novo_cliente', 'cliente_atualizacao'] as $key) {
            $payload = $this->input($key);
            if (! is_array($payload) || ! array_key_exists('cpf_cnpj', $payload)) {
                continue;
            }

            $digits = preg_replace('/\D+/', '', (string) ($payload['cpf_cnpj'] ?? ''));
            $payload['cpf_cnpj'] = $digits === '' ? null : $digits;
            $updates[$key] = $payload;
        }

        if ($updates !== []) {
            $this->merge($updates);
        }
    }
}
