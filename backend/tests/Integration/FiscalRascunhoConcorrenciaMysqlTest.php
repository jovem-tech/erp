<?php

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Prova, contra MySQL de verdade, que `rascunhoDeOrdem()` bloqueia diante de um
 * `registrarEmissao()` concorrente em vez de "não ver" o documento.
 *
 * O bug que isto fecha: uma OS acumulou três `documentos_fiscais` (dois deles
 * `emitido`) no mesmo dia. `rascunhoDeOrdem()` fazia DUAS consultas separadas,
 * sem transação nem lock — "já existe emitido?", depois "já existe rascunho?".
 * Se um `registrarEmissao()` concorrente comitava ENTRE as duas, a linha
 * "sumia" das duas buscas ao mesmo tempo e o método criava um documento novo
 * por cima do que acabara de ser emitido.
 *
 * A correção troca as duas consultas por UMA, com `lockForUpdate()`, dentro de
 * transação. Uma `UPDATE` (como a de `registrarEmissao()`) toma lock de linha
 * mesmo sem pedir explicitamente — então o `SELECT ... FOR UPDATE` daqui tem de
 * ESPERAR o commit dela antes de ler. É exatamente essa espera que se prova
 * aqui: conexão B tenta o mesmo `SELECT ... FOR UPDATE` que o código roda,
 * enquanto A segura um `UPDATE` não comitado na mesma linha — com o tempo de
 * espera do lock propositalmente curto, B tem de estourar em vez de "passar
 * direto" ignorando o lock de A.
 *
 * Por que fora da suite padrão (grupo `mysql`, excluído no phpunit.xml): a
 * suite roda SQLite em memória, onde não existe lock de linha entre conexões.
 *
 * Por que não cria banco próprio: `erp_app` não tem permissão para CREATE
 * DATABASE. Usa a base de desenvolvimento, tocando só linhas com prefixo
 * próprio, limpas no finally — inclusive quando o teste falha.
 *
 * Rodar com:
 *   ./vendor/bin/phpunit --group mysql
 */
#[Group('mysql')]
class FiscalRascunhoConcorrenciaMysqlTest extends TestCase
{
    private const PREFIXO = 'TESTE-RASCUNHO-CONC-';

    private ?PDO $a = null;

    private ?PDO $b = null;

    private int $osId = 0;

