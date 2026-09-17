<?php

namespace Tests\Unit\Services\Pdf;

use App\Models\PdfTemplateVersao;
use App\Services\Pdf\Snapshots\DocumentSnapshotSerializer;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class DocumentSnapshotSerializerTest extends TestCase
{
    public function test_envelope_strips_base64_keeps_references_and_round_trips_dates(): void
    {
        $versao = new PdfTemplateVersao(['template_id' => 3, 'versao' => 2, 'hash_schema' => str_repeat('f', 64)]);
        $versao->id = 17;

        $generated = Carbon::parse('2026-09-13T10:22:31-03:00');
        $context = [
            'os' => ['numero' => 'OS1', 'fotos_entrada' => ['data:image/jpeg;base64,AAAA', 'data:image/jpeg;base64,BBBB'], 'abertura' => $generated],
            'equipamento' => ['foto_principal_base64' => 'data:image/png;base64,CCCC'],
            'empresa' => ['nome_fantasia' => 'Jovem Tech', 'logo_base64' => 'data:image/png;base64,DDDD'],
            'orcamento' => ['total' => 10.0, 'validade_data' => null],
            'documento' => ['nome' => 'Laudo', 'gerado_em' => $generated, 'usuario' => 'Fulano'],
            'assinaturas' => ['responsavel' => ['imagem' => 'data:image/png;base64,EEEE'], 'cliente' => ['imagem' => 'data:image/png;base64,FFFF', 'nome' => 'Maria', 'hash_sha256' => 'h']],
            '_refs' => ['imagens' => [
                'os.fotos_entrada' => [['tipo' => 'order_photo', 'os_id' => 1, 'foto_id' => 5], ['tipo' => 'order_photo', 'os_id' => 1, 'foto_id' => 6]],
                'equipamento.foto_principal_base64' => ['tipo' => 'equipment_photo', 'equipamento_id' => 9, 'foto_id' => 4],
            ]],
        ];

        $envelope = (new DocumentSnapshotSerializer)->fromEngineContext(
            $context,
            ['codigo' => 'os_laudo_tecnico'],
            $versao,
            ['usuario_id' => 7, 'assinatura_id' => 2, 'hash_sha256' => 'abc', 'signatario_nome' => 'Fulano', 'metodo' => 'sessao'],
            ['perfil' => 'padrao', 'foto_max_dim' => 1400, 'foto_qualidade' => 72],
            ['tipo' => 'company_logo', 'arquivo' => 'logo.png', 'sha256' => 'l'],
            ['path' => 'private/assinaturas/cliente.png']
        );

        $json = DocumentSnapshotSerializer::encode($envelope);
        $this->assertStringNotContainsString('base64,', $json);
        $this->assertSame(1, $envelope['v']);
        $this->assertSame(['id' => 3, 'versao_id' => 17, 'versao' => 2, 'hash_schema' => str_repeat('f', 64)], $envelope['template']);
        $this->assertSame([], $envelope['contexto']['os']['fotos_entrada']);
        $this->assertSame('', $envelope['contexto']['equipamento']['foto_principal_base64']);
        $this->assertSame('', $envelope['contexto']['empresa']['logo_base64']);
        $this->assertSame(['$data' => '2026-09-13T10:22:31-03:00'], $envelope['documento']['gerado_em']);
        $this->assertSame(['$data' => '2026-09-13T10:22:31-03:00'], $envelope['contexto']['os']['abertura']);
        $this->assertSame(2, (int) $envelope['assinaturas']['responsavel']['assinatura_id']);
        $this->assertSame('private/assinaturas/cliente.png', $envelope['assinaturas']['cliente']['arquivo']);
        $this->assertSame('l', $envelope['imagens']['empresa.logo_base64']['sha256']);
        $this->assertCount(2, $envelope['imagens']['os.fotos_entrada']);
        $this->assertArrayNotHasKey('_refs', $envelope['contexto']);
        $this->assertStringContainsString('"total":10.0', $json, 'float preservado como float');

        $decoded = DocumentSnapshotSerializer::decode($json);
        $back = DocumentSnapshotSerializer::untagDates($decoded['documento']);
        $this->assertInstanceOf(Carbon::class, $back['gerado_em']);
        $this->assertSame('2026-09-13T10:22:31-03:00', $back['gerado_em']->toIso8601String());
    }

    public function test_canonical_hash_ignores_key_order_and_render_profile(): void
    {
        $a = ['v' => 1, 'contexto' => ['b' => 2, 'a' => [1, 2]], 'render' => ['perfil' => 'padrao']];
        $b = ['contexto' => ['a' => [1, 2], 'b' => 2], 'v' => 1, 'render' => ['perfil' => 'assinado']];
        $c = ['contexto' => ['a' => [2, 1], 'b' => 2], 'v' => 1];

        $this->assertSame(DocumentSnapshotSerializer::canonicalHash($a), DocumentSnapshotSerializer::canonicalHash($b));
        $this->assertNotSame(DocumentSnapshotSerializer::canonicalHash($a), DocumentSnapshotSerializer::canonicalHash($c), 'listas preservam ordem');
    }

    public function test_encode_tolerates_invalid_utf8_from_legacy_data(): void
    {
        $json = DocumentSnapshotSerializer::encode(['texto' => "Ol\xE1 mundo"]);
        $this->assertStringContainsString('"texto"', $json);
        $this->assertSame("Ol\u{FFFD} mundo", DocumentSnapshotSerializer::decode($json)['texto']);
    }
}
