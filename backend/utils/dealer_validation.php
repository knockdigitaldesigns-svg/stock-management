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
    $rawMobile = trim((string) ($input['mobile_no'] ?? $input['Mobile No'] ?? ''));
    $location = trim((string) ($input['location'] ?? $input['Location'] ?? ''));
    $enrolledDate = trim((string) ($input['enrolled_date'] ?? $input['Enrolled Date'] ?? ''));
    $installationStatus = trim((string) ($input['installation_status'] ?? $input['Installation Status'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? $input['Notes'] ?? ''));

    // 1. Dealer Name
    if ($dealerName === '') {
        $errors[] = "{$prefix}Dealer Name is required.";
    }

    // 2. Mobile No (EXACTLY 10 numeric digits)
    $cleanMobile = preg_replace('/\D+/', '', $rawMobile);
    if ($rawMobile === '') {
        $errors[] = "{$prefix}Mobile No is required.";
    } elseif (strlen($cleanMobile) !== 10 || !preg_match('/^[0-9]{10}$/', $cleanMobile)) {
        $errors[] = "{$prefix}Mobile No must be exactly 10 digits.";
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

    // 6. Threshold Amount (optional for all statuses, must be non-negative numeric if provided)
    $thresholdAmount = null;
    $rawThreshold = $input['threshold_amount'] ?? $input['Threshold Amount'] ?? null;
    if ($rawThreshold !== null && trim((string)$rawThreshold) !== '') {
        if (!is_numeric($rawThreshold) || (float)$rawThreshold < 0) {
            $errors[] = "{$prefix}Threshold Amount must be a valid non-negative number.";
        } else {
            $thresholdAmount = (float)$rawThreshold;
        }
    }

    // 7. Software Multi-Select
    $rawSoftware = $input['software'] ?? $input['Software'] ?? [];
    $softwareList = [];
    if (is_array($rawSoftware)) {
        $softwareList = array_values(array_unique(array_filter(array_map('trim', $rawSoftware))));
    } elseif (is_string($rawSoftware) && trim($rawSoftware) !== '') {
        $softwareList = array_values(array_unique(array_filter(array_map('trim', explode(',', $rawSoftware)))));
    }

    $allowedSoftware = ['Tracoo', 'Tracco', 'Eagle India', 'Navilap', 'Oneqlick', 'Trackzee', 'Gps Monitor'];
    foreach ($softwareList as $sw) {
        if (!in_array($sw, $allowedSoftware, true)) {
            $errors[] = "{$prefix}Invalid Software: {$sw}.";
        }
    }

    $softwareStr = !empty($softwareList) ? implode(', ', $softwareList) : null;

    return [
        'errors' => $errors,
        'data' => [
            'dealer_name' => $dealerName,
            'mobile_no' => $cleanMobile,
            'raw_mobile' => $rawMobile,
            'location' => $location,
            'enrolled_date' => $isoDate,
            'installation_status' => $normalizedStatus,
            'notes' => $notes,
            'software' => $softwareStr,
            'software_list' => $softwareList,
            'threshold_amount' => $thresholdAmount
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
