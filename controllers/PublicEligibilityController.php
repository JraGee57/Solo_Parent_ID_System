<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/EligibilityService.php';
require_once __DIR__ . '/../services/AuthService.php';

/**
 * eligibility.php -- the public, no-login Eligibility Checker.
 * GET shows the questions; POST stores the answers as this visitor's
 * preliminary assessment and redirects to ?result=1, so the result page
 * can be refreshed or revisited without resubmitting.
 */
class PublicEligibilityController extends Controller
{
    private EligibilityService $eligibility;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->eligibility = new EligibilityService($pdo);
    }

    public function handle(?array $user): void
    {
        if (isset($_GET['history'])) {
            if (!$user) {
                $this->redirectTo('login.php');
            }
            $stmt = $this->pdo->prepare("SELECT * FROM eligibility_assessments WHERE user_id = ? AND assessment_type = 'PRELIMINARY' ORDER BY created_at DESC, id DESC LIMIT 100");
            $stmt->execute([(int) $user['id']]);
            $this->render('views/home/eligibility-history.php', ['checks' => $stmt->fetchAll()]);
            return;
        }
        $error = '';
        $answers = ['resident' => '', 'circ' => '', 'child' => ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $answers = $this->eligibility->normalizeAnswers($_POST);
            if (!$this->eligibility->missingAnswers($answers)) {
                $check = $this->eligibility->record($answers, $user ? (int) $user['id'] : null);
                $this->redirectTo('eligibility.php?result=1&check=' . $check['id']);
            }
            $error = 'Please answer all three questions to see your result.';
        } else {
            $current = $this->eligibility->current();
            if (isset($_GET['result'])) {
                if (isset($_GET['check'])) {
                    $id = filter_var($_GET['check'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $current = $id ? $this->eligibility->resultForViewer($id, $user ? (int) $user['id'] : null) : null;
                    if (!$current) {
                        render_error_page(404);
                    }
                }
                if (!$current) {
                    $this->redirectTo('eligibility.php');
                }
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->eligibility->recordView($current['id'], $user ? (int) $user['id'] : null);
                }
                $category = $this->pdo->prepare('SELECT id FROM categories WHERE name = ? AND is_active = 1');
                $category->execute([$current['answers']['circ']]);
                $categoryId = $category->fetchColumn();
                $this->render('views/home/eligibility-result.php', $current + [
                    'requirements' => $categoryId ? get_category_only_requirements($this->pdo, (int) $categoryId) : [],
                    'loggedIn' => $user !== null,
                    'applyUrl' => base_url('applicant/dashboard.php?section=apply'),
                    'restartUrl' => base_url('eligibility.php'),
                ]);
                return;
            }
            $answers = $current['answers'] ?? $answers;
        }

        $this->render('views/home/eligibility.php', [
            'answers' => $answers,
            'error' => $error,
            'circumstances' => EligibilityService::circumstances(),
            'noneOption' => EligibilityService::NONE_OPTION,
            'unsureOption' => EligibilityService::UNSURE_OPTION,
            'loggedIn' => $user !== null,
            'formAction' => base_url('eligibility.php'),
        ]);
    }
}
