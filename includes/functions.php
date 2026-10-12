<?php

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    // Discard any HTML already buffered for this request (e.g. shared
    // header/nav markup rendered before a tab's controller decided to
    // redirect) so the 3xx response body is clean.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . $path);
    exit;
}

function base_url(string $path = ''): string
{
    // Shared private resources must use the session of the portal that linked to them.
    $endpoint = parse_url($path, PHP_URL_PATH);
    if (defined('AUTH_PORTAL') && in_array(AUTH_PORTAL, ['admin', 'staff'], true)
        && in_array($endpoint, ['mswd-login.php', 'forgot-password.php', 'reset-password.php', 'slip.php', 'download.php', 'avatar.php'], true)) {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $parameters);
        if (!isset($parameters['portal'])) {
            $fragment = strpos($path, '#');
            $suffix = $fragment === false ? '' : substr($path, $fragment);
            $path = $fragment === false ? $path : substr($path, 0, $fragment);
            $path .= (str_contains($path, '?') ? '&' : '?') . 'portal=' . AUTH_PORTAL . $suffix;
        }
    }
    return BASE_URL . '/' . ltrim($path, '/');
}

/** One-time message shown on the next page view (e.g. after a redirect). */
function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function take_flash(): ?string
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

/** Where a staff member should open an application: the MSWD processing page, or the admin's read-only monitoring view. */
function application_url(array $staff, int $applicationId): string
{
    return base_url((can($staff, 'applications.process') ? 'staff/application.php?id=' : 'admin/application_view.php?id=') . $applicationId);
}

// Shared management pages have separate entry points for each role.
function role_portal_path(array $account, string $page): string
{
    return ($account['role'] === 'admin' ? 'admin/' : 'staff/') . $page;
}

function asset(string $path): string
{
    return base_url('assets/' . ltrim($path, '/'));
}

/** URL for a file under public/ (CSS/JS), cache-busted by its mtime. */
function public_asset(string $path): string
{
    $path = ltrim($path, '/');
    return base_url('public/' . $path) . '?v=' . (@filemtime(APP_ROOT . '/public/' . $path) ?: 1);
}

/**
 * Clamps a requested page into range. Returns ['total', 'page', 'totalPages',
 * 'perPage', 'offset'] -- the shape views/partials/pagination.php expects.
 */
function paginate(int $total, int $page, int $perPage): array
{
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $totalPages);
    return ['total' => $total, 'page' => $page, 'totalPages' => $totalPages, 'perPage' => $perPage, 'offset' => ($page - 1) * $perPage];
}

/** Current query string with $overrides merged in -- for pagination/filter links that preserve the rest of the filters. */
function query_with(array $overrides): string
{
    return '?' . http_build_query(array_merge($_GET, $overrides));
}

function today_iso(): string
{
    return date('Y-m-d');
}

function format_date_long(?string $ymd): string
{
    if (!$ymd) {
        return '—';
    }
    $ts = strtotime($ymd);
    return $ts ? date('F j, Y', $ts) : '—';
}

function is_ymd(string $date): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

/** A real calendar date in Y-m-d form between 1900-01-01 and today (rejects e.g. 2026-02-30). */
function is_past_date(string $date): bool
{
    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date && $date >= '1900-01-01' && $date <= today_iso();
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function normalize_mobile(string $mobile): string
{
    return preg_replace('/[\s\-]/', '', $mobile);
}

/**
 * The ways one Philippine mobile number may be stored (09XXXXXXXXX or
 * +639XXXXXXXXX), so lookups and duplicate checks match either form.
 */
function mobile_variants(string $mobile): array
{
    $m = normalize_mobile($mobile);
    if (preg_match('/^(?:\+63|0)(9\d{9})$/', $m, $x)) {
        return ['0' . $x[1], '+63' . $x[1]];
    }
    return [$m, $m];
}

function is_valid_ph_mobile(string $mobile): bool
{
    return (bool) preg_match('/^(09\d{9}|\+639\d{9})$/', normalize_mobile($mobile));
}

function mask_name(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName));
    $out = [];
    foreach ($parts as $i => $p) {
        if ($p === '') {
            continue;
        }
        $out[] = $i === 0 ? $p : mb_substr($p, 0, 1) . '.';
    }
    return implode(' ', $out);
}

function status_style(string $status): array
{
    $map = [
        'Pending Review'      => ['bg' => '#fdf1d6', 'color' => '#8a6d1a'],
        'Under Verification'  => ['bg' => '#dce8f5', 'color' => '#1b3a6b'],
        'For Revision'        => ['bg' => '#fde8e8', 'color' => '#a51c1c'],
        'Interview Scheduled' => ['bg' => '#f0eafe', 'color' => '#5b3a9e'],
        'Approved'            => ['bg' => '#dcf3e2', 'color' => '#1c7a3f'],
        'ID Released'         => ['bg' => '#1b3a6b', 'color' => '#ffffff'],
        'Rejected'            => ['bg' => '#fde8e8', 'color' => '#7f1616'],
    ];
    return $map[$status] ?? $map['Pending Review'];
}

