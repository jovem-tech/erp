@php
    $client = $client ?? [];
    $formMethod = strtoupper((string) ($formMethod ?? 'POST'));
    $statusCadastro = old('status_cadastro', $client['status_cadastro'] ?? 'completo');
@endphp

<section class="desktop-form-card">
    <div class="desktop-form-intro">
        <div class="desktop-form-intro-copy">
            <h2 class="surface-title mb-1">{{ $formTitle ?? 'Cadastro operacional do cliente' }}</h2>
            <p class="surface-subtitle mb-0">
                {{ $formSubtitle ?? 'Nome e telefone principal são obrigatórios. Use CPF/CNPJ, endereço e contatos auxiliares para acelerar buscas, abertura de OS e liberações do portal.' }}
            </p>
        </div>
    </div>

    <form method="post" action="{{ $formAction }}" class="desktop-form-stack desktop-client-form">
        @csrf
        @if ($formMethod !== 'POST')
            @method($formMethod)
        @endif

        <input type="hidden" name="status_cadastro" value="{{ $statusCadastro }}">

        <div class="desktop-grid desktop-grid-three">
            <div>
                <label for="tipoPessoa">Tipo de pessoa</label>
                <select id="tipoPessoa" name="tipo_pessoa" class="form-select" required>
                    <option value="fisica" @selected(old('tipo_pessoa', $client['tipo_pessoa'] ?? 'fisica') === 'fisica')>Física</option>
                    <option value="juridica" @selected(old('tipo_pessoa', $client['tipo_pessoa'] ?? '') === 'juridica')>Jurídica</option>
                </select>
            </div>

            <div>
                <label for="cpfCnpj">CPF / CNPJ</label>
                <input type="text" id="cpfCnpj" name="cpf_cnpj" class="form-control" value="{{ old('cpf_cnpj', $client['cpf_cnpj'] ?? '') }}">
            </div>

            <div>
                <label for="rgIe">RG / IE</label>
                <input type="text" id="rgIe" name="rg_ie" class="form-control" value="{{ old('rg_ie', $client['rg_ie'] ?? '') }}">
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-person-vcard"></i>
                <span>DADOS PESSOAIS</span>
            </div>

            <div class="desktop-grid desktop-grid-two">
                <div class="desktop-grid-span-2">
                    <label for="nomeRazao">Nome / Razão Social *</label>
                    <input type="text" id="nomeRazao" name="nome_razao" class="form-control" value="{{ old('nome_razao', $client['nome_razao'] ?? '') }}" required>
                </div>

                <div>
                    <label for="telefone1">Telefone 1 *</label>
                    <input type="text" id="telefone1" name="telefone1" class="form-control" value="{{ old('telefone1', $client['telefone1'] ?? '') }}" required>
                </div>

                <div>
                    <label for="telefone2">Telefone 2 (Opcional)</label>
                    <input type="text" id="telefone2" name="telefone2" class="form-control" value="{{ old('telefone2', $client['telefone2'] ?? '') }}">
                </div>

                <div>
                    <label for="email">Email (Opcional)</label>
                    <input type="email" id="email" name="email" class="form-control" value="{{ old('email', $client['email'] ?? '') }}">
                </div>

                <div>
                    <label for="preferenciaContato">Preferência de contato</label>
                    <input type="text" id="preferenciaContato" name="preferencia_contato" class="form-control" value="{{ old('preferencia_contato', $client['preferencia_contato'] ?? '') }}" placeholder="WhatsApp, ligação, e-mail">
                </div>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-people"></i>
                <span>CONTATO ADICIONAL (opcional)</span>
            </div>

            <div class="desktop-grid desktop-grid-two">
                <div>
                    <label for="nomeContato">Nome (Ex: Esposa, Filho, Vizinho)</label>
                    <input type="text" id="nomeContato" name="nome_contato" class="form-control" value="{{ old('nome_contato', $client['nome_contato'] ?? '') }}">
                </div>

                <div>
                    <label for="telefoneContato">Telefone do contato</label>
                    <input type="text" id="telefoneContato" name="telefone_contato" class="form-control" value="{{ old('telefone_contato', $client['telefone_contato'] ?? '') }}">
                </div>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-geo-alt"></i>
                <span>ENDEREÇO</span>
            </div>

            <div class="desktop-grid desktop-grid-two">
                <div>
                    <label for="cep">CEP</label>
                    <input type="text" id="cep" name="cep" class="form-control" value="{{ old('cep', $client['cep'] ?? '') }}">
                </div>

                <div>
                    <label for="endereco">Endereço</label>
                    <input type="text" id="endereco" name="endereco" class="form-control" value="{{ old('endereco', $client['endereco'] ?? '') }}">
                </div>

                <div>
                    <label for="numero">Número</label>
                    <input type="text" id="numero" name="numero" class="form-control" value="{{ old('numero', $client['numero'] ?? '') }}">
                </div>

                <div>
                    <label for="complemento">Complemento</label>
                    <input type="text" id="complemento" name="complemento" class="form-control" value="{{ old('complemento', $client['complemento'] ?? '') }}">
                </div>

                <div>
                    <label for="bairro">Bairro</label>
                    <input type="text" id="bairro" name="bairro" class="form-control" value="{{ old('bairro', $client['bairro'] ?? '') }}">
                </div>

                <div>
                    <label for="cidade">Cidade</label>
                    <input type="text" id="cidade" name="cidade" class="form-control" value="{{ old('cidade', $client['cidade'] ?? '') }}">
                </div>

                <div>
                    <label for="uf">UF</label>
                    <input type="text" id="uf" name="uf" class="form-control" maxlength="2" value="{{ old('uf', $client['uf'] ?? '') }}">
                </div>

                <div>
                    <label for="referencia">Referência</label>
                    <input type="text" id="referencia" name="referencia" class="form-control" value="{{ old('referencia', $client['referencia'] ?? '') }}">
                </div>
            </div>
        </div>

        <div class="desktop-grid-span-2">
            <label for="observacoes">Observações</label>
            <textarea id="observacoes" name="observacoes" class="form-control" rows="5" placeholder="Observações operacionais, contato ou histórico relevante">{{ old('observacoes', $client['observacoes'] ?? '') }}</textarea>
        </div>

        <div class="desktop-form-actions">
            <a href="{{ $cancelUrl ?? route('clients.index') }}" class="btn btn-outline-light">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel ?? 'Salvar cliente' }}</button>
        </div>
    </form>
</section>
