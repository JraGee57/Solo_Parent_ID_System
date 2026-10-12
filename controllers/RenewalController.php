<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/RenewalService.php';
require_once __DIR__ . '/../services/ApplicationTrackerService.php';

/** Dashboard "ID Renewal" section: ?type=new (the renewal wizard), status, or history. */
class RenewalController extends Controller
{
    private const TYPE_TITLES = ['new' => 'Renew ID', 'status' => 'Renewal Status', 'history' => 'Renewal History'];
    private const URL = 'applicant/dashboard.php?section=renewal&type=new';

    public function handle(array $user, array $applications): array
    {
        $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
        if (!isset(self::TYPE_TITLES[$type])) {
            $type = 'new';
        }
        $renewals = array_values(array_filter($applications, fn($a) => $a['type'] === 'renewal'));

        if ($type === 'status') {
            return ['title' => self::TYPE_TITLES[$type], 'view' => 'renewal/status.php', 'data' => [
                'latestRenewal' => $renewals[0] ?? null,
                'renewalTracker' => isset($renewals[0]) ? (new ApplicationTrackerService($this->pdo))->track($renewals[0]) : null,
            ]];
        }
        if ($type === 'history') {
            $refs = array_column($applications, 'reference_no', 'id');
            return ['title' => self::TYPE_TITLES[$type], 'view' => 'renewal/history.php', 'data' => ['renewalHistory' => $renewals, 'referenceById' => $refs]];
        }
        return ['title' => self::TYPE_TITLES[$type]] + $this->wizard($user);
    }

    /** The renewal wizard: shows the ID being renewed, then Details -> Children -> Documents -> Review. */
    private function wizard(array $user): array
    {
        $service = new RenewalService($this->pdo);
        $source = $service->sourceApplication($user);
        $inProgress = $source ? $service->renewalInProgress($user) : null;
        $window = $source ? $service->window($source) : null;
        $data = ['renewalSource' => $source, 'renewalInProgress' => $inProgress, 'renewalWindow' => $window];
        if (!$source || $inProgress || !$window['open']) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                csrf_require();
            }
            return ['view' => 'renewal/index.php', 'data' => $data];
        }

        $draft = $service->getDraft($user, $source);
        $errors = [];
        $submitError = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            [$draft, $errors, $submitError] = $this->handleAction($service, $user, $source, $draft);
        }
        $saved = (new ApplicationDraft($this->pdo))->find((int) $user['id'], 'renewal');

        return ['view' => 'renewal/index.php', 'data' => $data + [
            'draft' => $draft,
            'errors' => $errors,
            'submitError' => $submitError,
            'step' => (int) $draft['step'],
            'stepMeta' => RenewalService::STEP_META,
            'docDefs' => $service->documentRequirements(),
            'changes' => $service->changes($source, $draft),
            'draftSavedAt' => $saved['updated_at'] ?? null,
            'flashMessage' => take_flash(),
        ]];
    }

    /** @return array{0: array, 1: array, 2: ?string} [draft, errors, submitError] -- redirects after every action that succeeds. */
    private function handleAction(RenewalService $service, array $user, array $source, array $draft): array
    {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $step = (int) $draft['step'];

        switch ($action) {
            case 'continue':
                $draft = $service->collectStepInput($step, $draft, $_POST, $source);
                $errors = $service->validateStep($step, $draft);
                if (!$errors) {
                    $draft['step'] = min(4, $step + 1);
                }
                $service->saveDraft($user, $draft);
                if ($errors) {
                    return [$draft, $errors, null];
                }
                break;

            case 'back':
                $draft = $service->collectStepInput($step, $draft, $_POST, $source);
                $draft['step'] = max(1, $step - 1);
                break;

            case 'goto':
                $n = is_string($_POST['n'] ?? null) ? (int) $_POST['n'] : 0;
                if ($n >= 1 && $n < $step) {
                    $draft['step'] = $n;
                }
                break;

            case 'add_child':
                $draft = $service->collectStepInput(2, $draft, $_POST, $source);
                if (count($draft['children']) < 20) {
                    $draft['children'][] = ['name' => '', 'birthdate' => ''];
                }
                break;

            case 'remove_child':
                $draft = $service->collectStepInput(2, $draft, $_POST, $source);
                $idx = is_string($_POST['idx'] ?? null) ? (int) $_POST['idx'] : -1;
                if (count($draft['children']) > 1 && isset($draft['children'][$idx])) {
                    array_splice($draft['children'], $idx, 1);
                }
                break;

            case 'save_draft':
                $service->saveDraft($user, $service->collectStepInput($step, $draft, $_POST, $source));
                flash('Your renewal draft is saved. Continue it any time from ID Renewal.');
                $this->redirectTo('applicant/dashboard.php?section=renewal&type=new');
                return [$draft, [], null];

            case 'discard_draft':
                $service->clearDraft($user);
                flash('Your renewal draft was discarded.');
                $this->redirectTo(self::URL);
                return [$draft, [], null];

            case 'submit':
                $draft = $service->collectStepInput(4, $draft, $_POST, $source);
                $invalid = $service->firstInvalidStep($draft);
                if ($invalid) {
                    $draft['step'] = $invalid['step'];
                    $service->saveDraft($user, $draft);
                    return [$draft, $invalid['errors'], null];
                }
                try {
                    $refNumber = $service->submit($user, $source, $draft);
                    $this->redirectTo('slip.php?ref=' . urlencode($refNumber));
                } catch (DomainException $e) {
                    $this->redirectTo(self::URL);
                } catch (Throwable $e) {
                    error_log('Renewal submission failed: ' . $e->getMessage());
                    $service->saveDraft($user, $draft);
                    return [$draft, [], 'Something went wrong while submitting your renewal. Your draft is saved -- please try again.'];
                }
                return [$draft, [], null];

            default:
                return [$draft, [], null];
        }

        $service->saveDraft($user, $draft);
        $this->redirectTo(self::URL);
        return [$draft, [], null];
    }
}
