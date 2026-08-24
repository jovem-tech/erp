<?php

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Prova, contra MySQL de verdade, que duas gravacoes simultaneas do mesmo Pix
 * produzem UMA liquidacao.
 *
 * Por que fora da suite padrao (grupo `mysql`, excluido no phpunit.xml):
 * a suite roda SQLite em memoria, onde nao existe concorrencia real entre
 * conexoes. Este teste abre DUAS conexoes PDO de verdade.
 *
 * Por que nao cria banco proprio: `erp_app` nao tem permissao para CREATE
 * DATABASE. Ele usa a base de desenvolvimento, mas toca APENAS linhas de
 * `inter_liquidacoes` que ele mesmo cria, com prefixo proprio no e2eid, e
 * limpa no finally — inclusive quando o teste falha.
 *
 * Rodar com:
 *   ./vendor/bin/phpunit --group mysql
 */
#[Group('mysql')]
class InterLiquidacaoConcorrenciaMysqlTest extends TestCase
{
    private const PREFIXO = 'TESTE-CONCORRENCIA-';

    private ?PDO $a = null;
    private ?PDO $b = null;
    private string $e2eid = '';

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
            $this->markTestSkipped('MySQL indisponivel para o teste de concorrencia: '.$e->getMessage());
        }

        $existe = $this->a->query("SHOW TABLES LIKE 'inter_liquidacoes'")->fetch();

        if ($existe === false) {
            $this->markTestSkipped('Tabela inter_liquidacoes ausente. Rode as migrations no banco de desenvolvimento.');
        }

        $this->e2eid = self::PREFIXO.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        // Limpeza incondicional: o teste escreve na base de desenvolvimento e
        // nao pode deixar residuo nem quando falha no meio.
        try {
            $this->a?->exec("DELETE FROM inter_liquidacoes WHERE e2eid LIKE '".self::PREFIXO."%'");
        } catch (PDOException) {
            // Ignorado de proposito: falha na limpeza nao pode mascarar o
            // resultado do teste.
        }

        $this->a = null;
        $this->b = null;

        parent::tearDown();
    }

    public function test_duas_conexoes_simultaneas_gravam_uma_unica_liquidacao(): void
    {
        $cobrancaId = $this->cobrancaDeApoio();

        $inserir = function (PDO $conexao) use ($cobrancaId): bool {
            try {
                $stmt = $conexao->prepare(
                    'INSERT INTO inter_liquidacoes (inter_cobranca_id, e2eid, valor, created_at, updated_at)
                     VALUES (?, ?, ?, NOW(), NOW())'
                );

                return $stmt->execute([$cobrancaId, $this->e2eid, 100.00]);
            } catch (PDOException $e) {
                // 23000 = violacao de integridade. E' exatamente o desfecho
                // esperado para o perdedor da corrida.
                $this->assertSame('23000', $e->getCode(), 'Erro inesperado: '.$e->getMessage());

                return false;
            }
        };

        // As duas conexoes abrem transacao ANTES de qualquer insert, para que a
        // segunda realmente dispute o indice com a primeira.
        $this->a->beginTransaction();
        $this->b->beginTransaction();

        $primeira = $inserir($this->a);
        $this->a->commit();

        $segunda = $inserir($this->b);
        $this->b->commit();

        $this->assertTrue($primeira, 'A primeira gravacao deveria ter sido aceita.');
        $this->assertFalse($segunda, 'A segunda gravacao do MESMO e2eid deveria ter sido recusada.');

        $total = (int) $this->a
            ->query("SELECT COUNT(*) FROM inter_liquidacoes WHERE e2eid = ".$this->a->quote($this->e2eid))
            ->fetchColumn();

        // O numero que importa: um Pix, uma liquidacao. Se este teste cair,
        // duas entregas concorrentes viram duas baixas no financeiro.
        $this->assertSame(1, $total);

        $this->limparCobrancaDeApoio($cobrancaId);
    }

    public function test_e2eids_diferentes_convivem_normalmente(): void
    {
        $cobrancaId = $this->cobrancaDeApoio();

        foreach (['A', 'B'] as $sufixo) {
            $stmt = $this->a->prepare(
                'INSERT INTO inter_liquidacoes (inter_cobranca_id, e2eid, valor, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW())'
            );
            $this->assertTrue($stmt->execute([$cobrancaId, $this->e2eid.$sufixo, 50.00]));
        }

        // Pagamento parcial: dois Pix distintos na mesma cobranca sao legitimos.
        $total = (int) $this->a
            ->query("SELECT COUNT(*) FROM inter_liquidacoes WHERE e2eid LIKE ".$this->a->quote($this->e2eid.'%'))
            ->fetchColumn();

        $this->assertSame(2, $total);

        $this->limparCobrancaDeApoio($cobrancaId);
    }

    private function cobrancaDeApoio(): int
    {
        $txid = 'TESTECONC'.strtoupper(bin2hex(random_bytes(8)));

        $stmt = $this->a->prepare(
            'INSERT INTO inter_cobrancas (provider, txid, valor, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute(['inter', $txid, 100.00, 'ATIVA']);

        return (int) $this->a->lastInsertId();
    }

    private function limparCobrancaDeApoio(int $id): void
    {
        $this->a->prepare('DELETE FROM inter_cobrancas WHERE id = ?')->execute([$id]);
    }

    /** @return array<string, string> */
    private function lerEnvDoBackend(): array
    {
        $caminho = dirname(__DIR__, 2).'/backend/.env';
        $caminho = is_file($caminho) ? $caminho : base_path('.env');

        if (! is_file($caminho)) {
            $this->markTestSkipped('.env do backend nao encontrado.');
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
