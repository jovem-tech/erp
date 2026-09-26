<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Trava de arquitetura do padrão único de inserção de imagem (specs/049).
 *
 * Toda tela que recebe imagem usa <x-image-picker.field> (formulário),
 * <x-image-picker.queue> (fila com Enviar) ou ErpImagePicker.attach() — com
 * Câmera, Computador/galeria, Colar, arrastar e recorte opcional. Se alguém
 * escrever um <input type="file"> de imagem por conta própria, este teste
 * falha e aponta o arquivo: a regra vale para implementações futuras também.
 */
class ImageInsertionStandardTest extends TestCase
{
    /** Marcas dos inputs que pertencem ao padrão. */
    private const STANDARD_MARKERS = ['data-image-picker-input', 'data-image-picker-file', 'data-image-picker-capture'];

    /**
     * Scripts que criam <input type="file"> por conta própria, com o motivo.
     * Acrescentar aqui exige justificativa: não é uma porta de fuga do padrão.
     */
    private const JS_FILE_INPUT_ALLOWLIST = [
        'image-picker.js' => 'a própria biblioteca do padrão',
        'orders-create.js' => 'só carrega no form da OS as fotos que o cadastro de equipamento embutido já escolheu pelo padrão',
    ];

    public function test_every_image_file_input_in_the_views_belongs_to_the_standard_picker(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // Comentários (Blade e HTML) citam tags como exemplo; não são tela.
            $contents = preg_replace(['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s'], '', $file->getContents()) ?? '';
            if (! preg_match_all('/<input\b[^>]*>/is', $contents, $matches)) {
                continue;
            }

            foreach ($matches[0] as $tag) {
                if (! preg_match('/\btype\s*=\s*["\']file["\']/i', $tag) || ! $this->acceptsImages($tag)) {
                    continue;
                }

                $isStandard = collect(self::STANDARD_MARKERS)->contains(static fn (string $marker): bool => str_contains($tag, $marker));
                if (! $isStandard) {
                    $offenders[] = $file->getRelativePathname().': '.preg_replace('/\s+/', ' ', trim($tag));
                }
            }
        }

        $this->assertSame([], $offenders, "Input de imagem fora do padrão de inserção de imagem (specs/049).\n"
            ."Use <x-image-picker.field> (formulário) ou <x-image-picker.queue> (fila com Enviar), ou marque o input "
            ."que só carrega arquivos para o form com data-image-picker-input e ligue as origens com ErpImagePicker.attach().\n"
            .implode("\n", $offenders));
    }

    public function test_scripts_do_not_create_their_own_file_inputs(): void
    {
        $offenders = [];

        foreach (File::files(public_path('assets/js')) as $file) {
            if ($file->getExtension() !== 'js' || array_key_exists($file->getFilename(), self::JS_FILE_INPUT_ALLOWLIST)) {
                continue;
            }

            $contents = $file->getContents();
            if (preg_match('/\.type\s*=\s*[\'"]file[\'"]|setAttribute\(\s*[\'"]type[\'"]\s*,\s*[\'"]file[\'"]|type=["\']file["\']/i', $contents)) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'Script criando <input type="file"> fora do padrão de inserção de imagem (specs/049): '
            .implode(', ', $offenders).'. Use window.ErpImagePicker (attach/field/uploader).');
    }

    public function test_standard_library_is_loaded_by_the_shared_layout(): void
    {
        $layout = File::get(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("asset('assets/js/image-picker.js')", $layout);
        $this->assertFileExists(public_path('assets/js/image-picker.js'));
        $this->assertFileExists(public_path('assets/libs/cropperjs/cropper.min.js'));
    }

    private function acceptsImages(string $tag): bool
    {
        if (! preg_match('/\baccept\s*=\s*["\']([^"\']*)["\']/i', $tag, $match)) {
            // Sem accept: aceita qualquer arquivo, inclusive imagem.
            return ! str_contains($tag, 'data-non-image-upload');
        }

        $accept = strtolower($match[1]);
        if (str_contains($accept, 'imagepicker::accept')) {
            return true;
        }

        return str_contains($accept, 'image') || (bool) preg_match('/\.(jpe?g|png|webp|gif|heic|heif|avif|bmp)\b/', $accept);
    }
}
