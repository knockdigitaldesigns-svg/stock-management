<?php
require_once __DIR__ . '/date.php';
require_once __DIR__ . '/validation.php';

function validateTechnicianFields($input, $rowNumber = null) {
    $prefix = $rowNumber !== null ? "Row {$rowNumber}: " : "";
    $errors = [];

    $technicianName = trim((string) ($input['technician_name'] ?? $input['Technician Name'] ?? ''));
    $mobileNo = trim((string) ($input['mobile_no'] ?? $input['Mobile No'] ?? ''));
    $location = trim((string) ($input['location'] ?? $input['Location'] ?? ''));
    $enrolledDate = trim((string) ($input['enrolled_date'] ?? $input['Enrolled Date'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? $input['Notes'] ?? ''));

    // 1. Technician Name
    if ($technicianName === '') {
        $errors[] = "{$prefix}Technician Name is required.";
    }

    // 2. Mobile No — must be exactly 10 digits
    $normalizedMobile = normalizeMobile($mobileNo);
    if ($mobileNo === '') {
        $errors[] = "{$prefix}Mobile No is required.";
    } elseif (strlen($normalizedMobile) !== 10 || !preg_match('/^\d{10}$/', $normalizedMobile)) {
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

    return [
        'errors' => $errors,
        'data' => [
            'technician_name' => $technicianName,
            'mobile_no' => $normalizedMobile,
            'raw_mobile' => $mobileNo,
            'location' => $location,
            'enrolled_date' => $isoDate,
            'notes' => $notes
        ]
    ];
}

function checkTechnicianDuplicatesInDb($conn, $technicianName, $normalizedMobile, $excludeId = null, $rowNumber = null) {
    $prefix = $rowNumber !== null ? "Row {$rowNumber}: " : "";
    $errors = [];

    if ($normalizedMobile !== '') {
        $sql = "SELECT id FROM technicians WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(mobile_no, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') = ?";
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

    if ($technicianName !== '') {
        $sql = "SELECT id FROM technicians WHERE LOWER(TRIM(technician_name)) = LOWER(TRIM(?))";
        if ($excludeId !== null) {
            $sql .= " AND id != " . (int) $excludeId;
        }
        $sql .= " LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $technicianName);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $errors[] = "{$prefix}Technician Name already exists.";
            }
            $stmt->close();
        }
    }

    return $errors;
}
?>