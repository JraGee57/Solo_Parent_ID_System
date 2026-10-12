<?php
require_once __DIR__ . '/../models/ChatMessage.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/ApplicationDraft.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/ApplicationTrackerService.php';
require_once __DIR__ . '/AppointmentService.php';
require_once __DIR__ . '/RenewalService.php';
require_once __DIR__ . '/NotificationService.php';

/**
 * Chat Support / Help Assistant for applicants.
 *
 * A rule-based assistant (no external AI service; nothing leaves the
 * server). It recognises what the applicant is asking about -- status,
 * documents, appointments, renewal, applying, the QR slip / ID, where
 * things are in the portal, the office -- and answers from the applicant's
 * own records and the FAQ list, with links to the right page.
 *
 * It never decides anything: questions about eligibility, approval or
 * rejection get the same answer every time -- only MSWD staff make those
 * decisions -- plus what is recorded so far and the preliminary
 * (non-binding) eligibility checker. When the assistant can't help, the
 * applicant can ask MSWDO staff; social workers reply in the same thread
 * from staff/chat.php.
 */
class HelpAssistantService
{
    public const MAX_LENGTH = 500;
    public const RATE_LIMIT = 30;       // messages ...
    public const RATE_WINDOW = 300;     // ... per 5 minutes, per applicant
    public const DISCLAIMER = 'Official decisions on eligibility and approval are made only by MSWD staff after reviewing your application. This assistant gives guidance only; it cannot approve, reject or guarantee anything.';

    /** Link keys the assistant may attach => [label, in-app path]. Replies store keys only, never URLs. */
    public const LINKS = [
        'status' => ['My Application & tracker', 'applicant/dashboard.php?section=application'],
        'documents' => ['Required Documents Checklist', 'applicant/dashboard.php?section=documents'],
        'appointment' => ['Appointment', 'applicant/dashboard.php?section=appointment'],
        'renewal' => ['ID Renewal', 'applicant/dashboard.php?section=renewal'],
        'myid' => ['My Solo Parent ID', 'applicant/dashboard.php?section=myid'],
        'apply' => ['Apply', 'applicant/dashboard.php?section=apply'],
        'profile' => ['My Profile', 'applicant/dashboard.php?section=profile'],
        'notifications' => ['Notifications', 'applicant/dashboard.php?section=notifications'],
        'news' => ['News & Announcements', 'applicant/dashboard.php?section=news'],
    ];

    /** Quick questions shown as buttons. */
    public const SUGGESTIONS = [
        'status' => 'Where is my application?',
        'documents' => 'What documents do I need?',
        'appointment' => 'How do I book an appointment?',
        'renewal' => 'How do I renew my ID?',
        'myid' => 'I lost my QR slip',
        'decision' => 'Am I eligible?',
        'application' => 'How do I apply?',
        'navigation' => 'How do I use this portal?',
    ];

