<?php
/**
 * Role-based access control: the single source of truth for who may do
 * what. Every controller action names the permission it needs (see
 * Controller::authorize()); a role not listed for a permission is denied.
 *
 * Key rules:
 * - Application decisions (status changes, document verification,
 *   eligibility assessment, interviews) and QR verification belong to
 *   MSWD staff only. Administrators monitor applications read-only.
 * - System administration (users, settings, SMS, audit, analytics,
 *   reports, backups) belongs to administrators only.
 * - Applicants only ever reach their own records, through the applicant
 *   portal; no staff or admin role can act as an applicant.
 */
const ROLE_PERMISSIONS = [
    'applicant' => [
        'applicant.portal',
    ],
    'mswd_social_worker' => [
        'staff.dashboard',
        'applications.process',
        'applicants.view',
        'appointments.view',
        'appointments.slots',
        'qr.verify',
        'reports.view',
        'reports.export',
        'chat.respond',
    ],
    'admin' => [
        'admin.dashboard',
        'applications.monitor',
        'applicants.view',
        'appointments.view',
        'appointments.slots',
        'reports.view',
        'reports.export',
        'users.manage',
        'settings.manage',
        'content.manage',
        'sms.manage',
        'audit.view',
        'eligibility.logs',
        'analytics.view',
        'data.export',
        'system.monitor',
    ],
];

function can(?array $account, string $permission): bool
{
    return $account !== null && in_array($permission, ROLE_PERMISSIONS[$account['role']] ?? [], true);
}
