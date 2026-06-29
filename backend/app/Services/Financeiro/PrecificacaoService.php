<?php

namespace App\Services\Financeiro;

use App\Models\Configuration;
use App\Models\Peca;
use App\Models\PrecificacaoCategoria;
use App\Models\PrecificacaoCategoriaEncargo;
use App\Models\PrecificacaoComponente;
use App\Models\PrecificacaoServicoOverride;
use App\Models\Servico;

class PrecificacaoService
{
    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'precificacao_peca_base' => 'custo',
        'precificacao_peca_encargos_percentual' => '15',
        'precificacao_peca_margem_percentual' => '45',
        'precificacao_peca_respeitar_preco_venda' => '1',
        'precificacao_peca_usa_componentes' => '1',
        'precificacao_servico_custo_hora_produtiva' => '40',
        'precificacao_servico_margem_percentual' => '25',
        'precificacao_servico_taxa_recebimento_percentual' => '3.5',
        'precificacao_servico_imposto_percentual' => '0',
        'precificacao_servico_tempo_padrao_horas' => '1',
        'precificacao_servico_usa_componentes' => '1',
        'precificacao_servico_aplicar_catalogo' => '1',
        'precificacao_servico_aplicar_piso' => '0',
    ];

    public function __construct(
        private readonly PrecificacaoComponente $componenteModel,
        private readonly PrecificacaoCategoria $categoriaModel,
        private readonly PrecificacaoCategoriaEncargo $categoriaEncargoModel,
        private readonly PrecificacaoServicoOverride $servicoOverrideModel
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $settings = $this->loadSettings();

        $pieceRules = $this->buildPieceRules($settings);
        $serviceRules = $this->buildServiceRules($settings);

        $componentesPeca = $this->componenteModel->getAtivosPorGrupo('encargo_peca_percentual', 'percentual');
        $componentesServicoCusto = $this->componenteModel->getAtivosPorGrupo('custo_servico_fixo', 'valor');
        $componentesServicoRisco = $this->componenteModel->getAtivosPorGrupo('risco_servico_percentual', 'percentual');

        $categoriasPeca = $this->categoriaModel->getAtivosPorTipo('peca');
        $categoriasServico = $this->categoriaModel->getAtivosPorTipo('servico');
        $categoriasProduto = $this->categoriaModel->getAtivosPorTipo('produto');

        $servicos = Servico::query()
            ->where('status', 'ativo')
            ->whereNull('encerrado_em')
            ->orderBy('nome')
            ->limit(200)
            ->get()
            ->toArray();

        $pecas = Peca::query()
            ->where('ativo', true)
            ->orderBy('nome')
            ->limit(200)
            ->get()
            ->toArray();

        $servicoOverrides = $this->servicoOverrideModel->getAtivos();

        return [
            'settings' => $settings,
            'summary' => [
                'piece' => $pieceRules,
                'service' => $serviceRules,
                'componentes_peca_total' => count($componentesPeca),
                'componentes_servico_custo_total' => count($componentesServicoCusto),
                'componentes_servico_risco_total' => count($componentesServicoRisco),
                'categorias_peca_total' => count($categoriasPeca),
                'categorias_servico_total' => count($categoriasServico),
                'categorias_produto_total' => count($categoriasProduto),
                'servico_overrides_total' => count($servicoOverrides),
            ],
            'rules_peca' => $pieceRules,
            'rules_servico' => $serviceRules,
            'componentes' => [
                'peca' => $componentesPeca,
                'servico_custo' => $componentesServicoCusto,
                'servico_risco' => $componentesServicoRisco,
            ],
            'categorias' => [
                'peca' => $categoriasPeca,
                'servico' => $categoriasServico,
                'produto' => $categoriasProduto,
            ],
            'categoria_encargos' => $this->buildCategoriaEncargosPayload($categoriasPeca),
            'servico_overrides' => $servicoOverrides,
            'pecas' => $pecas,
            'servicos' => $servicos,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $settings = $this->normalizeSettingsPayload($payload);

        foreach ($settings as $key => $value) {
            $this->upsertConfig((string) $key, (string) $value);
        }

        $this->syncCategorias($payload);
        $this->syncCategoriaEncargos($payload);
        $this->syncComponentes($payload);
        $this->syncServicoOverrides($payload);

        return $this->payload();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function simulatePeca(array $payload): array
    {
        $row = [];

        if (array_key_exists('preco_custo', $payload)) {
            $row['preco_custo'] = (float) $payload['preco_custo'];
        }

        if (array_key_exists('preco_venda', $payload)) {
            $row['preco_venda'] = (float) $payload['preco_venda'];
        }

        if (array_key_exists('categoria', $payload) || array_key_exists('categoria_nome', $payload)) {
            $row['categoria'] = (string) ($payload['categoria'] ?? $payload['categoria_nome'] ?? '');
        }

        $pecaId = (int) ($payload['peca_id'] ?? 0);
        if ($pecaId > 0) {
            $peca = Peca::query()->find($pecaId);
            if ($peca instanceof Peca) {
                $row = array_merge($peca->toArray(), $row);
            }
        }

        return $this->buildPieceQuote($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function simulateServico(array $payload): array
    {
        $row = [];

        if (array_key_exists('tempo_horas', $payload) || array_key_exists('tempo_padrao_horas', $payload)) {
            $row['tempo_padrao_horas'] = (float) ($payload['tempo_horas'] ?? $payload['tempo_padrao_horas'] ?? 0);
        }

        if (array_key_exists('custo_direto_padrao', $payload)) {
            $row['custo_direto_padrao'] = (float) $payload['custo_direto_padrao'];
        }

        if (array_key_exists('valor_cadastro', $payload) || array_key_exists('valor', $payload)) {
            $row['valor'] = (float) ($payload['valor_cadastro'] ?? $payload['valor'] ?? 0);
        }

        if (array_key_exists('tipo_equipamento', $payload)) {
            $row['tipo_equipamento'] = (string) $payload['tipo_equipamento'];
        }

        $servicoId = (int) ($payload['servico_id'] ?? 0);
        if ($servicoId > 0) {
            $servico = Servico::query()->find($servicoId);
            if ($servico instanceof Servico) {
                $row = array_merge($servico->toArray(), $row);
            }
        }

        return $this->buildServiceQuote($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadSettings(): array
    {
        $settings = [];

        foreach (self::DEFAULTS as $key => $default) {
            $value = Configuration::query()
                ->where('chave', $key)
                ->value('valor');

            $settings[$key] = (string) ($value ?? $default);
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildPieceRules(array $settings): array
    {
        $base = strtolower(trim((string) ($settings['precificacao_peca_base'] ?? 'custo')));
        if (! in_array($base, ['custo', 'venda'], true)) {
            $base = 'custo';
        }

        $encargos = $this->normalizePercent($settings['precificacao_peca_encargos_percentual'] ?? 15, 15.0);
        $margem = $this->normalizePercent($settings['precificacao_peca_margem_percentual'] ?? 45, 45.0);
        $respeitarPrecoVenda = $this->isTruthy($settings['precificacao_peca_respeitar_preco_venda'] ?? '1');
        $usarComponentes = $this->isTruthy($settings['precificacao_peca_usa_componentes'] ?? '1');

        $encargosComponentes = 0.0;
        if ($usarComponentes && $this->componenteModel->isTableReady()) {
            $encargosComponentes = $this->normalizePercent(
                $this->componenteModel->somarValorAtivoPorGrupo('encargo_peca_percentual', 'percentual'),
                0.0
            );
        }
        if ($encargosComponentes > 0) {
            $encargos = $encargosComponentes;
        }

        return [
            'base' => $base,
            'encargos_percentual' => round($encargos, 2),
            'margem_percentual' => round($margem, 2),
            'respeitar_preco_venda' => $respeitarPrecoVenda,
            'usar_componentes' => $usarComponentes,
            'encargos_componente_percentual' => round($encargosComponentes, 2),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildServiceRules(array $settings): array
    {
        $custoHora = $this->normalizeDecimal($settings['precificacao_servico_custo_hora_produtiva'] ?? 40, 40.0);
        $margem = $this->normalizePercent($settings['precificacao_servico_margem_percentual'] ?? 25, 25.0);
        $taxa = $this->normalizePercent($settings['precificacao_servico_taxa_recebimento_percentual'] ?? 3.5, 3.5);
        $imposto = $this->normalizePercent($settings['precificacao_servico_imposto_percentual'] ?? 0, 0.0);
        $tempoPadrao = $this->normalizeDecimal($settings['precificacao_servico_tempo_padrao_horas'] ?? 1, 1.0);
        if ($tempoPadrao <= 0) {
            $tempoPadrao = 1.0;
        }

        $usarComponentes = $this->isTruthy($settings['precificacao_servico_usa_componentes'] ?? '1');
        $aplicarCatalogo = $this->isTruthy($settings['precificacao_servico_aplicar_catalogo'] ?? '1');
        $aplicarPiso = $this->isTruthy($settings['precificacao_servico_aplicar_piso'] ?? '0');

        $custosDiretosComponente = 0.0;
        $riscoComponente = 0.0;
        if ($usarComponentes && $this->componenteModel->isTableReady()) {
            $custosDiretosComponente = max(0.0, $this->componenteModel->somarValorAtivoPorGrupo('custo_servico_fixo', 'valor'));
            $riscoComponente = $this->normalizePercent(
                $this->componenteModel->somarValorAtivoPorGrupo('risco_servico_percentual', 'percentual'),
                0.0
            );
        }

        return [
            'custo_hora_produtiva' => round($custoHora, 2),
            'margem_percentual' => round($margem, 2),
            'taxa_recebimento_percentual' => round($taxa, 2),
            'imposto_percentual' => round($imposto, 2),
            'tempo_padrao_horas' => round($tempoPadrao, 2),
            'usar_componentes' => $usarComponentes,
            'aplicar_catalogo' => $aplicarCatalogo,
            'aplicar_piso' => $aplicarPiso,
            'custo_direto_componente' => round($custosDiretosComponente, 2),
            'risco_percentual_componente' => round($riscoComponente, 2),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function normalizeSettingsPayload(array $payload): array
    {
        $normalized = [];

        foreach (self::DEFAULTS as $key => $default) {
            $value = $payload[$key] ?? $default;
            $normalized[$key] = (string) $this->normalizeSettingValue($key, $value);
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     */
    private function normalizeSettingValue(string $key, mixed $value): string
    {
        return match ($key) {
            'precificacao_peca_base' => in_array(strtolower(trim((string) $value)), ['venda', 'custo'], true)
                ? strtolower(trim((string) $value))
                : self::DEFAULTS[$key],
            'precificacao_peca_respeitar_preco_venda',
            'precificacao_peca_usa_componentes',
            'precificacao_servico_usa_componentes',
            'precificacao_servico_aplicar_catalogo',
            'precificacao_servico_aplicar_piso' => $this->normalizeBool($value),
            default => (string) $this->normalizeDecimal($value, (float) self::DEFAULTS[$key]),
        };
    }

    private function syncCategorias(array $payload): void
    {
        $ids = (array) ($payload['categoria_id'] ?? []);
        $tipos = (array) ($payload['categoria_tipo'] ?? []);
        $nomes = (array) ($payload['categoria_nome'] ?? []);
        $encargos = (array) ($payload['categoria_encargos'] ?? []);
        $margens = (array) ($payload['categoria_margem'] ?? []);
        $ordens = (array) ($payload['categoria_ordem'] ?? []);
        $ativos = (array) ($payload['categoria_ativo'] ?? []);

        $max = max(count($ids), count($tipos), count($nomes), count($encargos), count($margens), count($ordens), count($ativos));
        $keepIds = [];
        $ordem = 1;

        for ($i = 0; $i < $max; $i++) {
            $tipo = strtolower(trim((string) ($tipos[$i] ?? '')));
            $nome = trim((string) ($nomes[$i] ?? ''));
            if ($tipo === '' || $nome === '') {
                continue;
            }

            $payloadRow = [
                'tipo' => $tipo,
                'categoria_nome' => mb_substr($nome, 0, 120),
                'encargos_percentual' => $this->normalizePercent($encargos[$i] ?? 0, 0.0),
                'margem_percentual' => $this->normalizePercent($margens[$i] ?? 0, 0.0),
                'ativo' => filter_var($ativos[$i] ?? true, FILTER_VALIDATE_BOOL),
                'ordem' => (int) ($ordens[$i] ?? $ordem),
            ];

            $id = (int) ($ids[$i] ?? 0);
            if ($id > 0) {
                $this->categoriaModel->query()->whereKey($id)->update($payloadRow);
                $keepIds[] = $id;
            } else {
                $newId = $this->categoriaModel->query()->insertGetId($payloadRow);
                if ($newId > 0) {
                    $keepIds[] = $newId;
                }
            }

            $ordem++;
        }

        if ($keepIds === []) {
            return;
        }

        $this->categoriaModel->query()
            ->whereIn('id', $keepIds)
            ->update(['ativo' => true]);
    }

    private function syncCategoriaEncargos(array $payload): void
    {
        $ids = (array) ($payload['encargo_id'] ?? []);
        $categoriaIds = (array) ($payload['encargo_categoria_id'] ?? $payload['categoria_encargo_categoria_id'] ?? []);
        $nomes = (array) ($payload['encargo_nome'] ?? []);
        $valores = (array) ($payload['encargo_valor'] ?? []);

        $max = max(count($ids), count($categoriaIds), count($nomes), count($valores));
        $keepIds = [];
        $ordem = 1;

        for ($i = 0; $i < $max; $i++) {
            $categoriaId = (int) ($categoriaIds[$i] ?? 0);
            $nome = trim((string) ($nomes[$i] ?? ''));
            if ($categoriaId <= 0 || $nome === '') {
                continue;
            }

            $payloadRow = [
                'categoria_id' => $categoriaId,
                'nome' => mb_substr($nome, 0, 140),
                'percentual' => $this->normalizePercent($valores[$i] ?? 0, 0.0),
                'ativo' => true,
                'ordem' => $ordem,
            ];

            $id = (int) ($ids[$i] ?? 0);
            if ($id > 0) {
                $this->categoriaEncargoModel->query()->whereKey($id)->update($payloadRow);
                $keepIds[] = $id;
            } else {
                $newId = $this->categoriaEncargoModel->query()->insertGetId($payloadRow);
                if ($newId > 0) {
                    $keepIds[] = $newId;
                }
            }

            $ordem++;
        }

        if ($keepIds === []) {
            return;
        }

        $this->categoriaEncargoModel->query()
            ->whereIn('id', $keepIds)
            ->update(['ativo' => true]);
    }

    private function syncComponentes(array $payload): void
    {
        $groups = [
            'encargo_peca_percentual' => [
                'ids' => (array) ($payload['componentes_peca_id'] ?? []),
                'nomes' => (array) ($payload['componentes_peca_nome'] ?? []),
                'valores' => (array) ($payload['componentes_peca_valor'] ?? []),
                'tipo' => 'percentual',
            ],
            'custo_servico_fixo' => [
                'ids' => (array) ($payload['componentes_servico_custo_id'] ?? []),
                'nomes' => (array) ($payload['componentes_servico_custo_nome'] ?? []),
                'valores' => (array) ($payload['componentes_servico_custo_valor'] ?? []),
                'tipo' => 'valor',
            ],
            'risco_servico_percentual' => [
                'ids' => (array) ($payload['componentes_servico_risco_id'] ?? []),
                'nomes' => (array) ($payload['componentes_servico_risco_nome'] ?? []),
                'valores' => (array) ($payload['componentes_servico_risco_valor'] ?? []),
                'tipo' => 'percentual',
            ],
        ];

        foreach ($groups as $grupo => $groupPayload) {
            $this->syncComponentGroup($grupo, $groupPayload['tipo'], $groupPayload['ids'], $groupPayload['nomes'], $groupPayload['valores']);
        }
    }

    /**
     * @param array<int, mixed> $ids
     * @param array<int, mixed> $nomes
     * @param array<int, mixed> $valores
     */
    private function syncComponentGroup(string $grupo, string $tipoValor, array $ids, array $nomes, array $valores): void
    {
        $max = max(count($ids), count($nomes), count($valores));
        $keepIds = [];
        $ordem = 1;

        for ($i = 0; $i < $max; $i++) {
            $nome = trim((string) ($nomes[$i] ?? ''));
            if ($nome === '') {
                continue;
            }

            $payloadRow = [
                'grupo' => $grupo,
                'nome' => mb_substr($nome, 0, 120),
                'tipo_valor' => $tipoValor,
                'valor' => round($this->normalizeDecimal($valores[$i] ?? 0, 0.0), 4),
                'origem' => 'manual',
                'ativo' => true,
                'ordem' => $ordem,
            ];

            $id = (int) ($ids[$i] ?? 0);
            if ($id > 0) {
                $this->componenteModel->query()->whereKey($id)->update($payloadRow);
                $keepIds[] = $id;
            } else {
                $newId = $this->componenteModel->query()->insertGetId($payloadRow);
                if ($newId > 0) {
                    $keepIds[] = $newId;
                }
            }

            $ordem++;
        }

        if ($keepIds === []) {
            return;
        }

        $this->componenteModel->query()
            ->whereIn('id', $keepIds)
            ->update(['ativo' => true]);
    }

    private function syncServicoOverrides(array $payload): void
    {
        $ids = (array) ($payload['servico_override_id'] ?? []);
        $servicoIds = (array) ($payload['servico_override_servico_id'] ?? []);
        $custoHora = (array) ($payload['servico_override_custo_hora'] ?? []);
        $custosDiretos = (array) ($payload['servico_override_custos_diretos'] ?? []);
        $margem = (array) ($payload['servico_override_margem'] ?? []);
        $taxa = (array) ($payload['servico_override_taxa'] ?? []);
        $imposto = (array) ($payload['servico_override_imposto'] ?? []);
        $tempoTecnico = (array) ($payload['servico_override_tempo_tecnico'] ?? []);
        $risco = (array) ($payload['servico_override_risco'] ?? []);
        $precoTabela = (array) ($payload['servico_override_preco_tabela'] ?? []);
        $custosFixosMensais = (array) ($payload['servico_override_custos_fixos_mensais'] ?? []);
        $tecnicosAtivos = (array) ($payload['servico_override_tecnicos_ativos'] ?? []);
        $horasProdutivasDia = (array) ($payload['servico_override_horas_produtivas_dia'] ?? []);
        $diasUteisMes = (array) ($payload['servico_override_dias_uteis_mes'] ?? []);
        $consumiveisValor = (array) ($payload['servico_override_consumiveis_valor'] ?? []);
        $tempoIndiretoHoras = (array) ($payload['servico_override_tempo_indireto_horas'] ?? []);
        $reservaGarantiaValor = (array) ($payload['servico_override_reserva_garantia_valor'] ?? []);
        $perdasPequenasValor = (array) ($payload['servico_override_perdas_pequenas_valor'] ?? []);
        $tempoDesmontagemMin = (array) ($payload['servico_override_tempo_desmontagem_min'] ?? []);
        $tempoSubstituicaoMin = (array) ($payload['servico_override_tempo_substituicao_min'] ?? []);
        $tempoMontagemMin = (array) ($payload['servico_override_tempo_montagem_min'] ?? []);
        $tempoTesteFinalMin = (array) ($payload['servico_override_tempo_teste_final_min'] ?? []);

        $max = max(
            count($ids),
            count($servicoIds),
            count($custoHora),
            count($custosDiretos),
            count($margem),
            count($taxa),
            count($imposto),
            count($tempoTecnico),
            count($risco),
            count($precoTabela),
            count($custosFixosMensais),
            count($tecnicosAtivos),
            count($horasProdutivasDia),
            count($diasUteisMes),
            count($consumiveisValor),
            count($tempoIndiretoHoras),
            count($reservaGarantiaValor),
            count($perdasPequenasValor),
            count($tempoDesmontagemMin),
            count($tempoSubstituicaoMin),
            count($tempoMontagemMin),
            count($tempoTesteFinalMin),
        );

        for ($i = 0; $i < $max; $i++) {
            $servicoId = (int) ($servicoIds[$i] ?? 0);
            if ($servicoId <= 0) {
                continue;
            }

            $payloadRow = [
                'servico_id' => $servicoId,
                'custo_hora_produtiva' => round($this->normalizeDecimal($custoHora[$i] ?? 0, 0.0), 4),
                'custos_diretos_total' => round($this->normalizeDecimal($custosDiretos[$i] ?? 0, 0.0), 4),
                'margem_percentual' => round($this->normalizePercent($margem[$i] ?? 0, 0.0), 4),
                'taxa_recebimento_percentual' => round($this->normalizePercent($taxa[$i] ?? 0, 0.0), 4),
                'imposto_percentual' => round($this->normalizePercent($imposto[$i] ?? 0, 0.0), 4),
                'tempo_tecnico_horas' => round($this->normalizeDecimal($tempoTecnico[$i] ?? 0, 0.0), 4),
                'risco_percentual' => round($this->normalizePercent($risco[$i] ?? 0, 0.0), 4),
                'preco_tabela_referencia' => round($this->normalizeDecimal($precoTabela[$i] ?? 0, 0.0), 4),
                'custos_fixos_mensais' => round($this->normalizeDecimal($custosFixosMensais[$i] ?? 0, 0.0), 4),
                'tecnicos_ativos' => round($this->normalizeDecimal($tecnicosAtivos[$i] ?? 1, 1.0), 4),
                'horas_produtivas_dia' => round($this->normalizeDecimal($horasProdutivasDia[$i] ?? 0, 0.0), 4),
                'dias_uteis_mes' => round($this->normalizeDecimal($diasUteisMes[$i] ?? 1, 1.0), 4),
                'consumiveis_valor' => round($this->normalizeDecimal($consumiveisValor[$i] ?? 0, 0.0), 4),
                'tempo_indireto_horas' => round($this->normalizeDecimal($tempoIndiretoHoras[$i] ?? 0, 0.0), 4),
                'reserva_garantia_valor' => round($this->normalizeDecimal($reservaGarantiaValor[$i] ?? 0, 0.0), 4),
                'perdas_pequenas_valor' => round($this->normalizeDecimal($perdasPequenasValor[$i] ?? 0, 0.0), 4),
                'tempo_desmontagem_min' => round($this->normalizeDecimal($tempoDesmontagemMin[$i] ?? 0, 0.0), 4),
                'tempo_substituicao_min' => round($this->normalizeDecimal($tempoSubstituicaoMin[$i] ?? 0, 0.0), 4),
                'tempo_montagem_min' => round($this->normalizeDecimal($tempoMontagemMin[$i] ?? 0, 0.0), 4),
                'tempo_teste_final_min' => round($this->normalizeDecimal($tempoTesteFinalMin[$i] ?? 0, 0.0), 4),
                'ativo' => true,
            ];

            $id = (int) ($ids[$i] ?? 0);
            if ($id > 0) {
                $this->servicoOverrideModel->query()->whereKey($id)->update($payloadRow);
            } else {
                $this->servicoOverrideModel->query()->updateOrInsert(
                    ['servico_id' => $servicoId],
                    $payloadRow
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $categorias
     * @return array<int, array<string, mixed>>
     */
    private function buildCategoriaEncargosPayload(array $categorias): array
    {
        $payload = [];

        foreach ($categorias as $categoria) {
            $categoriaId = (int) ($categoria['id'] ?? 0);
            if ($categoriaId <= 0) {
                continue;
            }

            $payload[] = [
                'categoria' => $categoria,
                'encargos' => $this->categoriaEncargoModel->getAtivosPorCategoria($categoriaId),
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildPieceQuote(array $row): array
    {
        $settings = $this->buildPieceRules($this->loadSettings());
        $precoCusto = max(0.0, (float) ($row['preco_custo'] ?? 0));
        $precoVenda = max(0.0, (float) ($row['preco_venda'] ?? 0));

        $override = $this->lookupCategoryOverride('peca', (string) ($row['categoria'] ?? ''));
        if ($override !== null) {
            $settings['encargos_percentual'] = (float) ($override['encargos_percentual'] ?? $settings['encargos_percentual']);
            $settings['margem_percentual'] = (float) ($override['margem_percentual'] ?? $settings['margem_percentual']);
        }

        $basePreferida = $settings['base'] === 'venda' ? $precoVenda : $precoCusto;
        $baseAlternativa = $settings['base'] === 'venda' ? $precoCusto : $precoVenda;
        $precoBase = $basePreferida > 0 ? $basePreferida : $baseAlternativa;

        $encargosValor = round($precoBase * ((float) $settings['encargos_percentual'] / 100), 2);
        $margemValor = round($precoBase * ((float) $settings['margem_percentual'] / 100), 2);
        $valorCalculado = round($precoBase + $encargosValor + $margemValor, 2);
        $valorRecomendado = $valorCalculado;

        if ((bool) $settings['respeitar_preco_venda'] && $precoVenda > $valorRecomendado) {
            $valorRecomendado = round($precoVenda, 2);
        }

        return [
            'preco_custo_referencia' => round($precoCusto, 2),
            'preco_venda_referencia' => round($precoVenda, 2),
            'preco_base' => round($precoBase, 2),
            'percentual_encargos' => round((float) $settings['encargos_percentual'], 2),
            'valor_encargos' => $encargosValor,
            'percentual_margem' => round((float) $settings['margem_percentual'], 2),
            'valor_margem' => $margemValor,
            'valor_recomendado' => round(max(0.0, $valorRecomendado), 2),
            'categoria_override' => $override,
            'regra_base' => (string) $settings['base'],
            'regra_respeita_preco_venda' => (bool) $settings['respeitar_preco_venda'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildServiceQuote(array $row): array
    {
        $settings = $this->buildServiceRules($this->loadSettings());
        $servicoId = (int) ($row['id'] ?? 0);

        $categoryOverride = $this->lookupCategoryOverride(
            'servico',
            (string) ($row['categoria'] ?? $row['tipo_equipamento'] ?? '')
        );
        if ($categoryOverride !== null) {
            $settings['margem_percentual'] = (float) ($categoryOverride['margem_percentual'] ?? $settings['margem_percentual']);
            $settings['risco_percentual_componente'] = (float) ($categoryOverride['encargos_percentual'] ?? $settings['risco_percentual_componente']);
        }

        $override = $this->lookupServiceOverride($servicoId);
        if ($override !== null) {
            $settings['custo_hora_produtiva'] = (float) ($override['custo_hora_produtiva'] ?? $settings['custo_hora_produtiva']);
            $settings['margem_percentual'] = (float) ($override['margem_percentual'] ?? $settings['margem_percentual']);
            $settings['taxa_recebimento_percentual'] = (float) ($override['taxa_recebimento_percentual'] ?? $settings['taxa_recebimento_percentual']);
            $settings['imposto_percentual'] = (float) ($override['imposto_percentual'] ?? $settings['imposto_percentual']);
            $settings['tempo_padrao_horas'] = (float) ($override['tempo_tecnico_horas'] ?? $settings['tempo_padrao_horas']);
            $settings['risco_percentual_componente'] = (float) ($override['risco_percentual'] ?? $settings['risco_percentual_componente']);
        }

        $tempoPadrao = $this->normalizeDecimal($row['tempo_padrao_horas'] ?? $settings['tempo_padrao_horas'], (float) $settings['tempo_padrao_horas']);
        if ($tempoPadrao <= 0) {
            $tempoPadrao = (float) $settings['tempo_padrao_horas'];
        }

        $custoDiretoPadrao = max(0.0, $this->normalizeDecimal($row['custo_direto_padrao'] ?? 0, 0.0));
        $custoDiretoComponente = max(0.0, (float) $settings['custo_direto_componente']);
        if ($override !== null) {
            $custoDiretoPadrao = max($custoDiretoPadrao, (float) ($override['custos_diretos_total'] ?? 0));
            $custoDiretoComponente = 0.0;
        }
        $custoDiretoTotal = round($custoDiretoPadrao + $custoDiretoComponente, 2);

        $custoHora = max(0.0, (float) $settings['custo_hora_produtiva']);
        $custoMaoObra = round($tempoPadrao * $custoHora, 2);
        $custoBase = round($custoMaoObra + $custoDiretoTotal, 2);

        $riscoPercentual = max(0.0, (float) $settings['risco_percentual_componente']);
        $valorRisco = round($custoBase * ($riscoPercentual / 100), 2);
        $custoTotal = round($custoBase + $valorRisco, 2);

        $margem = max(0.0, (float) $settings['margem_percentual']);
        $taxa = max(0.0, (float) $settings['taxa_recebimento_percentual']);
        $imposto = max(0.0, (float) $settings['imposto_percentual']);

        $divisor = 1 - (($margem + $taxa + $imposto) / 100);
        if ($divisor <= 0.01) {
            $divisor = 0.01;
        }

        $precoMinimo = round($custoTotal / $divisor, 2);
        $valorCadastro = max(0.0, (float) ($row['valor'] ?? 0));
        if ($override !== null) {
            $valorCadastro = max($valorCadastro, (float) ($override['preco_tabela_referencia'] ?? 0));
        }

        $valorRecomendado = $precoMinimo;
        if ((bool) $settings['aplicar_catalogo']) {
            $valorRecomendado = max($valorRecomendado, $valorCadastro);
        }

        return [
            'tempo_padrao_horas' => round($tempoPadrao, 2),
            'custo_hora_produtiva' => round($custoHora, 2),
            'custo_mao_obra' => $custoMaoObra,
            'custo_direto_padrao' => round($custoDiretoPadrao, 2),
            'custo_direto_componente' => round($custoDiretoComponente, 2),
            'custo_direto_total' => $custoDiretoTotal,
            'risco_percentual' => round($riscoPercentual, 2),
            'valor_risco' => $valorRisco,
            'custo_total' => $custoTotal,
            'margem_percentual' => round($margem, 2),
            'taxa_recebimento_percentual' => round($taxa, 2),
            'imposto_percentual' => round($imposto, 2),
            'divisor_preco' => round($divisor, 4),
            'preco_minimo' => $precoMinimo,
            'valor_cadastro' => round($valorCadastro, 2),
            'valor_recomendado' => round($valorRecomendado, 2),
            'modo_precificacao' => $valorRecomendado > $valorCadastro ? 'servico_auto_recomendado' : 'servico_cadastro',
            'categoria_override' => $categoryOverride,
            'servico_override' => $override,
        ];
    }

    private function lookupCategoryOverride(string $type, string $categoryName): ?array
    {
        $categoryName = trim($categoryName);
        if ($categoryName === '') {
            return null;
        }

        $map = $this->categoriaModel->getMapaPorTipo($type);
        $key = function_exists('mb_strtolower') ? mb_strtolower($categoryName) : strtolower($categoryName);

        return $map[$key] ?? null;
    }

    private function lookupServiceOverride(int $servicoId): ?array
    {
        if ($servicoId <= 0) {
            return null;
        }

        return $this->servicoOverrideModel->getAtivoByServicoId($servicoId);
    }

    private function upsertConfig(string $key, string $value): void
    {
        Configuration::query()->updateOrInsert(
            ['chave' => $key],
            [
                'valor' => $value,
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * @param mixed $value
     */
    private function normalizeDecimal(mixed $value, float $fallback): float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return $fallback;
        }

        $raw = str_replace(',', '.', $raw);
        if (! is_numeric($raw)) {
            return $fallback;
        }

        return (float) $raw;
    }

    /**
     * @param mixed $value
     */
    private function normalizePercent(mixed $value, float $fallback): float
    {
        $percent = $this->normalizeDecimal($value, $fallback);
        if ($percent < 0) {
            return 0.0;
        }

        return min($percent, 500.0);
    }

    /**
     * @param mixed $value
     */
    private function normalizeBool(mixed $value): string
    {
        return $this->isTruthy($value) ? '1' : '0';
    }

    /**
     * @param mixed $value
     */
    private function isTruthy(mixed $value): bool
    {
        return ! in_array(strtolower(trim((string) $value)), ['0', 'false', 'nao', 'não', 'no'], true);
    }
}