    /**
     * Topic => keywords/phrases (English and common Filipino). Matched on
     * whole words; a multi-word phrase counts double. The topic with the
     * most hits wins; ties go to the earlier topic.
     */
    private const TOPICS = [
        'criteria' => ['who can apply', 'who may apply', 'sino ang pwede', 'sino ang puwede', 'categories', 'category', 'what is a solo parent', 'definition'],
        'decision' => ['eligible', 'eligibility', 'qualify', 'qualified', 'qualifies', 'approve', 'approved', 'approval', 'reject', 'rejected',
            'deny', 'denied', 'decide', 'decision', 'entitled', 'aprobado', 'karapat', 'karapat-dapat', 'pasado', 'pwede ba ako', 'will i get', 'guarantee'],
        'handoff' => ['staff', 'human', 'person', 'social worker', 'agent', 'kausap', 'talk to', 'speak to', 'someone'],
        'status' => ['status', 'track', 'tracker', 'progress', 'update', 'estado', 'nasaan', 'where is my application', 'reference', 'pending'],
        'documents' => ['document', 'documents', 'requirement', 'requirements', 'upload', 'reupload', 're-upload', 'file', 'files', 'pdf', 'photo', 'papers',
            'dokumento', 'papeles', 'correction', 'corrections', 'certificate', 'certification', 'attach'],
        'appointment' => ['appointment', 'appointments', 'interview', 'schedule', 'reschedule', 'book', 'booking', 'slot', 'slots', 'iskedyul', 'petsa'],
        'renewal' => ['renew', 'renewal', 'renewing', 'i-renew', 'expire', 'expired', 'expiry', 'expiration', 'validity', 'valid until'],
        'application' => ['apply', 'applying', 'application form', 'new application', 'draft', 'submit', 'mag-apply', 'register', 'how to apply', 'start'],
        'myid' => ['qr', 'slip', 'id card', 'lost', 'nawala', 'print', 'claim', 'release', 'released', 'digital id'],
        'navigation' => ['password', 'profile', 'notification', 'notifications', 'logout', 'log out', 'mobile number', 'email', 'address', 'navigate', 'menu', 'page', 'portal', 'saan', 'use this'],
        'office' => ['office', 'hours', 'open', 'location', 'contact', 'phone', 'opisina', 'visit', 'mswdo'],
        'thanks' => ['thanks', 'thank you', 'salamat', 'ty'],
        'greeting' => ['hi', 'hello', 'hey', 'help', 'kumusta', 'kamusta', 'good morning', 'good afternoon', 'good evening', 'magandang', 'start over'],
    ];

    private const STOPWORDS = ['what', 'when', 'where', 'which', 'with', 'this', 'that', 'have', 'does', 'from', 'your', 'about', 'there', 'they', 'will', 'would', 'should', 'could', 'paano', 'ano', 'para'];

