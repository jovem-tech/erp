<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends DesktopController
{
    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    public function index(Request $request): View
    {
        $page = max(1, (int) $request->query('page', 1));
        $result = $this->notificationService->paginate([
            'page' => $page,
            'per_page' => 20,
        ]);

        return view('notifications.index', [
            'pageTitle' => 'Notificações',
            'notifications' => $result['items'],
            'pagination' => $result['pagination'],
            'unreadCount' => $result['unread_count'],
        ]);
    }

    public function open(string $notification): RedirectResponse
    {
        $result = $this->notificationService->open($notification);
        $item = $result['notification'] ?? [];
        $destination = trim((string) ($item['url'] ?? ''));

        if ($destination === '') {
            $destination = route('notifications.index');
        }

        return redirect($destination);
    }

    public function markAllRead(): RedirectResponse
    {
        $this->notificationService->markAllRead();

        return redirect()
            ->route('notifications.index')
            ->with('success', 'Todas as notificações foram marcadas como lidas.');
    }
}
