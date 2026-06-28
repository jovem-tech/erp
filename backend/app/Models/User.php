<?php

namespace App\Models;

use App\Notifications\FrontendPasswordResetNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $table = 'usuarios';

    protected $primaryKey = 'id';

    protected $fillable = [
        'nome',
        'email',
        'senha',
        'telefone',
        'perfil',
        'grupo_id',
        'foto',
        'ativo',
        'ultimo_acesso',
        'remember_token_hash',
        'remember_token_expires_at',
    ];

    protected $hidden = [
        'senha',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'grupo_id' => 'integer',
            'ultimo_acesso' => 'datetime',
            'token_expiracao' => 'datetime',
            'remember_token_expires_at' => 'datetime',
        ];
    }

    public function getAuthPassword(): string
    {
        return (string) $this->senha;
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'grupo_id', 'id');
    }

    public function sendPasswordResetNotification($token, ?string $frontend = null): void
    {
        $this->notify(new FrontendPasswordResetNotification((string) $token, $frontend));
    }
}
