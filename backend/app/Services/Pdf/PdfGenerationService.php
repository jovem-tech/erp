<?php

namespace App\Services\Pdf;

use App\Models\PdfTemplate;
use App\Models\PdfTemplateVersao;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\Pdf\Contexts\CompanyContextProvider;
use App\Services\Pdf\Contexts\PdfContextFactoryInterface;
use App\Services\Pdf\Snapshots\DocumentSnapshotHydrator;
use App\Services\Pdf\Snapshots\DocumentSnapshotSerializer;
use App\Services\Signatures\SignatureImageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Entrada ÚNICA de geração de PDF do sistema. Nenhuma feature deve chamar
 * Pdf::loadView/loadHTML diretamente — tudo passa por generate():
 * registry -> template publicado (cacheado) -> DocumentContext completo ->
 * renderer de blocos -> dompdf (isRemoteEnabled=false, isPhpEnabled=false)
 * -> numeração de páginas via canvas page_text.
 */
class PdfGenerationService
{
    private const SCHEMA_CACHE_TTL_SECONDS = 3600;

    private const MAX_RENDER_SECONDS_WARNING = 10;

    public function __construct(
        private readonly PdfTemplateRegistry $registry,
        private readonly PdfTemplateRenderer $renderer,
        private readonly CompanyContextProvider $companyContextProvider,
        private readonly SignatureImageService $signatureImageService,
        private readonly DocumentSnapshotSerializer $snapshotSerializer,
        private readonly DocumentSnapshotHydrator $snapshotHydrator,
        private readonly PdfCompressionService $compressionService
    ) {
    }

    public function publishedTemplateFingerprint(string $tipoCodigo): ?string
    {
        $version = $this->resolvePublishedVersion($tipoCodigo);
        if (! $version instanceof PdfTemplateVersao) {
            return null;
        }

        return hash('sha256', implode('|', [
            (string) $version->template_id,
            (string) $version->versao,
            (string) ($version->hash_schema ?? ''),
        ]));
    }

