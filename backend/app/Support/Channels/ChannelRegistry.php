<?php

namespace App\Support\Channels;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolve o model concreto de um canal (ex.: App\Models\Chat\Channel\Whatsapp) a partir do
 * par (channel_type, channel_id) gravado em Inbox, sem usar morphTo() sobre nome de classe
 * cru. Ver specs/010-inbox-whatsapp-tempo-real/plan.md, "Abstracao de Channel em Laravel".
 */
class ChannelRegistry
{
    public function resolve(string $channelType, int $channelId): ?Model
    {
        $class = $this->classFor($channelType);

        if ($class === null) {
            return null;
        }

        return $class::query()->find($channelId);
    }

    public function classFor(string $channelType): ?string
    {
        $class = config("channels.types.{$channelType}");

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            return null;
        }

        return $class;
    }
}