    private PDO $pdo;
    private ChatMessage $messages;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->messages = new ChatMessage($pdo);
    }

    // ---- Conversation ----

    /** The applicant's conversation; starts with the assistant's welcome if it is empty. */
    public function thread(array $user): array
    {
        $thread = $this->messages->thread((int) $user['id']);
        if (!$thread) {
            $welcome = $this->welcome($user);
            $this->messages->add((int) $user['id'], 'ASSISTANT', $welcome['text'], 'greeting', $welcome['meta']);
            $thread = $this->messages->thread((int) $user['id']);
        }
        return $thread;
    }

    public function awaitingStaff(array $user): bool
    {
        return $this->messages->awaitingStaff((int) $user['id']);
    }

    /** Checks a message the applicant typed. Returns [error or null, cleaned text]. */
    public function validate(array $user, string $text, bool $allowEmpty = false): array
    {
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '');
        if ($text === '' && !$allowEmpty) {
            return ['Please type a question.', ''];
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            return ['Please keep your message to ' . self::MAX_LENGTH . ' characters or fewer.', $text];
        }
        if ($this->messages->sentSince((int) $user['id'], self::RATE_WINDOW) >= self::RATE_LIMIT) {
            return ['You are sending messages too quickly. Please wait a few minutes and try again.', $text];
        }
        return [null, $text];
    }

    /** The applicant asks the assistant. Stores the question and the answer. Returns an error or null. */
    public function ask(array $user, array $applications, string $text): ?string
    {
        [$error, $text] = $this->validate($user, $text);
        if ($error) {
            return $error;
        }
        $reply = $this->answer($user, $applications, $text);
        $this->messages->add((int) $user['id'], 'APPLICANT', $text, $reply['topic']);
        $this->messages->add((int) $user['id'], 'ASSISTANT', $reply['text'], $reply['topic'], $reply['meta']);
        return null;
    }

    /**
     * The applicant asks for MSWDO staff. Their message (optional) is kept
     * with the request; social workers are notified once per open request.
     */
    public function requestStaff(array $user, string $text): ?string
    {
        [$error, $text] = $this->validate($user, $text, true);
        if ($error) {
            return $error;
        }
        $userId = (int) $user['id'];
        $alreadyWaiting = $this->messages->awaitingStaff($userId);
        $this->messages->add($userId, 'APPLICANT', $text !== '' ? $text : 'I would like to talk to MSWDO staff.', 'handoff', null, true);
        $this->messages->add($userId, 'ASSISTANT',
            ($alreadyWaiting ? 'I added this to your request. ' : 'I have passed your request to MSWDO staff. ')
            . 'A social worker will reply here during office hours (' . setting('office_hours') . '), and you will get a notification when they do. '
            . 'You can keep asking me questions in the meantime.',
            'handoff', ['links' => ['notifications']]);
        if (!$alreadyWaiting) {
            (new NotificationService($this->pdo))->notifyRoles(['mswd_social_worker'], 'chat_request',
                fit_text($user['full_name'] . ' asked for help in Chat Support' . ($text !== '' ? ': "' . $text . '"' : '.'), 300),
                null, 'staff/chat.php?user=' . $userId);
        }
        return null;
    }

    // ---- Staff side ----

    public function inbox(): array
    {
        return $this->messages->staffInbox();
    }

    public function waitingCount(): int
    {
        return $this->messages->waitingCount();
    }

    /** Staff may only open conversations in which the applicant asked for staff. */
    public function staffThread(int $applicantId): ?array
    {
        return $this->messages->hasHandoff($applicantId) ? $this->messages->thread($applicantId, 200) : null;
    }

    /** A social worker's reply. Returns an error or null. */
    public function staffReply(array $staff, array $applicant, string $text): ?string
    {
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '');
        if ($text === '') {
            return 'Please type a reply.';
        }
        if (mb_strlen($text) > 1000) {
            return 'Please keep the reply to 1000 characters or fewer.';
        }
        $applicantId = (int) $applicant['id'];
        if (!$this->messages->hasHandoff($applicantId)) {
            return 'This applicant has not asked for staff in Chat Support.';
        }
        $messageId = $this->messages->add($applicantId, 'STAFF', $text, 'handoff', null, false, (int) $staff['id']);
        (new NotificationService($this->pdo))->notify($applicantId, 'chat_reply',
            fit_text('MSWDO staff replied in Chat Support: "' . $text . '"', 300), null, 'applicant/dashboard.php?section=chat');
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Chat Reply Sent', 'user', $applicantId, 'Chat message #' . $messageId);
        return null;
    }

    // ---- Answers ----

    /** Link key (or "slip:<ref>") => [label, url], or null if it isn't an allowed link. */
    public static function resolveLink(string $key): ?array
    {
        if (isset(self::LINKS[$key])) {
            return [self::LINKS[$key][0], base_url(self::LINKS[$key][1])];
        }
        if (preg_match('/^slip:([A-Za-z0-9-]{1,40})$/', $key, $m)) {
            return ['Application slip (QR)', base_url('slip.php?ref=' . urlencode($m[1]))];
        }
        return null;
    }

    /** Which topic a message is about: a key of TOPICS, or null. */
    public function topicOf(string $text): ?string
    {
        $haystack = ' ' . preg_replace('/[^\p{L}\p{N}\-]+/u', ' ', mb_strtolower($text)) . ' ';
        $best = null;
        $bestScore = 0;
        foreach (self::TOPICS as $topic => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, ' ' . $keyword . ' ')) {
                    $score += str_contains($keyword, ' ') ? 2 : 1;
                }
            }
            // Eligibility/approval questions always get the guardrail answer (general "who can apply" questions get the criteria).
            if ($topic === 'criteria' && $score > 0) {
                return 'criteria';
            }
            if ($topic === 'decision' && $score > 0) {
                return 'decision';
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$topic, $score];
            }
        }
        return $best;
    }

    /** ['text' => ..., 'topic' => ..., 'meta' => ['links' => [...], 'suggestions' => [...]]] */
    public function answer(array $user, array $applications, string $text): array
    {
        $topic = $this->topicOf($text);
        // A greeting or an unrecognised question may still be answered by an FAQ.
        if (in_array($topic, [null, 'greeting'], true) && ($faq = $this->matchFaq($text))) {
            return $this->reply('faq', $faq['answer'], [], ['status', 'documents', 'appointment']);
        }
        switch ($topic) {
            case 'criteria': return $this->criteriaAnswer();
            case 'decision': return $this->decisionAnswer($applications);
            case 'status': return $this->statusAnswer($user, $applications);
            case 'documents': return $this->documentsAnswer($user, $applications);
            case 'appointment': return $this->appointmentAnswer($applications);
            case 'renewal': return $this->renewalAnswer($user);
            case 'application': return $this->applicationAnswer($user, $applications);
            case 'myid': return $this->myIdAnswer($applications);
            case 'navigation': return $this->navigationAnswer($text);
            case 'office': return $this->officeAnswer();
            case 'handoff':
                return $this->reply('handoff', 'To talk to a person, use "Ask MSWDO staff" below the chat and type your question. A social worker will reply here during office hours (' . setting('office_hours') . ').', [], ['status', 'documents']);
            case 'thanks':
                return $this->reply('thanks', "You're welcome! Is there anything else I can help you with?", [], ['status', 'documents', 'appointment', 'renewal']);
            case 'greeting':
                return $this->reply('greeting', $this->menuText($user), [], array_keys(self::SUGGESTIONS));
        }
        return $this->reply('fallback',
            "Sorry, I'm not sure I understood. I can help with your application status, required documents, appointments, ID renewal, "
            . 'your QR slip and finding your way around this portal. Try one of the questions below, or use "Ask MSWDO staff" to talk to a social worker.',
            [], array_keys(self::SUGGESTIONS));
    }

    private function reply(string $topic, string $text, array $links, array $suggestionKeys): array
    {
        $suggestions = array_values(array_intersect_key(self::SUGGESTIONS, array_flip($suggestionKeys)));
        return ['topic' => $topic, 'text' => $text, 'meta' => ['links' => array_values(array_unique($links)), 'suggestions' => $suggestions]];
    }

    private function welcome(array $user): array
    {
        $r = $this->reply('greeting', $this->menuText($user) . "\n\n" . self::DISCLAIMER, [], array_keys(self::SUGGESTIONS));
        return ['text' => $r['text'], 'meta' => $r['meta']];
    }

    private function menuText(array $user): string
    {
        $first = trim(explode(' ', trim((string) $user['full_name']))[0] ?? '');
        return 'Hi' . ($first !== '' ? ' ' . $first : '') . "! I'm the MSWDO Virac Help Assistant. I can explain how to apply, which documents to upload, "
            . 'how to book an appointment, how renewal works, where your application is, and where to find things in this portal.';
    }

    /** The applicant's application that is still being processed, else their latest one. */
    private function currentApplication(array $applications): ?array
    {
        foreach ($applications as $app) {
            if (in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
                return $app;
            }
        }
        return $applications[0] ?? null;
    }

    private static function typeLabel(array $app): string
    {
        return $app['type'] === 'renewal' ? 'renewal' : 'application';
    }

    /** Staff text quoted inside a sentence, without doubling its final full stop. */
    private static function quote(string $text): string
    {
        return '"' . rtrim(trim($text), '.') . '"';
    }

    private static function date(?string $value, bool $withTime = false): string
    {
        return $value ? date($withTime ? 'F j, Y g:i A' : 'F j, Y', strtotime($value)) : '';
    }

    /** General information on who can apply -- the categories MSWD accepts, never a verdict on this applicant. */
    private function criteriaAnswer(): array
    {
        $categories = $this->pdo->query('SELECT name FROM categories ORDER BY sort_order, name')->fetchAll(PDO::FETCH_COLUMN);
        $text = 'A solo parent who lives in Virac, Catanduanes and alone provides parental care to a child (below 22, or of any age if the child has a disability) '
            . 'may apply under one of these circumstances (RA 8972 / RA 11861):' . "\n- " . implode("\n- ", $categories)
            . "\n\n" . self::DISCLAIMER;
        return $this->reply('criteria', $text, ['apply'],['documents', 'application']);
    }

    // The guardrail: never decides, always the same position.
    private function decisionAnswer(array $applications): array
    {
        $text = "I can't tell you whether you are eligible or whether you will be approved. " . self::DISCLAIMER;
        $links = [];
        $app = $this->currentApplication($applications);
        if ($app) {
            $text .= "\n\nWhat is recorded so far: your " . self::typeLabel($app) . ' ' . $app['reference_no'] . ' has the status "' . $app['status'] . '".';
            if (in_array($app['status'], ['Approved', 'ID Released', 'Rejected'], true)) {
                $text .= ' That decision was made by MSWD staff.';
            }
            $links[] = 'status';
        } else {
            $text .= "\n\nYou can apply online; MSWD staff will assess your application and documents.";
            $links[] = 'apply';
        }
        return $this->reply('decision', $text, $links, ['documents', 'status', 'application']);
    }

    private function statusAnswer(array $user, array $applications): array
    {
        $app = $this->currentApplication($applications);
        if (!$app) {
            $draft = (new ApplicationDraft($this->pdo))->find((int) $user['id']);
            $text = "You haven't submitted an application yet.";
            if ($draft) {
                $text .= ' You have a saved draft (last saved ' . self::date($draft['updated_at'], true) . ') -- open Apply to continue where you left off.';
            } else {
                $text .= ' Open Apply to start one; you can save your progress and come back later.';
            }
            return $this->reply('status', $text, ['apply'],['application', 'documents']);
        }

        $track = (new ApplicationTrackerService($this->pdo))->track($app);
        $text = 'Your ' . self::typeLabel($app) . ' ' . $app['reference_no'] . ' (' . $app['category_name'] . ') is "' . $app['status'] . '"'
            . ($track['current'] !== 'Completed' && $app['status'] !== 'Rejected' ? '; current stage: ' . $track['current'] : '') . '.';
        $links = ['status'];
        switch ($app['status']) {
            case 'Pending Review':
            case 'Under Verification':
                $text .= ' MSWD staff are reviewing it. You will get a notification whenever the status changes.';
                break;
            case 'For Revision':
                $text .= ' MSWD asked for corrections' . (!empty($track['correction']['reason']) ? ': ' . self::quote($track['correction']['reason']) : '') . '. Please fix and re-upload the flagged documents.';
                $links[] = 'documents';
                break;
            case 'Interview Scheduled':
                $text .= ' ' . $this->interviewSentence($app);
                $links[] = 'appointment';
                break;
            case 'Approved':
                $text .= ' MSWD staff approved it. Your ID will be released at the MSWDO office; you will be notified.';
                $links[] = 'myid';
                break;
            case 'ID Released':
                $text .= ' Your ID has been released' . ($app['id_expires_at'] ? ' and is valid until ' . self::date($app['id_expires_at']) : '') . '.';
                $links[] = 'myid';
                break;
            case 'Rejected':
                $text .= ' MSWD staff did not approve it' . (!empty($track['rejected']['reason']) ? '. Reason recorded: ' . self::quote($track['rejected']['reason']) : '')
                    . '. If you have questions about this decision, use "Ask MSWDO staff".';
                break;
        }
        if (count($applications) > 1) {
            $text .= ' You have ' . count($applications) . ' applications on record; all of them are listed in My Application.';
        }
        $text .= "\n\nI only report what is recorded. " . self::DISCLAIMER;
        return $this->reply('status', $text, $links, ['documents', 'appointment', 'myid']);
    }

    private function interviewSentence(array $app): string
    {
        if (($app['interview_status'] ?? null) === 'BOOKED' && $app['interview_at']) {
            return 'Your appointment is on ' . self::date($app['interview_at'], true) . ' at ' . ($app['interview_location'] ?: 'the MSWDO office') . '.';
        }
        if (($app['interview_status'] ?? null) === 'COMPLETED') {
            return 'Your interview is done; MSWD staff will update your status.';
        }
        return "You don't have an appointment booked yet -- pick an open slot on the Appointment page.";
    }

    private function documentsAnswer(array $user, array $applications): array
    {
        $documents = new Document($this->pdo);
        $app = $this->currentApplication($applications);
        $inProgress = $app && in_array($app['status'], Application::IN_PROGRESS_STATUSES, true);
        $requirements = $documents->requirementsFor($app ? (int) $app['category_id'] : null);
        $required = array_map(fn($r) => $r['label'], array_filter($requirements, fn($r) => (int) $r['is_required'] === 1));
        $optional = array_map(fn($r) => $r['label'], array_filter($requirements, fn($r) => (int) $r['is_required'] !== 1));

        $text = '';
        $links = ['documents'];
        if ($inProgress) {
            $uploaded = $documents->forUserByReference((int) $user['id'])[$app['reference_no']] ?? [];
            $fix = array_filter($uploaded, fn($d) => in_array($d['verification_status'], ['NEEDS_CORRECTION', 'REJECTED'], true));
            $verified = count(array_filter($uploaded, fn($d) => $d['verification_status'] === 'VERIFIED'));
            $text .= 'For ' . $app['reference_no'] . ': ' . count($uploaded) . ' document(s) uploaded, ' . $verified . ' verified by MSWD.';
            if ($fix) {
                $text .= ' These need your attention:';
                foreach ($fix as $d) {
                    $text .= "\n- " . $d['doc_label'] . ($d['verification_status'] === 'REJECTED' ? ' (rejected)' : ' (needs correction)')
                        . (!empty($d['staff_remarks']) ? ': ' . self::quote(fit_text((string) $d['staff_remarks'], 150)) : '');
                }
                $text .= "\nReview your Required Documents Checklist and follow the staff remarks. Staff verification is recorded separately.";
            } else {
                $text .= ' Nothing needs correcting right now.';
            }
            $text .= "\n\n";
        }
        $text .= ($app ? 'Requirements for ' . $app['category_name'] : 'Standard requirements') . ":\n- " . implode("\n- ", $required ?: ['(none listed)']);
        if ($optional) {
            $text .= "\nOptional: " . implode(', ', $optional) . '.';
        }
        if (!$app) {
            $text .= "\nThere may be one more document depending on your solo parent category; the application form shows the exact list.";
        }
        $rules = document_rules();
        $text .= "\n\nFiles must be " . $rules['label'] . ', up to ' . format_mb($rules['max_mb']) . ' each, clear and readable. MSWD staff verify every document.';
        if (!$inProgress) {
            $links[] = 'apply';
        }
        return $this->reply('documents', $text, $links, ['status', 'appointment']);
    }

    private function appointmentAnswer(array $applications): array
    {
        $service = new AppointmentService($this->pdo);
        $notice = $service->minNoticeHours();
        $app = $this->currentApplication($applications);
        if (!$app || !in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            $text = 'Appointments (interviews with MSWD) are booked after you submit an application. '
                . ($app ? 'You have no application in progress right now.' : 'Apply first; then choose an open slot on the Appointment page.');
            return $this->reply('appointment', $text, $app ? ['appointment', 'status'] : ['apply'], ['application', 'documents']);
        }
        $text = 'For ' . $app['reference_no'] . ': ' . $this->interviewSentence($app);
        if (AppointmentService::needsBooking($app)) {
            $open = count((new Appointment($this->pdo))->openSlots($notice));
            $text .= ' ' . ($open ? "There are $open open slot(s) right now." : 'There are no open slots at the moment; MSWD adds new ones regularly, so please check again later.');
        }
        $text .= "\n\nYou can book, reschedule or cancel online up to $notice hour(s) before the appointment. After that, please contact the MSWDO office. "
            . 'Bring your original documents and a valid ID to the interview.';
        return $this->reply('appointment', $text, ['appointment'], ['status', 'documents']);
    }

    private function renewalAnswer(array $user): array
    {
        $renewals = new RenewalService($this->pdo);
        if ($active = $renewals->renewalInProgress($user)) {
            return $this->reply('renewal', 'Your renewal ' . $active['reference_no'] . ' is being processed (status: "' . $active['status'] . '"). You can follow it in My Application.',
                ['status'], ['documents', 'appointment']);
        }
        $source = $renewals->sourceApplication($user);
        if (!$source) {
            return $this->reply('renewal', 'Renewal is for a Solo Parent ID that MSWD has already released. You do not have a released ID yet, so apply for a new one instead (or check the status of your application).',
                ['status', 'apply'], ['status', 'application']);
        }
        $window = $renewals->window($source);
        if (!$window['expiresAt']) {
            $text = 'Your ID ' . $source['reference_no'] . ' has no expiry date on record, so you can renew it now.';
        } elseif ($window['expired']) {
            $text = 'Your ID ' . $source['reference_no'] . ' expired on ' . self::date($window['expiresAt']) . '. You can renew it now.';
        } elseif ($window['open']) {
            $text = 'Your ID ' . $source['reference_no'] . ' expires on ' . self::date($window['expiresAt']) . '. Renewal is open now.';
        } else {
            $text = 'Your ID ' . $source['reference_no'] . ' is valid until ' . self::date($window['expiresAt']) . '. Renewal opens on '
                . self::date($window['opensOn']) . ' (' . $window['windowDays'] . ' days before it expires); you will get a reminder.';
        }
        $text .= "\n\nThe renewal form has 4 steps and starts from your current ID's details. Your name, birthdate, sex and category can't be changed there -- contact MSWDO for that. "
            . 'Update your address, contact details and children, upload the renewal documents, then review and submit.';
        return $this->reply('renewal', $text, ['renewal'], ['documents', 'status']);
    }

    private function applicationAnswer(array $user, array $applications): array
    {
        $blocking = (new Application($this->pdo))->blockingForNewApplication((int) $user['id']);
        if ($blocking) {
            $text = 'You already have ' . self::typeLabel($blocking) . ' ' . $blocking['reference_no'] . ' (status "' . $blocking['status'] . '"), so a new application is not needed.'
                . ($blocking['status'] === 'ID Released' ? ' When your ID is close to expiring, renew it instead.' : ' You can follow it in My Application.');
            return $this->reply('application', $text, $blocking['status'] === 'ID Released' ? ['renewal', 'myid'] : ['status'], ['status', 'renewal']);
        }
        $text = "Applying takes 4 steps:\n1. Personal information\n2. Your children\n3. Required Documents Checklist\n4. Review and submit\n"
            . 'Your progress is saved as a draft, so you can stop and continue later. After you submit you will get a reference number and a QR slip.';
        $draft = (new ApplicationDraft($this->pdo))->find((int) $user['id']);
        if ($draft) {
            $text .= "\n\nYou have a saved draft (last saved " . self::date($draft['updated_at'], true) . ', step ' . (int) ($draft['data']['step'] ?? 1) . ' of 4).';
        }
        return $this->reply('application', $text, ['apply'],['documents', 'decision']);
    }

    private function myIdAnswer(array $applications): array
    {
        $released = null;
        foreach (['ID Released', 'Approved'] as $status) {
            foreach ($applications as $app) {
                if (!$released && $app['status'] === $status) {
                    $released = $app;
                }
            }
        }
        $app = $released ?: $this->currentApplication($applications);
        if (!$app) {
            return $this->reply('myid', 'You get an application slip with a QR code when you submit an application. You have not applied yet.', ['apply'], ['application']);
        }
        $links = ['slip:' . $app['reference_no']];
        if ($app['status'] === 'ID Released') {
            $text = 'Your ID ' . $app['reference_no'] . ' has been released' . ($app['id_expires_at'] ? ' (valid until ' . self::date($app['id_expires_at']) . ')' : '')
                . '. Your digital ID and its QR code are on My Solo Parent ID.';
            $links[] = 'myid';
        } elseif ($app['status'] === 'Approved') {
            $text = 'Your application ' . $app['reference_no'] . ' has been approved by MSWD; the ID has not been released yet. Bring your slip to the MSWDO office when you are notified.';
            $links[] = 'myid';
        } else {
            $text = 'Your application slip for ' . $app['reference_no'] . ' has a QR code that MSWD staff scan at the office. You can view, print or save it any time.';
        }
        $text .= "\n\nLost your slip? Open it and choose \"Lost it? Get a new QR\". The old QR stops working immediately, so nobody else can use it.";
        return $this->reply('myid', $text, $links, ['status', 'appointment']);
    }

    private function navigationAnswer(string $text): array
    {
        $t = mb_strtolower($text);
        if (str_contains($t, 'password')) {
            return $this->reply('navigation', 'To change your password, open My Profile and use "Change password". Forgot it? Log out and choose "Forgot password?" on the login page; a code will be sent to your registered mobile number.', ['profile'], ['navigation']);
        }
        if (str_contains($t, 'notification')) {
            return $this->reply('navigation', 'Notifications about your application (status changes, corrections, appointments, reminders) are under Notifications and the bell at the top. Important ones are also sent by SMS.', ['notifications'], ['status']);
        }
        if (preg_match('/profile|mobile|email|address/', $t)) {
            return $this->reply('navigation', 'You can update your contact details (mobile number, email, address) and photo in My Profile. Details on a submitted application are reviewed by MSWD staff.', ['profile'], ['navigation']);
        }
        if (preg_match('/log ?out/', $t)) {
            return $this->reply('navigation', 'To log out, open the menu with your name at the top and choose Log out.', [], ['navigation']);
        }
        return $this->reply('navigation',
            "Here's where things are (left menu):\n- Dashboard: summary and next steps\n- My Application: status tracker and QR slip\n- Required Documents Checklist: review your selections and staff results\n"
            . "- Appointment: book or change your interview\n- ID Renewal: renew an expiring ID\n- My Solo Parent ID: your digital ID\n- Notifications, News & Announcements\n- My Profile: contact details and password",
            ['status', 'documents', 'appointment', 'profile'], ['status', 'documents']);
    }

    private function officeAnswer(): array
    {
        return $this->reply('office', setting('office_name') . ': ' . setting('office_address') . '. Office hours: ' . setting('office_hours') . '. '
            . 'Email ' . setting('office_email') . ', phone ' . setting('office_phone') . '. You can also use "Ask MSWDO staff" here to send a question to a social worker.', ['appointment'], ['appointment', 'documents']);
    }

    /** The active FAQ that best matches the question (at least two shared keywords, or one for a very short question), or null. */
    private function matchFaq(string $text): ?array
    {
        $words = $this->keywords($text);
        if (!$words) {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach (get_faqs($this->pdo) as $faq) {
            $score = count(array_intersect($words, $this->keywords($faq['question'] . ' ' . $faq['answer'])));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$faq, $score];
            }
        }
        return $bestScore >= min(2, count($words)) ? $best : null;
    }

    private function keywords(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter($words, fn($w) => mb_strlen($w) >= 4 && !in_array($w, self::STOPWORDS, true))));
    }
}
