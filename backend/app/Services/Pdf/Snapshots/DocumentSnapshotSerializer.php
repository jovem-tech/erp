<?php

namespace App\Services\Pdf\Snapshots;

use App\Models\PdfTemplateVersao;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Transforma o DocumentContext de uma emissão no envelope gravado em
 * os_documento_snapshots. Nenhum base64 entra: logo, fotos e rubricas viram
 * referências (id/caminho/hash) e datas viram {"$data": ISO-8601}, para o
 * re-render reproduzir o documento daquele instante sem carregar MBs.
 */
final class DocumentSnapshotSerializer
{
    public const FORMAT_VERSION = 1;

    /** Chaves do contexto que carregam data URI e saem do envelope. */
    private const IMAGE_PATHS = [
        'empresa.logo_base64' => '',
        'equipamento.foto_principal_base64' => '',
        'os.fotos_entrada' => [],
    ];

    /**
     * @param  array<string, mixed>  $context  contexto completo já renderizado (com base64)
     * @param  array<string, mixed>  $descriptor
     * @param  array<string, mixed>  $signatureAudit  PdfGenerationService::signatureContext()['audit']
     * @param  array<string, mixed>  $renderProfile  ['perfil' => 'padrao', 'foto_max_dim' => 1400, 'foto_qualidade' => 72]
     * @param  array<string, mixed>|null  $logoReference  CompanyContextProvider::logoReference()
     * @param  array<string, mixed>|null  $customerSignature  opção customer_signature da emissão
     * @return array<string, mixed>
     */
    public function fromEngineContext(
        array $context,
        array $descriptor,
        PdfTemplateVersao $versao,
        array $signatureAudit,
        array $renderProfile,
        ?array $logoReference = null,
        ?array $customerSignature = null
    ): array {
        $refs = is_array($context['_refs']['imagens'] ?? null) ? $context['_refs']['imagens'] : [];
        unset($context['_refs']);

        $documento = is_array($context['documento'] ?? null) ? $context['documento'] : [];
        $assinaturas = is_array($context['assinaturas'] ?? null) ? $context['assinaturas'] : [];
        unset($context['documento'], $context['assinaturas']);

        foreach (self::IMAGE_PATHS as $path => $blank) {
            if (self::hasPath($context, $path)) {
                self::setPath($context, $path, $blank);
            }
        }

        if (is_array($logoReference)) {
            $refs['empresa.logo_base64'] = $logoReference;
        }

        $responsavel = null;
        if ((int) ($signatureAudit['usuario_id'] ?? 0) > 0) {
            $responsavel = [
                'usuario_id' => (int) $signatureAudit['usuario_id'],
                'assinatura_id' => (int) ($signatureAudit['assinatura_id'] ?? 0),
                'hash_sha256' => (string) ($signatureAudit['hash_sha256'] ?? ''),
                'nome' => (string) ($signatureAudit['signatario_nome'] ?? ''),
                'funcao' => (string) ($signatureAudit['signatario_funcao'] ?? ''),
                'assinada_em' => (string) ($signatureAudit['assinada_em'] ?? ''),
                'metodo' => (string) ($signatureAudit['metodo'] ?? ''),
            ];
        }

        $cliente = null;
        if (is_array($assinaturas['cliente'] ?? null)) {
            $cliente = [
                'nome' => (string) ($assinaturas['cliente']['nome'] ?? 'Cliente'),
                'funcao' => (string) ($assinaturas['cliente']['funcao'] ?? 'Cliente'),
                'assinada_em' => (string) ($assinaturas['cliente']['assinada_em'] ?? ''),
                'hash_sha256' => (string) ($assinaturas['cliente']['hash_sha256'] ?? ''),
                'arquivo' => (string) ($customerSignature['path'] ?? ''),
            ];
        }

        return [
            'v' => self::FORMAT_VERSION,
            'tipo_codigo' => (string) ($descriptor['codigo'] ?? $descriptor['tipo_codigo'] ?? ''),
            'template' => [
                'id' => (int) $versao->template_id,
                'versao_id' => (int) $versao->id,
                'versao' => (int) $versao->versao,
                'hash_schema' => (string) ($versao->hash_schema ?? ''),
            ],
            'documento' => self::tagDates($documento),
            'contexto' => self::tagDates($context),
            'imagens' => $refs,
            'assinaturas' => [
                'responsavel' => $responsavel,
                'cliente' => $cliente,
            ],
            'render' => $renderProfile,
        ];
    }

    /**
     * Hash canônico do envelope (sem `render`: perfil de compressão é chave
     * de cache, não identidade do documento).
     *
     * @param  array<string, mixed>  $envelope
     */
    public static function canonicalHash(array $envelope): string
    {
        unset($envelope['render']);

        return hash('sha256', self::canonicalJson($envelope));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function canonicalJson(array $value): string
    {
        return self::encode(self::ksortRecursive($value));
    }

    /**
     * Dados legados podem trazer bytes fora de UTF-8; substituir em vez de
     * falhar, senão a emissão inteira seria bloqueada por um caractere.
     *
     * @param  array<string, mixed>  $value
     */
    public static function encode(array $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (! is_string($json)) {
            throw new \RuntimeException('Não foi possível serializar o snapshot do documento: '.json_last_error_msg());
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Datas viram {"$data": "2026-09-13T10:22:31-03:00"}; objetos com
     * __toString viram string; o resto passa como está.
     */
    public static function tagDates(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return ['$data' => Carbon::instance($value)->toIso8601String()];
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::tagDates($item);
            }

            return $value;
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : null;
        }

        return $value;
    }

    public static function untagDates(mixed $value): mixed
    {
        if (is_array($value)) {
            if (count($value) === 1 && array_key_exists('$data', $value) && is_string($value['$data'])) {
                try {
                    return Carbon::parse($value['$data']);
                } catch (\Throwable) {
                    return $value['$data'];
                }
            }

            foreach ($value as $key => $item) {
                $value[$key] = self::untagDates($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function ksortRecursive(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::ksortRecursive($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function hasPath(array $data, string $path): bool
    {
        $cursor = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return false;
            }
            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function setPath(array &$data, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $last = array_pop($segments);
        $cursor = &$data;
        foreach ($segments as $segment) {
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
        $cursor[$last] = $value;
    }
}
