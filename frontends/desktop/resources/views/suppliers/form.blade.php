@php
    $supplier = $supplier ?? [];
    $formMethod = strtoupper((string) ($formMethod ?? 'POST'));
    $tipoPessoa = old('tipo_pessoa', $supplier['tipo_pessoa'] ?? 'juridica');
    $ativo = old('ativo', $supplier['ativo'] ?? true);
@endphp

<section class="desktop-form-card">
    <div class="desktop-form-intro">
        <div class="desktop-form-intro-copy">
            <h2 class="surface-title mb-1">{{ $formTitle ?? 'Cadastro de fornecedor' }}</h2>
            <p class="surface-subtitle mb-0">
                {{ $formSubtitle ?? 'Use CNPJ para preencher dados públicos e mantenha telefone principal, nome fantasia e endereço sempre atualizados.' }}
            </p>
        </div>
    </div>

    <form
        method="post"
        action="{{ $formAction }}"
        class="desktop-form-stack desktop-supplier-form"
        data-supplier-lookup-url="{{ route('suppliers.lookup-cnpj') }}"
        id="supplierForm"
    >
        @csrf
        @if ($formMethod !== 'POST')
            @method($formMethod)
        @endif

        <div class="desktop-grid desktop-grid-three">
            <div>
                <label for="supplierTipoPessoa">Tipo de pessoa</label>
                <select id="supplierTipoPessoa" name="tipo_pessoa" class="form-select" required>
                    <option value="juridica" @selected($tipoPessoa === 'juridica')>Jurídica (CNPJ)</option>
                    <option value="fisica" @selected($tipoPessoa === 'fisica')>Física (CPF)</option>
                </select>
            </div>

            <div>
                <label for="supplierCnpjCpf">CNPJ / CPF</label>
                <input type="text" id="supplierCnpjCpf" name="cnpj_cpf" class="form-control" value="{{ old('cnpj_cpf', $supplier['cnpj_cpf'] ?? '') }}" autocomplete="off">
                <div class="form-text mt-1" id="supplierCnpjFeedback">
                    Informe um CNPJ válido para tentar preencher os dados públicos automaticamente.
                </div>
            </div>

            <div>
                <label for="supplierIeRg">Inscrição estadual / RG</label>
                <input type="text" id="supplierIeRg" name="ie_rg" class="form-control" value="{{ old('ie_rg', $supplier['ie_rg'] ?? '') }}">
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-building"></i>
                <span>DADOS PRINCIPAIS</span>
            </div>

            <div class="desktop-grid desktop-grid-two">
                <div>
                    <label for="supplierNomeFantasia">Nome fantasia *</label>
                    <input type="text" id="supplierNomeFantasia" name="nome_fantasia" class="form-control" value="{{ old('nome_fantasia', $supplier['nome_fantasia'] ?? '') }}" required>
                </div>

                <div>
                    <label for="supplierRazaoSocial">Razão social / Nome completo</label>
                    <input type="text" id="supplierRazaoSocial" name="razao_social" class="form-control" value="{{ old('razao_social', $supplier['razao_social'] ?? '') }}">
                </div>

                <div>
                    <label for="supplierEmail">E-mail</label>
                    <input type="email" id="supplierEmail" name="email" class="form-control" value="{{ old('email', $supplier['email'] ?? '') }}">
                </div>

                <div>
                    <label for="supplierTelefone1">Telefone principal *</label>
                    <input type="text" id="supplierTelefone1" name="telefone1" class="form-control" value="{{ old('telefone1', $supplier['telefone1'] ?? '') }}" required>
                </div>

                <div>
                    <label for="supplierTelefone2">Telefone secundário</label>
                    <input type="text" id="supplierTelefone2" name="telefone2" class="form-control" value="{{ old('telefone2', $supplier['telefone2'] ?? '') }}">
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
                    <label for="supplierCep">CEP</label>
                    <input type="text" id="supplierCep" name="cep" class="form-control" value="{{ old('cep', $supplier['cep'] ?? '') }}">
                </div>

                <div>
                    <label for="supplierEndereco">Endereço</label>
                    <input type="text" id="supplierEndereco" name="endereco" class="form-control" value="{{ old('endereco', $supplier['endereco'] ?? '') }}">
                </div>

                <div>
                    <label for="supplierNumero">Número</label>
                    <input type="text" id="supplierNumero" name="numero" class="form-control" value="{{ old('numero', $supplier['numero'] ?? '') }}">
                </div>

                <div>
                    <label for="supplierComplemento">Complemento</label>
                    <input type="text" id="supplierComplemento" name="complemento" class="form-control" value="{{ old('complemento', $supplier['complemento'] ?? '') }}">
                </div>

                <div>
                    <label for="supplierBairro">Bairro</label>
                    <input type="text" id="supplierBairro" name="bairro" class="form-control" value="{{ old('bairro', $supplier['bairro'] ?? '') }}">
                </div>

                <div>
                    <label for="supplierCidade">Cidade</label>
                    <input type="text" id="supplierCidade" name="cidade" class="form-control" value="{{ old('cidade', $supplier['cidade'] ?? '') }}">
                </div>

                <div>
                    <label for="supplierUf">UF</label>
                    <input type="text" id="supplierUf" name="uf" class="form-control" maxlength="2" value="{{ old('uf', $supplier['uf'] ?? '') }}">
                </div>
            </div>
        </div>

        <div class="desktop-form-section">
            <div class="desktop-form-section-title">
                <i class="bi bi-journal-text"></i>
                <span>INFORMAÇÕES ADICIONAIS</span>
            </div>

            <div>
                <label for="supplierObservacoes">Observações</label>
                <textarea id="supplierObservacoes" name="observacoes" class="form-control" rows="4" placeholder="Observações operacionais, contatos, alertas ou histórico relevante">{{ old('observacoes', $supplier['observacoes'] ?? '') }}</textarea>
            </div>

            <div class="form-check form-switch mt-3">
                <input type="hidden" name="ativo" value="0">
                <input class="form-check-input" type="checkbox" name="ativo" id="supplierAtivo" value="1" @checked((bool) $ativo)>
                <label class="form-check-label" for="supplierAtivo">Fornecedor ativo no sistema</label>
            </div>
        </div>

        <div class="desktop-form-actions">
            <a href="{{ $cancelUrl ?? route('suppliers.index') }}" class="btn btn-outline-light">Cancelar</a>
            <button type="submit" class="btn btn-primary">{{ $submitLabel ?? 'Salvar fornecedor' }}</button>
        </div>
    </form>
</section>
