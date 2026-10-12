<?php
/**
 * Server-side SMS service. Every message goes through send(), which:
 *   1. rejects an invalid Philippine mobile number without calling any gateway;
 *   2. logs the attempt in sms_logs as PENDING before anything is sent;
 *   3. hands it to the configured provider (SMS_PROVIDER, see config/app.php);
 *   4. updates the log to SENT or FAILED with the provider's answer.
 * Sending never throws for a gateway problem -- the failure is recorded and an
 * administrator can retry it (up to SMS_MAX_ATTEMPTS attempts in total).
 *
 * Providers (all settings come from the environment / config/local.php, none are hard-coded):
 *   test       test mode (default): logged as SENT with test_mode = 1, never sent anywhere.
 *   semaphore  Semaphore PH HTTP API (SMS_API_URL, SMS_API_KEY, SMS_SENDER_NAME, SMS_TIMEOUT).
 *
 * Message text comes from the admin-editable sms_templates table (see
 * TEMPLATES for the keys, placeholders and the built-in fallback text).
 */
class SMSService
{
    /** key => [label, fallback text]. Placeholders in braces are filled in by the notify* methods. */
    public const TEMPLATES = [
        'submitted' => ['Application Submitted', 'MSWDO Virac: Your Solo Parent ID application {ref} has been received and is Pending Review. We will text you when its status changes.'],
        'status_change' => ['Status Change (approval/rejection/correction/etc.)', 'MSWDO Virac: Application {ref} is now {status}. Note: {remarks}'],
        'interview_scheduled' => ['Interview/Appointment Scheduled', 'Hello {name}, your Solo Parent ID application interview is scheduled on {when}. Location: {location}. Please arrive on time. For inquiries, contact the MSWDO Virac office.'],
        'renewal_submitted' => ['Renewal Submitted', 'MSWDO Virac: Your Solo Parent ID renewal request {ref} has been received and is Pending Review. We will text you when its status changes.'],
        'renewal_reminder' => ['Renewal Reminder (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please visit MSWDO or apply for renewal online soon to avoid interruption.'],
        'renewal_expired' => ['Renewal Reminder after Expiry (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expired on {when}. Please renew online or visit MSWDO to keep your benefits.'],
        'appointment_reminder' => ['Appointment Reminder (auto)', 'MSWDO Virac: Reminder -- your appointment for application {ref} is on {when} at {location}. Please arrive on time and bring a valid ID.'],
        'appointment_cancelled' => ['Appointment Cancelled', 'MSWDO Virac: Your appointment for application {ref} on {when} was cancelled. Reason: {reason}. Please book a new appointment online.'],
        'appointment_missed' => ['Appointment Missed', 'MSWDO Virac: You missed your appointment for application {ref} on {when}. Please log in and book a new appointment.'],
        'document_correction' => ['Document Checklist Needs Correction', 'MSWDO Virac: The requirement "{document}" for application {ref} needs correction. Reason: {remarks}. Please log in and update your Required Documents Checklist.'],
        'password_reset' => ['Password Reset Code', "MSWDO Virac: Your password reset code is {code}. It expires in {minutes} minutes. If you didn't request this, ignore this message."],
        'mobile_verification' => ['Mobile Number Verification Code', 'MSWDO Virac: Your e-SoloParentID verification code is {code}. It expires in {minutes} minutes. Do not share this code with anyone.'],
    ];
    /** Placeholders each template can use (anything else would be sent literally); the first is required. */
    public const PLACEHOLDERS = [
        'submitted' => ['{ref}'],
        'status_change' => ['{ref}', '{status}', '{remarks}'],
        'interview_scheduled' => ['{name}', '{ref}', '{when}', '{location}'],
        'renewal_submitted' => ['{ref}'],
        'renewal_reminder' => ['{ref}', '{when}'],
        'renewal_expired' => ['{ref}', '{when}'],
        'appointment_reminder' => ['{ref}', '{when}', '{location}'],
        'appointment_cancelled' => ['{ref}', '{when}', '{reason}'],
        'appointment_missed' => ['{ref}', '{when}'],
        'document_correction' => ['{ref}', '{document}', '{remarks}'],
        'password_reset' => ['{code}', '{minutes}'],
        'mobile_verification' => ['{code}', '{minutes}'],
    ];
    public const PROVIDERS = ['test', 'semaphore'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** 'test' or 'semaphore' -- an unknown SMS_PROVIDER value is treated as test mode so nothing is sent by accident. */
    public static function provider(): string
    {
        return in_array(SMS_PROVIDER, self::PROVIDERS, true) ? SMS_PROVIDER : 'test';
    }

    public static function testMode(): bool
    {
        return self::provider() === 'test';
    }

    /** A Philippine mobile number in 09XXXXXXXXX form, or null if it isn't one. */
    public static function normalizeNumber(string $mobile): ?string
    {
        $digits = preg_replace('/[\s().-]/', '', $mobile);
        if (preg_match('/^(?:\+?63|0)?(9\d{9})$/', $digits, $m)) {
            return '0' . $m[1];
        }
        return null;
    }

    /**
     * Sends one SMS and logs it. $logMessage replaces the text stored in
     * sms_logs when the real text holds a secret (e.g. a reset code).
     * Returns the log row's ['id', 'status' (SENT|FAILED), 'test_mode', 'error'].
     */
    public function send(?int $applicationId, string $mobile, string $message, ?string $templateKey = null, ?string $logMessage = null): array
    {
        $message = fit_text($message, 500);
        $number = self::normalizeNumber($mobile);
        $provider = self::provider();
        $stmt = $this->pdo->prepare(
            "INSERT INTO sms_logs (application_id, mobile, message, status, test_mode, template_key, provider, provider_status)
             VALUES (?, ?, ?, 'PENDING', ?, ?, ?, 'pending')"
        );
        $stmt->execute([$applicationId, mb_substr($number ?? $mobile, 0, 20), fit_text($logMessage ?? $message, 500), (int) ($provider === 'test'), $templateKey, $provider]);
        $logId = (int) $this->pdo->lastInsertId();

        $result = $number === null
            ? ['ok' => false, 'provider_status' => 'not-sent', 'message_id' => null, 'error' => 'Invalid mobile number.']
            : $this->dispatch($provider, $number, $message);
        return $this->record($logId, $result, $provider === 'test');
    }

    /**
     * Retries a FAILED message (administrators, from the SMS log). Only
     * possible while attempts remain and the message wasn't masked. Returns
     * [error or null, result].
     */
    public function retry(int $logId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sms_logs WHERE id = ?');
        $stmt->execute([$logId]);
        $log = $stmt->fetch();
        if (!$log || $log['status'] !== 'FAILED') {
            return ['Only a failed message can be retried.', null];
        }
        if ((int) $log['attempts'] >= SMS_MAX_ATTEMPTS) {
            return ['This message has already been tried ' . SMS_MAX_ATTEMPTS . ' times.', null];
        }
        if ($log['template_key'] === 'password_reset') {
            return ['Password reset codes cannot be re-sent from the log; the applicant should request a new code.', null];
        }
        if (in_array($log['template_key'], ['interview_scheduled', 'interview_custom', 'id_release_scheduled', 'id_release_custom'], true) && !$this->templateEnabled('interview_scheduled')) {
            return ['Appointment SMS notifications are disabled in Notification Settings.', null];
        }
        $number = self::normalizeNumber($log['mobile']);
        $provider = self::provider();
        $claim = $this->pdo->prepare("UPDATE sms_logs SET status = 'PENDING', attempts = attempts + 1, provider = ?, test_mode = ?, error = NULL WHERE id = ? AND status = 'FAILED' AND attempts < ?");
        $claim->execute([$provider, (int) ($provider === 'test'), $logId, SMS_MAX_ATTEMPTS]);
        if ($claim->rowCount() !== 1) {
            return ['This SMS has already been retried or has reached its retry limit.', null];
        }
        $result = $number === null
            ? ['ok' => false, 'provider_status' => 'not-sent', 'message_id' => null, 'error' => 'Invalid mobile number.']
            : $this->dispatch($provider, $number, $log['message']);
        return [null, $this->record($logId, $result, $provider === 'test')];
    }

    private function record(int $logId, array $result, bool $testMode): array
    {
        $status = $result['ok'] ? 'SENT' : 'FAILED';
        $this->pdo->prepare('UPDATE sms_logs SET status = ?, provider_status = ?, provider_message_id = ?, error = ? WHERE id = ?')
            ->execute([$status, mb_substr($result['provider_status'], 0, 30), $result['message_id'] !== null ? mb_substr((string) $result['message_id'], 0, 100) : null,
                $result['error'] !== null ? mb_substr($result['error'], 0, 255) : null, $logId]);
        return ['id' => $logId, 'status' => $status, 'test_mode' => $testMode, 'error' => $result['error']];
    }

    /** ['ok', 'provider_status', 'message_id', 'error'] from the provider. Never throws. */
    private function dispatch(string $provider, string $number, string $message): array
    {
        if ($provider === 'test') {
            return ['ok' => true, 'provider_status' => 'test-mode', 'message_id' => null, 'error' => null];
        }
        try {
            return $this->sendViaSemaphore($number, $message);
        } catch (Throwable $e) {
            error_log('SMS gateway error: ' . $e->getMessage());
            return ['ok' => false, 'provider_status' => 'error', 'message_id' => null, 'error' => 'Gateway error.'];
        }
    }

    /**
     * Semaphore PH: POST apikey/number/message/sendername. Success is a JSON
     * array of accepted messages (message_id + status); anything else --
     * HTTP error, validation errors, a "Failed" status -- is a failure. The
     * API key is never written to the log.
     */
    private function sendViaSemaphore(string $number, string $message): array
    {
        if (SMS_API_KEY === '') {
            return ['ok' => false, 'provider_status' => 'not-configured', 'message_id' => null, 'error' => 'SMS_API_KEY is not set.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'provider_status' => 'not-configured', 'message_id' => null, 'error' => 'The PHP curl extension is not enabled.'];
        }
        $ch = curl_init(SMS_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, SMS_TIMEOUT),
            CURLOPT_TIMEOUT => SMS_TIMEOUT,
            CURLOPT_POSTFIELDS => http_build_query([
                'apikey' => SMS_API_KEY,
                'number' => $number,
                'message' => $message,
                'sendername' => SMS_SENDER_NAME,
            ]),
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($body === false) {
            return ['ok' => false, 'provider_status' => 'unreachable', 'message_id' => null, 'error' => 'Could not reach the SMS gateway: ' . $curlError];
        }
        $data = json_decode((string) $body, true);
        $first = is_array($data) && array_is_list($data) && isset($data[0]) && is_array($data[0]) ? $data[0] : null;
        if ($httpCode >= 200 && $httpCode < 300 && $first && isset($first['message_id']) && strcasecmp((string) ($first['status'] ?? ''), 'Failed') !== 0) {
            return ['ok' => true, 'provider_status' => strtolower((string) ($first['status'] ?? 'accepted')), 'message_id' => (string) $first['message_id'], 'error' => null];
        }
        return ['ok' => false, 'provider_status' => $first ? strtolower((string) ($first['status'] ?? 'rejected')) : 'http-' . $httpCode, 'message_id' => $first['message_id'] ?? null,
            'error' => 'Gateway rejected the message (HTTP ' . $httpCode . '): ' . self::describeError($data, (string) $body)];
    }

    /** A short, key-free description of a gateway error response. */
    private static function describeError(mixed $data, string $body): string
    {
        if (is_array($data)) {
            $parts = [];
            array_walk_recursive($data, function ($v, $k) use (&$parts) {
                if (is_string($v) && $k !== 'apikey') {
                    $parts[] = (is_string($k) ? $k . ': ' : '') . $v;
                }
            });
            $text = implode('; ', $parts);
        } else {
            $text = trim(strip_tags($body));
        }
        return mb_substr(str_ireplace(SMS_API_KEY !== '' ? SMS_API_KEY : "\0", '[key]', $text !== '' ? $text : 'no details'), 0, 180);
    }

    /** The admin-edited text for $key (or its built-in fallback), with {placeholders} filled in. */
    public function render(string $key, array $placeholders): string
    {
        $stmt = $this->pdo->prepare('SELECT body FROM sms_templates WHERE template_key = ?');
        $stmt->execute([$key]);
        $body = trim((string) $stmt->fetchColumn());
        if ($body === '') {
            $body = self::TEMPLATES[$key][1] ?? '';
        }
        // An optional placeholder left empty (e.g. no staff remarks) drops the sentence it sits in, so the text
        // never reads "Note: none" or "Note: ." -- unless that sentence also carries other details, in which
        // case only the placeholder goes (an administrator may have reworded the template).
        $filled = array_keys(array_filter($placeholders, fn ($value) => $value !== ''));
        foreach ($placeholders as $placeholder => $value) {
            if ($value === '' && strpos($body, $placeholder) !== false) {
                $body = implode(' ', array_filter(preg_split('/(?<=[.!?])\s+/u', $body), function ($sentence) use ($placeholder, $filled) {
                    if (strpos($sentence, $placeholder) === false) {
                        return true;
                    }
                    foreach ($filled as $other) {
                        if (strpos($sentence, $other) !== false) {
                            return true;
                        }
                    }
                    return false;
                }));
            }
        }
        return trim(strtr($body, $placeholders));
    }

    private function sendTemplate(string $key, array $placeholders, ?int $applicationId, string $mobile): array
    {
        if (!$this->templateEnabled($key)) {
            // Switched off in Notification Settings: nothing is sent or logged.
            return ['id' => null, 'status' => 'DISABLED', 'test_mode' => false, 'error' => null];
        }
        return $this->send($applicationId, $applicationId !== null ? $this->recipientFor($applicationId, $mobile) : $mobile, $this->render($key, $placeholders), $key);
    }

    /**
     * Where texts about an application go: the applicant's confirmed account
     * number, so a number re-confirmed on My Profile after submission is
     * used. $fallback (the number on the application) is used only while
     * the account has no confirmed number.
     */
    private function recipientFor(int $applicationId, string $fallback): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.mobile, u.mobile_verified_at FROM applications a JOIN users u ON u.id = a.user_id WHERE a.id = ?'
        );
        $stmt->execute([$applicationId]);
        $account = $stmt->fetch();
        return ($account ? self::confirmedNumber($account['mobile'], $account['mobile_verified_at']) : null) ?? $fallback;
    }