/** $text shortened to at most $max characters (ending in an ellipsis when cut), for fixed-size columns. */
function fit_text(string $text, int $max): string
{
    return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)) . '…';
}

/** Active FAQ entries for the applicant-facing Chat Support page, in display order. */
function get_faqs(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM faqs WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
}

/** Admin-configurable runtime setting, falling back to $default if unset. */
function get_setting(PDO $pdo, string $key, string $default): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false && $value !== '' ? (string) $value : $default;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
    $GLOBALS['__settings_cache'] = null;
}

/** The registry entry (config/settings.php) for a setting key, or null. */
function setting_definition(string $key): ?array
{
    foreach (SETTINGS_REGISTRY as $section) {
        if (isset($section['keys'][$key])) {
            return $section['keys'][$key];
        }
    }
    return null;
}

/**
 * A system setting: the saved value, else its default from
 * config/settings.php. All settings are read in one query per request.
 */
function setting(string $key): string
{
    $cache = &$GLOBALS['__settings_cache'];
    if (!is_array($cache)) {
        $cache = [];
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            foreach ($GLOBALS['pdo']->query('SELECT setting_key, setting_value FROM system_settings') as $row) {
                $cache[$row['setting_key']] = (string) $row['setting_value'];
            }
        }
    }
    return $cache[$key] ?? (string) (setting_definition($key)['default'] ?? '');
}

/** A list setting as an array of its values (e.g. ['jpg', 'pdf']). */
function setting_list(string $key): array
{
    return array_values(array_filter(explode(',', setting($key)), fn($v) => $v !== ''));
}

/**
 * What may be uploaded as an application document right now (Document
 * Limits settings): types, extensions, MIME types, max size, and text/accept
 * strings for forms.
 */
function document_rules(): array
{
    $byType = ['jpg' => [['jpg', 'jpeg'], 'image/jpeg', 'JPG'], 'png' => [['png'], 'image/png', 'PNG'], 'pdf' => [['pdf'], 'application/pdf', 'PDF']];
    $types = array_values(array_intersect(array_keys($byType), setting_list('document_allowed_types'))) ?: array_keys($byType);
    $exts = $mimes = $labels = [];
    foreach ($types as $t) {
        array_push($exts, ...$byType[$t][0]);
        $mimes[] = $byType[$t][1];
        $labels[] = $byType[$t][2];
    }
    $maxMb = (float) setting('document_max_size_mb');
    $last = array_pop($labels);
    return [
        'types' => $types,
        'exts' => $exts,
        'mimes' => $mimes,
        'max_mb' => $maxMb > 0 ? $maxMb : 5.0,
        'label' => $labels ? implode(', ', $labels) . ' or ' . $last : $last,
        'accept' => implode(',', array_merge(array_map(fn($e) => '.' . $e, $exts), $mimes)),
    ];
}

/** "5 MB", "2.5 MB". */
function format_mb(float $mb): string
{
    return rtrim(rtrim(number_format($mb, 1, '.', ''), '0'), '.') . ' MB';
}

/**
 * One-line, already-escaped HTML describing an application row's appointment
 * (interview_at / interview_location / interview_status), worded by status.
 */
function appointment_summary_html(array $app): string
{
    $when = '<b>' . e(date('F j, Y g:i A', strtotime($app['interview_at']))) . '</b>';
    $where = e($app['interview_location']);
    switch ($app['interview_status'] ?? 'BOOKED') {
        case 'COMPLETED':
            return '✅ Interview completed on ' . $when . '.';
        case 'MISSED':
            return '⚠️ You missed your appointment on ' . $when . '. <a href="' . base_url('applicant/dashboard.php?section=appointment') . '">Book a new one</a>.';
        default:
            return '📅 Interview scheduled for ' . $when . ' at ' . $where . '.';
    }
}

/** Display label for a document status code (see Document::STATUSES). */
function document_status_label(string $status): string
{
    $labels = [
        'UPLOADED' => 'Uploaded', 'VALIDATING' => 'Validating', 'VALID' => 'Valid', 'INVALID' => 'Invalid',
        'FOR_VERIFICATION' => 'For Verification', 'VERIFIED' => 'Verified',
        'NEEDS_CORRECTION' => 'Needs Correction', 'REJECTED' => 'Rejected',
    ];
    return $labels[$status] ?? $status;
}

function document_status_style(string $status): array
{
    $map = [
        'VALID'            => ['bg' => '#dce8f5', 'color' => '#1b3a6b'],
        'FOR_VERIFICATION' => ['bg' => '#fdf1d6', 'color' => '#8a6d1a'],
        'VERIFIED'         => ['bg' => '#dcf3e2', 'color' => '#1c7a3f'],
        'NEEDS_CORRECTION' => ['bg' => '#fde8e8', 'color' => '#a51c1c'],
        'INVALID'          => ['bg' => '#fde8e8', 'color' => '#a51c1c'],
        'REJECTED'         => ['bg' => '#fde8e8', 'color' => '#7f1616'],
    ];
    return $map[$status] ?? $map['FOR_VERIFICATION'];
}

