<?php
function parseAndNormalizeDate($value) {
    if ($value === null || $value === '') return '';
    if (is_numeric($value)) {
        if ((float) $value < 1) return '';
        $base = DateTimeImmutable::createFromFormat('!Y-m-d', '1899-12-30');
        if (!$base) return '';
        return $base->modify('+' . (int) floor((float) $value) . ' days')->format('Y-m-d');
    }
    $str = trim((string) $value);
    // Format: DD-MM-YYYY or DD/MM/YYYY
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $str, $m)) {
        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        return false;
    }
    // Format: YYYY-MM-DD or YYYY/MM/DD
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $str, $m)) {
        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
        return false;
    }
    return false;
}

function isFutureDate($value) {
    $normalized = parseAndNormalizeDate($value);
    if ($normalized === false || $normalized === '') return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return false;
    }
    return $date > new DateTimeImmutable('today');
}
?>