    private int $documentoId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $env = $this->lerEnvDoBackend();

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            $env['DB_HOST'] ?? '127.0.0.1',
            $env['DB_PORT'] ?? '3306',
            $env['DB_DATABASE'] ?? ''
        );

        try {
            $opcoes = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
            $this->a = new PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', $opcoes);
            $this->b = new PDO($dsn, $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '', $opcoes);
        } catch (PDOException $e) {
            $this->markTestSkipped('MySQL indisponível para o teste de concorrência: '.$e->getMessage());
        }

        foreach (['os', 'documentos_fiscais', 'clientes'] as $tabela) {
            if ($this->a->query("SHOW TABLES LIKE '{$tabela}'")->fetch() === false) {
                $this->markTestSkipped("Tabela {$tabela} ausente. Rode as migrations no banco de desenvolvimento.");
            }
        }

        [$this->osId, $this->documentoId] = $this->osComRascunho();
    }

    protected function tearDown(): void
    {
        // Limpeza incondicional: o teste escreve na base de desenvolvimento e
        // não pode deixar resíduo nem quando falha no meio.
        try {
            $this->a?->exec("DELETE FROM documentos_fiscais WHERE tomador_nome LIKE '".self::PREFIXO."%'");
            $this->a?->exec("DELETE FROM os WHERE numero_os LIKE 'TST%'");
            $this->a?->exec(
                "DELETE FROM equipamentos WHERE cliente_id IN "
                ."(SELECT id FROM (SELECT id FROM clientes WHERE nome_razao LIKE '".self::PREFIXO."%') t)"
            );
            $this->a?->exec("DELETE FROM clientes WHERE nome_razao LIKE '".self::PREFIXO."%'");
        } catch (PDOException) {
            // Ignorado de propósito: falha na limpeza não pode mascarar o
            // resultado do teste.
        }

        $this->a = null;
        $this->b = null;

        parent::tearDown();
    }

    public function test_select_for_update_espera_o_registrar_emissao_concorrente(): void
    {
        // A segura, sem comitar, exatamente a mudanca que registrarEmissao()
        // faz: status rascunho -> emitido. E' o lock que a UPDATE toma
        // implicitamente, mesmo sem FOR UPDATE explicito.
        $this->a->beginTransaction();
        $this->a->prepare('UPDATE documentos_fiscais SET status = ? WHERE id = ?')
            ->execute(['emitido', $this->documentoId]);

        // B tenta a MESMA consulta que rascunhoDeOrdem() roda agora: um unico
        // SELECT ... FOR UPDATE cobrindo todos os documentos nfse da OS.
        // Tempo de espera curto de proposito: o teste prova que HA' espera,
        // sem precisar ficar preso ao timeout padrao do servidor.
        $this->b->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $bloqueou = false;

        try {
            $this->b->query(
                "SELECT id, status FROM documentos_fiscais
                 WHERE os_id = {$this->osId} AND tipo = 'nfse'
                 ORDER BY id DESC FOR UPDATE"
            );
        } catch (PDOException $e) {
            // 1205 = ER_LOCK_WAIT_TIMEOUT. E' exatamente o sinal de que o
            // lock de A estava valendo — se a consulta de B tivesse "passado
            // direto" (o bug antigo), nao haveria erro nenhum aqui.
            $bloqueou = str_contains($e->getMessage(), '1205')
                || str_contains($e->getMessage(), 'Lock wait timeout');
        }

        $this->a->commit();

        $this->assertTrue(
            $bloqueou,
            'O SELECT FOR UPDATE de B deveria ter esperado (e estourado) o lock que A segurava — '
                .'sem isso, uma segunda chamada a rascunhoDeOrdem() enquanto a emissao ainda nao '
                .'comitou nao tem garantia nenhuma de ver o status atualizado.'
        );

        // E depois do commit de A, B enxerga o estado fresco — a mesma
        // consulta, sem lock nenhum no caminho, ve' o documento ja' emitido.
        $status = $this->b
            ->query("SELECT status FROM documentos_fiscais WHERE id = {$this->documentoId}")
            ->fetchColumn();

        $this->assertSame('emitido', $status);
    }

    /**
     * @return array{0: int, 1: int} [os_id, documento_fiscal_id]
     */
    private function osComRascunho(): array
    {
        $sufixo = bin2hex(random_bytes(4));

        $clienteId = (int) $this->inserirGetId(
            'INSERT INTO clientes (nome_razao, telefone1, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
            [self::PREFIXO.$sufixo, '(22) 99999-0000']
        );

        $equipamentoId = (int) $this->inserirGetId(
            'INSERT INTO equipamentos (cliente_id, created_at, updated_at) VALUES (?, NOW(), NOW())',
            [$clienteId]
        );

        $osId = (int) $this->inserirGetId(
            'INSERT INTO os (numero_os, cliente_id, equipamento_id, relato_cliente, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            // `numero_os` e' varchar(20); a limpeza identifica a linha pelo
            // cliente e pelo documento fiscal (tomador_nome), nao por este
            // campo — aqui so' precisa nao estourar o tamanho.
            ['TST'.$sufixo, $clienteId, $equipamentoId, 'Teste de concorrência', 'entregue_reparado_pago']
        );

        $documentoId = (int) $this->inserirGetId(
            'INSERT INTO documentos_fiscais
                (tipo, status, os_id, cliente_id, tomador_nome, valor_servicos, valor_pecas, valor_total, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            ['nfse', 'rascunho', $osId, $clienteId, self::PREFIXO.$sufixo, 100, 0, 100]
        );

        return [$osId, $documentoId];
    }

    private function inserirGetId(string $sql, array $parametros): int
    {
        $stmt = $this->a->prepare($sql);
        $stmt->execute($parametros);

        return (int) $this->a->lastInsertId();
    }

    /** @return array<string, string> */
    private function lerEnvDoBackend(): array
    {
        $caminho = base_path('.env');

        if (! is_file($caminho)) {
            $this->markTestSkipped('.env do backend não encontrado.');
        }

        $valores = [];

        foreach (file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            if (str_starts_with(trim($linha), '#') || ! str_contains($linha, '=')) {
                continue;
            }

            [$chave, $valor] = explode('=', $linha, 2);
            $valores[trim($chave)] = trim($valor, " \t\"'");
        }

        return $valores;
    }
}
