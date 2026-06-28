<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registry de tipos de canal da Central de Atendimento
    |--------------------------------------------------------------------------
    |
    | Mapeia o channel_type (string curta gravada em caixas_entrada.channel_type) para a
    | classe Eloquent concreta do canal. Usado por App\Support\Channels\ChannelRegistry
    | para resolver Inbox::channel() sem morphTo() sobre nome de classe cru.
    |
    */

    'types' => [
        'whatsapp' => \App\Models\Chat\Channel\Whatsapp::class,
    ],

];
