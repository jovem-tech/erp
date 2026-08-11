<?php

namespace Tests\Unit\Services;

use App\Services\Orders\OrderWorkflowService;
use PHPUnit\Framework\TestCase;

/**
 * Mensagem enviada ao cliente quando o status da OS muda.
 *
 * O template é fonte única (CLIENT_STATUS_MESSAGE_TEMPLATE): o desktop o
 * recebe pelo payload da OS e usa para pré-preencher o texto editável do
 * modal "Notificar o cliente". Se a frase for alterada aqui, o frontend
 * acompanha sozinho — por isso ela não pode ser duplicada em JS.
 */
class ClientStatusMessageTest extends TestCase
{
    public function test_builds_the_default_message_with_order_number_and_status(): void
    {
        $this->assertSame(
            'Olá! O status da sua OS OS26070036 foi atualizado para: "Aguardando Reparo".',
            OrderWorkflowService::buildClientStatusMessage('OS26070036', 'Aguardando Reparo')
        );
    }

    public function test_appends_the_observation_when_present(): void
    {
        $this->assertSame(
            'Olá! O status da sua OS OS1 foi atualizado para: "Irreparável". Peça sem reposição no mercado.',
            OrderWorkflowService::buildClientStatusMessage('OS1', 'Irreparável', 'Peça sem reposição no mercado.')
        );
    }

    public function test_ignores_blank_observation(): void
    {
        $this->assertSame(
            'Olá! O status da sua OS OS1 foi atualizado para: "Triagem".',
            OrderWorkflowService::buildClientStatusMessage('OS1', 'Triagem', '   ')
        );
    }

    public function test_template_exposes_both_placeholders_for_the_frontend(): void
    {
        $this->assertStringContainsString('{numero_os}', OrderWorkflowService::CLIENT_STATUS_MESSAGE_TEMPLATE);
        $this->assertStringContainsString('{status}', OrderWorkflowService::CLIENT_STATUS_MESSAGE_TEMPLATE);
    }
}