    /**
     * An account's mobile number if it is confirmed, else null. The one rule
     * for "is there a confirmed number" — recipientFor() and the staff
     * screens (views/partials/mobile-numbers.php) both use it.
     */
    public static function confirmedNumber(?string $mobile, ?string $verifiedAt): ?string
    {
        return $mobile !== null && $mobile !== '' && $verifiedAt !== null ? $mobile : null;
    }

    /** False when SMS notifications, or this message, are switched off (Notification Settings). */
    public function templateEnabled(string $key): bool
    {
        if (setting('sms_enabled') !== '1') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT is_enabled FROM sms_templates WHERE template_key = ?');
        $stmt->execute([$key]);
        $enabled = $stmt->fetchColumn();
        return $enabled === false || (int) $enabled === 1;
    }

    public function notifySubmitted(int $applicationId, string $mobile, string $refNumber): array
    {
        return $this->sendTemplate('submitted', ['{ref}' => $refNumber], $applicationId, $mobile);
    }

    public function notifyStatusChange(int $applicationId, string $mobile, string $refNumber, string $status, string $remarks = ''): array
    {
        return $this->sendTemplate('status_change', ['{ref}' => $refNumber, '{status}' => $status, '{remarks}' => $remarks], $applicationId, $mobile);
    }

    public function interviewScheduleMessage(string $applicantName, string $refNumber, string $scheduledAt, string $location): string
    {
        $date = date('F j, Y', strtotime($scheduledAt));
        $time = date('g:i A', strtotime($scheduledAt));
        return 'Hello ' . $applicantName . ', your Solo Parent ID application interview is scheduled on ' . $date . ' at ' . $time
            . '. Location: ' . $location . '. Please arrive on time. For inquiries, contact the MSWDO Virac office.';
    }

