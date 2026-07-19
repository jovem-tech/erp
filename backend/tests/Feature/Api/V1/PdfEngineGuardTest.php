<?php

namespace Tests\Feature\Api\V1;

use App\Services\Company\CompanyProfileService;
use App\Services\Pdf\Contexts\CompanyContextProvider;
use App\Services\Pdf\PdfDefaultTemplates;
use App\Services\Pdf\PdfSchemaValidator;
use App\Services\Pdf\PdfTemplateRegistry;
use App\Services\Pdf\PdfVariableResolver;
use App\Support\TemplateHtmlSanitizer;
use Tests\TestCase;

/**
 * Guardas do motor central de PDF:
 *  1. Todo tipo registrado precisa ter um template-padrão (fonte do seed) —
 *     sem isso, um tipo novo entraria no registry sem template publicável.
 *  2. Nenhum código pode chamar dompdf fora de app/Services/Pdf. Assim não
 *     surgem geradores paralelos que ignorem o template publicado.
 */
class PdfEngineGuardTest extends TestCase
{
    public function test_every_registered_type_has_a_default_template_definition(): void
    {
        $registry = new PdfTemplateRegistry();
        $defaults = array_keys(PdfDefaultTemplates::all());

        foreach ($registry->codes() as $codigo) {
            $this->assertContains(
                $codigo,
                $defaults,
                sprintf('O tipo registrado "%s" não tem template-padrão em PdfDefaultTemplates — o seed nunca vai publicá-lo.', $codigo)
            );
        }
    }

    public function test_every_default_template_belongs_to_a_registered_type_and_validates(): void
    {
        $registry = new PdfTemplateRegistry();
        $validator = app(PdfSchemaValidator::class);

        foreach (PdfDefaultTemplates::all() as $codigo => $definition) {
            $descriptor = $registry->get($codigo);
            $this->assertNotNull($descriptor, sprintf('Template-padrão "%s" sem tipo registrado no registry.', $codigo));

            $errors = $validator->validate($definition['schema'], $descriptor);
            $this->assertSame([], $errors, sprintf('Template-padrão "%s" inválido: %s', $codigo, implode(' | ', $errors)));
        }
    }

    public function test_no_new_code_uses_dompdf_outside_the_pdf_engine_namespace(): void
    {
        $appDir = base_path('app');
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path() . '/', '', $file->getPathname());

            // Motor central: único namespace autorizado a usar dompdf.
            if (str_starts_with($relative, 'app/Services/Pdf/')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/Pdf::loadView|Pdf::loadHTML|Barryvdh\\\\DomPDF|new\s+Dompdf/i', $contents)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Geração de PDF fora do motor central detectada (use App\\Services\\Pdf\\PdfGenerationService): '
                . implode(', ', $offenders)
        );
    }

    public function test_plain_text_is_escaped_and_only_rich_text_keeps_sanitized_markup(): void
    {
        $resolver = new PdfVariableResolver();
        $context = ['cliente' => ['nome' => '<b>Maria</b>']];
        $types = ['cliente.nome' => 'string'];

        $plain = $resolver->resolveText(
            "Termo <script>alert('xss')</script>\n{{ cliente.nome }}",
            $context,
            $types
        );

        $this->assertStringContainsString('&lt;script&gt;', $plain);
        $this->assertStringContainsString('&lt;b&gt;Maria&lt;/b&gt;', $plain);
        $this->assertStringContainsString("&lt;/script&gt;\n&lt;b&gt;", $plain);
        $this->assertStringNotContainsString('<script>', $plain);

        $rich = TemplateHtmlSanitizer::sanitize(
            $resolver->resolveRichText('<strong>Garantia</strong><script>alert(1)</script>', [], [])
        );

        $this->assertStringContainsString('<strong>Garantia</strong>', $rich);
        $this->assertStringNotContainsString('<script>', $rich);
    }

    public function test_company_trade_name_falls_back_to_the_configured_system_name(): void
    {
        CompanyContextProvider::forgetLogoCache();

        $profile = \Mockery::mock(CompanyProfileService::class);
        $profile->shouldReceive('payload')->once()->andReturn(['settings' => [
            'sistema_nome' => 'Jovem Tech OS',
            'empresa_razao_social' => '',
            'empresa_nome_fantasia' => '',
        ]]);
        $profile->shouldReceive('resolveLogoFile')->once()->andReturn(null);

        $context = (new CompanyContextProvider($profile))->build();

        $this->assertSame('Jovem Tech OS', $context['nome_sistema']);
        $this->assertSame('Jovem Tech OS', $context['nome_fantasia']);
        $this->assertSame('', $context['razao_social']);
    }
}
