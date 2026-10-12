<?php
/**
 * QR code rendering for the printable Application Slip.
 *
 * Uses endroid/qr-code (see composer.json) when installed via
 * `composer install`. If the vendor library isn't present, the slip page
 * still works -- it just shows the reference number as text instead of a
 * QR image, so the app runs out of the box without Composer.
 */
function qr_code_data_uri(string $data): ?string
{
    $autoload = APP_ROOT . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        return null;
    }
    require_once $autoload;

    if (!class_exists(\Endroid\QrCode\Builder\Builder::class)) {
        return null;
    }

    try {
        $result = (new \Endroid\QrCode\Builder\Builder())
            ->data($data)
            ->size(220)
            ->margin(8)
            ->build();
        return $result->getDataUri();
    } catch (\Throwable $e) {
        return null;
    }
}
