<?php

namespace App\Models\Chat\Channel;

use App\Models\Chat\Account;
use App\Services\Channels\Whatsapp\WhatsappChannelDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Whatsapp extends Model
{
    protected $connection = 'chat';

    protected $table = 'canais_whatsapp';

    protected $guarded = [];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'conta_id', 'id');
    }

    /**
     * Credenciais (URL/apikey/instancia da Evolution API) NAO ficam aqui — sao lidas em
     * tempo de execucao de App\Services\Integrations\IntegrationSettingsService, que ja
     * gerencia a integracao Evolution existente na tela de Configuracoes (ver
     * specs/010-inbox-whatsapp-tempo-real/plan.md, "Phase 0 - Research Decisions").
     */
    public function driver(): WhatsappChannelDriver
    {
        return app(WhatsappChannelDriver::class);
    }
}
