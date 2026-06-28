<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'senha' => Hash::make('password'),
            'telefone' => fake()->phoneNumber(),
            'perfil' => fake()->randomElement(['admin', 'tecnico', 'atendente']),
            'grupo_id' => null,
            'foto' => null,
            'ativo' => true,
            'ultimo_acesso' => null,
            'token_recuperacao' => null,
            'token_expiracao' => null,
            'remember_token_hash' => null,
            'remember_token_expires_at' => null,
        ];
    }
}
