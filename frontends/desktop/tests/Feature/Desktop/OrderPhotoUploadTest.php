<?php

namespace Tests\Feature\Desktop;

use App\Support\OrderPhotoTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fotos direto na visualização da OS (specs/048): o quadro Fotos do detalhe
 * ganha câmera, computador/galeria, colar e arrastar para quem edita a OS, e
 * POST /os/{id}/fotos encaminha o lote ao backend e devolve a galeria já
 * renderizada pelo mesmo parcial da página.
 */
class OrderPhotoUploadTest extends TestCase
{
    private const API = 'http://127.0.0.1:8000/api/v1';

    public function test_editor_gets_the_uploader_with_every_source_and_the_phase_based_category(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            self::API.'/orders/501' => Http::response($this->orderPayload(['status_grupo_macro' => 'execucao'])),
        ]));

        $response = $this
            ->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/os/501');

        $response->assertOk()
            ->assertSee('id="os-fotos"', false)
            // Padrão único de inserção de imagem (specs/049): fila com Enviar.
            ->assertSee('data-image-picker="uploader"', false)
            ->assertSee('data-image-picker-upload-url="'.route('orders.photos.store', 501).'"', false)
            ->assertSee('data-image-picker-field="fotos[]"', false)
            ->assertSee('data-image-picker-camera', false)
            ->assertSee('data-image-picker-pick', false)
            ->assertSee('data-image-picker-paste', false)
            ->assertSee('data-image-picker-dropzone', false)
            ->assertSee('data-image-picker-queue', false)
            ->assertSee('capture="environment"', false)
            ->assertSee('Computador / galeria')
            ->assertSee('Nenhuma foto nesta OS ainda')
            ->assertSee('assets/js/image-picker.js', false)
            // Execução do serviço → registro técnico → Diagnóstico sugerido.
            ->assertSee('<option value="diagnostico" selected>Diagnóstico</option>', false)
            ->assertDontSee('Sem fotos vinculadas');
    }

    public function test_closed_order_still_accepts_photos_and_suggests_delivery(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            self::API.'/orders/501' => Http::response($this->orderPayload([
                'status_grupo_macro' => 'encerrado',
                'is_encerrada' => true,
            ])),
        ]));

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->get('/os/501')
            ->assertOk()
            ->assertSee('data-image-picker="uploader"', false)
            ->assertSee('<option value="entrega" selected>Entrega</option>', false);
    }

    public function test_category_suggestion_follows_the_status_phase(): void
    {
        $this->assertSame('recepcao', OrderPhotoTypes::suggestedFor('recepcao'));
        foreach (['diagnostico', 'orcamento', 'interrupcao', 'execucao', 'qualidade', ''] as $phase) {
            $this->assertSame('diagnostico', OrderPhotoTypes::suggestedFor($phase), $phase);
        }
        foreach (['concluido', 'finalizado_sem_reparo', 'encerrado', 'cancelado'] as $phase) {
            $this->assertSame('entrega', OrderPhotoTypes::suggestedFor($phase), $phase);
        }
    }

    public function test_view_only_user_sees_the_grouped_gallery_without_upload_controls(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            self::API.'/orders/501' => Http::response($this->orderPayload([
                'fotos' => [
                    $this->photo(90, 'recepcao'),
                    $this->photo(91, 'diagnostico'),
                    $this->photo(92, 'diagnostico'),
                ],
            ])),
        ]));

        $response = $this
            ->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/os/501');

        $response->assertOk()
            ->assertSee(route('orders.photos.show', [501, 90]), false)
            ->assertSee(route('orders.photos.show', [501, 92]), false)
            ->assertSee('Recepção <span class="os-count">1</span>', false)
            ->assertSee('Diagnóstico <span class="os-count">2</span>', false)
            ->assertDontSee('data-image-picker="uploader"', false)
            ->assertDontSee('data-image-picker-dropzone', false)
            ->assertDontSee('data-image-picker-camera', false)
            ->assertDontSee('data-image-picker-queue', false)
            ->assertDontSee('Sem fotos vinculadas');
    }

    public function test_view_only_user_without_photos_sees_the_empty_state(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            self::API.'/orders/501' => Http::response($this->orderPayload()),
        ]));

        $this->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/os/501')
            ->assertOk()
            ->assertSee('Sem fotos vinculadas')
            ->assertDontSee('Nenhuma foto nesta OS ainda');
    }

    public function test_store_forwards_the_batch_to_the_backend_and_returns_the_rendered_gallery(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::API.'/orders/501/photos' => Http::response([
                'status' => 'success',
                'data' => [
                    'foto_ids' => [91, 92],
                    'fotos' => [
                        $this->photo(90, 'recepcao'),
                        $this->photo(91, 'diagnostico'),
                        $this->photo(92, 'diagnostico'),
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->withHeader('Accept', 'application/json')
            ->post('/os/501/fotos', [
                'tipo' => 'diagnostico',
                'fotos' => [
                    UploadedFile::fake()->image('placa-1.jpg'),
                    UploadedFile::fake()->image('colada-20260925-1.png'),
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('added', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('message', '2 fotos adicionadas em Diagnóstico.');

        $html = (string) $response->json('html');
        $this->assertStringContainsString(route('orders.photos.show', [501, 90]), $html);
        $this->assertStringContainsString(route('orders.photos.show', [501, 92]), $html);
        $this->assertStringContainsString('data-photo-viewer-group="order-501-photos"', $html);
        $this->assertSame(2, substr_count($html, 'class="os-photo-thumb is-new"'));
        $this->assertSame(1, substr_count($html, 'class="os-photo-thumb"'));
        $this->assertStringContainsString('Diagnóstico <span class="os-count">2</span>', $html);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== self::API.'/orders/501/photos' || $request->method() !== 'POST' || ! $request->isMultipart()) {
                return false;
            }

            $parts = collect($request->data());

            return $parts->contains(static fn (array $part): bool => ($part['name'] ?? '') === 'tipo' && ($part['contents'] ?? '') === 'diagnostico')
                && $parts->where('name', 'fotos[]')->count() === 2
                && $request->hasFile('fotos[]', null, 'placa-1.jpg')
                && $request->hasFile('fotos[]', null, 'colada-20260925-1.png');
        });
    }

    public function test_store_rejects_missing_files_unknown_category_and_more_than_four_photos_before_calling_the_backend(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $session = $this->desktopSession(['os' => ['visualizar', 'editar']]);

        $this->withSession($session)
            ->withHeader('Accept', 'application/json')
            ->post('/os/501/fotos', ['tipo' => 'diagnostico'])
            ->assertStatus(422)
            ->assertJsonPath('errors.fotos.0', 'Selecione ao menos uma foto.');

        $this->withSession($session)
            ->withHeader('Accept', 'application/json')
            ->post('/os/501/fotos', [
                'tipo' => 'reparo',
                'fotos' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tipo']);

        $this->withSession($session)
            ->withHeader('Accept', 'application/json')
            ->post('/os/501/fotos', [
                'fotos' => array_map(static fn (int $i) => UploadedFile::fake()->image("f{$i}.jpg"), range(1, 5)),
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.fotos.0', 'Envie no máximo 4 fotos por vez.');

        Http::assertNothingSent();
    }

    public function test_store_surfaces_the_backend_field_message_instead_of_the_generic_one(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::API.'/orders/501/photos' => Http::response([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Falha na validação dos dados enviados.',
                    'details' => [
                        'fotos.0' => ['Envie uma foto JPEG, PNG, WebP, AVIF, HEIC ou HEIF com extensão compatível com o conteúdo.'],
                    ],
                ],
                'meta' => [],
            ], 422),
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->withHeader('Accept', 'application/json')
            ->post('/os/501/fotos', [
                'fotos' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Envie uma foto JPEG, PNG, WebP, AVIF, HEIC ou HEIF com extensão compatível com o conteúdo.');
    }

    public function test_store_passes_through_the_rate_limit_and_warns_before_a_blind_resend_on_timeout(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence(self::API.'/orders/501/photos')
            ->push([
                'status' => 'error',
                'data' => null,
                'error' => ['code' => 'PHOTO_RATE_LIMITED', 'message' => 'Limite de envios de fotos atingido. Aguarde e tente novamente.', 'details' => null],
                'meta' => ['retry_after' => 30],
            ], 429)
            ->pushFailedConnection();

        $session = $this->desktopSession(['os' => ['visualizar', 'editar']]);
        $payload = ['fotos' => [UploadedFile::fake()->image('a.jpg')]];

        $this->withSession($session)
            ->withHeader('Accept', 'application/json')
            ->post('/os/501/fotos', $payload)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Limite de envios de fotos atingido. Aguarde e tente novamente.');

        $this->withSession($session)
            ->withHeader('Accept', 'application/json')
            ->post('/os/501/fotos', $payload)
            ->assertStatus(504)
            ->assertJsonPath('message', 'O servidor demorou a responder e as fotos podem ter sido gravadas. Recarregue a página e confira antes de enviar de novo.');
    }

    public function test_store_without_json_redirects_back_to_the_photo_section(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::API.'/orders/501/photos' => Http::response([
                'status' => 'success',
                'data' => ['foto_ids' => [93], 'fotos' => [$this->photo(93, 'entrega')]],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $this->withSession($this->desktopSession(['os' => ['visualizar', 'editar']]))
            ->post('/os/501/fotos', [
                'tipo' => 'entrega',
                'fotos' => [UploadedFile::fake()->image('entrega.jpg')],
            ])
            ->assertRedirect(route('orders.show', 501).'#os-fotos')
            ->assertSessionHas('success', '1 foto adicionada em Entrega.');
    }

    public function test_store_requires_the_edit_permission(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->withHeader('Accept', 'application/json')
            ->post('/os/501/fotos', [
                'fotos' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertRedirect()
            ->assertSessionHas('error', 'Você não tem permissão para acessar este recurso.');

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function photo(int $id, string $tipo): array
    {
        return [
            'id' => $id,
            'tipo' => $tipo,
            'tipo_label' => OrderPhotoTypes::label($tipo),
            'arquivo' => "private/os/501/os_501_{$id}.avif",
            'nome_arquivo' => "os_501_{$id}.avif",
            'url' => self::API."/orders/501/photos/{$id}",
            'created_at' => '2026-09-25 10:00:00',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orderPayload(array $overrides = []): array
    {
        return [
            'status' => 'success',
            'data' => [
                'order' => array_merge([
                    'id' => 501,
                    'numero_os' => 'OS26090042',
                    'status' => 'diagnostico',
                    'status_nome' => 'Diagnóstico Técnico',
                    'status_cor' => '#64748b',
                    'status_grupo_macro' => 'diagnostico',
                    'is_encerrada' => false,
                    'cliente' => ['id' => 201, 'nome_razao' => 'Cliente Alpha'],
                    'equipamento' => ['id' => 301, 'resumo_tecnico' => 'Notebook Acer Nitro 5'],
                    'tecnico' => ['id' => 51, 'nome' => 'Tecnico Banco'],
                    'fotos' => [],
                    'documentos' => [],
                    'status_disponiveis' => [],
                    'proximas_etapas' => [],
                    'orcamento' => null,
                ], $overrides),
            ],
            'error' => null,
            'meta' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationsFixture(): array
    {
        return [
            self::API.'/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ]),
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $permissions
     * @return array<string, mixed>
     */
    private function desktopSession(array $permissions): array
    {
        return [
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => time(),
                'user' => [
                    'id' => 99,
                    'nome' => 'Usuário de Teste',
                    'email' => 'usuario@teste.local',
                    'perfil' => 'admin',
                    'group' => ['id' => 1, 'nome' => 'Administrador', 'descricao' => 'Grupo completo', 'sistema' => true],
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                    'foto' => '',
                    'ativo' => true,
                ],
            ],
        ];
    }
}
