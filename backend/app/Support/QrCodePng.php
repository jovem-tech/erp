<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * QR Code como PNG em data URI, para embutir em PDF.
 *
 * **Por que o PNG é montado à mão** em vez de usar um renderer da própria
 * biblioteca: o destino é o dompdf. O renderer SVG do BaconQrCode depende do
 * suporte parcial a SVG do dompdf, e o renderer de imagem exige a extensão
 * Imagick, que não está instalada nos servidores deste projeto. Um PNG em
 * escala de cinza é meia dúzia de chunks e o dompdf desenha sem intermediário
 * nenhum — nem Imagick, nem GD.
 *
 * A biblioteca continua fazendo a parte difícil (Reed-Solomon, versão, máscara);
 * daqui sai só o desenho da matriz.
 */
class QrCodePng
{
    /**
     * PNG em `data:` URI, pronto para o `src` de uma `<img>`.
     *
     * @param  int  $escala  pixels por módulo — quanto maior, mais nítido no papel
     * @param  int  $margem  módulos de zona de silêncio; 4 é o mínimo da norma ISO
     */
    public static function dataUri(string $conteudo, int $escala = 8, int $margem = 4): string
    {
        return 'data:image/png;base64,'.base64_encode(self::png($conteudo, $escala, $margem));
    }

    /**
     * @return string bytes do PNG
     */
    public static function png(string $conteudo, int $escala = 8, int $margem = 4): string
    {
        $matriz = Encoder::encode($conteudo, ErrorCorrectionLevel::M())->getMatrix();

        $modulos = $matriz->getWidth();
        $lado = ($modulos + 2 * $margem) * $escala;

        // Uma linha inteiramente branca, reaproveitada na zona de silêncio de
        // cima e de baixo. O primeiro byte de cada scanline é o filtro (0 = sem
        // filtro), exigência do formato.
        $linhaBranca = "\x00".str_repeat("\xff", $lado);

        $bruto = str_repeat($linhaBranca, $margem * $escala);

        for ($y = 0; $y < $modulos; $y++) {
            $linha = str_repeat("\xff", $margem * $escala);

            for ($x = 0; $x < $modulos; $x++) {
                $linha .= str_repeat($matriz->get($x, $y) === 1 ? "\x00" : "\xff", $escala);
            }

            $linha .= str_repeat("\xff", $margem * $escala);

            // A mesma linha de módulos se repete `escala` vezes na vertical.
            $bruto .= str_repeat("\x00".$linha, $escala);
        }

        $bruto .= str_repeat($linhaBranca, $margem * $escala);

        return "\x89PNG\r\n\x1a\n"
            // IHDR: largura, altura, 8 bits por amostra, tipo 0 (tons de
            // cinza), compressao/filtro/entrelacamento padrao.
            .self::chunk('IHDR', pack('NNCCCCC', $lado, $lado, 8, 0, 0, 0, 0))
            .self::chunk('IDAT', (string) gzcompress($bruto, 9))
            .self::chunk('IEND', '');
    }

    private static function chunk(string $tipo, string $dados): string
    {
        return pack('N', strlen($dados)).$tipo.$dados.pack('N', crc32($tipo.$dados));
    }
}
