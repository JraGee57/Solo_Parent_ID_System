<?php
/**
 * Dashboard Eligibility Checker: the same server-evaluated form/result as
 * the public eligibility.php page, embedded without its page chrome.
 *
 * @var ?array $result
 */
$standalone = false;
if ($result) {
    require APP_ROOT . '/views/home/eligibility-result.php';
} else {
    $embedded = true;
    require APP_ROOT . '/views/home/eligibility.php';
}
