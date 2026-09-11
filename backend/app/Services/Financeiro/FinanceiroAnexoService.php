<?php

namespace App\Services\Financeiro;

use App\Models\Financeiro;
use App\Models\FinanceiroAnexo;
use App\Services\Files\FileManagerConfiguration;
use App\Services\Files\FilePathGuard;
use App\Services\Files\FinanceiroAnexoCatalogService;
use App\Services\Files\ManagedFileDomainLifecycleService;
use App\Enums\Files\FileCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FinanceiroAnexoService
{
    public function __construct(
        private readonly FinanceiroAnexoCatalogService $catalog,
        private readonly ManagedFileDomainLifecycleService $lifecycle,
        private readonly FileManagerConfiguration $configuration
    ) {}

    public function anexar(Financeiro $financeiro, UploadedFile $arquivo, ?string $descricao, int $usuarioId): FinanceiroAnexo
    {
        $realPath = $arquivo->getRealPath();
        $conteudo = is_string($realPath) ? file_get_contents($realPath) : false;
        if (! is_string($conteudo) || $conteudo === '') {
            throw new RuntimeException('Não foi possível ler o arquivo anexado.');
        }
        if (strlen($conteudo) > 20 * 1024 * 1024) {
            throw new RuntimeException('O arquivo anexado excede o limite permitido.');
        }

        $mime = (string) ($arquivo->getMimeType() ?: '');
        $extensao = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('Tipo real do arquivo anexado não permitido.'),
        };
        $caminho = sprintf('private/financeiro/%d/%s.%s', $financeiro->id, (string) Str::uuid(), $extensao);
        $nomeSeguro = FilePathGuard::safeFileName($arquivo->getClientOriginalName(), $extensao);

        if (! Storage::disk('local')->put($caminho, $conteudo)) {
            throw new RuntimeException('Não foi possível armazenar o arquivo anexado.');
        }

        try {
            return FinanceiroAnexo::query()->create([
                'financeiro_id' => $financeiro->id,
                'nome_original' => $nomeSeguro,
                'descricao' => $descricao,
                'arquivo' => $caminho,
                'mime' => $mime,
                'tamanho_bytes' => strlen($conteudo),
                'hash_sha256' => hash('sha256', $conteudo),
                'usuario_id' => $usuarioId,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($caminho);
            throw $exception;
        }
    }

    public function excluir(FinanceiroAnexo $anexo, ?int $actorId = null): void
    {
        if (! $this->configuration->isAuthoritativeCategory(FileCategory::FinanceiroAnexo)) {
            Storage::disk('local')->delete($anexo->arquivo);
            $anexo->forceDelete();

            return;
        }

        $managed = $anexo->managedFile;
        if ($managed === null) {
            try {
                $managed = $this->catalog->synchronize($anexo);
            } catch (\Throwable $exception) {
                Log::warning('[FILE_MANAGER][FINANCEIRO] Exclusão mantida como pendente de catálogo.', [
                    'anexo_id' => (int) $anexo->id,
                    'error_type' => $exception::class,
                ]);
            }
        }

        if ($managed !== null) {
            $this->lifecycle->trash(
                $managed,
                $actorId,
                'Exclusão solicitada no módulo Financeiro.',
                $actorId,
                'financeiro'
            );

            return;
        }

        $anexo->delete();
    }

    public function prepararExclusaoDoLancamento(FinanceiroAnexo $anexo, ?int $actorId = null): void
    {
        if (! $this->configuration->isAuthoritativeCategory(FileCategory::FinanceiroAnexo)) {
            Storage::disk('local')->delete($anexo->arquivo);

            return;
        }

        $managed = $anexo->managedFile ?? $this->catalog->synchronize($anexo);
        if ($managed === null) {
            throw new RuntimeException('Não foi possível preservar o anexo na lixeira antes de excluir o lançamento.');
        }

        $this->lifecycle->trash(
            $managed,
            $actorId,
            'Exclusão do lançamento financeiro de origem.',
            $actorId,
            'financeiro_parent_delete'
        );
    }
}
