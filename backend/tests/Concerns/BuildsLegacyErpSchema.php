<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

trait BuildsLegacyErpSchema
{
    protected function rebuildLegacySchema(): void
    {
        Cache::flush();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'password_reset_tokens',
            'grupo_permissoes',
            'permissoes',
            'modulos',
            'usuarios',
            'grupos',
            'mobile_notifications',
            'checklist_respostas',
            'checklist_execucoes',
            'checklist_itens',
            'checklist_modelos',
            'checklist_tipos',
            'whatsapp_templates',
            'os_pdf_templates',
            'os_documentos',
            'os_fotos',
            'os_status_historico',
            'os_status_transicoes',
            'os_status',
            'os',
            'orcamento_aprovacoes',
            'orcamento_envios',
            'orcamento_status_historico',
            'orcamento_itens',
            'orcamentos',
            'movimentacoes',
            'pecas',
            'servicos',
            'precificacao_categorias',
            'precificacao_componentes',
            'precificacao_categoria_encargos',
            'precificacao_servico_overrides',
            'equipment_collector_pairings',
            'equipamentos_fotos',
            'equipamentos_catalogo_relacoes',
            'equipamentos_modelos',
            'equipamentos_marcas',
            'equipamentos_tipos',
            'equipamentos',
            'clientes',
            'fornecedores',
            'configuracoes',
            'financeiro_cartao_taxas',
            'financeiro_cartao_bandeiras',
            'financeiro_cartao_operadoras',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();

        $this->createRbacTables();
        $this->createUsersTable();
        $this->createPasswordResetTokensTable();
        $this->createConfigurationsTable();
        $this->createFinanceiroCartaoTables();
        $this->createWhatsappTemplatesTable();
        $this->createOsPdfTemplatesTable();
        $this->createClientsTable();
        $this->createSuppliersTable();
        $this->createServicesTable();
        $this->createPartsTable();
        $this->createMovimentacoesTable();
        $this->createPrecificacaoCategoriasTable();
        $this->createPrecificacaoComponentesTable();
        $this->createPrecificacaoCategoriaEncargosTable();
        $this->createPrecificacaoServicoOverridesTable();
        $this->createEquipmentCatalogTables();
        $this->createEquipmentsTable();
        $this->createEquipmentPhotosTable();
        $this->createEquipmentCollectorPairingsTable();
        $this->createMobileNotificationsTable();
        $this->createOrderStatusesTable();
        $this->createOrderStatusTransitionsTable();
        $this->createOrdersTable();
        $this->createOrderStatusHistoryTable();
        $this->createOrderPhotosTable();
        $this->createOrderDocumentsTable();
        $this->createOrderItemsTable();
        $this->createChecklistTables();
        $this->createBudgetTables();
        $this->seedEquipmentCatalog();
        $this->seedPrecificacaoCatalog();
    }

    protected function seedRbacCatalog(): void
    {
        DB::table('grupos')->insert([
            ['id' => 1, 'nome' => 'Administrador', 'descricao' => 'Grupo protegido', 'sistema' => 1, 'created_at' => now()],
            ['id' => 2, 'nome' => 'Técnico', 'descricao' => 'Operação em campo', 'sistema' => 1, 'created_at' => now()],
            ['id' => 3, 'nome' => 'Atendente', 'descricao' => 'Atendimento', 'sistema' => 0, 'created_at' => now()],
            ['id' => 4, 'nome' => 'Gerente', 'descricao' => 'Gestão', 'sistema' => 0, 'created_at' => now()],
        ]);

        DB::table('modulos')->insert([
            ['id' => 1, 'nome' => 'Dashboard', 'slug' => 'dashboard', 'icone' => 'bi-speedometer2', 'ordem_menu' => 1, 'ativo' => 1],
            ['id' => 2, 'nome' => 'Clientes', 'slug' => 'clientes', 'icone' => 'bi-people', 'ordem_menu' => 10, 'ativo' => 1],
            ['id' => 99, 'nome' => 'Fornecedores', 'slug' => 'fornecedores', 'icone' => 'bi-truck', 'ordem_menu' => 11, 'ativo' => 1],
            ['id' => 3, 'nome' => 'Usuários', 'slug' => 'usuarios', 'icone' => 'bi-person-badge', 'ordem_menu' => 13, 'ativo' => 1],
            ['id' => 4, 'nome' => 'Grupos', 'slug' => 'grupos', 'icone' => 'bi-shield-lock', 'ordem_menu' => 14, 'ativo' => 1],
            ['id' => 5, 'nome' => 'Equipamentos', 'slug' => 'equipamentos', 'icone' => 'bi-pc-display', 'ordem_menu' => 20, 'ativo' => 1],
            ['id' => 6, 'nome' => 'Ordens de ServiÃ§o', 'slug' => 'os', 'icone' => 'bi-clipboard2-check', 'ordem_menu' => 30, 'ativo' => 1],
            ['id' => 7, 'nome' => 'ServiÃ§os', 'slug' => 'servicos', 'icone' => 'bi-gear-fill', 'ordem_menu' => 35, 'ativo' => 1],
            ['id' => 8, 'nome' => 'Estoque de PeÃ§as', 'slug' => 'estoque', 'icone' => 'bi-box-seam', 'ordem_menu' => 40, 'ativo' => 1],
            ['id' => 10, 'nome' => 'ConfiguraÃ§Ãµes', 'slug' => 'configuracoes', 'icone' => 'bi-gear-wide-connected', 'ordem_menu' => 80, 'ativo' => 1],
        ]);

        DB::table('modulos')->insert([
            ['id' => 9, 'nome' => 'Orçamentos', 'slug' => 'orcamentos', 'icone' => 'bi-receipt', 'ordem_menu' => 32, 'ativo' => 1],
        ]);

        DB::table('modulos')->insert([
            ['id' => 11, 'nome' => 'Financeiro', 'slug' => 'financeiro', 'icone' => 'bi-cash-coin', 'ordem_menu' => 45, 'ativo' => 1],
            ['id' => 12, 'nome' => 'Atendimento WhatsApp', 'slug' => 'atendimento_whatsapp', 'icone' => 'bi-chat-dots', 'ordem_menu' => 70, 'ativo' => 1],
            ['id' => 13, 'nome' => 'Precificação', 'slug' => 'precificacao', 'icone' => 'bi-calculator', 'ordem_menu' => 46, 'ativo' => 1],
            ['id' => 14, 'nome' => 'Conhecimento', 'slug' => 'conhecimento', 'icone' => 'bi-journal-bookmark-fill', 'ordem_menu' => 75, 'ativo' => 1],
        ]);

        DB::table('permissoes')->insert([
            ['id' => 1, 'nome' => 'Visualizar', 'slug' => 'visualizar'],
            ['id' => 2, 'nome' => 'Criar', 'slug' => 'criar'],
            ['id' => 3, 'nome' => 'Editar', 'slug' => 'editar'],
            ['id' => 4, 'nome' => 'Excluir', 'slug' => 'excluir'],
            ['id' => 5, 'nome' => 'Encerrar', 'slug' => 'encerrar'],
            ['id' => 6, 'nome' => 'Exportar', 'slug' => 'exportar'],
            ['id' => 7, 'nome' => 'Importar', 'slug' => 'importar'],
        ]);
    }

