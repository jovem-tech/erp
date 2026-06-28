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
        $paginator = $this->inboxService->paginateForUser($user, $onlyUnread, $perPage);

        return $this->success(
            [
                'items' => $paginator->items(),
                'unread_count' => $this->inboxService->unreadCountForUser($user),
                'last_notification_id' => $this->inboxService->lastNotificationIdForUser($user),
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

        return $this->success(
            [
                'notification' => $this->inboxService->map($item),
                'unread_count' => $this->inboxService->unreadCountForUser($user),
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
            $this->inboxService->markAllRead($user),
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
            $this->inboxService->clearRead($user),
            request: $request
        );
    }
}
