<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/ReportService.php';
require_once __DIR__ . '/../models/Report.php';

/**
 * The Reports page, shared by MSWD staff (staff/reports.php) and
 * administrators (admin/reports.php): eight reports with date range, search
 * and report-specific filters, 50 rows per page, a print view and CSV
 * export of every matching row. Exports and prints are audit-logged.
 * Callers have already checked reports.view.
 */
class ReportController extends Controller
{
    /**
     * Streams the CSV, renders the print view (and stops), or returns the
     * data for the page view. $basePath is the page these links point to.
     */
    public function handle(array $account, string $basePath): ?array
    {
        $service = new ReportService($this->pdo);
        $report = new Report($this->pdo);
        $type = is_string($_GET['type'] ?? null) && isset(Report::TYPES[$_GET['type']]) ? $_GET['type'] : 'application';
        [$filters, $error] = $service->reportFilters($type, $_GET);

        // The expiring-ID report opens on the renewal reminder window.
        if ($type === 'expiring' && !isset($_GET['from']) && !isset($_GET['to'])) {
            $filters['from'] = date('Y-m-d');
            $filters['to'] = date('Y-m-d', strtotime('+' . max(1, (int) setting('renewal_reminder_days')) . ' days'));
        }

        $output = is_string($_GET['output'] ?? null) ? $_GET['output'] : '';
        if ($output === 'csv' || $output === 'print') {
            $this->authorize($account, 'reports.export');
            $rows = $report->all($type, $filters);
            if ($rows === null) {
                http_response_code(413);
                die('This report has more than ' . number_format(Report::EXPORT_LIMIT) . ' rows. Narrow the date range or search, then export again.');
            }
            $columns = $report->columns($type);
            audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Report Export', 'report', null,
                Report::TYPES[$type] . ' (' . strtoupper($output) . ', ' . count($rows) . ' rows) — ' . $service->describeFilters($type, $filters));
            if ($output === 'csv') {
                $service->streamCsv(
                    $type . '-report-' . date('Y-m-d') . '.csv',
                    array_column($columns, 0),
                    array_map(fn($r) => array_map(fn($k) => $r[$k] ?? '', array_keys($columns)), $rows)
                );
                exit;
            }
            $this->render('views/reports/print.php', [
                'title' => Report::TYPES[$type],
                'description' => Report::DESCRIPTIONS[$type],
                'filterText' => $service->describeFilters($type, $filters),
                'columns' => $columns,
                'rows' => $rows,
                'totals' => $report->totalsFor($type, $filters),
                'account' => $account,
            ]);
            exit;
        }

        $result = $report->run($type, $filters, (int) ($_GET['page'] ?? 1));
        return [
            'type' => $type,
            'typeLabels' => Report::TYPES,
            'description' => Report::DESCRIPTIONS[$type],
            'filters' => $filters,
            'filterError' => $error,
            'filterOptions' => $report->filterOptions($type),
            'filterText' => $service->describeFilters($type, $filters),
            'dateLabel' => $report->dateLabel($type),
            'columns' => $report->columns($type),
            'rows' => $result['rows'],
            'totals' => $result['totals'],
            'pager' => $result,
            'basePath' => $basePath,
            'canExport' => can($account, 'reports.export'),
        ];
    }
}
