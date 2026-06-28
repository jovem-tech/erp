<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase só migra/transaciona config('database.default') por padrão. A
     * conexão 'chat' (Central de Atendimento, specs/010-inbox-whatsapp-tempo-real)
     * precisa estar aqui — não só nos testes que a usam — porque o flag estático
     * RefreshDatabaseState::$migrated é global para a suíte: se um teste sem 'chat'
     * nesta lista rodar primeiro, ele consome o único migrate:fresh da execução e
     * nenhum teste posterior recebe uma conexão 'chat' com schema correto cacheado.
     */
    protected array $connectionsToTransact = ['sqlite', 'chat'];
}
