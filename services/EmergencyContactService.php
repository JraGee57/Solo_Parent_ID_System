<?php
/** Shared optional emergency-contact fields for application drafts and ID records. */
class EmergencyContactService
{
    public const FIELD_MAP = [
        'emergencyName' => 'emergency_contact_name',
        'emergencyRelation' => 'emergency_contact_relation',
        'emergencyPhone' => 'emergency_contact_phone',
        'emergencyAddress' => 'emergency_contact_address',
    ];

    public static function draftFields(array $application = []): array
    {
        $fields = [];
        foreach (self::FIELD_MAP as $draftKey => $column) {
            $fields[$draftKey] = (string) ($application[$column] ?? '');
        }
        return $fields;
    }

    public static function applicationFields(array $draft): array
    {
        $fields = [];
        foreach (self::FIELD_MAP as $draftKey => $column) {
            $value = is_string($draft[$draftKey] ?? null) ? trim($draft[$draftKey]) : '';
            if ($draftKey === 'emergencyPhone') {
                $value = preg_replace('/[\s()-]/', '', $value);
            }
            $fields[$column] = $value === '' ? null : $value;
        }
        return $fields;
    }

    public static function validate(array $draft): array
    {
        $values = [];
        foreach (self::FIELD_MAP as $key => $_) {
            $values[$key] = is_string($draft[$key] ?? null) ? trim($draft[$key]) : '';
        }
        if (!array_filter($values, static fn($value) => $value !== '')) {
            return [];
        }
        $errors = [];
        if ($values['emergencyName'] === '') {
            $errors['emergencyName'] = 'Enter the name of your emergency contact.';
        }
        foreach (['emergencyName' => 150, 'emergencyRelation' => 80, 'emergencyPhone' => 30, 'emergencyAddress' => 255] as $key => $limit) {
            if (mb_strlen($values[$key]) > $limit) {
                $errors[$key] = 'Use no more than ' . $limit . ' characters.';
            }
        }
        $phone = preg_replace('/[\s()-]/', '', $values['emergencyPhone']);
        if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
            $errors['emergencyPhone'] = 'Enter a valid emergency contact number (7–15 digits; spaces, hyphens and parentheses are allowed).';
        }
        return $errors;
    }
}
