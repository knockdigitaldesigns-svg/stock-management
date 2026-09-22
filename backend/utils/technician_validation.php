<?php
require_once __DIR__ . '/date.php';
require_once __DIR__ . '/validation.php';

function validateTechnicianFields($input, $rowNumber = null) {
    $prefix = $rowNumber !== null ? "Row {$rowNumber}: " : "";
    $errors = [];

    $technicianName = trim((string) ($input['technician_name'] ?? $input['Technician Name'] ?? ''));
    $mobileNo = (string) ($input['mobile_no'] ?? $input['Mobile No'] ?? '');
    $alternateMobileNo = (string) ($input['alternate_mobile_no'] ?? $input['Alternate Mobile No'] ?? '');
    $location = trim((string) ($input['location'] ?? $input['Location'] ?? ''));
    $enrolledDate = trim((string) ($input['enrolled_date'] ?? $input['Enrolled Date'] ?? ''));
    $notes = trim((string) ($input['notes'] ?? $input['Notes'] ?? ''));

    // 1. Technician Name
    if ($technicianName === '') {
        $errors[] = "{$prefix}Technician Name is required.";
    }

    // 2. Mobile No — must be exactly 10 digits
    if ($mobileNo === '') {
        $errors[] = "{$prefix}Mobile No is required.";
    } elseif (!preg_match('/^[0-9]{10}$/', $mobileNo)) {
        $errors[] = "{$prefix}Mobile number must contain exactly 10 digits.";
    }
    if ($alternateMobileNo !== '' && !preg_match('/^[0-9]{10}$/', $alternateMobileNo)) {
        $errors[] = "{$prefix}Alternate mobile number must contain exactly 10 digits.";
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
            'mobile_no' => $mobileNo,
            'raw_mobile' => $mobileNo,
            'alternate_mobile_no' => $alternateMobileNo !== '' ? $alternateMobileNo : null,
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