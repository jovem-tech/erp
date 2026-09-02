<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documento fiscal emitido — specs/041-emissao-fiscal-nfse/spec.md, fase 042.
 *
 * Tabela nova, e nao colunas em `os`, porque uma OS gera MAIS DE UM documento:
 * a NFS-e do servico e a NF-e da peca saem por orgaos diferentes, e um
 * documento cancelado e' substituido por outro. `os.numero_nfse` quebraria no
 * primeiro cancelamento e perderia o historico que a fiscalizacao pede.
 *
 * O vinculo segue a familia que `movimentacoes` ja' usa (`os_id`/`venda_id`),
 * sem FK, e NAO `origem_tipo`/`origem_id`: apesar do nome, `origem_id` em
 * `financeiro` e' um belongsTo(FinanceiroMovimento), e gravar outra coisa ali
 * carrega registro alheio de mesmo id, em silencio — armadilha ja' registrada
 * na 039.
 *
 * Nesta fase nao ha' integracao: o ERP monta o rascunho, o operador emite no
 * portal do gov.br e devolve numero e chave. Por isso `numero`, `serie` e
 * `chave` sao nullable — o documento existe como rascunho antes de existir de
 * verdade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('documentos_fiscais')) {
            return;
        }

        Schema::create('documentos_fiscais', function (Blueprint $table): void {
            $table->id();

            // nfse (servico, ISS, Emissor Nacional) | nfe / nfce (mercadoria,
            // ICMS, SEFAZ estadual). Sao documentos diferentes, nao variacoes.
            $table->string('tipo', 20)->default('nfse');
            $table->string('status', 30)->default('rascunho');

            $table->unsignedBigInteger('os_id')->nullable();
            $table->unsignedBigInteger('venda_id')->nullable();
            $table->unsignedBigInteger('cliente_id')->nullable();

            // Congelados no momento em que o documento foi montado: renomear o
            // cliente ou corrigir o CPF depois nao pode reescrever o que foi
            // declarado ao fisco.
            $table->string('tomador_nome', 160)->nullable();
            $table->string('tomador_documento', 20)->nullable();

            $table->text('discriminacao')->nullable();
            $table->decimal('valor_servicos', 12, 2)->default(0);
            $table->decimal('valor_pecas', 12, 2)->default(0);
            $table->decimal('valor_total', 12, 2)->default(0);

            // Preenchidos quando o operador devolve o retorno do portal.
            $table->string('numero', 30)->nullable();
            $table->string('serie', 10)->nullable();
            $table->string('chave', 60)->nullable();
            $table->dateTime('emitido_em')->nullable();

            $table->dateTime('cancelado_em')->nullable();
            $table->text('motivo_cancelamento')->nullable();
            $table->text('motivo_rejeicao')->nullable();

            // Guarda dos 5 anos. Caminho relativo + hash, mesma forma de
            // `os_documento_arquivos`, e NAO um `managed_file_id`: o
            // Gerenciador Central roda em modo `shadow` com escrita central
            // desligada (`FILE_MANAGER_MODE=shadow`, `ALLOW_WRITES=false`),
            // entao no momento do upload nao existe ManagedFile para apontar.
            // Ele cataloga o arquivo depois, pela varredura automatica — e' o
            // mesmo caminho de todo upload do sistema.
            $table->string('xml_arquivo', 255)->nullable();
            $table->string('xml_hash_sha256', 64)->nullable();
            $table->unsignedBigInteger('xml_tamanho_bytes')->nullable();
            $table->string('pdf_arquivo', 255)->nullable();
            $table->string('pdf_hash_sha256', 64)->nullable();
            $table->unsignedBigInteger('pdf_tamanho_bytes')->nullable();

            $table->unsignedBigInteger('criado_por')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->index(['os_id', 'tipo'], 'idx_docfiscal_os_tipo');
            $table->index(['venda_id', 'tipo'], 'idx_docfiscal_venda_tipo');
            $table->index(['status', 'emitido_em'], 'idx_docfiscal_status_emissao');
            // MySQL permite varios NULL num indice unico, entao rascunho (sem
            // numero) nao colide — mas dois documentos emitidos com o mesmo
            // numero na mesma serie, sim.
            $table->unique(['tipo', 'serie', 'numero'], 'ux_docfiscal_tipo_serie_numero');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_fiscais');
    }
};
