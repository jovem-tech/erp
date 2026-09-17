<?php

namespace Tests\Unit\Services\Pdf;

use App\Services\Pdf\PdfCompressionService;
use Tests\TestCase;

/**
 * A distro Ubuntu confina o binário `gs` num profile AppArmor que só libera
 * escrita em /tmp, /var/tmp ou dentro do $HOME do usuário do processo — um
 * diretório arbitrário sob o próprio projeto (ex.: storage/framework/cache)
 * é negado pelo kernel mesmo com permissões Unix corretas. Por isso o
 * serviço grava os temporários do Ghostscript em sys_get_temp_dir(), nunca
 * em document-rendering.temp_directory. Estes testes rodam contra o `gs`
 * real (skip se ausente) para nunca reintroduzir essa regressão.
 */
class PdfCompressionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable('/usr/bin/gs')) {
            $this->markTestSkipped('Ghostscript (/usr/bin/gs) não disponível.');
        }

        config()->set('document-rendering.archive.ghostscript', true);
        config()->set('document-rendering.archive.gs_binary', '/usr/bin/gs');
        config()->set('document-rendering.archive.gs_timeout_seconds', 20);
    }

    public function test_compresses_an_oversized_pdf_below_the_byte_budget(): void
    {
        $bytes = $this->syntheticPdfWithImage(1200, 1200, 95);
        $this->assertGreaterThan(80 * 1024, strlen($bytes), 'fixture precisa nascer acima do teto para o teste fazer sentido');

        $compressed = (new PdfCompressionService)->compress($bytes, 80 * 1024);

        $this->assertLessThanOrEqual(80 * 1024, strlen($compressed));
        $this->assertStringStartsWith('%PDF-', $compressed);
    }

    public function test_never_returns_something_larger_than_the_input(): void
    {
        $bytes = $this->syntheticPdfWithImage(40, 40, 95);

        $compressed = (new PdfCompressionService)->compress($bytes, 80 * 1024);

        $this->assertLessThanOrEqual(strlen($bytes), strlen($compressed));
    }

    public function test_missing_binary_returns_original_bytes_without_throwing(): void
    {
        config()->set('document-rendering.archive.gs_binary', '/usr/bin/gs-que-nao-existe');

        $bytes = $this->syntheticPdfWithImage(200, 200, 90);

        $this->assertSame($bytes, (new PdfCompressionService)->compress($bytes, 80 * 1024));
    }

    public function test_writes_its_temporary_files_under_the_system_temp_directory(): void
    {
        // Regressão do achado do AppArmor: se algum dia esse método voltar a
        // usar document-rendering.temp_directory (dentro do projeto), o gs
        // falha silenciosamente no Ubuntu e a compressão nunca acontece.
        config()->set('document-rendering.temp_directory', '/definitivamente/nao/existe/e/nao/pode/ser/usado');

        $bytes = $this->syntheticPdfWithImage(1200, 1200, 95);
        $compressed = (new PdfCompressionService)->compress($bytes, 80 * 1024);

        $this->assertLessThan(strlen($bytes), strlen($compressed), 'a compressão precisa ter funcionado mesmo com temp_directory inválido');
    }

    /**
     * PDF sintético (sem dompdf) com uma única imagem JPEG embutida em
     * $width x $height pixels, mas exibida numa área bem menor (1/6 do
     * lado) — o sobre-amostragamento é o que dá ao Ghostscript trabalho
     * real de reamostragem em /screen (72 dpi)/nível 2 (55 dpi); embutir a
     * imagem já do tamanho de exibição faria o preset não ter nada a fazer.
     */
    private function syntheticPdfWithImage(int $width, int $height, int $quality): string
    {
        // Textura com gradiente + ruído local (não uma cor sólida, que o
        // Ghostscript já reduziria a quase nada mesmo sem downsampling; nem
        // ruído puramente aleatório, que não compacta em NENHUM nível —
        // fotos reais ficam entre esses dois extremos).
        $image = imagecreatetruecolor($width, $height);
        mt_srand(42);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $base = (int) (($x / max(1, $width)) * 255);
                $noise = mt_rand(-20, 20);
                $value = max(0, min(255, $base + $noise));
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $value, (int) (255 - $value), (int) (($x + $y) % 256)));
            }
        }
        ob_start();
        imagejpeg($image, null, $quality);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $displayWidth = max(50, (int) ($width / 6));
        $displayHeight = max(50, (int) ($height / 6));
        $stream = "q\n{$displayWidth} 0 0 {$displayHeight} 0 0 cm\n/Im0 Do\nQ";
        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$displayWidth} {$displayHeight}] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>",
            4 => "<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream",
            5 => "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>\nstream\n{$jpeg}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($objects as $id => $body) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        return $pdf;
    }
}
