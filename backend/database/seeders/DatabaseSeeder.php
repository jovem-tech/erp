<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'nome' => 'Usuário Teste',
            'email' => 'test@example.com',
            'senha' => bcrypt('password'),
            'perfil' => 'admin',
            'ativo' => true,
        ]);
    }
}
