<?php

namespace Tests\Unit\Photos;

use App\Services\Photos\OperationalPhotoFileName;
use Tests\TestCase;

class OperationalPhotoFileNameTest extends TestCase
{
    public function test_builds_equipment_name_with_real_extension_and_sequence(): void
    {
        $this->assertSame(
            'Notebook_DellInspiron15-Joao_Silva_02.avif',
            OperationalPhotoFileName::forEquipment(
                'Notebook',
                'Dell',
                'Inspiron 15',
                'Joao Silva',
                'AVIF',
                2,
            ),
        );
    }

    public function test_builds_order_name_from_order_client_and_photo_type(): void
    {
        $this->assertSame(
            'os_3673_Joao_Silva_recepcao.jpg',
            OperationalPhotoFileName::forOrder('3673', 'Joao Silva', 'recepcao', 'jpg'),
        );
    }

    public function test_removes_path_and_control_characters_from_logical_name(): void
    {
        $name = OperationalPhotoFileName::forOrder(
            '../3673',
            "Joao/../Silva\n",
            'defeito/entrada',
            'webp',
        );

        $this->assertSame('os_3673_Joao_Silva_defeito_entrada.webp', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('\\', $name);
    }
}