    /**
     * @param array<string, array<int, string>> $permissionsByModule
     */
    protected function grantGroupPermissions(int $groupId, array $permissionsByModule): void
    {
        $moduleMap = DB::table('modulos')->pluck('id', 'slug')->all();
        $permissionMap = DB::table('permissoes')->pluck('id', 'slug')->all();

        $rows = [];

        foreach ($permissionsByModule as $moduleSlug => $actions) {
            $moduleId = (int) ($moduleMap[$moduleSlug] ?? 0);
            if ($moduleId <= 0) {
                continue;
            }

            foreach ($actions as $actionSlug) {
                $permissionId = (int) ($permissionMap[$actionSlug] ?? 0);
                if ($permissionId <= 0) {
                    continue;
                }

                $rows[] = [
                    'grupo_id' => $groupId,
                    'modulo_id' => $moduleId,
                    'permissao_id' => $permissionId,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('grupo_permissoes')->insert($rows);
        }
    }

    protected function seedOrderCatalog(): void
    {
        DB::table('os_status')->insert([
            [
                'codigo' => 'triagem',
                'nome' => 'Triagem',
                'grupo_macro' => 'recepcao',
                'icone' => null,
                'cor' => 'secondary',
                'ordem_fluxo' => 10,
                'status_final' => 0,
                'status_pausa' => 0,
                'gera_evento_crm' => 1,
                'estado_fluxo_padrao' => 'em_atendimento',
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'aguardando_reparo',
                'nome' => 'Aguardando Reparo',
                'grupo_macro' => 'execucao',
                'icone' => null,
                'cor' => 'warning',
                'ordem_fluxo' => 20,
                'status_final' => 0,
                'status_pausa' => 0,
                'gera_evento_crm' => 1,
                'estado_fluxo_padrao' => 'em_execucao',
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'entregue_reparado',
                'nome' => 'Entregue Reparo',
                'grupo_macro' => 'encerrado',
                'icone' => null,
                'cor' => 'dark',
                'ordem_fluxo' => 30,
                'status_final' => 1,
                'status_pausa' => 0,
                'gera_evento_crm' => 1,
                'estado_fluxo_padrao' => 'encerrado',
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'devolvido_sem_reparo',
                'nome' => 'Devolvido Sem Reparo',
                'grupo_macro' => 'encerrado',
                'icone' => null,
                'cor' => 'secondary',
                'ordem_fluxo' => 31,
                'status_final' => 1,
                'status_pausa' => 0,
                'gera_evento_crm' => 1,
                'estado_fluxo_padrao' => 'encerrado',
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'descartado',
                'nome' => 'Equipamento Descartado',
                'grupo_macro' => 'encerrado',
                'icone' => null,
                'cor' => 'danger',
                'ordem_fluxo' => 32,
                'status_final' => 1,
                'status_pausa' => 0,
                'gera_evento_crm' => 1,
                'estado_fluxo_padrao' => 'encerrado',
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'entregue_pagamento_pendente',
                'nome' => 'Entregue - Pendência Financeira',
                'grupo_macro' => 'encerrado',
                'icone' => null,
                'cor' => 'warning',
                'ordem_fluxo' => 33,
                'status_final' => 0,
                'status_pausa' => 1,
                'gera_evento_crm' => 1,
                'estado_fluxo_padrao' => 'pausado',
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'aguardando_orcamento',
                'nome' => 'Aguardando Orçamento',
                'grupo_macro' => 'recepcao',
                'icone' => null,
                'cor' => 'info',
                'ordem_fluxo' => 12,
                'status_final' => 0,
                'status_pausa' => 0,
                'gera_evento_crm' => 1,
                'estado_fluxo_padrao' => 'em_atendimento',
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'aguardando_autorizacao',
                'nome' => 'Aguardando Autorização',
                'grupo_macro' => 'recepcao',
                'icone' => null,
                'cor' => 'info',
                'ordem_fluxo' => 14,
                'status_final' => 0,
                'status_pausa' => 1,
                'gera_evento_crm' => 1,
                'estado_fluxo_padrao' => 'pausado',
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'codigo' => 'cancelado',
                'nome' => 'Cancelado',
                'grupo_macro' => 'encerrado',
                'icone' => null,
                'cor' => 'danger',
                'ordem_fluxo' => 90,
                'status_final' => 1,
                'status_pausa' => 0,
                'gera_evento_crm' => 1,
                'estado_fluxo_padrao' => 'cancelado',
                'ativo' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    protected function seedOrderNumberConfiguration(): void
    {
        DB::table('configuracoes')->insert([
            ['chave' => 'os_prefixo', 'valor' => 'OS', 'tipo' => 'texto', 'created_at' => now()],
            ['chave' => 'os_ano', 'valor' => now()->format('y'), 'tipo' => 'numero', 'created_at' => now()],
            ['chave' => 'os_mes', 'valor' => now()->format('m'), 'tipo' => 'numero', 'created_at' => now()],
            ['chave' => 'os_ultimo_numero', 'valor' => '8', 'tipo' => 'numero', 'created_at' => now()],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createUserRecord(array $overrides = []): User
    {
        return User::create(array_merge([
            'nome' => 'Usuário Teste',
            'email' => 'usuario.' . uniqid() . '@example.com',
            'senha' => Hash::make('Senha@123'),
            'telefone' => '(11) 99999-9999',
            'perfil' => 'atendente',
            'grupo_id' => 3,
            'foto' => null,
            'ativo' => true,
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createClientRecord(array $overrides = []): int
    {
        return (int) DB::table('clientes')->insertGetId(array_merge([
            'tipo_pessoa' => 'fisica',
            'nome_razao' => 'Cliente Teste',
            'cpf_cnpj' => '12.345.678/0001-99',
            'rg_ie' => null,
            'email' => 'cliente@example.com',
            'telefone1' => '(11) 3333-4444',
            'telefone2' => null,
            'nome_contato' => 'Contato Principal',
            'telefone_contato' => '(11) 99999-0000',
            'cep' => '01000-000',
            'endereco' => 'Rua Teste',
            'numero' => '123',
            'complemento' => null,
            'referencia' => null,
            'bairro' => 'Centro',
            'cidade' => 'São Paulo',
            'uf' => 'SP',
            'observacoes' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'status_cadastro' => 'completo',
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createSupplierRecord(array $overrides = []): int
    {
        return (int) DB::table('fornecedores')->insertGetId(array_merge([
            'tipo_pessoa' => 'juridica',
            'nome_fantasia' => 'Fornecedor Teste',
            'razao_social' => 'Fornecedor Teste LTDA',
            'cnpj_cpf' => '12.345.678/0001-99',
            'ie_rg' => 'ISENTO',
            'email' => 'fornecedor@example.com',
            'telefone1' => '(11) 3333-4444',
            'telefone2' => null,
            'cep' => '01000-000',
            'endereco' => 'Rua Teste',
            'numero' => '123',
            'complemento' => null,
            'bairro' => 'Centro',
            'cidade' => 'SÃ£o Paulo',
            'uf' => 'SP',
            'observacoes' => null,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createServiceRecord(array $overrides = []): int
    {
        return (int) DB::table('servicos')->insertGetId(array_merge([
            'nome' => 'Serviço Teste',
            'descricao' => 'Serviço cadastrado nos testes',
            'valor' => 120.00,
            'tempo_padrao_horas' => 1.50,
            'custo_direto_padrao' => 45.00,
            'status' => 'ativo',
            'tipo_equipamento' => 'Notebook',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createPecaRecord(array $overrides = []): int
    {
        return (int) DB::table('pecas')->insertGetId(array_merge([
            'codigo' => 'PC00001',
            'codigo_fabricante' => 'FAB-001',
            'nome' => 'Peça Teste',
            'categoria' => 'Insumos',
            'modelos_compativeis' => 'Universal',
            'fornecedor' => 'Fornecedor Teste',
            'localizacao' => 'A1',
            'preco_custo' => 25.00,
            'preco_venda' => 45.00,
            'quantidade_atual' => 10,
            'estoque_minimo' => 3,
            'estoque_maximo' => 20,
            'observacoes' => 'Peça criada para testes',
            'ativo' => 1,
            'status' => 'ativo',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createMovimentacaoRecord(array $overrides = []): int
    {
        return (int) DB::table('movimentacoes')->insertGetId(array_merge([
            'peca_id' => 1,
            'os_id' => null,
            'tipo' => 'entrada',
            'quantidade' => 1,
            'motivo' => 'Movimentação de teste',
            'responsavel_id' => 1,
            'created_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createEquipmentRecord(int $clientId, array $overrides = []): int
    {
        return (int) DB::table('equipamentos')->insertGetId(array_merge([
            'cliente_id' => $clientId,
            'tipo_id' => 1,
            'marca_id' => 1,
            'modelo_id' => 1,
            'cor' => 'Preto',
            'cor_hex' => '#101010',
            'cor_rgb' => '16, 16, 16',
            'numero_serie' => 'SER-' . uniqid(),
            'imei' => 'IMEI-' . uniqid(),
            'senha_acesso' => '1234',
            'estado_fisico' => 'Bom estado',
            'acessorios' => 'Fonte, mouse',
            'observacoes' => 'Observação técnica',
            'desktop_modalidade' => 'desktop',
            'gabinete_tipo' => 'Mid Tower',
            'gabinete_identificacao_status' => 'manual',
            'gabinete_observacao' => 'Gabinete preto',
            'placa_mae' => 'B550M',
            'chipset' => 'B550',
            'processador' => 'Ryzen 5 5600',
            'memoria_ram' => '16GB DDR4',
            'armazenamento' => 'SSD 512GB',
            'placa_video' => 'RTX 3060',
            'fonte_alimentacao' => '650W 80 Plus',
            'resumo_tecnico' => 'Notebook Dell Inspiron',
            'configuracao_status' => 'manual',
            'configuracao_origem' => 'formulario',
            'configuracao_detectada_em' => now(),
            'status_operacional' => 'ativo',
            'status' => 'ativo',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createOrderRecord(array $overrides = []): int
    {
        return (int) DB::table('os')->insertGetId(array_merge([
            'numero_os' => 'OS' . now()->format('ym') . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT),
            'cliente_id' => 1,
            'equipamento_id' => 1,
            'tecnico_id' => null,
            'status' => 'triagem',
            'estado_fluxo' => 'em_atendimento',
            'prioridade' => 'normal',
            'status_atualizado_em' => now(),
            'relato_cliente' => 'Sem relato',
            'diagnostico_tecnico' => null,
            'solucao_aplicada' => null,
            'procedimentos_executados' => null,
            'data_abertura' => now(),
            'data_entrada' => now(),
            'data_previsao' => now()->addDays(3)->toDateString(),
            'data_conclusao' => null,
            'data_entrega' => null,
            'garantia_dias' => 90,
            'garantia_validade' => now()->addDays(90)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createRbacTables(): void
    {
        Schema::create('grupos', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->string('nome', 80);
            $table->string('descricao', 200)->nullable();
            $table->boolean('sistema')->default(false);
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('modulos', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->string('nome', 80);
            $table->string('slug', 80)->unique();
            $table->string('icone', 60)->nullable();
            $table->integer('ordem_menu')->default(0);
            $table->boolean('ativo')->default(true);
        });

        Schema::create('permissoes', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->string('nome', 80);
            $table->string('slug', 80)->unique();
        });

        Schema::create('grupo_permissoes', function (Blueprint $table): void {
            $table->integer('id')->autoIncrement();
            $table->integer('grupo_id');
            $table->integer('modulo_id');
            $table->integer('permissao_id');
            $table->unique(['grupo_id', 'modulo_id', 'permissao_id'], 'uq_grupo_modulo_perm');
            $table->foreign('grupo_id')->references('id')->on('grupos')->cascadeOnDelete();
            $table->foreign('modulo_id')->references('id')->on('modulos')->cascadeOnDelete();
            $table->foreign('permissao_id')->references('id')->on('permissoes')->cascadeOnDelete();
        });
    }

    private function createUsersTable(): void
    {
        Schema::create('usuarios', function (Blueprint $table): void {
            $table->id();
            $table->string('nome', 100);
            $table->string('email', 100)->unique();
            $table->string('senha');
            $table->string('telefone', 20)->nullable();
            $table->string('perfil', 30)->nullable();
            $table->unsignedBigInteger('grupo_id')->nullable();
            $table->string('foto', 255)->nullable();
            $table->boolean('ativo')->default(true);
            $table->dateTime('ultimo_acesso')->nullable();
            $table->string('token_recuperacao', 255)->nullable();
            $table->dateTime('token_expiracao')->nullable();
            $table->string('remember_token_hash', 255)->nullable();
            $table->dateTime('remember_token_expires_at')->nullable();
            $table->timestamps();
            $table->foreign('grupo_id')->references('id')->on('grupos')->nullOnDelete();
        });
    }

    private function createPasswordResetTokensTable(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    private function createConfigurationsTable(): void
    {
        Schema::create('configuracoes', function (Blueprint $table): void {
            $table->id();
            $table->string('chave', 100)->unique();
            $table->text('valor')->nullable();
            $table->string('tipo', 20)->default('texto');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function createFinanceiroCartaoTables(): void
    {
        Schema::create('financeiro_cartao_operadoras', function (Blueprint $table): void {
            $table->id();
            $table->string('nome', 120);
            $table->string('descricao', 255)->nullable();
            $table->integer('ordem_exibicao')->default(0);
            $table->integer('prazo_padrao_dias')->default(30);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('financeiro_cartao_bandeiras', function (Blueprint $table): void {
            $table->id();
            $table->string('nome', 80);
            $table->integer('ordem_exibicao')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('financeiro_cartao_taxas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('operadora_id');
            $table->unsignedBigInteger('bandeira_id')->nullable();
            $table->string('modalidade', 20);
            $table->integer('parcelas_inicial')->default(1);
            $table->integer('parcelas_final')->default(1);
            $table->decimal('taxa_percentual', 10, 4)->default(0);
            $table->decimal('taxa_fixa', 10, 2)->default(0);
            $table->integer('prazo_recebimento_dias')->default(0);
            $table->text('observacoes')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->foreign('operadora_id')->references('id')->on('financeiro_cartao_operadoras')->cascadeOnDelete();
            $table->foreign('bandeira_id')->references('id')->on('financeiro_cartao_bandeiras')->nullOnDelete();
            $table->index(['operadora_id', 'bandeira_id', 'modalidade'], 'idx_financeiro_cartao_taxas_catalogo');
        });
    }

    private function createClientsTable(): void
    {
        Schema::create('clientes', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo_pessoa', 20)->default('fisica');
            $table->string('nome_razao', 100);
            $table->string('cpf_cnpj', 20)->nullable();
            $table->string('rg_ie', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('telefone1', 20);
            $table->string('telefone2', 20)->nullable();
            $table->string('nome_contato', 100)->nullable();
            $table->string('telefone_contato', 20)->nullable();
            $table->string('cep', 10)->nullable();
            $table->string('endereco', 100)->nullable();
            $table->string('numero', 10)->nullable();
            $table->string('complemento', 50)->nullable();
            $table->string('referencia', 255)->nullable();
            $table->string('bairro', 50)->nullable();
            $table->string('cidade', 50)->nullable();
            $table->string('uf', 2)->nullable();
            $table->text('observacoes')->nullable();
            $table->string('legacy_origem', 60)->nullable();
            $table->string('legacy_id', 100)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('preferencia_contato', 50)->nullable();
            $table->dateTime('termos_aceite_em')->nullable();
            $table->string('google_id', 255)->nullable();
            $table->text('foto_perfil')->nullable();
            $table->string('status_cadastro', 20)->default('completo');
        });
    }

    private function createSuppliersTable(): void
    {
        Schema::create('fornecedores', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo_pessoa', 20)->default('juridica');
            $table->string('nome_fantasia', 100);
            $table->string('razao_social', 100)->nullable();
            $table->string('cnpj_cpf', 20)->nullable();
            $table->string('ie_rg', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('telefone1', 20);
            $table->string('telefone2', 20)->nullable();
            $table->string('cep', 10)->nullable();
            $table->string('endereco', 100)->nullable();
            $table->string('numero', 10)->nullable();
            $table->string('complemento', 50)->nullable();
            $table->string('bairro', 50)->nullable();
            $table->string('cidade', 50)->nullable();
            $table->string('uf', 2)->nullable();
            $table->text('observacoes')->nullable();
            $table->boolean('ativo')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function createServicesTable(): void
    {
        Schema::create('servicos', function (Blueprint $table): void {
            $table->id();
            $table->string('nome', 120);
            $table->text('descricao')->nullable();
            $table->decimal('valor', 10, 2)->default(0);
            $table->decimal('tempo_padrao_horas', 10, 2)->default(0);
            $table->decimal('custo_direto_padrao', 10, 2)->default(0);
            $table->string('status', 30)->default('ativo');
            $table->dateTime('encerrado_em')->nullable();
            $table->string('tipo_equipamento', 120)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function createPartsTable(): void
    {
        Schema::create('pecas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 120)->nullable();
            $table->string('codigo_fabricante', 120)->nullable();
            $table->string('nome', 160);
            $table->string('categoria', 120)->nullable();
            $table->text('modelos_compativeis')->nullable();
            $table->string('fornecedor', 120)->nullable();
            $table->string('localizacao', 120)->nullable();
            $table->decimal('preco_custo', 10, 2)->default(0);
            $table->decimal('preco_venda', 10, 2)->default(0);
            $table->integer('quantidade_atual')->default(0);
            $table->integer('estoque_minimo')->default(0);
            $table->integer('estoque_maximo')->default(0);
            $table->text('observacoes')->nullable();
            $table->boolean('ativo')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('status', 30)->default('ativo');
            $table->dateTime('encerrado_em')->nullable();
            $table->string('tipo_equipamento', 120)->nullable();
        });
    }

    private function createMovimentacoesTable(): void
    {
        Schema::create('movimentacoes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('peca_id');
            $table->unsignedBigInteger('os_id')->nullable();
            $table->string('tipo', 30);
            $table->integer('quantidade');
            $table->string('motivo', 255)->nullable();
            $table->unsignedBigInteger('responsavel_id')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    private function createPrecificacaoCategoriasTable(): void
    {
        Schema::create('precificacao_categorias', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 30);
            $table->string('categoria_nome', 120);
            $table->decimal('encargos_percentual', 10, 2)->default(0);
            $table->decimal('margem_percentual', 10, 2)->default(0);
            $table->boolean('ativo')->default(true);
            $table->integer('ordem')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function createPrecificacaoComponentesTable(): void
    {
        Schema::create('precificacao_componentes', function (Blueprint $table): void {
            $table->id();
            $table->string('grupo', 50);
            $table->string('nome', 120);
            $table->string('tipo_valor', 20)->default('percentual');
            $table->decimal('valor', 12, 4)->default(0);
            $table->string('origem', 20)->default('manual');
            $table->boolean('ativo')->default(true);
            $table->integer('ordem')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function createPrecificacaoCategoriaEncargosTable(): void
    {
        Schema::create('precificacao_categoria_encargos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('categoria_id');
            $table->string('nome', 140);
            $table->decimal('percentual', 8, 2)->default(0);
            $table->boolean('ativo')->default(true);
            $table->integer('ordem')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('categoria_id')->references('id')->on('precificacao_categorias')->cascadeOnDelete();
        });
    }

    private function createPrecificacaoServicoOverridesTable(): void
    {
        Schema::create('precificacao_servico_overrides', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('servico_id');
            $table->decimal('custo_hora_produtiva', 14, 4)->default(0);
            $table->decimal('custos_diretos_total', 14, 4)->default(0);
            $table->decimal('margem_percentual', 8, 4)->default(0);
            $table->decimal('taxa_recebimento_percentual', 8, 4)->default(0);
            $table->decimal('imposto_percentual', 8, 4)->default(0);
            $table->decimal('tempo_tecnico_horas', 10, 4)->default(0);
            $table->decimal('risco_percentual', 8, 4)->default(0);
            $table->decimal('preco_tabela_referencia', 14, 4)->default(0);
            $table->decimal('custos_fixos_mensais', 14, 4)->default(0);
            $table->decimal('tecnicos_ativos', 10, 4)->default(1);
            $table->decimal('horas_produtivas_dia', 10, 4)->default(0);
            $table->decimal('dias_uteis_mes', 10, 4)->default(1);
            $table->decimal('consumiveis_valor', 14, 4)->default(0);
            $table->decimal('tempo_indireto_horas', 10, 4)->default(0);
            $table->decimal('reserva_garantia_valor', 14, 4)->default(0);
            $table->decimal('perdas_pequenas_valor', 14, 4)->default(0);
            $table->decimal('tempo_desmontagem_min', 10, 4)->default(0);
            $table->decimal('tempo_substituicao_min', 10, 4)->default(0);
            $table->decimal('tempo_montagem_min', 10, 4)->default(0);
            $table->decimal('tempo_teste_final_min', 10, 4)->default(0);
            $table->boolean('ativo')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique('servico_id', 'ux_precificacao_servico_overrides_servico');
            $table->foreign('servico_id')->references('id')->on('servicos')->cascadeOnDelete();
        });
    }

    private function createEquipmentCatalogTables(): void
    {
        Schema::create('equipamentos_tipos', function (Blueprint $table): void {
            $table->id();
            $table->string('nome', 120);
            $table->boolean('ativo')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('equipamentos_marcas', function (Blueprint $table): void {
            $table->id();
            $table->string('nome', 120);
            $table->boolean('ativo')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::create('equipamentos_modelos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('marca_id');
            $table->string('nome', 160);
            $table->boolean('ativo')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign('marca_id')->references('id')->on('equipamentos_marcas')->cascadeOnDelete();
        });

        Schema::create('equipamentos_catalogo_relacoes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tipo_id');
            $table->unsignedBigInteger('marca_id');
            $table->unsignedBigInteger('modelo_id');
            $table->boolean('ativo')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['tipo_id', 'marca_id', 'modelo_id'], 'ux_equip_catalogo_rel_tipo_marca_modelo');
            $table->index('tipo_id', 'idx_equip_catalogo_rel_tipo');
            $table->index('marca_id', 'idx_equip_catalogo_rel_marca');
            $table->index('modelo_id', 'idx_equip_catalogo_rel_modelo');
            $table->index(['tipo_id', 'marca_id'], 'idx_equip_catalogo_rel_tipo_marca');
            $table->foreign('tipo_id', 'fk_equip_catalogo_rel_tipo')->references('id')->on('equipamentos_tipos')->cascadeOnDelete();
            $table->foreign('marca_id', 'fk_equip_catalogo_rel_marca')->references('id')->on('equipamentos_marcas')->cascadeOnDelete();
            $table->foreign('modelo_id', 'fk_equip_catalogo_rel_modelo')->references('id')->on('equipamentos_modelos')->cascadeOnDelete();
        });
    }

    private function createEquipmentsTable(): void
    {
        Schema::create('equipamentos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cliente_id');
            $table->unsignedBigInteger('tipo_id')->nullable();
            $table->unsignedBigInteger('marca_id')->nullable();
            $table->unsignedBigInteger('modelo_id')->nullable();
            $table->string('cor', 50)->nullable();
            $table->string('cor_hex', 7)->nullable();
            $table->string('cor_rgb', 30)->nullable();
            $table->string('numero_serie', 100)->nullable();
            $table->string('imei', 20)->nullable();
            $table->string('senha_acesso', 255)->nullable();
            $table->text('estado_fisico')->nullable();
            $table->text('acessorios')->nullable();
            $table->text('observacoes')->nullable();
            $table->string('desktop_modalidade', 30)->nullable();
            $table->string('gabinete_tipo', 120)->nullable();
            $table->string('gabinete_identificacao_status', 30)->nullable();
            $table->text('gabinete_observacao')->nullable();
            $table->string('placa_mae', 255)->nullable();
            $table->string('chipset', 255)->nullable();
            $table->string('processador', 255)->nullable();
            $table->string('memoria_ram', 255)->nullable();
            $table->string('armazenamento', 255)->nullable();
            $table->string('placa_video', 255)->nullable();
            $table->string('fonte_alimentacao', 255)->nullable();
            $table->string('resumo_tecnico', 255)->nullable();
            $table->string('configuracao_status', 30)->nullable();
            $table->string('configuracao_origem', 60)->nullable();
            $table->dateTime('configuracao_detectada_em')->nullable();
            $table->string('legacy_origem', 60)->nullable();
            $table->string('legacy_id', 100)->nullable();
            $table->string('status_operacional', 20)->default('ativo');
            $table->string('status', 20)->default('ativo');
            $table->dateTime('encerrado_em')->nullable();
            $table->string('motivo_encerramento', 60)->nullable();
            $table->text('observacao_encerramento')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign('cliente_id')->references('id')->on('clientes')->cascadeOnDelete();
            $table->foreign('tipo_id')->references('id')->on('equipamentos_tipos')->nullOnDelete();
            $table->foreign('marca_id')->references('id')->on('equipamentos_marcas')->nullOnDelete();
            $table->foreign('modelo_id')->references('id')->on('equipamentos_modelos')->nullOnDelete();
        });
    }

    private function createEquipmentPhotosTable(): void
    {
        Schema::create('equipamentos_fotos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('equipamento_id');
            $table->string('arquivo', 255);
            $table->boolean('is_principal')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->foreign('equipamento_id')->references('id')->on('equipamentos')->cascadeOnDelete();
        });
    }

    private function createEquipmentCollectorPairingsTable(): void
    {
        Schema::create('equipment_collector_pairings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('code', 32)->unique();
            $table->longText('snapshot_payload')->nullable();
            $table->longText('snapshot_normalized')->nullable();
            $table->string('source', 120)->nullable();
            $table->string('agent_version', 60)->nullable();
            $table->string('hostname', 120)->nullable();
            $table->dateTime('snapshot_received_at')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign('user_id')->references('id')->on('usuarios')->nullOnDelete();
        });
    }

    private function createOrderStatusesTable(): void
    {
        Schema::create('os_status', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 80)->unique();
            $table->string('nome', 120);
            $table->string('grupo_macro', 60);
            $table->string('icone', 60)->nullable();
            $table->string('cor', 30)->nullable();
            $table->integer('ordem_fluxo')->default(0);
            $table->boolean('status_final')->default(false);
            $table->boolean('status_pausa')->default(false);
            $table->boolean('gera_evento_crm')->default(true);
            $table->string('estado_fluxo_padrao', 40)->default('em_atendimento');
            $table->boolean('ativo')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function createOrderStatusTransitionsTable(): void
    {
        Schema::create('os_status_transicoes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('status_origem_id');
            $table->unsignedBigInteger('status_destino_id');
            $table->boolean('ativo')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['status_origem_id', 'ativo'], 'idx_os_status_transicoes_origem');
        });
    }

    private function createMobileNotificationsTable(): void
    {
        Schema::create('mobile_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('usuario_id');
            $table->string('tipo_evento', 80);
            $table->string('titulo', 180);
            $table->text('corpo');
            $table->string('rota_destino', 255)->nullable();
            $table->longText('payload_json')->nullable();
            $table->dateTime('lida_em')->nullable();
            $table->dateTime('enviada_push_em')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['usuario_id', 'id'], 'idx_mobile_notifications_usuario_id');
            $table->index(['usuario_id', 'lida_em'], 'idx_mobile_notifications_usuario_lida');
            $table->foreign('usuario_id')->references('id')->on('usuarios')->cascadeOnDelete();
        });
    }

    private function createOrdersTable(): void
    {
        Schema::create('os', function (Blueprint $table): void {
            $table->id();
            $table->string('numero_os', 20)->unique();
            $table->string('legacy_origem', 60)->nullable();
            $table->string('legacy_id', 100)->nullable();
            $table->string('numero_os_legado', 60)->nullable();
            $table->unsignedBigInteger('cliente_id');
            $table->unsignedBigInteger('equipamento_id');
            $table->unsignedBigInteger('tecnico_id')->nullable();
            $table->string('status', 80)->default('triagem');
            $table->string('status_final_pendente_pagamento', 60)->nullable();
            $table->string('estado_fluxo', 40)->default('em_atendimento');
            $table->dateTime('status_atualizado_em')->nullable();
            $table->string('prioridade', 20)->default('normal');
            $table->text('relato_cliente');
            $table->text('diagnostico_tecnico')->nullable();
            $table->text('solucao_aplicada')->nullable();
            $table->text('procedimentos_executados')->nullable();
            $table->text('acessorios')->nullable();
            $table->string('forma_pagamento', 30)->nullable();
            $table->dateTime('data_abertura')->nullable();
            $table->dateTime('data_entrada')->nullable();
            $table->date('data_previsao')->nullable();
            $table->dateTime('data_conclusao')->nullable();
            $table->dateTime('data_entrega')->nullable();
            $table->dateTime('baixa_tecnica_em')->nullable();
            $table->unsignedBigInteger('baixa_tecnica_por')->nullable();
            $table->decimal('valor_mao_obra', 10, 2)->default(0);
            $table->decimal('valor_pecas', 10, 2)->default(0);
            $table->decimal('valor_total', 10, 2)->default(0);
            $table->decimal('desconto', 10, 2)->default(0);
            $table->decimal('valor_final', 10, 2)->default(0);
            $table->boolean('orcamento_aprovado')->default(false);
            $table->dateTime('data_aprovacao')->nullable();
            $table->string('orcamento_pdf', 255)->nullable();
            $table->integer('garantia_dias')->default(90);
            $table->date('garantia_validade')->nullable();
            $table->text('observacoes_internas')->nullable();
            $table->text('observacoes_cliente')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            // Espelha as colunas geradas/indexadas criadas pela migration
            // 2026_06_30_120000_add_effective_dates_index_to_os_table (que e
            // no-op em teste, pois `os` so existe via este trait). Mantém
            // DashboardSummaryService::OPEN_DATE_SQL/DELIVERY_DATE_SQL
            // funcionando igual em teste e em producao.
            $table->dateTime('data_abertura_efetiva')
                ->storedAs('COALESCE(data_abertura, data_entrada, status_atualizado_em, updated_at, created_at)')
                ->nullable();
            $table->dateTime('data_entrega_efetiva')
                ->storedAs('COALESCE(data_entrega, data_conclusao, status_atualizado_em, updated_at, created_at)')
                ->nullable();
            $table->foreign('cliente_id')->references('id')->on('clientes');
            $table->foreign('equipamento_id')->references('id')->on('equipamentos');
            $table->foreign('tecnico_id')->references('id')->on('usuarios');
        });
    }

    private function createOrderStatusHistoryTable(): void
    {
        Schema::create('os_status_historico', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('os_id');
            $table->string('legacy_origem', 60)->nullable();
            $table->string('legacy_tabela', 60)->nullable();
            $table->string('legacy_id', 120)->nullable();
            $table->string('status_anterior', 80)->nullable();
            $table->string('status_novo', 80);
            $table->string('estado_fluxo', 40)->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->text('observacao')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->foreign('os_id')->references('id')->on('os')->cascadeOnDelete();
            $table->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
        });
    }

    private function createOrderPhotosTable(): void
    {
        Schema::create('os_fotos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('os_id');
            $table->string('tipo', 30)->default('recepcao');
            $table->string('arquivo', 255);
            $table->dateTime('created_at')->nullable();
            $table->foreign('os_id')->references('id')->on('os')->cascadeOnDelete();
        });
    }

    private function createWhatsappTemplatesTable(): void
    {
        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 80)->unique();
            $table->string('nome', 140);
            $table->string('evento', 80)->nullable();
            $table->longText('conteudo');
            $table->boolean('ativo')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function createOsPdfTemplatesTable(): void
    {
        Schema::create('os_pdf_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 255)->unique();
            $table->string('nome', 255);
            $table->text('descricao')->nullable();
            $table->longText('conteudo_html');
            $table->integer('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function createOrderDocumentsTable(): void
    {
        Schema::create('os_documentos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('os_id');
            $table->string('tipo_documento', 50);
            $table->string('arquivo', 255);
            $table->integer('versao')->default(1);
            $table->string('hash_sha1', 40)->nullable();
            $table->unsignedBigInteger('gerado_por')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign('os_id')->references('id')->on('os')->cascadeOnDelete();
            $table->foreign('gerado_por')->references('id')->on('usuarios')->nullOnDelete();
        });
    }

    private function createOrderItemsTable(): void
    {
        Schema::create('os_itens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('os_id');
            $table->string('legacy_origem', 60)->nullable();
            $table->string('legacy_tabela', 60)->nullable();
            $table->string('legacy_id', 120)->nullable();
            $table->enum('tipo', ['servico', 'peca']);
            $table->string('descricao', 255);
            $table->text('observacao')->nullable();
            $table->integer('quantidade')->nullable()->default(1);
            $table->decimal('valor_unitario', 10, 2);
            $table->decimal('valor_total', 10, 2);
            $table->unsignedBigInteger('peca_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->unsignedBigInteger('servico_id')->nullable();
            $table->string('status_item_estoque', 40)->nullable()->default('disponivel');
            $table->unsignedTinyInteger('estoque_reservado')->default(0);
            $table->dateTime('pendencia_resolvida_em')->nullable();
            $table->text('pendencia_observacao')->nullable();
            $table->string('pendencia_fornecedor', 120)->nullable();
            $table->decimal('pendencia_valor_compra', 10, 2)->nullable();
            $table->date('pendencia_data_entrada')->nullable();
            $table->string('pendencia_tipo_aquisicao', 60)->nullable();
            $table->string('pendencia_destino_despesa', 255)->nullable();
            $table->decimal('preco_custo_referencia', 12, 2)->nullable();
            $table->decimal('preco_venda_referencia', 12, 2)->nullable();
            $table->decimal('preco_base', 12, 2)->nullable();
            $table->decimal('percentual_encargos', 7, 2)->nullable();
            $table->decimal('valor_encargos', 12, 2)->nullable();
            $table->decimal('percentual_margem', 7, 2)->nullable();
            $table->decimal('valor_margem', 12, 2)->nullable();
            $table->decimal('valor_recomendado', 12, 2)->nullable();
            $table->string('modo_precificacao', 40)->nullable();

            $table->index('os_id');
            $table->unique(['legacy_origem', 'legacy_tabela', 'legacy_id'], 'ux_os_itens_legacy_ref');
            $table->foreign('os_id')->references('id')->on('os')->cascadeOnDelete();
        });
    }

    private function createChecklistTables(): void
    {
        Schema::create('checklist_tipos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 60)->unique();
            $table->string('nome', 120);
            $table->text('descricao')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('checklist_modelos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('checklist_tipo_id');
            $table->unsignedBigInteger('tipo_equipamento_id');
            $table->string('nome', 160);
            $table->text('descricao')->nullable();
            $table->integer('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['checklist_tipo_id', 'tipo_equipamento_id', 'ativo'], 'idx_checklist_modelos_lookup');
            $table->foreign('checklist_tipo_id')->references('id')->on('checklist_tipos')->cascadeOnDelete();
            $table->foreign('tipo_equipamento_id')->references('id')->on('equipamentos_tipos')->cascadeOnDelete();
        });

        Schema::create('checklist_itens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('checklist_modelo_id');
            $table->string('descricao', 255);
            $table->integer('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['checklist_modelo_id', 'ativo', 'ordem'], 'idx_checklist_itens_modelo');
            $table->foreign('checklist_modelo_id')->references('id')->on('checklist_modelos')->cascadeOnDelete();
        });

        Schema::create('checklist_execucoes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('os_id');
            $table->unsignedBigInteger('checklist_tipo_id');
            $table->unsignedBigInteger('checklist_modelo_id');
            $table->unsignedBigInteger('tipo_equipamento_id');
            $table->string('status', 40)->default('rascunho');
            $table->integer('total_itens')->default(0);
            $table->integer('total_discrepancias')->default(0);
            $table->text('resumo_texto')->nullable();
            $table->text('observacoes_estado')->nullable();
            $table->dateTime('concluido_em')->nullable();
            $table->timestamps();

            $table->index(['os_id', 'checklist_tipo_id'], 'idx_checklist_execucoes_os_tipo');
            $table->foreign('os_id')->references('id')->on('os')->cascadeOnDelete();
            $table->foreign('checklist_tipo_id')->references('id')->on('checklist_tipos')->cascadeOnDelete();
            $table->foreign('checklist_modelo_id')->references('id')->on('checklist_modelos')->cascadeOnDelete();
            $table->foreign('tipo_equipamento_id')->references('id')->on('equipamentos_tipos')->cascadeOnDelete();
        });

        Schema::create('checklist_respostas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('checklist_execucao_id');
            $table->unsignedBigInteger('checklist_item_id');
            $table->string('descricao_item', 255);
            $table->integer('ordem')->default(0);
            $table->string('status', 40)->default('nao_verificado');
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->index(['checklist_execucao_id', 'ordem'], 'idx_checklist_respostas_execucao');
            $table->foreign('checklist_execucao_id')->references('id')->on('checklist_execucoes')->cascadeOnDelete();
            $table->foreign('checklist_item_id')->references('id')->on('checklist_itens')->cascadeOnDelete();
        });
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createBudgetRecord(array $overrides = []): int
    {
        return (int) DB::table('orcamentos')->insertGetId(array_merge([
            'numero' => 'ORC-' . now()->format('ym') . '-000001',
            'versao' => 1,
            'tipo_orcamento' => 'previo',
            'status' => 'rascunho',
            'origem' => 'manual',
            'cliente_id' => null,
            'contato_id' => null,
            'cliente_nome_avulso' => null,
            'telefone_contato' => null,
            'email_contato' => null,
            'os_id' => null,
            'equipamento_id' => null,
            'equipamento_tipo_id' => null,
            'equipamento_marca_id' => null,
            'equipamento_modelo_id' => null,
            'equipamento_cor' => null,
            'equipamento_cor_hex' => null,
            'equipamento_cor_rgb' => null,
            'conversa_id' => null,
            'responsavel_id' => null,
            'criado_por' => null,
            'atualizado_por' => null,
            'titulo' => 'Orçamento de teste',
            'validade_dias' => 10,
            'validade_data' => now()->addDays(10)->toDateString(),
            'subtotal' => 0,
            'desconto' => 0,
            'desconto_tipo' => 'valor',
            'desconto_percentual' => null,
            'acrescimo' => 0,
            'acrescimo_tipo' => 'valor',
            'acrescimo_percentual' => null,
            'total' => 0,
            'prazo_execucao' => null,
            'observacoes' => null,
            'condicoes' => null,
            'token_publico' => 'token-' . uniqid(),
            'token_expira_em' => null,
            'enviado_em' => null,
            'aprovado_em' => null,
            'rejeitado_em' => null,
            'cancelado_em' => null,
            'motivo_rejeicao' => null,
            'convertido_tipo' => null,
            'convertido_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createBudgetItemRecord(int $budgetId, array $overrides = []): int
    {
        return (int) DB::table('orcamento_itens')->insertGetId(array_merge([
            'orcamento_id' => $budgetId,
            'tipo_item' => 'servico',
            'referencia_id' => null,
            'descricao' => 'Item de teste',
            'quantidade' => 1,
            'valor_unitario' => 100.00,
            'desconto' => 0,
            'desconto_tipo' => 'valor',
            'desconto_percentual' => null,
            'acrescimo' => 0,
            'acrescimo_tipo' => 'valor',
            'acrescimo_percentual' => null,
            'total' => 100.00,
            'ordem' => 1,
            'observacoes' => null,
            'preco_custo_referencia' => 0,
            'preco_venda_referencia' => 100.00,
            'preco_base' => 100.00,
            'percentual_encargos' => 0,
            'valor_encargos' => 0,
            'percentual_margem' => 0,
            'valor_margem' => 0,
            'valor_recomendado' => 100.00,
            'modo_precificacao' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function createBudgetHistoryRecord(int $budgetId, array $overrides = []): int
    {
        return (int) DB::table('orcamento_status_historico')->insertGetId(array_merge([
            'orcamento_id' => $budgetId,
            'status_anterior' => 'rascunho',
            'status_novo' => 'enviado',
            'observacao' => 'Histórico de teste',
            'origem' => 'sistema',
            'alterado_por' => null,
            'created_at' => now(),
        ], $overrides));
    }

    private function createBudgetTables(): void
    {
        Schema::create('orcamentos', function (Blueprint $table): void {
            $table->id();
            $table->string('numero', 40)->unique();
            $table->integer('versao')->default(1);
            $table->string('tipo_orcamento', 30)->default('previo');
            $table->unsignedBigInteger('orcamento_revisao_de_id')->nullable();
            $table->string('status', 40)->default('rascunho');
            $table->string('origem', 40)->default('manual');
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->unsignedBigInteger('contato_id')->nullable();
            $table->string('cliente_nome_avulso', 160)->nullable();
            $table->string('telefone_contato', 30)->nullable();
            $table->string('email_contato', 120)->nullable();
            $table->unsignedBigInteger('os_id')->nullable();
            $table->unsignedBigInteger('equipamento_id')->nullable();
            $table->unsignedBigInteger('equipamento_tipo_id')->nullable();
            $table->unsignedBigInteger('equipamento_marca_id')->nullable();
            $table->unsignedBigInteger('equipamento_modelo_id')->nullable();
            $table->string('equipamento_cor', 100)->nullable();
            $table->string('equipamento_cor_hex', 7)->nullable();
            $table->string('equipamento_cor_rgb', 32)->nullable();
            $table->unsignedBigInteger('conversa_id')->nullable();
            $table->unsignedBigInteger('responsavel_id')->nullable();
            $table->unsignedBigInteger('criado_por')->nullable();
            $table->unsignedBigInteger('atualizado_por')->nullable();
            $table->string('titulo', 180)->nullable();
            $table->integer('validade_dias')->default(10);
            $table->date('validade_data')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('desconto', 12, 2)->default(0);
            $table->string('desconto_tipo', 20)->default('valor');
            $table->decimal('desconto_percentual', 8, 4)->nullable();
            $table->decimal('acrescimo', 12, 2)->default(0);
            $table->string('acrescimo_tipo', 20)->default('valor');
            $table->decimal('acrescimo_percentual', 8, 4)->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->string('prazo_execucao', 120)->nullable();
            $table->text('observacoes')->nullable();
            $table->text('condicoes')->nullable();
            $table->string('token_publico', 80)->nullable()->unique();
            $table->dateTime('token_expira_em')->nullable();
            $table->dateTime('enviado_em')->nullable();
            $table->dateTime('aprovado_em')->nullable();
            $table->dateTime('rejeitado_em')->nullable();
            $table->dateTime('cancelado_em')->nullable();
            $table->text('motivo_rejeicao')->nullable();
            $table->string('convertido_tipo', 30)->nullable();
            $table->unsignedBigInteger('convertido_id')->nullable();
            $table->timestamps();
        });

        Schema::create('orcamento_itens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('orcamento_id');
            $table->string('tipo_item', 30)->default('servico');
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->string('descricao', 255);
            $table->decimal('quantidade', 12, 3)->default(1);
            $table->decimal('valor_unitario', 12, 2)->default(0);
            $table->decimal('desconto', 12, 2)->default(0);
            $table->string('desconto_tipo', 20)->default('valor');
            $table->decimal('desconto_percentual', 8, 4)->nullable();
            $table->decimal('acrescimo', 12, 2)->default(0);
            $table->string('acrescimo_tipo', 20)->default('valor');
            $table->decimal('acrescimo_percentual', 8, 4)->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->integer('ordem')->default(0);
            $table->text('observacoes')->nullable();
            $table->decimal('preco_custo_referencia', 12, 2)->default(0);
            $table->decimal('preco_venda_referencia', 12, 2)->default(0);
            $table->decimal('preco_base', 12, 2)->default(0);
            $table->decimal('percentual_encargos', 12, 2)->default(0);
            $table->decimal('valor_encargos', 12, 2)->default(0);
            $table->decimal('percentual_margem', 12, 2)->default(0);
            $table->decimal('valor_margem', 12, 2)->default(0);
            $table->decimal('valor_recomendado', 12, 2)->default(0);
            $table->string('modo_precificacao', 30)->nullable();
            $table->timestamps();
            $table->foreign('orcamento_id')->references('id')->on('orcamentos')->cascadeOnDelete();
        });

        Schema::create('orcamento_status_historico', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('orcamento_id');
            $table->string('status_anterior', 80)->nullable();
            $table->string('status_novo', 80);
            $table->text('observacao')->nullable();
            $table->string('origem', 30)->default('sistema');
            $table->unsignedBigInteger('alterado_por')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->foreign('orcamento_id')->references('id')->on('orcamentos')->cascadeOnDelete();
            $table->foreign('alterado_por')->references('id')->on('usuarios')->nullOnDelete();
        });

        Schema::create('orcamento_envios', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('orcamento_id');
            $table->string('canal', 30);
            $table->string('destino', 160)->nullable();
            $table->text('mensagem')->nullable();
            $table->string('documento_path', 255)->nullable();
            $table->string('status', 30)->default('pendente');
            $table->string('provedor', 80)->nullable();
            $table->string('referencia_externa', 160)->nullable();
            $table->text('erro_detalhe')->nullable();
            $table->unsignedBigInteger('enviado_por')->nullable();
            $table->dateTime('enviado_em')->nullable();
            $table->timestamps();
            $table->foreign('orcamento_id')->references('id')->on('orcamentos')->cascadeOnDelete();
            $table->foreign('enviado_por')->references('id')->on('usuarios')->nullOnDelete();
        });

        Schema::create('orcamento_aprovacoes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('orcamento_id');
            $table->string('token_publico', 80)->nullable();
            $table->string('acao', 40);
            $table->string('origem', 30)->default('cliente');
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('usuario_nome', 120)->nullable();
            $table->text('resposta_cliente')->nullable();
            $table->text('observacao')->nullable();
            $table->string('ip_origem', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->foreign('orcamento_id')->references('id')->on('orcamentos')->cascadeOnDelete();
            $table->foreign('usuario_id')->references('id')->on('usuarios')->nullOnDelete();
        });
    }

    private function seedEquipmentCatalog(): void
    {
        DB::table('equipamentos_tipos')->insert([
            ['id' => 1, 'nome' => 'Desktop', 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'nome' => 'Notebook', 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'nome' => 'Smartphone', 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('equipamentos_marcas')->insert([
            ['id' => 1, 'nome' => 'Dell', 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'nome' => 'Montado', 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'nome' => 'Apple', 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('equipamentos_modelos')->insert([
            ['id' => 1, 'marca_id' => 1, 'nome' => 'Inspiron 15', 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'marca_id' => 2, 'nome' => 'Desktop montado', 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'marca_id' => 3, 'nome' => 'iPhone 13', 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('equipamentos_catalogo_relacoes')->insert([
            ['tipo_id' => 1, 'marca_id' => 2, 'modelo_id' => 2, 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['tipo_id' => 2, 'marca_id' => 1, 'modelo_id' => 1, 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['tipo_id' => 3, 'marca_id' => 3, 'modelo_id' => 3, 'ativo' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function seedPrecificacaoCatalog(): void
    {
        DB::table('precificacao_categorias')->insert([
            ['id' => 1, 'tipo' => 'servico', 'categoria_nome' => 'Software', 'encargos_percentual' => 10, 'margem_percentual' => 35, 'ativo' => 1, 'ordem' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'tipo' => 'peca', 'categoria_nome' => 'Insumos', 'encargos_percentual' => 5, 'margem_percentual' => 20, 'ativo' => 1, 'ordem' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('precificacao_componentes')->insert([
            ['grupo' => 'encargo_peca_percentual', 'nome' => 'Triagem e testes da peça', 'tipo_valor' => 'percentual', 'valor' => 4, 'origem' => 'manual', 'ativo' => 1, 'ordem' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'encargo_peca_percentual', 'nome' => 'Risco de garantia da peça', 'tipo_valor' => 'percentual', 'valor' => 5, 'origem' => 'manual', 'ativo' => 1, 'ordem' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'encargo_peca_percentual', 'nome' => 'Armazenagem e obsolescência', 'tipo_valor' => 'percentual', 'valor' => 3, 'origem' => 'manual', 'ativo' => 1, 'ordem' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'custo_servico_fixo', 'nome' => 'Consumíveis e limpeza técnica', 'tipo_valor' => 'valor', 'valor' => 6, 'origem' => 'manual', 'ativo' => 1, 'ordem' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['grupo' => 'risco_servico_percentual', 'nome' => 'Reserva de garantia e retrabalho', 'tipo_valor' => 'percentual', 'valor' => 3, 'origem' => 'manual', 'ativo' => 1, 'ordem' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('precificacao_categoria_encargos')->insert([
            ['categoria_id' => 1, 'nome' => 'Margem técnica de software', 'percentual' => 12, 'ativo' => 1, 'ordem' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['categoria_id' => 2, 'nome' => 'Triagem e garantia da peça', 'percentual' => 8, 'ativo' => 1, 'ordem' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
