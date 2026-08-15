<div class="modal fade" id="quickEditClientModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content modal-shell">
            <div class="modal-header">
                <div>
                    <p class="desktop-eyebrow mb-1">Clientes</p>
                    <h5 class="modal-title mb-0">Editar cliente</h5>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <a href="#" id="quickEditClientFullLink" class="btn btn-soft btn-sm">
                        Abrir cadastro completo
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
            </div>

            <div class="modal-body">
                <div class="alert alert-light border mb-4">
                    Nome e telefone principal são obrigatórios. Para situação cadastral, RG/IE e demais campos do cadastro, use "Abrir cadastro completo".
                </div>

                <form id="quickEditClientForm" class="desktop-grid desktop-grid-two">
                    <div class="desktop-grid-span-2">
                        <label for="quickEditClientNomeRazao">Nome / razão social *</label>
                        <input type="text" id="quickEditClientNomeRazao" name="nome_razao" class="form-control" required autocomplete="off">
                    </div>

                    <div>
                        <label for="quickEditClientTelefone1">Telefone principal *</label>
                        <input type="text" id="quickEditClientTelefone1" name="telefone1" class="form-control" required autocomplete="off">
                    </div>

                    <div>
                        <label for="quickEditClientEmail">E-mail</label>
                        <input type="email" id="quickEditClientEmail" name="email" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickEditClientCpfCnpj">CPF / CNPJ</label>
                        <input type="text" id="quickEditClientCpfCnpj" name="cpf_cnpj" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickEditClientTelefoneContato">Telefone do contato</label>
                        <input type="text" id="quickEditClientTelefoneContato" name="telefone_contato" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickEditClientNomeContato">Nome do contato</label>
                        <input type="text" id="quickEditClientNomeContato" name="nome_contato" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickEditClientCep">CEP</label>
                        <input type="text" id="quickEditClientCep" name="cep" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickEditClientNumero">Número</label>
                        <input type="text" id="quickEditClientNumero" name="numero" class="form-control" autocomplete="off">
                    </div>

                    <div class="desktop-grid-span-2">
                        <label for="quickEditClientEndereco">Endereço</label>
                        <input type="text" id="quickEditClientEndereco" name="endereco" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickEditClientBairro">Bairro</label>
                        <input type="text" id="quickEditClientBairro" name="bairro" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickEditClientCidade">Cidade</label>
                        <input type="text" id="quickEditClientCidade" name="cidade" class="form-control" autocomplete="off">
                    </div>

                    <div>
                        <label for="quickEditClientUf">UF</label>
                        <input type="text" id="quickEditClientUf" name="uf" class="form-control" maxlength="2" autocomplete="off">
                    </div>

                    <div class="desktop-grid-span-2">
                        <div id="quickEditClientErrors" class="alert alert-danger d-none mb-0"></div>
                    </div>
                </form>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">
                    Cancelar
                </button>
                <button type="button" id="quickEditClientSubmit" class="btn btn-primary">
                    <i class="bi bi-check2-circle me-2"></i>
                    Salvar cliente
                </button>
            </div>
        </div>
    </div>
</div>
