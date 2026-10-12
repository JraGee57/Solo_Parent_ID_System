<?php
/** Builds the limited information shown by reference-only public tracking. */
class PublicApplicationTrackingService
{
    public static function summarize(array $app, array $history, ?array $interview, ?array $release, ?array $renewal = null, int $renewalDays = 30): array
    {
        $status = $app['status'];
        $released = $status === 'ID Released';
        $releasePending = $status === 'Approved' && $release && !$release['released_at'];
        $interviewPending = !in_array($status, ['Approved', 'ID Released', 'Rejected'], true)
            && $interview && $interview['status'] === 'BOOKED';
        $guidance = [
            'Pending Review' => ['Waiting for review', 'MSWDO has received your application. Check here for updates while staff reviews it.'],
            'Under Verification' => ['Your application is being reviewed', 'MSWDO is checking your information and documents. Monitor your account for any requests.'],
            'For Revision' => ['Corrections are needed', 'Log in to My Applications to read the requested corrections and update your information or document checklist.'],
            'Interview Scheduled' => ['Prepare for your interview', 'Check your appointment below. Bring your application slip, a valid ID, and the documents requested by MSWDO.'],
            'Approved' => ['Your application is approved', 'Your ID is being prepared. An ID release appointment has not been recorded yet. Wait for MSWDO claiming instructions before visiting to collect it.'],
            'ID Released' => ['Your ID has been released', 'Keep your Solo Parent ID safe and check its validity below. Log in to view your ID or check renewal options.'],
            'Rejected' => ['Review the application decision', 'Log in to read the decision and guidance from MSWDO. Contact the office if you need help with the next steps.'],
        ];
        [$title, $instruction] = $guidance[$status] ?? ['Check your application', 'Log in to view your application details or contact MSWDO for assistance.'];
        $appointment = null;
        if ($releasePending || $interviewPending) {
            $source = $releasePending ? $release : $interview;
            $appointment = [
                'label' => $releasePending ? 'ID release appointment' : 'Interview appointment',
                'scheduled_at' => $source['scheduled_at'], 'location' => $source['location'],
                'past' => strtotime($source['scheduled_at']) < time(),
            ];
            if ($releasePending) {
                $title = 'Your ID release is scheduled';
                $instruction = 'Visit MSWDO at the date, time, and location below. Bring your application slip and a valid ID. Your status becomes ID Released after staff confirms handover.';
            }
        } elseif ($interview && in_array($interview['status'], ['MISSED', 'CANCELLED'], true) && !in_array($status, ['Approved', 'ID Released', 'Rejected'], true)) {
            $title = 'Arrange another interview';
            $instruction = 'Your interview was ' . ($interview['status'] === 'MISSED' ? 'marked missed' : 'cancelled') . '. Log in to check appointment options or contact MSWDO to arrange another schedule.';
        }
        $releasedAt = $released ? ($release['released_at'] ?? null) : null;
        $timeline = [];
        foreach (array_reverse($history) as $entry) {
            $timeline[] = ['status' => $entry['status'], 'date' => $entry['changed_at']];
            if ($released && !$releasedAt && $entry['status'] === 'ID Released') {
                $releasedAt = $entry['changed_at'];
            }
        }
        $expiry = $released ? ($app['id_expires_at'] ?? null) : null;
        $expired = $expiry && $expiry < date('Y-m-d');
        if ($expired) {
            $title = 'Your Solo Parent ID has expired';
            $instruction = 'Log in to check your ID renewal options, or contact MSWDO for renewal requirements.';
        }
        $renewalOpens = $expiry ? (new DateTimeImmutable($expiry))->modify('-' . max(1, $renewalDays) . ' days')->format('Y-m-d') : null;
        $renewalDue = $released && $renewalOpens && date('Y-m-d') >= $renewalOpens;
        $handoverConfirmed = $released && !empty($release['released_at']);
        $idSummary = [
            'headline' => 'ID not yet issued', 'tone' => 'pending',
            'received' => 'Not yet', 'validity' => 'No ID issued yet',
            'renewal' => 'Not applicable until your ID is issued', 'renewal_ref' => null,
        ];
        if ($status === 'Approved') {
            $idSummary['headline'] = $releasePending ? 'ID scheduled for collection' : 'ID being prepared';
            $idSummary['validity'] = 'Your ID has not been handed over yet';
        } elseif ($status === 'Rejected') {
            $idSummary['headline'] = 'No ID issued';
        } elseif ($released) {
            $idSummary = [
                'headline' => $expired ? 'ID expired — renewal needed' : ($renewalDue ? 'ID valid — renewal due' : ($handoverConfirmed ? 'ID received' : 'ID recorded as released')),
                'tone' => $renewalDue ? 'attention' : 'received',
                'received' => $handoverConfirmed ? 'Yes — staff confirmed handover' : 'Recorded as released by MSWDO',
                'validity' => $expiry ? ($expired ? 'Expired on ' : 'Valid until ') . date('F j, Y', strtotime($expiry)) : 'Expiry date not recorded. Confirm with MSWDO.',
                'renewal' => $renewalDue ? 'Renewal needed. Log in to start or check your renewal.' : ($renewalOpens ? 'Not needed yet. Renewal opens on ' . date('F j, Y', strtotime($renewalOpens)) . '.' : 'Confirm your ID expiry date with MSWDO.'),
                'renewal_ref' => null,
            ];
            if ($renewal && $renewal['status'] !== 'Rejected') {
                $renewed = $renewal['status'] === 'ID Released';
                $idSummary['headline'] = $renewed ? 'This ID has been renewed' : 'Renewal in progress';
                $idSummary['tone'] = $renewed ? 'received' : 'pending';
                $idSummary['renewal'] = $renewed ? 'Your renewed ID has been released. Track the new reference below for its validity.' : 'A renewal is already filed. Track its reference below for updates.';
                $idSummary['renewal_ref'] = $renewal['reference_no'];
                $title = $renewed ? 'Check your renewed ID' : 'Follow your renewal application';
                $instruction = $idSummary['renewal'];
            } elseif ($renewalDue) {
                $title = 'Renew your Solo Parent ID';
                $instruction = 'Log in to ID Renewal to submit your renewal, or contact MSWDO for the requirements.';
            } elseif ($expiry) {
                $title = 'Your ID is valid; renewal is not needed yet';
                $instruction = 'Keep your ID safe. You can apply for renewal starting ' . date('F j, Y', strtotime($renewalOpens)) . '.';
            }
        }
        // Allowlist public fields: no mobile, address, assessment, staff notes, SMS, or QR token.
        return [
            'reference_no' => $app['reference_no'], 'masked_name' => mask_name($app['full_name']),
            'status' => $status, 'type' => $app['type'], 'submitted_at' => $app['submitted_at'],
            'updated_at' => max($app['updated_at'] ?? $app['submitted_at'], $history[0]['changed_at'] ?? $app['submitted_at']),
            'title' => $title, 'instruction' => $instruction, 'appointment' => $appointment,
            'released_at' => $releasedAt, 'expires_at' => $expiry, 'expired' => (bool) $expired,
            'id_summary' => $idSummary,
            'timeline' => $timeline,
        ];
    }
}