    public function notifyInterviewScheduled(int $applicationId, string $mobile, string $refNumber, string $scheduledAt, string $location, string $applicantName = '', ?string $customMessage = null): array
    {
        $custom = $customMessage !== null;
        $message = $custom ? trim($customMessage) : $this->interviewScheduleMessage($applicantName, $refNumber, $scheduledAt, $location);
        $key = $custom ? 'interview_custom' : 'interview_scheduled';
        if (!$this->templateEnabled('interview_scheduled')) {
            $recipient = $this->recipientFor($applicationId, $mobile);
            $stmt = $this->pdo->prepare("INSERT INTO sms_logs (application_id, mobile, message, status, test_mode, template_key, provider, provider_status, error) VALUES (?, ?, ?, 'FAILED', 0, ?, ?, 'disabled', 'Interview SMS notifications are disabled in settings.')");
            $stmt->execute([$applicationId, mb_substr($recipient, 0, 20), fit_text($message, 500), $key, self::provider()]);
            return ['id' => (int) $this->pdo->lastInsertId(), 'status' => 'FAILED', 'test_mode' => false, 'error' => 'Interview SMS notifications are disabled in settings.', 'provider_status' => 'disabled'];
        }
        return $this->send($applicationId, $this->recipientFor($applicationId, $mobile), $message, $key);
    }

