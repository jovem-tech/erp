<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Padrão único de inserção de imagem (specs/049): os componentes
 * <x-image-picker.*> e as telas que passaram a usá-los. O comportamento no
 * navegador (câmera, colar, arrastar, recorte, fila) é da biblioteca
 * public/assets/js/image-picker.js; aqui se prova que cada tela entrega a
 * marcação que a liga, com o nome de campo e os limites do destino certos.
 */
class ImagePickerScreensTest extends TestCase
{
    public function test_field_component_renders_sources_list_and_form_input_with_destination_limits(): void
    {
        $html = Blade::render('<x-image-picker.field name="anexo" accept="document" paste="page" :input-attributes="[\'id\' => \'meuAnexo\', \'data-outro-script\' => true]" />');

        $this->assertStringContainsString('data-image-picker="field"', $html);
        $this->assertStringContainsString('data-image-picker-accept="document"', $html);
        $this->assertStringContainsString('data-image-picker-max-bytes="20971520"', $html);
        $this->assertStringContainsString('data-image-picker-paste="page"', $html);
        $this->assertStringContainsString('data-image-picker-multiple="false"', $html);
        $this->assertStringContainsString('data-image-picker-camera', $html);
        $this->assertStringContainsString('data-image-picker-pick', $html);
        $this->assertStringContainsString('data-image-picker-paste', $html);
        $this->assertStringContainsString('data-image-picker-dropzone', $html);
        $this->assertStringContainsString('data-image-picker-list', $html);
        $this->assertStringContainsString('capture="environment"', $html);
        $this->assertStringContainsString('PDF, JPG, PNG ou WebP, até 20 MB. Recortar é opcional.', $html);
        // Só o input do campo vai para o form; os das origens não têm name.
        $this->assertSame(1, preg_match_all('/<input[^>]*type="file"[^>]*name="/', $html));
        $this->assertMatchesRegularExpression('/<input[^>]*name="anexo"[^>]*id="meuAnexo"[^>]*data-outro-script[^>]*data-image-picker-input/s', $html);
        $this->assertStringContainsString('accept="image/jpeg,image/png,image/webp,application/pdf,.pdf"', $html);
    }

    public function test_multiple_photo_field_and_single_png_field_expose_their_rules(): void
    {
        $photos = Blade::render('<x-image-picker.field name="fotos[]" accept="photo" multiple :max="4" />');
        $this->assertStringContainsString('data-image-picker-max="4"', $photos);
        $this->assertStringNotContainsString('data-image-picker-multiple="false"', $photos);
        $this->assertStringContainsString('.heic', $photos);
        $this->assertStringContainsString('JPEG, PNG, WebP, AVIF ou HEIC, até 20 MB cada.', $photos);

        $logo = Blade::render('<x-image-picker.field name="empresa_logo" accept="image" keep-png crop-ratio="1" :max-bytes="2097152" />');
        $this->assertStringContainsString('data-image-picker-keep-png="true"', $logo);
        $this->assertStringContainsString('data-image-picker-crop-ratio="1"', $logo);
        $this->assertStringContainsString('data-image-picker-max-bytes="2097152"', $logo);
        $this->assertStringContainsString('JPG, PNG ou WebP, até 2 MB. Recortar é opcional.', $logo);
    }

    public function test_profile_photo_and_signature_upload_use_the_standard_field(): void
    {
        Http::fake($this->notificationsFixture());

        $html = (string) $this->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/perfil/configuracoes')
            ->assertOk()
            ->getContent();

        // Foto: recorte já quadrado e gravação só no "Salvar foto".
        $this->assertMatchesRegularExpression('/data-profile-photo-form.*?data-image-picker="field".*?data-image-picker-crop-ratio="1".*?name="photo_file".*?Salvar foto/s', $html);
        $this->assertStringContainsString('image-picker-when-filled', $html);
        // Assinatura: PNG transparente continua PNG, até 2 MB.
        $this->assertMatchesRegularExpression('/data-signature-panel="upload".*?data-image-picker-max-bytes="2097152".*?data-image-picker-keep-png="true".*?name="signature_file"[^>]*data-signature-file/s', $html);
        $this->assertStringContainsString('assets/js/profile-photo.js', $html);
    }

    public function test_company_logo_and_login_background_use_the_standard_field(): void
    {
        Http::fake($this->notificationsFixture());

        $html = (string) $this->withSession($this->desktopSession(['configuracoes' => ['visualizar', 'editar']]))
            ->get('/configuracoes/sistema?tab=empresa')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-image-picker-keep-png="true".*?name="empresa_logo"[^>]*id="empresa_logo"/s', $html);
        $this->assertMatchesRegularExpression('/data-image-picker="field".*?name="login_background_image"[^>]*id="login_background_image"/s', $html);
        // O texto antigo prometia GIF e SVG, que o backend recusa.
        $this->assertStringNotContainsString('PNG, JPG, GIF ou SVG', $html);
    }

    public function test_financeiro_form_attachment_uses_the_standard_document_field(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => []],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $html = (string) $this->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-image-picker-accept="document".*?data-image-picker-paste="page".*?name="anexo"[^>]*id="financeiroAnexoArquivo"[^>]*data-image-picker-input/s', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationsFixture(): array
    {
        return [
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
