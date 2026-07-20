<?php

namespace App\Services\Files\Authorizers;

use App\Contracts\Files\FileAuthorizer;
use App\Models\Files\ManagedFile;
use App\Models\Order;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;

class OrderFileAuthorizer implements FileAuthorizer
{
    public function __construct(private readonly RbacAuthorizationService $rbac) {}

    public function allows(User $actor, ManagedFile $file, string $ability): bool
    {
        $action = in_array($ability, ['metadata', 'download'], true) ? 'visualizar' : 'editar';
        if (! $this->rbac->allows($actor, 'os', $action)) {
            return false;
        }

        $orderIds = $file->links
            ->where('subject_type', 'order')
            ->whereNull('unlinked_at')
            ->pluck('subject_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        if ($orderIds->isEmpty()) {
            return false;
        }

        $orders = Order::query()->whereKey($orderIds)->get(['id', 'tecnico_id']);
        if (mb_strtolower(trim((string) ($actor->perfil ?? ''))) !== 'tecnico') {
            return $orders->isNotEmpty();
        }

        return $orders->contains(
            static fn (Order $order): bool => (int) ($order->tecnico_id ?? 0) === (int) $actor->id
        );
    }
}
