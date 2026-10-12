<?php
/**
 * Every administrator-configurable setting (system_settings table), by
 * section of the System Settings page: type, default and allowed range.
 * A setting that was never saved uses its default here. Read values with
 * setting('key') (includes/functions.php); change them only through
 * SettingsService, which validates against this list.
 *
 * Types: text, email, phone, int, decimal, bool, time (HH:MM), list
 * (comma-separated subset of 'options').
 */
const SETTINGS_REGISTRY = [
    'general' => [
        'title' => 'Basic System Information',
        'note' => 'Shown in page titles, the footer, the Help Assistant and the applicant pages.',
        'keys' => [
            'system_name' => ['label' => 'System name', 'type' => 'text', 'default' => 'Virac Solo Parent ID Online Portal', 'max' => 120],
            'office_name' => ['label' => 'Office name', 'type' => 'text', 'default' => 'MSWDO Virac', 'max' => 100],
            'office_address' => ['label' => 'Office address', 'type' => 'text', 'default' => 'Municipal Building, Salvacion, Virac, Catanduanes, 4800', 'max' => 255],
            'office_hours' => ['label' => 'Office hours', 'type' => 'text', 'default' => 'Monday to Friday, 8:00 AM to 5:00 PM', 'max' => 100],
            'office_email' => ['label' => 'Contact email', 'type' => 'email', 'default' => 'lguvirac2022@gmail.com', 'max' => 150],
            'office_phone' => ['label' => 'Contact phone', 'type' => 'phone', 'default' => '052 740 1512', 'max' => 30],
        ],
    ],
    'documents' => [
        'title' => 'Document Limits',
        'note' => 'Applies to every new upload (applications, corrections and renewals). Files already uploaded are not affected.',
        'keys' => [
            'document_max_size_mb' => ['label' => 'Maximum file size (MB)', 'type' => 'decimal', 'default' => '5', 'min' => 0.5, 'max' => 20, 'step' => 0.5],
            'document_allowed_types' => ['label' => 'Allowed file types', 'type' => 'list', 'default' => 'jpg,png,pdf',
                'options' => ['jpg' => 'JPG / JPEG image', 'png' => 'PNG image', 'pdf' => 'PDF document']],
        ],
    ],
    'appointments' => [
        'title' => 'Appointments',
        'note' => 'MSWD staff open the actual dates and times on Appointment Slots; these rules limit when slots may be opened and booked.',
        'keys' => [
            'appointment_location' => ['label' => 'Default appointment location', 'type' => 'text', 'default' => 'MSWDO Office, Municipal Building, Salvacion, Virac, Catanduanes', 'max' => 255],
            'appointment_default_capacity' => ['label' => 'Default capacity per slot', 'type' => 'int', 'default' => '10', 'min' => 1, 'max' => 100],
            'appointment_days' => ['label' => 'Days slots may be scheduled', 'type' => 'list', 'default' => '1,2,3,4,5',
                'options' => ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun']],
            'appointment_day_start' => ['label' => 'Earliest slot start', 'type' => 'time', 'default' => '08:00'],
            'appointment_day_end' => ['label' => 'Latest slot end', 'type' => 'time', 'default' => '17:00'],
            'appointment_max_days_ahead' => ['label' => 'Slots may be opened up to (days ahead)', 'type' => 'int', 'default' => '366', 'min' => 7, 'max' => 366],
            'appointment_min_notice_hours' => ['label' => 'Minimum notice to book, reschedule or cancel online (hours)', 'type' => 'int', 'default' => '2', 'min' => 0, 'max' => 168],
            'appointment_reminder_hours' => ['label' => 'Send the appointment reminder (hours before)', 'type' => 'int', 'default' => '24', 'min' => 1, 'max' => 168],
        ],
    ],
    'renewals' => [
        'title' => 'ID Validity & Renewal',
        'note' => 'New and renewed IDs expire one year after confirmed release. Previously released IDs keep their recorded expiry date.',
        'keys' => [
            'id_validity_years' => ['label' => 'ID validity (years, fixed)', 'type' => 'int', 'default' => '1', 'min' => 1, 'max' => 1, 'readonly' => true],
            'renewal_reminder_days' => ['label' => 'Renewal reminder period (days before expiry; renewal opens then)', 'type' => 'int', 'default' => '30', 'min' => 1, 'max' => 180],
        ],
    ],
    'notifications' => [
        'title' => 'Notification Settings',
        'note' => 'In-app notifications to applicants are always kept: they are the record of what happened to an application.',
        'keys' => [
            'sms_enabled' => ['label' => 'Send SMS notifications to applicants', 'type' => 'bool', 'default' => '1'],
            'notify_staff_new_work' => ['label' => 'Alert MSWD staff in-app about new work (new applications and renewals, re-uploaded documents, appointment changes)', 'type' => 'bool', 'default' => '1'],
        ],
    ],
];
