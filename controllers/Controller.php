<?php
/**
 * Thin base for every Controller. Deliberately does not reimplement
 * redirect()/base_url()/e() -- those already exist in
 * includes/functions.php and are used by every legacy page too, so
 * Controllers reuse the exact same helpers rather than diverging.
 */
abstract class Controller
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    protected function redirectTo(string $path): void
    {
        redirect(base_url($path));
    }

    /**
     * Stops the request unless $account's role has $permission (see
     * config/permissions.php). Every denial is audit-logged. If the role
     * has its own equivalent page, pass it as $redirectIfDenied to send
     * the user there; otherwise they get a 403 page.
     */
    protected function authorize(array $account, string $permission, ?string $redirectIfDenied = null): void
    {
        if (can($account, $permission)) {
            return;
        }
        audit_log(
            $this->pdo, (int) $account['id'], $account['full_name'], $account['role'],
            'Access Denied', 'permission', null, $permission . ' — ' . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? '')
        );
        if ($redirectIfDenied !== null) {
            $this->redirectTo($redirectIfDenied);
        }
        render_error_page(403);
    }

    /**
     * After an administrator reset their access, an account must choose a
     * new password before using anything else: every other page sends them
     * to $changePage (which itself is allowed through).
     */
    protected function requireOwnPassword(array $account, string $changePage, bool $onChangePage): void
    {
        if (!empty($account['must_change_password']) && !$onChangePage) {
            flash('Your password was reset by an administrator. Please choose a new password to continue.');
            $this->redirectTo($changePage);
        }
    }

    /**
     * Renders a view file with $data extracted into local variables.
     * Views are plain PHP templates (same convention as the rest of the
     * app), not a separate template language.
     */
    protected function render(string $viewPath, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require APP_ROOT . '/' . ltrim($viewPath, '/');
    }
}
