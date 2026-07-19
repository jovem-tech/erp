<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends BaseApiController
{
    public function __construct(
        private readonly NotificationInboxService $inboxService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse($request);
        }

        $onlyUnread = filter_var($request->query('only_unread', false), FILTER_VALIDATE_BOOL);
        $perPage = max(1, min(50, (int) $request->query('per_page', 6)));
        $box = $this->inboxService->normalizeBox((string) $request->query('box', 'all'));
        $paginator = $this->inboxService->paginateForUser($user, $onlyUnread, $perPage, $box);

        return $this->success(
            [
                'items' => $paginator->items(),
                'box' => $box,
                'unread_count' => $this->inboxService->unreadCountForUser($user, $box),
                'last_notification_id' => $this->inboxService->lastNotificationIdForUser($user, $box),
            ],
            meta: $this->paginationMeta($paginator),
            request: $request
        );
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse($request);
        }

        $item = $this->inboxService->markAsRead($user, (int) $notification);
        if ($item === null) {
            return $this->error(
                'Notificação não encontrada.',
                404,
                'NOTIFICATION_NOT_FOUND',
                null,
                request: $request
            );
        }

        $mapped = $this->inboxService->map($item);

        return $this->success(
            [
                'notification' => $mapped,
                'unread_count' => $this->inboxService->unreadCountForUser($user, (string) ($mapped['caixa'] ?? 'all')),
            ],
            request: $request
        );
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse($request);
        }

        return $this->success(
            $this->inboxService->markAllRead(
                $user,
                $this->inboxService->normalizeBox((string) $request->query('box', 'all'))
            ),
            request: $request
        );
    }

    public function clearRead(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if (! $user instanceof User) {
            return $this->unauthenticatedResponse($request);
        }

        return $this->success(
            $this->inboxService->clearRead(
                $user,
                $this->inboxService->normalizeBox((string) $request->query('box', 'all'))
            ),
            request: $request
        );
    }
}
