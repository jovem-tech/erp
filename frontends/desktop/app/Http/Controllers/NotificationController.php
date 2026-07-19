<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
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
        $box = $this->normalizeBox((string) $request->query('box', 'all'));
        $result = $this->notificationService->paginate([
            'page' => $page,
            'per_page' => 20,
            'box' => $box,
        ]);

        return view('notifications.index', [
            'pageTitle' => $box === 'correspondence' ? 'Mensagens e documentos' : 'Notificações',
            'notifications' => $result['items'],
            'pagination' => $result['pagination'],
            'unreadCount' => $result['unread_count'],
            'notificationBox' => $box,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $box = $this->normalizeBox((string) $request->query('box', 'all'));

        return response()->json([
            'status' => 'success',
            'data' => $this->notificationService->summary($box),
            'error' => null,
            'meta' => [],
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

    public function markAllRead(Request $request): RedirectResponse
    {
        $box = $this->normalizeBox((string) $request->input('box', 'all'));
        $this->notificationService->markAllRead($box);

        return redirect()
            ->route('notifications.index', $box === 'all' ? [] : ['box' => $box])
            ->with('success', 'Todas as notificações foram marcadas como lidas.');
    }

    public function clearRead(Request $request): RedirectResponse
    {
        $box = $this->normalizeBox((string) $request->input('box', 'all'));
        $this->notificationService->clearRead($box);

        // back(): o botão vive no dropdown do sino, presente em qualquer
        // página — o usuário deve continuar onde estava após limpar.
        return redirect()
            ->back()
            ->with('success', 'Notificações lidas removidas.');
    }

    private function normalizeBox(string $box): string
    {
        $normalized = strtolower(trim($box));

        return in_array($normalized, ['operational', 'correspondence'], true)
            ? $normalized
            : 'all';
    }
}
