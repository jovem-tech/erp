<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Notifications\MobileNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(3, [
            'dashboard' => ['visualizar'],
        ]);
    }

    public function test_notifications_endpoint_returns_mobile_notifications_inbox(): void
    {
        $user = $this->createUserRecord([
            'nome' => 'Inbox User',
            'email' => 'inbox@example.com',
            'grupo_id' => 3,
        ]);

        $otherUser = $this->createUserRecord([
            'nome' => 'Outro User',
            'email' => 'outro@example.com',
            'grupo_id' => 3,
        ]);

        DB::table('mobile_notifications')->insert([
            [
                'usuario_id' => $user->id,
                'tipo_evento' => 'message.inbound',
                'titulo' => 'Nova mensagem',
                'corpo' => 'Cliente enviou uma nova mensagem.',
                'rota_destino' => '/conversas/207',
                'payload_json' => json_encode(['conversa_id' => 207], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'lida_em' => null,
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'usuario_id' => $user->id,
                'tipo_evento' => 'order.updated',
                'titulo' => 'OS atualizada',
                'corpo' => 'A OS #123 mudou de status.',
                'rota_destino' => 'https://localhost:8443/sistema-hml/os',
                'payload_json' => json_encode(['os_id' => 123], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'lida_em' => now()->subSeconds(10),
                'created_at' => now()->subSeconds(30),
                'updated_at' => now()->subSeconds(10),
            ],
            [
                'usuario_id' => $otherUser->id,
                'tipo_evento' => 'message.inbound',
                'titulo' => 'Não deve aparecer',
                'corpo' => 'Inbox de outro usuário.',
                'rota_destino' => '/conversas/999',
                'payload_json' => json_encode(['conversa_id' => 999], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'lida_em' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/notifications?per_page=5');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.last_notification_id', 2)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.tipo_evento', 'order.updated')
            ->assertJsonPath('data.items.0.rota_destino', '/os/123')
            ->assertJsonPath('data.items.1.tipo_evento', 'message.inbound')
            ->assertJsonPath('data.items.1.rota_destino', '/atendimento-whatsapp?conversa_id=207')
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_notifications_actions_use_mobile_notifications_and_mobile_channel(): void
    {
        $user = $this->createUserRecord([
            'nome' => 'Action User',
            'email' => 'action@example.com',
            'grupo_id' => 3,
        ]);

        Sanctum::actingAs($user, ['*']);

        $user->notify(new MobileNotification([
            'kind' => 'order.created',
            'title' => 'Nova OS',
            'body' => 'Uma nova ordem foi criada.',
            'route' => '/os/77',
            'icon' => 'clipboard',
            'order_id' => 77,
        ]));

        $createdId = (int) DB::table('mobile_notifications')->max('id');
        $this->assertGreaterThan(0, $createdId);

        $this->patchJson('/api/v1/notifications/' . $createdId . '/read')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.notification.id', $createdId)
            ->assertJsonPath('data.unread_count', 0);

        DB::table('mobile_notifications')->insert([
            [
                'usuario_id' => $user->id,
                'tipo_evento' => 'manual.info',
                'titulo' => 'Lida',
                'corpo' => 'Notificação já lida.',
                'rota_destino' => '/dashboard',
                'payload_json' => null,
                'lida_em' => now()->subMinute(),
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'usuario_id' => $user->id,
                'tipo_evento' => 'manual.info',
                'titulo' => 'Pendente',
                'corpo' => 'Notificação pendente.',
                'rota_destino' => '/dashboard',
                'payload_json' => null,
                'lida_em' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->patchJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.unread_count', 0);

        $this->deleteJson('/api/v1/notifications/read')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.deleted_count', 3)
            ->assertJsonPath('data.unread_count', 0);

        $remaining = DB::table('mobile_notifications')
            ->where('usuario_id', $user->id)
            ->count();

        $this->assertSame(0, $remaining);
    }

    public function test_operational_and_correspondence_boxes_are_isolated(): void
    {
        $user = $this->createUserRecord([
            'nome' => 'Usuário das Caixas',
            'email' => 'boxes@example.com',
            'grupo_id' => 3,
        ]);

        DB::table('mobile_notifications')->insert([
            [
                'usuario_id' => $user->id,
                'tipo_evento' => 'message.inbound',
                'titulo' => 'Mensagem recebida',
                'corpo' => 'Mensagem para a caixa de correspondências.',
                'rota_destino' => '/atendimento-whatsapp',
                'payload_json' => null,
                'lida_em' => null,
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'usuario_id' => $user->id,
                'tipo_evento' => 'document.signature.requested',
                'titulo' => 'Assinatura pendente',
                'corpo' => 'Documento aguardando assinatura.',
                'rota_destino' => '/os/88/documentos#assinaturas-pendentes',
                'payload_json' => json_encode(['signature_request_id' => 9]),
                'lida_em' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'usuario_id' => $user->id,
                'tipo_evento' => 'order.updated',
                'titulo' => 'OS atualizada',
                'corpo' => 'Aviso operacional.',
                'rota_destino' => '/os/88',
                'payload_json' => null,
                'lida_em' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/notifications?box=correspondence&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.box', 'correspondence')
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.caixa', 'correspondence');

        $this->getJson('/api/v1/notifications?box=operational&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.box', 'operational')
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.tipo_evento', 'order.updated');

        $this->patchJson('/api/v1/notifications/read-all?box=correspondence')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(1, DB::table('mobile_notifications')
            ->where('usuario_id', $user->id)
            ->whereNull('lida_em')
            ->count());
    }
}
