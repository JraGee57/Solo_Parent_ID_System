<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/NotificationService.php';

/**
 * The notifications list for whoever is signed in (applicant, MSWD staff or
 * administrator): all/unread filter, paging, mark one or all as read, and
 * open (marks it read, then follows its link). Only the account's own
 * notifications are ever read or changed.
 */
class NotificationController extends Controller
{
    /**
     * Handles the request for $account on the page at $baseUrl (app-relative).
     * Redirects after every action; otherwise returns the list view's data.
     * $handlePost false: the page's POST belongs to someone else (e.g. the
     * admin announcement form) -- just build the list.
     */
    public function handle(array $account, string $baseUrl, bool $handlePost = true): array
    {
        $service = new NotificationService($this->pdo);
        $userId = (int) $account['id'];
        $sep = str_contains($baseUrl, '?') ? '&' : '?';

        $openId = is_string($_GET['open'] ?? null) ? (int) $_GET['open'] : 0;
        if ($openId > 0) {
            $link = $service->open($userId, $openId, $account['role']);
            $this->redirectTo($link ?? $baseUrl);
        }

        if ($handlePost && $_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
            if ($action === 'mark_read') {
                $service->markRead($userId, is_string($_POST['id'] ?? null) ? (int) $_POST['id'] : 0);
            } elseif ($action === 'mark_all_read') {
                $count = $service->markAllRead($userId);
                flash($count ? "Marked $count notification" . ($count === 1 ? '' : 's') . ' as read.' : 'No unread notifications.');
            }
            $back = $baseUrl . (($_GET['filter'] ?? '') === 'unread' ? $sep . 'filter=unread' : '');
            $this->redirectTo($back);
        }

        $unreadOnly = ($_GET['filter'] ?? '') === 'unread';
        $page = is_string($_GET['page'] ?? null) ? max(1, (int) $_GET['page']) : 1;
        return [
            'notifPage' => $service->page($userId, $page, $unreadOnly),
            'unreadOnly' => $unreadOnly,
            'baseUrl' => $baseUrl,
            'notifRole' => $account['role'],
            'flashMessage' => take_flash(),
        ];
    }
}