/** Status pill for a document status code. */
function document_status_pill(string $status, string $extraStyle = ''): string
{
    $st = document_status_style($status);
    return '<span class="status-pill" style="background:' . e($st['bg']) . ';color:' . e($st['color']) . ';' . e($extraStyle) . '">' . e(document_status_label($status)) . '</span>';
}

function generate_reference_no(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE reference_no LIKE ?");
    $stmt->execute(["VIRAC-SP-$year-%"]);
    $count = (int) $stmt->fetchColumn();
    do {
        $count++;
        $ref = sprintf('VIRAC-SP-%s-%04d', $year, $count);
        $check = $pdo->prepare('SELECT id FROM applications WHERE reference_no = ?');
        $check->execute([$ref]);
    } while ($check->fetch());
    return $ref;
}

/** Fetch all categories ordered for display. */
function get_categories(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
}

/** Standard (category_id IS NULL) + category-specific document requirements, in order. */
function get_document_requirements(PDO $pdo, ?int $categoryId): array
{
    if ($categoryId !== null) {
        $active = $pdo->prepare('SELECT is_active FROM categories WHERE id = ?');
        $active->execute([$categoryId]);
        if ((int) $active->fetchColumn() === 1) {
            return get_category_only_requirements($pdo, $categoryId);
        }
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM document_requirements
         WHERE category_id IS NULL OR category_id = ?
         ORDER BY (category_id IS NULL) DESC, sort_order, id'
    );
    $stmt->execute([$categoryId]);
    return $stmt->fetchAll();
}

function get_category_only_requirements(PDO $pdo, ?int $categoryId): array
{
    if (!$categoryId) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM document_requirements WHERE category_id = ? ORDER BY sort_order, id');
    $stmt->execute([$categoryId]);
    return $stmt->fetchAll();
}

/** Session-tracked read state for the News & Announcements tab (works for logged-in and anonymous visitors alike). */
function is_announcement_unread(int $id): bool
{
    return empty($_SESSION['read_announcements'][$id]);
}

function mark_announcement_read(int $id): void
{
    $_SESSION['read_announcements'][$id] = true;
}

function count_unread_announcements(array $announcements): int
{
    $count = 0;
    foreach ($announcements as $a) {
        if (is_announcement_unread($a['id'])) {
            $count++;
        }
    }
    return $count;
}

/**
 * Records an in-app notification for one applicant. Independent of
 * SMSService/sms_logs -- a notification can exist with no SMS behind it
 * (e.g. registration, which has no application yet for sms_logs to key
 * off), and every caller that also sends an SMS calls both explicitly.
 */
/** Records an in-app notification; $link is an in-app path it opens (see NotificationService::safeLink). Returns the new id. */
function create_notification(PDO $pdo, int $userId, string $type, string $message, ?int $applicationId = null, ?string $link = null): int
{
    $message = fit_text($message, 500); // column size; callers may include free-text staff remarks
    $pdo->prepare(
        'INSERT INTO notifications (user_id, type, message, related_application_id, link) VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, $type, $message, $applicationId, $link !== null ? mb_substr($link, 0, 255) : null]);
    return (int) $pdo->lastInsertId();
}

function count_unread_notifications(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * The header notification bell for whoever is signed in: ['url', 'count'],
 * or null when nobody is. Uses the app's global connection because the
 * header is rendered inside controllers' view scope.
 */
function nav_notifications(?array $user, ?array $staff): ?array
{
    $pdo = $GLOBALS['pdo'] ?? null;
    $account = $staff ?: $user;
    if (!$pdo instanceof PDO || !$account) {
        return null;
    }
    $url = $staff
        ? base_url($staff['role'] === 'admin' ? 'admin/notifications.php' : 'staff/notifications.php')
        : base_url('applicant/dashboard.php?section=notifications');
    return ['url' => $url, 'count' => count_unread_notifications($pdo, (int) $account['id'])];
}

/**
 * Records a security/workflow-relevant action. actor_name/actor_role are
 * snapshotted rather than resolved via a live join, so the log stays
 * accurate even if that account's name/role changes or the account is
 * later removed (user_id is nullable and ON DELETE SET NULL for that reason).
 */
function audit_log(
    PDO $pdo,
    ?int $userId,
    string $actorName,
    string $actorRole,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $details = null
): void {
    $details = $details !== null ? fit_text($details, 500) : null;
    $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, actor_name, actor_role, action, entity_type, entity_id, details, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $actorName, $actorRole, $action, $entityType, $entityId, $details, $_SERVER['REMOTE_ADDR'] ?? null, $userAgent]);
}

/** Fixed applicant destinations; arbitrary URLs are never accepted. */
function applicant_return_path($key): ?string
{
    return is_string($key) ? ([
        'apply' => 'applicant/dashboard.php?section=apply',
        'applications' => 'applicant/dashboard.php?section=application',
        'track' => 'portal.php?tab=status',
    ][$key] ?? null) : null;
}

function take_applicant_return_path(): string
{
    $path = applicant_return_path($_SESSION['applicant_return'] ?? null);
    unset($_SESSION['applicant_return']);
    return $path ?? 'applicant/dashboard.php';
}
