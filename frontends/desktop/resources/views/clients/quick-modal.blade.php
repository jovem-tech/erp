<div class="modal fade" id="quickClientModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <div>
                    <p class="desktop-eyebrow mb-1">Clientes</p>
                    <h5 class="modal-title mb-0">Cadastro rápido de cliente</h5>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="{{ $fullCreateUrl ?? route('clients.create') }}" class="btn btn-soft btn-sm">
                        Abrir cadastro completo
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
            </div>

            <div class="modal-body">
                <div class="alert alert-light border mb-4">
                    Nome e telefone principal são obrigatórios. Use os demais campos para manter o cadastro pronto para a OS sem sair da tela.
                </div>

                <form
                    id="quickClientForm"
                    class="desktop-grid desktop-grid-two"
                    method="post"
                    action="{{ $quickStoreUrl ?? route('clients.quick.store') }}"
                >
                    @csrf

                    <div class="desktop-grid-span-2">
                        <label for="quickClientNomeRazao">Nome / razão social *</label>
                        <input type="text" id="quickClientNomeRazao" name="nome_razao" class="form-control" required autocomplete="off">
                    </div>

                    <div>
                        <label for="quickClientTelefone1">Telefone principal *</label>
                        <input type="text" id="quickClientTelefone1" name="telefone1" class="form-control" required autocomplete="off">
                    </div>

                    <div>
                        <label for="quickClientEmail">E-mail</label>
                        <input type="email" id="quickClientEmail" name="email" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickClientCpfCnpj">CPF / CNPJ</label>
                        <input type="text" id="quickClientCpfCnpj" name="cpf_cnpj" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickClientTelefoneContato">Telefone do contato</label>
                        <input type="text" id="quickClientTelefoneContato" name="telefone_contato" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickClientNomeContato">Nome do contato</label>
                        <input type="text" id="quickClientNomeContato" name="nome_contato" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickClientCep">CEP</label>
                        <input type="text" id="quickClientCep" name="cep" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickClientNumero">Número</label>
                        <input type="text" id="quickClientNumero" name="numero" class="form-control" autocomplete="off">
                    </div>

                    <div class="desktop-grid-span-2">
                        <label for="quickClientEndereco">Endereço</label>
                        <input type="text" id="quickClientEndereco" name="endereco" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickClientBairro">Bairro</label>
                        <input type="text" id="quickClientBairro" name="bairro" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickClientCidade">Cidade</label>
                        <input type="text" id="quickClientCidade" name="cidade" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickClientUf">UF</label>
                        <input type="text" id="quickClientUf" name="uf" class="form-control" maxlength="2" autocomplete="off">
                    </div>

                    <div class="desktop-grid-span-2">
                        <div id="quickClientErrors" class="alert alert-danger d-none mb-0"></div>
                    </div>
                </form>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">
                    Cancelar
                </button>
                <button type="button" id="quickClientSubmit" class="btn btn-primary">
                    <i class="bi bi-person-plus me-2"></i>
                    Cadastrar cliente
                </button>
            </div>
        </div>
    </div>
</div>
