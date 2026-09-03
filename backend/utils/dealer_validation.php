<?php
require_once __DIR__ . '/date.php';
require_once __DIR__ . '/validation.php';

function normalizeInstallationStatus($value) {
    $clean = strtolower(trim(preg_replace('/\s+/', ' ', (string) $value)));
    $map = [
        'onsite' => 'Onsite',
        'offsite' => 'Offsite',
        'not willing' => 'Not Willing'
    ];
    return $map[$clean] ?? null;
}

function validateDealerFields($input, $rowNumber = null) {
    $prefix = $rowNumber !== null ? "Row {$rowNumber}: " : "";
    $errors = [];

    $dealerName = trim((string) ($input['dealer_name'] ?? $input['Dealer Name'] ?? ''));
    $mobileNo = trim((string) ($input['mobile_no'] ?? $input['Mobile No'] ?? ''));
    $location = trim((string) ($input['location'] ?? $input['Location'] ?? ''));
    $enrolledDate = trim((string) ($input['enrolled_date'] ?? $input['Enrolled Date'] ?? ''));
    $installationStatus = trim((string) ($input['installation_status'] ?? $input['Installation Status'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? $input['Notes'] ?? ''));
    $software = trim((string) ($input['software'] ?? ''));
    $allowedSoftware = ['Tracoo', 'Eagle India', 'Navilap', 'Oneqlick', 'Trackzee', 'Gps Monitor'];
    if ($software !== '' && !in_array($software, $allowedSoftware, true)) $errors[] = "{$prefix}Invalid Software.";

    // 1. Dealer Name
    if ($dealerName === '') {
        $errors[] = "{$prefix}Dealer Name is required.";
    }

    // 2. Mobile No
    $normalizedMobile = normalizeMobile($mobileNo);
    if ($mobileNo === '') {
        $errors[] = "{$prefix}Mobile No is required.";
    } elseif (!preg_match('/^[0-9+\-\s()]{10,15}$/', $mobileNo) || strlen($normalizedMobile) < 10 || strlen($normalizedMobile) > 15) {
        $errors[] = "{$prefix}Mobile No is invalid.";
    }

    // 3. Location
    if ($location === '') {
        $errors[] = "{$prefix}Location is required.";
    }

    // 4. Enrolled Date
    $isoDate = '';
    if ($enrolledDate === '') {
        $errors[] = "{$prefix}Enrolled Date is required.";
    } else {
        $parsed = parseAndNormalizeDate($enrolledDate);
        if ($parsed === false) {
            $errors[] = "{$prefix}Invalid Enrolled Date.";
        } else {
            $isoDate = $parsed;
            if (isFutureDate($isoDate)) {
                $errors[] = "{$prefix}Future dates are not allowed.";
            }
        }
    }

    // 5. Installation Status
    $normalizedStatus = null;
    if ($installationStatus === '') {
        $errors[] = "{$prefix}Installation Status is required.";
    } else {
        $normalizedStatus = normalizeInstallationStatus($installationStatus);
        if ($normalizedStatus === null) {
            $errors[] = "{$prefix}Invalid Installation Status.";
        }
    }

    return [
        'errors' => $errors,
        'data' => [
            'dealer_name' => $dealerName,
            'mobile_no' => $normalizedMobile,
            'raw_mobile' => $mobileNo,
            'location' => $location,
            'enrolled_date' => $isoDate,
            'installation_status' => $normalizedStatus,
            'notes' => $notes
            , 'software' => $software !== '' ? $software : null
        ]
    ];
}

function checkDealerDuplicatesInDb($conn, $dealerName, $normalizedMobile, $excludeId = null, $rowNumber = null) {
    $prefix = $rowNumber !== null ? "Row {$rowNumber}: " : "";
    $errors = [];

    if ($normalizedMobile !== '') {
        $sql = "SELECT id FROM dealers WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(mobile_no, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') = ?";
        if ($excludeId !== null) {
            $sql .= " AND id != " . (int) $excludeId;
        }
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $normalizedMobile);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = "{$prefix}Mobile No already exists.";
            }
            $stmt->close();
        }
    }

    if ($dealerName !== '') {
        $sql = "SELECT id FROM dealers WHERE LOWER(TRIM(dealer_name)) = LOWER(TRIM(?))";
        if ($excludeId !== null) {
            $sql .= " AND id != " . (int) $excludeId;
        }
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $dealerName);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = "{$prefix}Dealer Name already exists.";
            }
            $stmt->close();
        }
    }

    return $errors;
}
?>