    /** Sends the selected custom release message to the applicant's current confirmed number (or application fallback). */
    public function notifyIdReleaseScheduled(int $applicationId, string $mobile, string $message, bool $custom): array
    {
        $key = $custom ? 'id_release_custom' : 'id_release_scheduled';
        if (!$this->templateEnabled('interview_scheduled')) {
            $recipient = $this->recipientFor($applicationId, $mobile);
            $stmt = $this->pdo->prepare("INSERT INTO sms_logs (application_id, mobile, message, status, test_mode, template_key, provider, provider_status, error) VALUES (?, ?, ?, 'FAILED', 0, ?, ?, 'disabled', 'Appointment SMS notifications are disabled in settings.')");
            $stmt->execute([$applicationId, mb_substr($recipient, 0, 20), fit_text($message, 500), $key, self::provider()]);
            return ['id' => (int) $this->pdo->lastInsertId(), 'status' => 'FAILED', 'test_mode' => false, 'error' => 'Appointment SMS notifications are disabled in settings.', 'provider_status' => 'disabled'];
        }
        return $this->send($applicationId, $this->recipientFor($applicationId, $mobile), $message, $key);
    }

    /** The latest interview scheduling notification attempt for an application. */
    public function latestInterviewNotification(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM sms_logs WHERE application_id = ? AND template_key IN ('interview_scheduled','interview_custom') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    public function notifyRenewalSubmitted(int $applicationId, string $mobile, string $refNumber): array
    {
        return $this->sendTemplate('renewal_submitted', ['{ref}' => $refNumber], $applicationId, $mobile);
    }

    public function notifyRenewalReminder(int $applicationId, string $mobile, string $refNumber, string $expiresAt): array
    {
        return $this->sendTemplate($expiresAt < date('Y-m-d') ? 'renewal_expired' : 'renewal_reminder',
            ['{ref}' => $refNumber, '{when}' => date('F j, Y', strtotime($expiresAt))], $applicationId, $mobile);
    }

    public function notifyAppointmentReminder(int $applicationId, string $mobile, string $refNumber, string $scheduledAt, string $location): array
    {
        return $this->sendTemplate('appointment_reminder', ['{ref}' => $refNumber, '{when}' => date('F j, Y g:i A', strtotime($scheduledAt)), '{location}' => $location], $applicationId, $mobile);
    }

    public function notifyAppointmentCancelled(int $applicationId, string $mobile, string $refNumber, string $scheduledAt, string $reason): array
    {
        return $this->sendTemplate('appointment_cancelled', ['{ref}' => $refNumber, '{when}' => date('F j, Y g:i A', strtotime($scheduledAt)), '{reason}' => $reason], $applicationId, $mobile);
    }

    public function notifyAppointmentMissed(int $applicationId, string $mobile, string $refNumber, string $scheduledAt): array
    {
        return $this->sendTemplate('appointment_missed', ['{ref}' => $refNumber, '{when}' => date('F j, Y g:i A', strtotime($scheduledAt))], $applicationId, $mobile);
    }

    /** A document flagged by staff (needs correction or rejected) that the applicant must re-upload. */
    public function notifyDocumentCorrection(int $applicationId, string $mobile, string $refNumber, string $documentLabel, string $remarks): array
    {
        return $this->sendTemplate('document_correction', ['{ref}' => $refNumber, '{document}' => $documentLabel, '{remarks}' => rtrim($remarks, ' .')], $applicationId, $mobile);
    }

    /** The code is sent but never stored: the log keeps the text with the code masked. */
    public function notifyPasswordReset(string $mobile, string $code): array
    {
        $vars = ['{minutes}' => (string) PASSWORD_RESET_CODE_MINUTES];
        return $this->send(null, $mobile, $this->render('password_reset', $vars + ['{code}' => $code]), 'password_reset',
            $this->render('password_reset', $vars + ['{code}' => '******']));
    }

    /** Confirms a mobile number belongs to the account holder. Always sent; the log keeps the text with the code masked. */
    public function notifyMobileVerification(string $mobile, string $code): array
    {
        $vars = ['{minutes}' => (string) MOBILE_CODE_MINUTES];
        return $this->send(null, $mobile, $this->render('mobile_verification', $vars + ['{code}' => $code]), 'mobile_verification',
            $this->render('mobile_verification', $vars + ['{code}' => '******']));
    }
}