    /**
     * @param array<string, mixed> $subject ex.: ['order' => Order] | ['budget' => Budget]
     * @param array<string, mixed> $options formato (a4|80mm), actor (User), approval_link, dados do encerramento,
     *                                      render_profile (padrao|assinado), capture_snapshot (bool)...
     * @return array{ok: bool, bytes?: string, template_id?: int, template_versao?: int, hash_schema?: string, tipo_codigo?: string, snapshot?: array<string, mixed>, hash_snapshot?: string, message?: string}
     */
    public function generate(string $tipoCodigo, array $subject, array $options = []): array
    {
        $descriptor = $this->registry->get($tipoCodigo);
        if ($descriptor === null) {
            return ['ok' => false, 'message' => sprintf('Tipo documental desconhecido: "%s".', $tipoCodigo)];
        }

        $versao = $this->resolvePublishedVersion($tipoCodigo);
        if (! $versao instanceof PdfTemplateVersao) {
            return ['ok' => false, 'message' => sprintf('Nenhum template publicado para o tipo "%s".', $tipoCodigo)];
        }

        $schema = $this->cachedSchema($versao);
        if ($schema === []) {
            return ['ok' => false, 'message' => sprintf('Schema vazio/ inválido no template publicado de "%s".', $tipoCodigo)];
        }

        try {
            /** @var PdfContextFactoryInterface $factory */
            $factory = app((string) $descriptor['context_factory']);
            $context = $factory->build($subject, array_merge($options, [
                'image_tokens' => $this->imageTokens($schema),
            ]));
            if ($context === []) {
                return ['ok' => false, 'message' => 'Entidade do documento não encontrada para montar o contexto.'];
            }

            $actor = ($options['actor'] ?? null) instanceof User ? $options['actor'] : null;
            $context['empresa'] = $this->companyContextProvider->build();
            $context['documento'] = [
                'nome' => (string) ($descriptor['nome'] ?? $tipoCodigo),
                'codigo' => $tipoCodigo,
                'gerado_em' => Carbon::now(),
                'usuario' => $actor?->nome ?? 'Sistema',
                'versao_template' => 'v' . (int) $versao->versao,
            ];

            $unsignedReview = (bool) ($options['unsigned_review'] ?? false);
            $signatureMetadata = $unsignedReview
                ? [
                    'context' => ['responsavel' => null, 'cliente' => null],
                    'audit' => ['modo' => 'previa_sem_assinatura'],
                ]
                : $this->signatureContext($actor, $options);
            $hasHumanSigner = ! $unsignedReview && (
                $actor instanceof User
                || ($options['signature_signer'] ?? null) instanceof User
            );
            if (
                $hasHumanSigner
                && (bool) config('document-signatures.require_user_signature', true)
                && (int) ($signatureMetadata['audit']['usuario_id'] ?? 0) <= 0
            ) {
                return [
                    'ok' => false,
                    'message' => 'O usuário responsável deve cadastrar sua assinatura no perfil antes de emitir documentos.',
                ];
            }
            $context['assinaturas'] = $signatureMetadata['context'];

            $formato = self::normalizeFormat($options['formato'] ?? null);
            $startedAt = microtime(true);

            $bytes = $this->renderPdfBytes($schema, $context, $descriptor, $formato);

            $elapsed = microtime(true) - $startedAt;
            if ($elapsed > self::MAX_RENDER_SECONDS_WARNING) {
                logger()->warning('[PDF ENGINE] Render lento', [
                    'tipo' => $tipoCodigo,
                    'formato' => $formato,
                    'segundos' => round($elapsed, 2),
                    'bytes' => strlen($bytes),
                ]);
            }

            $result = [
                'ok' => true,
                'bytes' => $bytes,
                'template_id' => (int) $versao->template_id,
                'template_versao' => (int) $versao->versao,
                'hash_schema' => (string) ($versao->hash_schema ?? ''),
                'tipo_codigo' => $tipoCodigo,
                'assinatura' => $signatureMetadata['audit'],
            ];

            if ((bool) ($options['capture_snapshot'] ?? false)) {
                $snapshot = $this->snapshotSerializer->fromEngineContext(
                    $context,
                    $descriptor,
                    $versao,
                    $signatureMetadata['audit'],
                    self::renderProfileDescriptor($options),
                    $this->companyContextProvider->logoReference(),
                    is_array($options['customer_signature'] ?? null) ? $options['customer_signature'] : null
                );
                $result['snapshot'] = $snapshot;
                $result['hash_snapshot'] = DocumentSnapshotSerializer::canonicalHash($snapshot);
            }

            return $result;
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'message' => 'Falha ao renderizar o documento PDF.'];
        }
    }

    /**
     * Pré-visualização de um schema arbitrário (rascunho do editor), com
     * entidade real ou contexto simulado. Não persiste nada.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed>|null $subject null => contexto simulado
     * @param array<string, mixed> $options
     * @return array{ok: bool, bytes?: string, message?: string}
     */
    public function renderPreview(string $tipoCodigo, array $schema, ?array $subject, array $options = []): array
    {
        $descriptor = $this->registry->get($tipoCodigo);
        if ($descriptor === null) {
            return ['ok' => false, 'message' => sprintf('Tipo documental desconhecido: "%s".', $tipoCodigo)];
        }

        try {
            if ($subject !== null) {
                /** @var PdfContextFactoryInterface $factory */
                $factory = app((string) $descriptor['context_factory']);
                $context = $factory->build($subject, array_merge($options, [
                    'image_tokens' => $this->imageTokens($schema),
                ]));
                if ($context === []) {
                    return ['ok' => false, 'message' => 'Entidade informada para a prévia não foi encontrada.'];
                }
            } else {
                $context = PdfSampleContext::for($descriptor);
            }

            $context['empresa'] = $this->companyContextProvider->build();
            $context['documento'] = [
                'nome' => (string) ($descriptor['nome'] ?? $tipoCodigo) . ' (prévia)',
                'codigo' => $tipoCodigo,
                'gerado_em' => Carbon::now(),
                'usuario' => 'Prévia do editor',
                'versao_template' => 'rascunho',
            ];

            $formato = self::normalizeFormat($options['formato'] ?? null);

            return ['ok' => true, 'bytes' => $this->renderPdfBytes($schema, $context, $descriptor, $formato)];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'message' => 'Falha ao renderizar a prévia do documento.'];
        }
    }

    /**
     * Re-render de uma versão documental a partir do snapshot gravado na
     * emissão. Pina a versão de template usada na época (cai para a
     * publicada se ela sumiu, anotando divergência), reidrata imagens e
     * rubricas por referência e NÃO aplica as regras de emissão (assinatura
     * obrigatória etc.) — isto é leitura, não emissão.
     *
     * @param array<string, mixed> $envelope
     * @param array<string, mixed> $options
     * @return array{ok: bool, bytes?: string, divergencias?: array<int, string>, message?: string}
     */
    public function renderSnapshot(array $envelope, string $formato, array $options = []): array
    {
        $tipoCodigo = trim((string) ($envelope['tipo_codigo'] ?? ''));
        $descriptor = $tipoCodigo !== '' ? $this->registry->get($tipoCodigo) : null;
        if ($descriptor === null) {
            return ['ok' => false, 'message' => sprintf('Tipo documental desconhecido no snapshot: "%s".', $tipoCodigo)];
        }

        $divergencias = [];
        $versaoId = (int) ($envelope['template']['versao_id'] ?? 0);
        $versao = $versaoId > 0 ? PdfTemplateVersao::query()->find($versaoId) : null;
        if (! $versao instanceof PdfTemplateVersao) {
            $versao = $this->resolvePublishedVersion($tipoCodigo);
            $divergencias[] = 'template_original_indisponivel';
        }
        if (! $versao instanceof PdfTemplateVersao) {
            return ['ok' => false, 'message' => sprintf('Nenhum template disponível para re-renderizar "%s".', $tipoCodigo)];
        }

        $schema = $this->cachedSchema($versao);
        if ($schema === []) {
            return ['ok' => false, 'message' => 'Schema vazio/ inválido no template do snapshot.'];
        }

        try {
            $hydrated = $this->snapshotHydrator->toRenderContext($envelope, $descriptor, $this->imageTokens($schema));
            $context = $hydrated['context'];
            $divergencias = array_values(array_unique(array_merge($divergencias, $hydrated['divergencias'])));

            $bytes = $this->renderPdfBytes($schema, $context, $descriptor, self::normalizeFormat($formato));

            return [
                'ok' => true,
                'bytes' => $bytes,
                'divergencias' => $divergencias,
                'template_id' => (int) $versao->template_id,
                'template_versao' => (int) $versao->versao,
                'hash_schema' => (string) ($versao->hash_schema ?? ''),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'message' => 'Falha ao re-renderizar o documento a partir do snapshot.'];
        }
    }

    /**
     * Descritor do perfil de compressão de fotos usado na emissão, gravado
     * no snapshot para o re-render reproduzir o mesmo resultado.
     *
     * @param array<string, mixed> $options
     * @return array{perfil: string, foto_max_dim: int, foto_qualidade: int}
     */
    public static function renderProfileDescriptor(array $options): array
    {
        $name = trim((string) ($options['render_profile'] ?? 'padrao')) ?: 'padrao';
        $profile = config('document-rendering.photos.'.$name);
        if (! is_array($profile)) {
            $name = 'padrao';
            $profile = (array) config('document-rendering.photos.padrao', []);
        }

        return [
            'perfil' => $name,
            'foto_max_dim' => (int) ($profile['max_dimension'] ?? 1400),
            'foto_qualidade' => (int) ($profile['quality'] ?? 72),
        ];
    }

    public static function normalizeFormat(mixed $formato): string
    {
        return strtolower(trim((string) ($formato ?? 'a4'))) === '80mm' ? '80mm' : 'a4';
    }

    /**
     * Miolo único do dompdf: HTML dos blocos -> papel -> render -> numeração
     * de páginas via canvas -> bytes. Usado pela emissão, pela prévia do
     * editor e pelo re-render a partir de snapshot.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $context
     * @param array<string, mixed> $descriptor
     */
    private function renderPdfBytes(array $schema, array $context, array $descriptor, string $formato): string
    {
        $html = $this->renderer->render($schema, $context, $descriptor, $formato);

        // Marcadores de paginação são aplicados via canvas (page_text) —
        // removidos do HTML para não imprimirem literalmente.
        $hasPageMarkers = str_contains($html, '{PAGE_NUM}') || str_contains($html, '{PAGE_COUNT}');
        if ($hasPageMarkers) {
            $html = str_replace(['Página {PAGE_NUM} de {PAGE_COUNT}', '{PAGE_NUM}', '{PAGE_COUNT}'], '', $html);
        }

        $pagina = is_array($schema['pagina'] ?? null) ? $schema['pagina'] : [];
        $orientation = strtolower(trim((string) ($pagina['orientacao'] ?? 'retrato'))) === 'paisagem' ? 'landscape' : 'portrait';

        $pdf = Pdf::loadHTML($html)
            ->setOption('isRemoteEnabled', false)
            ->setOption('isPhpEnabled', false)
            // Sem subsetting o dompdf embute as DejaVu inteiras (~1,4 MB de
            // TTF) em todo documento — um cupom 80mm sem foto pesava 800 KB.
            ->setOption('isFontSubsettingEnabled', (bool) config('document-rendering.font_subsetting', true));

        if ($formato === '80mm') {
            $pdf->setPaper([0, 0, 226.77, 1200], 'portrait');
        } else {
            $pdf->setPaper('a4', $orientation);
        }

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        if ($hasPageMarkers && $formato === 'a4') {
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
            $canvas->page_text(
                $canvas->get_width() / 2 - 40,
                $canvas->get_height() - 10,
                'Página {PAGE_NUM} de {PAGE_COUNT}',
                $font,
                8,
                [0.28, 0.33, 0.41]
            );
        }

        $bytes = (string) $dompdf->output();

        // Teto de tamanho aplicado a TODO PDF gerado (emissão, prévia e
        // re-render de snapshot) — só chama o Ghostscript quando necessário,
        // então documentos já pequenos (sem foto) não pagam esse custo.
        $maxBytes = (int) config('document-rendering.max_bytes', 0);
        if ($maxBytes > 0 && strlen($bytes) > $maxBytes) {
            $bytes = $this->compressionService->compress($bytes, $maxBytes);
        }

        return $bytes;
    }

    /**
     * Metadados de auditoria da emissão para gravar em
     * os_documentos.metadados_json.
     *
     * @param array{template_id?: int, template_versao?: int, hash_schema?: string, tipo_codigo?: string} $result
     * @return array<string, mixed>
     */
    public static function auditMetadata(array $result, string $origem, ?string $motivoReemissao = null): array
    {
        return array_filter([
            'motor' => 'pdf_engine',
            'tipo_codigo' => (string) ($result['tipo_codigo'] ?? ''),
            'template_id' => (int) ($result['template_id'] ?? 0),
            'template_versao' => (int) ($result['template_versao'] ?? 0),
            'hash_schema' => (string) ($result['hash_schema'] ?? ''),
            'origin' => $origem,
            'motivo_reemissao' => $motivoReemissao,
            'assinatura' => is_array($result['assinatura'] ?? null) ? $result['assinatura'] : null,
        ], static fn ($value): bool => $value !== null && $value !== '' && $value !== 0);
    }

    /**
     * A imagem nunca vem do schema editável nem de URL externa. Somente a
     * assinatura privada validada do usuário (ou uma rubrica de cliente já
     * normalizada pelo fluxo público) entra no contexto do renderer.
     *
     * @param array<string, mixed> $options
     * @return array{context: array<string, mixed>, audit: array<string, mixed>}
     */
    private function signatureContext(?User $actor, array $options): array
    {
        $signer = ($options['signature_signer'] ?? null) instanceof User
            ? $options['signature_signer']
            : $actor;
        $responsible = null;
        $audit = [];

        if ($signer instanceof User) {
            $signature = ($options['responsible_signature'] ?? null) instanceof UserSignature
                ? $options['responsible_signature']
                : $this->signatureImageService->activeFor($signer);
            $dataUri = $signature ? $this->signatureImageService->dataUri($signature) : null;
            if ($signature !== null && $dataUri !== null) {
                $responsible = [
                    'imagem' => $dataUri,
                    'nome' => (string) ($signer->nome ?? ''),
                    'funcao' => $this->signatureRole($signer),
                    'assinada_em' => $this->signatureDate(
                        $options['responsible_signed_at'] ?? $options['signature_signed_at'] ?? now()
                    ),
                    'assinatura_id' => (int) $signature->id,
                    'hash_sha256' => (string) $signature->hash_sha256,
                ];
                $audit = [
                    'usuario_id' => (int) $signer->id,
                    'signatario_nome' => (string) ($signer->nome ?? ''),
                    'signatario_funcao' => $responsible['funcao'],
                    'assinada_em' => $responsible['assinada_em'],
                    'assinatura_id' => (int) $signature->id,
                    'hash_sha256' => (string) $signature->hash_sha256,
                    'metodo' => trim((string) ($options['signature_method'] ?? 'sessao')) ?: 'sessao',
                ];
            }
        }

        $customer = is_array($options['customer_signature'] ?? null)
            ? $options['customer_signature']
            : null;
        if ($customer !== null) {
            $dataUri = (string) ($customer['data_uri'] ?? '');
            if (! str_starts_with($dataUri, 'data:image/png;base64,')) {
                $customer = null;
            } else {
                $customer = [
                    'imagem' => $dataUri,
                    'nome' => trim((string) ($customer['name'] ?? 'Cliente')) ?: 'Cliente',
                    'funcao' => 'Cliente',
                    'assinada_em' => $this->signatureDate($customer['signed_at'] ?? now()),
                    'hash_sha256' => (string) ($customer['hash_sha256'] ?? ''),
                ];
                $audit['cliente_hash_sha256'] = (string) $customer['hash_sha256'];
                $audit['cliente_nome'] = (string) $customer['nome'];
                $audit['cliente_assinada_em'] = (string) $customer['assinada_em'];
            }
        }

        return [
            'context' => [
                'responsavel' => $responsible,
                'cliente' => $customer,
            ],
            'audit' => $audit,
        ];
    }

    private function signatureRole(User $signer): string
    {
        if (Schema::hasTable('equipe_membros')) {
            $signer->loadMissing('teamMember');
            $member = $signer->teamMember;
            if ($member instanceof \App\Models\TeamMember) {
                $role = trim((string) ($member->cargo ?? ''));
                if ($role !== '') {
                    return $role;
                }
                if ((bool) ($member->atua_tecnico ?? false)) {
                    return 'Técnico responsável';
                }
                if ((bool) ($member->atua_vendas ?? false)) {
                    return 'Atendimento e vendas';
                }
                if ((bool) ($member->atua_administrativo ?? false)) {
                    return 'Administrativo';
                }
            }
        }

        return trim((string) ($signer->perfil ?? '')) ?: 'Responsável';
    }

    private function signatureDate(mixed $value): string
    {
        try {
            $date = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string) $value);

            return $date->setTimezone((string) config('app.timezone', 'America/Sao_Paulo'))->format('d/m/Y');
        } catch (Throwable) {
            return Carbon::now()->format('d/m/Y');
        }
    }

    private function resolvePublishedVersion(string $tipoCodigo): ?PdfTemplateVersao
    {
        $family = PdfTemplate::query()
            ->where('tipo_codigo', $tipoCodigo)
            ->where('arquivado', false)
            ->first();

        return $family?->versaoPublicada();
    }

    /**
     * Extrai somente tokens internos realmente usados pelo schema. Contextos
     * podem assim evitar I/O de imagens que o documento não renderizará.
     *
     * @param array<string, mixed> $schema
     * @return array<int, string>
     */
    private function imageTokens(array $schema): array
    {
        $tokens = [];
        $walk = null;
        $walk = static function (mixed $node) use (&$walk, &$tokens): void {
            if (! is_array($node)) {
                return;
            }

            $tipo = strtolower(trim((string) ($node['tipo'] ?? '')));

            if ($tipo === 'imagem') {
                $token = strtolower(trim((string) ($node['token'] ?? ''), '() '));
                if (in_array($token, PdfTemplateRegistry::IMAGE_TOKENS, true)) {
                    $tokens[] = $token;
                }
            }

            if ($tipo === 'fotos_entrada') {
                $tokens[] = 'fotos_entrada';
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($schema);

        return array_values(array_unique($tokens));
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedSchema(PdfTemplateVersao $versao): array
    {
        $cacheKey = sprintf('pdf_engine_schema:%d:%d', (int) $versao->id, (int) $versao->versao);

        $schema = Cache::remember(
            $cacheKey,
            self::SCHEMA_CACHE_TTL_SECONDS,
            static fn (): array => $versao->schema()
        );

        return is_array($schema) ? $schema : [];
    }

    public static function forgetSchemaCache(PdfTemplateVersao $versao): void
    {
        Cache::forget(sprintf('pdf_engine_schema:%d:%d', (int) $versao->id, (int) $versao->versao));
    }
}
