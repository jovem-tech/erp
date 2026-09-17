<?php

namespace App\Services\Pdf\Snapshots;

use App\Models\UserSignature;
use App\Services\Pdf\Contexts\CompanyContextProvider;
use App\Services\Pdf\Contexts\OrderPdfContextFactory;
use App\Services\Signatures\SignatureImageService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Storage;

/**
 * Faz o caminho inverso do serializer: envelope do snapshot -> contexto
 * pronto para o PdfTemplateRenderer, reembutindo logo, fotos e rubricas a
 * partir das referências. Nada aqui derruba o render: imagem que sumiu é
 * omitida e anotada em `divergencias`.
 */
final class DocumentSnapshotHydrator
{
    public function __construct(
        private readonly Container $container,
        private readonly CompanyContextProvider $companyContextProvider,
        private readonly SignatureImageService $signatureImageService
    ) {
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $descriptor
     * @param  array<int, string>  $imageTokens  tokens de imagem que o schema realmente usa
     * @return array{context: array<string, mixed>, divergencias: array<int, string>}
     */
    public function toRenderContext(array $envelope, array $descriptor, array $imageTokens): array
    {
        $divergencias = [];
        $context = DocumentSnapshotSerializer::untagDates(is_array($envelope['contexto'] ?? null) ? $envelope['contexto'] : []);
        $context['documento'] = DocumentSnapshotSerializer::untagDates(is_array($envelope['documento'] ?? null) ? $envelope['documento'] : []);

        $refs = is_array($envelope['imagens'] ?? null) ? $envelope['imagens'] : [];
        $profile = $this->photoProfile($envelope);
        $factory = $this->orderFactory($descriptor);

        // Logo: sempre a atual (a empresa é uma só); só anota se mudou.
        if (is_array($context['empresa'] ?? null)) {
            $context['empresa']['logo_base64'] = $this->companyContextProvider->logoDataUri();
            $expected = (string) ($refs['empresa.logo_base64']['sha256'] ?? '');
            $current = $this->companyContextProvider->logoReference();
            if ($expected !== '' && $expected !== (string) ($current['sha256'] ?? '')) {
                $divergencias[] = 'logo_alterada_desde_emissao';
            }
        }

        if (
            $factory instanceof OrderPdfContextFactory
            && in_array('foto_equipamento_principal', $imageTokens, true)
            && is_array($refs['equipamento.foto_principal_base64'] ?? null)
        ) {
            $dataUri = $factory->equipmentPhotoFromRef($refs['equipamento.foto_principal_base64'], $profile);
            if ($dataUri === '') {
                $divergencias[] = 'foto_equipamento_indisponivel';
            }
            $context['equipamento'] = is_array($context['equipamento'] ?? null) ? $context['equipamento'] : [];
            $context['equipamento']['foto_principal_base64'] = $dataUri;
        }

        if (
            $factory instanceof OrderPdfContextFactory
            && in_array('fotos_entrada', $imageTokens, true)
            && is_array($refs['os.fotos_entrada'] ?? null)
        ) {
            $dataUris = $factory->entryPhotosFromRefs($refs['os.fotos_entrada'], $profile);
            if (count($dataUris) !== count($refs['os.fotos_entrada'])) {
                $divergencias[] = 'fotos_entrada_incompletas';
            }
            $context['os'] = is_array($context['os'] ?? null) ? $context['os'] : [];
            $context['os']['fotos_entrada'] = $dataUris;
            if (array_key_exists('fotos_quantidade', $context['os'])) {
                $context['os']['fotos_quantidade'] = count($dataUris);
            }
        }

        $context['assinaturas'] = $this->signatures($envelope, $divergencias);

        return ['context' => $context, 'divergencias' => $divergencias];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<int, string>  $divergencias
     * @return array{responsavel: array<string, mixed>|null, cliente: array<string, mixed>|null}
     */
    private function signatures(array $envelope, array &$divergencias): array
    {
        $stored = is_array($envelope['assinaturas'] ?? null) ? $envelope['assinaturas'] : [];

        $responsavel = null;
        $ref = is_array($stored['responsavel'] ?? null) ? $stored['responsavel'] : null;
        if ($ref !== null && (int) ($ref['assinatura_id'] ?? 0) > 0) {
            $signature = UserSignature::query()->find((int) $ref['assinatura_id']);
            $dataUri = $signature instanceof UserSignature ? $this->signatureImageService->dataUri($signature) : null;
            if ($dataUri !== null) {
                $responsavel = [
                    'imagem' => $dataUri,
                    'nome' => (string) ($ref['nome'] ?? ''),
                    'funcao' => (string) ($ref['funcao'] ?? ''),
                    'assinada_em' => (string) ($ref['assinada_em'] ?? ''),
                    'assinatura_id' => (int) $ref['assinatura_id'],
                    'hash_sha256' => (string) ($ref['hash_sha256'] ?? ''),
                ];
                if ((string) ($ref['hash_sha256'] ?? '') !== '' && (string) $signature->hash_sha256 !== (string) $ref['hash_sha256']) {
                    $divergencias[] = 'assinatura_responsavel_alterada';
                }
            } else {
                $divergencias[] = 'assinatura_responsavel_indisponivel';
            }
        }

        $cliente = null;
        $ref = is_array($stored['cliente'] ?? null) ? $stored['cliente'] : null;
        if ($ref !== null) {
            $path = trim((string) ($ref['arquivo'] ?? ''));
            $bytes = $path !== '' && ! str_contains($path, '..') && Storage::disk('local')->exists($path)
                ? Storage::disk('local')->get($path)
                : '';
            if ($bytes !== '' && (
                (string) ($ref['hash_sha256'] ?? '') === ''
                || hash_equals((string) $ref['hash_sha256'], hash('sha256', $bytes))
            )) {
                $cliente = [
                    'imagem' => 'data:image/png;base64,'.base64_encode($bytes),
                    'nome' => (string) ($ref['nome'] ?? 'Cliente'),
                    'funcao' => (string) ($ref['funcao'] ?? 'Cliente'),
                    'assinada_em' => (string) ($ref['assinada_em'] ?? ''),
                    'hash_sha256' => (string) ($ref['hash_sha256'] ?? ''),
                ];
            } else {
                $divergencias[] = 'assinatura_cliente_indisponivel';
            }
        }

        return ['responsavel' => $responsavel, 'cliente' => $cliente];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{max_dimension: int, quality: int}
     */
    private function photoProfile(array $envelope): array
    {
        $render = is_array($envelope['render'] ?? null) ? $envelope['render'] : [];
        $fallback = (array) config('document-rendering.photos.padrao', []);

        return [
            'max_dimension' => (int) ($render['foto_max_dim'] ?? $fallback['max_dimension'] ?? 1400),
            'quality' => (int) ($render['foto_qualidade'] ?? $fallback['quality'] ?? 72),
        ];
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function orderFactory(array $descriptor): ?OrderPdfContextFactory
    {
        $class = (string) ($descriptor['context_factory'] ?? '');
        if ($class === '' || ! is_a($class, OrderPdfContextFactory::class, true)) {
            return null;
        }

        $factory = $this->container->make($class);

        return $factory instanceof OrderPdfContextFactory ? $factory : null;
    }
}
