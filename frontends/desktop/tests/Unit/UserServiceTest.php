<?php

namespace Tests\Unit;

use App\Services\ApiClient;
use App\Services\UserService;
use Tests\TestCase;

class UserServiceTest extends TestCase
{
    public function test_update_active_uses_api_patch_contract(): void
    {
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient
            ->expects($this->once())
            ->method('patch')
            ->with('/users/2/active', ['active' => false])
            ->willReturn([
                'data' => [
                    'user' => [
                        'id' => 2,
                        'ativo' => false,
                    ],
                ],
            ]);

        $service = new UserService($apiClient);

        $this->assertSame([
            'id' => 2,
            'ativo' => false,
        ], $service->updateActive(2, false));
    }
}
