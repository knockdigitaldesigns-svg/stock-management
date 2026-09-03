<?php
function normalizeName($value) {
    return strtolower(trim((string) $value));
}

function normalizeMobile($value) {
    return preg_replace('/\D+/', '', trim((string) $value));
}

function duplicateMessage($entity, $field) {
    $messages = [
        'device_imei' => 'IMEI number already exists.',
        'sim_number' => 'SIM number already exists.',
        'dealer_name' => 'Dealer name already exists.',
        'dealer_mobile' => 'Dealer mobile number already exists.',
        'technician_name' => 'Technician name already exists.',
        'technician_mobile' => 'Technician mobile number already exists.'
    ];
    return $messages[$entity . '_' . $field] ?? 'Duplicate record already exists.';
}
?>
