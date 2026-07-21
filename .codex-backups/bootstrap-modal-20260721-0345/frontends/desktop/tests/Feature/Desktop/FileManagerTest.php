<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FileManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_dashboard_catalog_and_masked_findings_through_api_only(): void
    {
        Http::fake([
            '*/api/v1/file-manager/dashboard*' => Http::response($this->success([
                'totals' => [
                    'files' => 1,
                    'bytes' => 2048,
                    'quarantined' => 0,
                    'trashed' => 0,
                    'integrity_issues' => 0,
                    'open_findings' => 1,
                ],
                'by_category' => [[
                    'category' => 'company_logo',
                    'file_count' => 1,
                    'total_bytes' => 2048,
                ]],
                'operation' => [
                    'mode' => 'off',
                    'enabled_categories' => [],
                    'observing' => false,
                    'cataloging_new_files' => false,
                    'central_writes_enabled' => false,
                    'scanner_enabled' => false,
                ],
                'state_mutations_enabled' => false,
                'last_scan_run' => null,
            ])),
            '*/api/v1/files*' => Http::response($this->success([[
                'uuid' => '019f7c54-fd90-7cc1-a455-aa6f3efd15d1',
                'safe_download_name' => 'logo.png',
                'detected_mime_type' => 'image/png',
                'size_bytes' => 2048,
                'category' => 'company_logo',
                'origin' => 'upload',
                'lifecycle_status' => 'active',
                'integrity_status' => 'valid',
                'security_status' => 'clean',
                'migration_status' => 'native',
                'active_links_count' => 1,
                'created_at' => '2026-07-19T12:00:00-03:00',
                'document_created_at' => '2026-07-18T09:30:00-03:00',
                'linked_client' => [
                    'id' => 44,
                    'name' => 'Cliente Vinculado ao Card',
                ],
            ]], ['pagination' => [
                'current_page' => 1,
                'per_page' => 25,
                'total' => 1,
                'last_page' => 1,
            ]])),
            '*/api/v1/file-manager/findings*' => Http::response($this->success([[
                'id' => 1,
                'finding_type' => 'orphan',
                'severity' => 'high',
                'resolution_status' => 'open',
                'restricted_path_hint' => 'hash:abcdef123456',
            ]])),
        ]);

        $response = $this
            ->withSession($this->desktopSession())
            ->get('/arquivos?category=company_logo');

        $response->assertOk()
            ->assertSee('Gerenciador de Arquivos')
            ->assertSee('aria-label="Gerenciador de Arquivos"', false)
            ->assertSee('Catalogação automática desativada.')
            ->assertSee('logo.png')
            ->assertSee('Criado em 18/07/2026 09:30')
            ->assertSee('Cliente: Cliente Vinculado ao Card')
            ->assertSee('id="filePreviewModal"', false)
            ->assertSee('data-preview-kind="image"', false)
            ->assertSee('data-file-preview-action="zoom-in"', false)
            ->assertSee('assets/js/file-preview-modal.js', false)
            ->assertSee('Pastas por categoria')
            ->assertSee('Baixar selecionados')
            ->assertSee('Excluir selecionados')
            ->assertSee('E-mail do responsável autorizado')
            ->assertSee('permissão <strong>Administrar</strong> no módulo Arquivos', false)
            ->assertSee('value="operador@example.com"', false)
            ->assertSee('hash:abcdef123456')
            ->assertDontSee('/var/www')
            ->assertDontSee('storage_key');

        // Três chamadas do módulo + uma leitura institucional do shell desktop.
        Http::assertSentCount(4);
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/api/v1/files?')
            && str_contains($request->url(), 'category=company_logo'));
    }

    public function test_invalid_step_up_returns_inline_safe_error_without_logging_out_or_flashing_password(): void
    {
        Http::fake([
            '*/api/v1/files/*/archive' => Http::response([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'code' => 'FILE_ADMIN_AUTH_INVALID',
                    'message' => 'Credenciais de administrador inválidas.',
                    'details' => null,
                ],
                'meta' => [],
            ], 422),
        ]);

        $response = $this
            ->withSession($this->desktopSession())
            ->withHeader('Accept', 'application/json')
            ->post('/arquivos/019f7c54-fd90-7cc1-a455-aa6f3efd15d1/arquivar', [
                'reason' => 'Solicitação operacional aprovada.',
                'admin_email' => 'admin@example.com',
                'admin_password' => 'senha-incorreta',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Credenciais de administrador inválidas.')
            ->assertSessionHas('desktop_auth.token', 'desktop-token')
            ->assertSessionMissing('_old_input.admin_password');
    }

    public function test_index_reports_active_automatic_synchronization(): void
    {
        Http::fake([
            '*/api/v1/file-manager/dashboard*' => Http::response($this->success([
                'totals' => ['files' => 0, 'bytes' => 0],
                'by_category' => [],
                'operation' => [
                    'mode' => 'shadow',
                    'automatic_sync_enabled' => true,
                    'automatic_sync_interval_minutes' => 5,
                ],
                'state_mutations_enabled' => true,
            ])),
            '*/api/v1/files*' => Http::response($this->success([], ['pagination' => [
                'current_page' => 1,
                'per_page' => 25,
                'total' => 0,
                'last_page' => 1,
            ]])),
            '*/api/v1/file-manager/findings*' => Http::response($this->success([])),
        ]);

        $this->withSession($this->desktopSession())
            ->get('/arquivos')
            ->assertOk()
            ->assertSee('Sincronização automática ativa.')
            ->assertSee('em até 5 minutos')
            ->assertSee('Sincronizar agora')
            ->assertDontSee('Catalogação automática desativada.');
    }

    public function test_administrator_can_request_manual_synchronization(): void
    {
        Http::fake([
            '*/api/v1/file-manager/sync' => Http::response($this->success([
                'status' => 'queued',
                'queued' => true,
            ]), 202),
        ]);

        $this->withSession($this->desktopSession())
            ->post('/arquivos/sincronizar')
            ->assertRedirect()
            ->assertSessionHas('success', 'Sincronização solicitada. O processamento começa em até um minuto.');

        Http::assertSent(static fn ($request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v1/file-manager/sync'));
    }

    public function test_backend_manual_sync_rate_limit_returns_to_catalog_with_friendly_error(): void
    {
        Http::fake([
            '*/api/v1/file-manager/sync' => Http::response([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'code' => 'TOO_MANY_REQUESTS',
                    'message' => 'Too Many Attempts.',
                    'details' => null,
                ],
                'meta' => [],
            ], 429),
        ]);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->withSession($this->desktopSession())
                ->from('/arquivos')
                ->post('/arquivos/sincronizar')
                ->assertRedirect('/arquivos')
                ->assertSessionHas('error', 'Muitas solicitações em sequência. Aguarde até um minuto antes de sincronizar novamente.');
        }

        Http::assertSentCount(4);
    }

    public function test_successful_state_action_is_forwarded_with_reason_and_step_up_credentials(): void
    {
        Http::fake([
            '*/api/v1/files/*/quarantine' => Http::response($this->success([
                'uuid' => '019f7c54-fd90-7cc1-a455-aa6f3efd15d1',
                'security_status' => 'quarantined',
            ])),
        ]);

        $this->withSession($this->desktopSession())
            ->withHeader('Accept', 'application/json')
            ->post('/arquivos/019f7c54-fd90-7cc1-a455-aa6f3efd15d1/quarentenar', [
                'reason' => 'Conteúdo requer análise de segurança.',
                'admin_email' => 'admin@example.com',
                'admin_password' => 'Senha@123',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('file.security_status', 'quarantined');

        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/api/v1/files/019f7c54-fd90-7cc1-a455-aa6f3efd15d1/quarantine')
            && $request['reason'] === 'Conteúdo requer análise de segurança.'
            && $request['admin_email'] === 'admin@example.com'
            && $request['admin_password'] === 'Senha@123');
    }

    public function test_selected_files_can_be_downloaded_as_zip_through_the_backend(): void
    {
        Http::fake([
            '*/api/v1/files/download-batch' => Http::response('zip-content', 200, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="arquivos-selecionados.zip"',
            ]),
        ]);

        $uuid = '019f7c54-fd90-7cc1-a455-aa6f3efd15d1';
        $response = $this->withSession($this->desktopSession())
            ->post('/arquivos/baixar-selecionados', ['file_uuids' => [$uuid]]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('zip-content', $response->getContent());
        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/api/v1/files/download-batch')
            && $request['file_uuids'] === [$uuid]);
    }

    public function test_pdf_thumbnail_is_proxied_with_safe_cache_headers(): void
    {
        $uuid = '019f7c54-fd90-7cc1-a455-aa6f3efd15d1';
        $thumbnail = 'png-thumbnail-content';
        Http::fake([
            '*/api/v1/files/'.$uuid.'/thumbnail' => Http::response($thumbnail, 200, [
                'Content-Type' => 'image/png',
                'Content-Disposition' => 'inline; filename="pagina-1.png"',
                'Cache-Control' => 'private, max-age=86400',
                'ETag' => '"pdf-p1-test"',
            ]),
        ]);

        $response = $this->withSession($this->desktopSession())
            ->get('/arquivos/'.$uuid.'/miniatura');

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('ETag', '"pdf-p1-test"');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=86400', $cacheControl);
        $this->assertSame($thumbnail, $response->getContent());
        Http::assertSent(static fn ($request): bool => str_ends_with(
            $request->url(),
            '/api/v1/files/'.$uuid.'/thumbnail'
        ));
    }

    public function test_grid_exposes_lazy_pdf_thumbnail_and_modal_without_preloading_the_document(): void
    {
        $uuid = '019f7c54-fd90-7cc1-a455-aa6f3efd15d1';
        Http::fake([
            '*/api/v1/file-manager/dashboard*' => Http::response($this->success([
                'totals' => ['files' => 1, 'bytes' => 1024],
                'by_category' => [],
                'operation' => ['mode' => 'shadow'],
                'state_mutations_enabled' => true,
            ])),
            '*/api/v1/files*' => Http::response($this->success([[
                'uuid' => $uuid,
                'safe_download_name' => 'orcamento.pdf',
                'detected_mime_type' => 'application/pdf',
                'size_bytes' => 1024,
                'category' => 'order_pdf',
                'origin' => 'upload',
                'lifecycle_status' => 'active',
                'integrity_status' => 'valid',
                'security_status' => 'clean',
                'migration_status' => 'cataloged',
                'active_links_count' => 1,
                'created_at' => '2026-07-20T12:00:00-03:00',
            ]], ['pagination' => [
                'current_page' => 1,
                'per_page' => 25,
                'total' => 1,
                'last_page' => 1,
            ]])),
            '*/api/v1/file-manager/findings*' => Http::response($this->success([])),
        ]);

        $response = $this->withSession($this->desktopSession())
            ->get('/arquivos?view=grid');

        $response->assertOk()
            ->assertSee('orcamento.pdf')
            ->assertSee('data-pdf-thumbnail-url=', false)
            ->assertSee('/arquivos/'.$uuid.'/miniatura', false)
            ->assertSee('data-preview-kind="pdf"', false)
            ->assertSee('id="filePreviewFrame"', false)
            ->assertSee('src="about:blank"', false)
            ->assertDontSee('<embed', false);
    }

    public function test_list_displays_lazy_thumbnail_and_linked_client_columns(): void
    {
        $uuid = '019f7c54-fd90-7cc1-a455-aa6f3efd15d1';
        Http::fake([
            '*/api/v1/file-manager/dashboard*' => Http::response($this->success([
                'totals' => ['files' => 1, 'bytes' => 1024],
                'by_category' => [],
                'operation' => ['mode' => 'shadow'],
                'state_mutations_enabled' => true,
            ])),
            '*/api/v1/files*' => Http::response($this->success([[
                'uuid' => $uuid,
                'safe_download_name' => 'abertura-os.pdf',
                'detected_mime_type' => 'application/pdf',
                'size_bytes' => 1024,
                'category' => 'order_pdf',
                'origin' => 'upload',
                'lifecycle_status' => 'active',
                'integrity_status' => 'valid',
                'security_status' => 'clean',
                'migration_status' => 'cataloged',
                'active_links_count' => 1,
                'created_at' => '2026-07-20T12:00:00-03:00',
                'document_created_at' => '2026-07-18T09:30:00-03:00',
                'linked_client' => ['id' => 44, 'name' => 'Cliente da OS'],
            ]], ['pagination' => [
                'current_page' => 1,
                'per_page' => 50,
                'total' => 1,
                'last_page' => 1,
            ]])),
            '*/api/v1/file-manager/findings*' => Http::response($this->success([])),
        ]);

        $response = $this->withSession($this->desktopSession())
            ->get('/arquivos?view=list');

        $response->assertOk()
            ->assertSee('<th class="file-list-photo-column">Foto</th>', false)
            ->assertSee('<th>Cliente</th>', false)
            ->assertSee('class="file-list-preview"', false)
            ->assertSee('/arquivos/'.$uuid.'/miniatura', false)
            ->assertSee('Cliente da OS')
            ->assertSee('18/07/2026 09:30')
            ->assertSee('data-preview-kind="pdf"', false);
    }

    public function test_selected_files_can_be_moved_to_trash_with_one_step_up(): void
    {
        Http::fake([
            '*/api/v1/files/trash-batch' => Http::response($this->success([
                'trashed_count' => 2,
            ])),
        ]);

        $uuids = [
            '019f7c54-fd90-7cc1-a455-aa6f3efd15d1',
            '019f7c54-fd90-7cc1-a455-aa6f3efd15d2',
        ];
        $this->withSession($this->desktopSession())
            ->withHeader('Accept', 'application/json')
            ->post('/arquivos/excluir-selecionados', [
                'file_uuids' => $uuids,
                'reason' => 'Arquivos substituídos por novas versões.',
                'admin_email' => 'admin@example.com',
                'admin_password' => 'Senha@123',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.trashed_count', 2);

        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/api/v1/files/trash-batch')
            && $request['file_uuids'] === $uuids
            && $request['reason'] === 'Arquivos substituídos por novas versões.');
        Http::assertSentCount(1);
    }

    public function test_trash_list_exposes_preview_details_restore_purge_and_retention_controls(): void
    {
        $uuid = '019f7c54-fd90-7cc1-a455-aa6f3efd15d1';
        Http::fake([
            '*/api/v1/file-manager/dashboard*' => Http::response($this->success([
                'totals' => ['files' => 1, 'bytes' => 1024, 'trashed' => 1],
                'by_category' => [],
                'operation' => [
                    'mode' => 'shadow',
                    'trash_retention' => ['days' => 30, 'enabled' => true],
                    'permanent_deletion_enabled' => true,
                ],
                'state_mutations_enabled' => true,
            ])),
            '*/api/v1/files*' => Http::response($this->success([[
                'uuid' => $uuid,
                'safe_download_name' => 'abertura-os.pdf',
                'detected_mime_type' => 'application/pdf',
                'size_bytes' => 1024,
                'category' => 'order_pdf',
                'origin' => 'upload',
                'lifecycle_status' => 'trashed',
                'integrity_status' => 'valid',
                'security_status' => 'clean',
                'migration_status' => 'cataloged',
                'active_links_count' => 0,
                'created_at' => '2026-07-20T12:00:00-03:00',
                'trashed_at' => '2026-07-20T13:00:00-03:00',
                'linked_client' => ['id' => 44, 'name' => 'Cliente da OS'],
            ]], ['pagination' => [
                'current_page' => 1,
                'per_page' => 50,
                'total' => 1,
                'last_page' => 1,
            ]])),
            '*/api/v1/file-manager/findings*' => Http::response($this->success([])),
        ]);

        $response = $this->withSession($this->desktopSession())
            ->get('/arquivos?lifecycle_status=trashed&view=list');

        $response->assertOk()
            ->assertSee('Retenção da lixeira')
            ->assertSee('30 dias')
            ->assertSee('Restaurar selecionados')
            ->assertSee('Excluir definitivamente')
            ->assertSee('title="Detalhes"', false)
            ->assertSee('class="btn btn-outline-success btn-sm file-restore-one"', false)
            ->assertSee('class="btn btn-outline-danger btn-sm file-purge-one"', false)
            ->assertSee('/arquivos/'.$uuid.'/visualizar', false)
            ->assertSee('data-download-url=""', false)
            ->assertSee('/arquivos/'.$uuid.'/miniatura', false);
    }

    public function test_missing_trash_content_is_separated_into_the_audit_collection(): void
    {
        $uuid = '019f7c54-fd90-7cc1-a455-aa6f3efd15d1';
        Http::fake([
            '*/api/v1/file-manager/dashboard*' => Http::response($this->success([
                'totals' => ['files' => 0, 'bytes' => 0, 'trashed' => 0, 'audit_records' => 1],
                'by_category' => [],
                'operation' => [
                    'mode' => 'shadow',
                    'trash_retention' => ['days' => 30, 'enabled' => true],
                    'permanent_deletion_enabled' => true,
                ],
                'state_mutations_enabled' => true,
            ])),
            '*/api/v1/files*' => Http::response($this->success([[
                'uuid' => $uuid,
                'safe_download_name' => 'foto-antiga.jpg',
                'detected_mime_type' => 'image/jpeg',
                'size_bytes' => 1024,
                'category' => 'user_profile_photo',
                'origin' => 'upload',
                'lifecycle_status' => 'trashed',
                'integrity_status' => 'missing',
                'security_status' => 'clean',
                'migration_status' => 'cataloged',
                'active_links_count' => 0,
                'capabilities' => ['restore' => false, 'purge' => true],
                'created_at' => '2026-07-20T12:00:00-03:00',
            ]], ['pagination' => [
                'current_page' => 1,
                'per_page' => 24,
                'total' => 1,
                'last_page' => 1,
            ]])),
            '*/api/v1/file-manager/findings*' => Http::response($this->success([])),
        ]);

        $response = $this->withSession($this->desktopSession())
            ->get('/arquivos?lifecycle_status=audit&view=grid');

        $response->assertOk()
            ->assertSee('Auditoria de conteúdo ausente')
            ->assertSee('Eles não entram na contagem nem na retenção automática da lixeira.')
            ->assertSee('Excluir registros selecionados')
            ->assertSee('Conteúdo ausente')
            ->assertSee('data-restoreable="0"', false)
            ->assertDontSee('Restaurar selecionados')
            ->assertDontSee('class="btn btn-outline-success btn-sm file-restore-one"', false);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/api/v1/files')
            && str_contains($request->url(), 'audit_only=1'));
    }

    public function test_trash_actions_and_retention_are_forwarded_once_with_step_up_credentials(): void
    {
        $uuid = '019f7c54-fd90-7cc1-a455-aa6f3efd15d1';
        Http::fake([
            '*/api/v1/files/restore-batch' => Http::response($this->success([
                'restored_count' => 1,
            ])),
            '*/api/v1/files/purge-batch' => Http::response($this->success([
                'purged_count' => 1,
                'failed_count' => 0,
            ])),
            '*/api/v1/file-manager/trash-retention' => Http::response($this->success([
                'days' => 90,
                'enabled' => true,
            ])),
        ]);

        $stepUp = [
            'reason' => 'Manutenção autorizada da lixeira documental.',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'Senha@123',
        ];

        $this->withSession($this->desktopSession())
            ->withHeader('Accept', 'application/json')
            ->post('/arquivos/restaurar-selecionados', array_merge($stepUp, ['file_uuids' => [$uuid]]))
            ->assertOk()
            ->assertJsonPath('result.restored_count', 1);

        $this->withSession($this->desktopSession())
            ->withHeader('Accept', 'application/json')
            ->post('/arquivos/excluir-definitivamente', array_merge($stepUp, [
                'file_uuids' => [$uuid],
                'confirmation' => 'EXCLUIR',
            ]))
            ->assertOk()
            ->assertJsonPath('result.purged_count', 1);

        $this->withSession($this->desktopSession())
            ->withHeader('Accept', 'application/json')
            ->post('/arquivos/retencao-lixeira', array_merge($stepUp, ['days' => 90]))
            ->assertOk()
            ->assertJsonPath('settings.days', 90);

        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/api/v1/files/restore-batch')
            && $request['file_uuids'] === [$uuid]);
        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/api/v1/files/purge-batch')
            && $request['confirmation'] === 'EXCLUIR');
        Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/api/v1/file-manager/trash-retention')
            && $request['days'] === 90);
        Http::assertSentCount(3);
    }

    public function test_desktop_retention_route_delegates_rate_limit_to_authenticated_backend(): void
    {
        Http::fake([
            '*/api/v1/file-manager/trash-retention' => Http::response($this->success([
                'days' => 30,
                'enabled' => true,
            ])),
        ]);

        $payload = [
            'days' => 30,
            'reason' => 'Atualização autorizada da política documental.',
            'admin_email' => 'admin@example.com',
            'admin_password' => 'Senha@123',
        ];

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->withSession($this->desktopSession())
                ->withHeader('Accept', 'application/json')
                ->post('/arquivos/retencao-lixeira', $payload)
                ->assertOk()
                ->assertJsonPath('settings.days', 30);
        }

        Http::assertSentCount(6);
    }

    public function test_trash_command_is_not_retried_after_backend_server_error(): void
    {
        Http::fake([
            '*/api/v1/files/trash-batch' => Http::response([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'Ocorreu um erro inesperado. Tente novamente em instantes.',
                    'details' => null,
                ],
                'meta' => [],
            ], 500),
        ]);

        $this->withSession($this->desktopSession())
            ->withHeader('Accept', 'application/json')
            ->post('/arquivos/excluir-selecionados', [
                'file_uuids' => ['019f7c54-fd90-7cc1-a455-aa6f3efd15d1'],
                'reason' => 'Arquivo substituído por uma nova versão revisada.',
                'admin_email' => 'admin@example.com',
                'admin_password' => 'Senha@123',
            ])
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        Http::assertSentCount(1);
    }

    public function test_state_actions_reject_requests_without_csrf_token_before_calling_backend(): void
    {
        Http::fake();

        $environment = $this->app->environment();
        $this->app['env'] = 'production';

        try {
            $this->withMiddleware(PreventRequestForgery::class)
                ->withSession($this->desktopSession())
                ->post('/arquivos/019f7c54-fd90-7cc1-a455-aa6f3efd15d1/arquivar', [
                    'reason' => 'Solicitacao sem token CSRF.',
                    'admin_email' => 'admin@example.com',
                    'admin_password' => 'Senha@123',
                ])
                ->assertStatus(419);
        } finally {
            $this->app['env'] = $environment;
        }

        Http::assertNotSent(static fn ($request): bool => str_ends_with(
            $request->url(),
            '/api/v1/files/019f7c54-fd90-7cc1-a455-aa6f3efd15d1/archive'
        ));
    }

    /** @return array<string, mixed> */
    private function desktopSession(): array
    {
        $actions = ['listar', 'metadados', 'baixar', 'excluir', 'quarentenar', 'restaurar', 'administrar'];

        return [
            'desktop_auth' => [
                'token' => 'desktop-token',
                'synced_at' => time(),
                'last_activity' => time(),
                'user' => [
                    'id' => 10,
                    'nome' => 'Operador',
                    'email' => 'operador@example.com',
                    'ativo' => true,
                    'modules' => ['arquivos'],
                    'permissions' => ['arquivos' => $actions],
                ],
            ],
        ];
    }

    /** @param array<string, mixed>|array<int, mixed> $data */
    private function success(array $data, array $meta = []): array
    {
        return [
            'status' => 'success',
            'data' => $data,
            'error' => null,
            'meta' => $meta,
        ];
    }
}
