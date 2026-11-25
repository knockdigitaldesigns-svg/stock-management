<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/customer_payment_verification.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
$reviewer = requireCustomerPaymentVerificationReviewer();
$canDecide = isSuperAdminUser((int) ($reviewer['user_id'] ?? 0));
$canDelete = $canDecide;

$status = trim((string) ($_GET['status'] ?? 'Pending Verification'));
$search = trim((string) ($_GET['search'] ?? ''));
$allowedStatuses = ['Pending Verification', 'Applying', 'Approved', 'Rejected'];
if (!in_array($status, $allowedStatuses, true)) {
    sendResponse(false, 'Invalid verification status.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
$statusSql = $status === 'Pending Verification'
    ? "s.status IN ('Pending Verification', 'Applying')"
    : 's.status = ?';
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 10)));
$offset = ($page - 1) * $pageSize;
$pattern = '%' . $search . '%';
$countStmt = $conn->prepare(
    'SELECT COUNT(*) AS total
     FROM customer_payment_verification_submissions s
     INNER JOIN users submitter ON submitter.id = s.submitter_user_id
     WHERE ' . $statusSql . '
       AND (? = \'\' OR s.submission_data LIKE ? OR submitter.employee_name LIKE ? OR submitter.username LIKE ?)'
);
if (!$countStmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare verification queue count query.', [], [], 500);
}
if ($status === 'Pending Verification') {
    $countStmt->bind_param('ssss', $search, $pattern, $pattern, $pattern);
} else {
    $countStmt->bind_param('sssss', $status, $search, $pattern, $pattern, $pattern);
}
if (!$countStmt->execute()) {
    $countStmt->close();
    $conn->close();
    sendResponse(false, 'Unable to count verification submissions.', [], [], 500);
}
$total = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();
$stmt = $conn->prepare(
    'SELECT s.id, s.submitter_user_id, s.existing_customer_id, s.status, s.submission_data,
            s.submitted_at, s.created_at, s.rejection_reason,
            submitter.employee_name AS submitted_by,
            reviewer.employee_name AS reviewed_by,
            (
                SELECT COUNT(DISTINCT cv.id)
                FROM customer_vehicle_details cv
                WHERE cv.customer_id IN (s.existing_customer_id, s.linked_customer_id)
                   OR cv.verification_submission_id = s.id
            ) AS vehicle_count
     FROM customer_payment_verification_submissions s
     INNER JOIN users submitter ON submitter.id = s.submitter_user_id
     LEFT JOIN users reviewer ON reviewer.id = s.reviewer_user_id
     WHERE ' . $statusSql . '
       AND (? = \'\' OR s.submission_data LIKE ? OR submitter.employee_name LIKE ? OR submitter.username LIKE ?)
     ORDER BY COALESCE(s.submitted_at, s.created_at) DESC, s.id DESC
     LIMIT ? OFFSET ?'
);
if (!$stmt) {
    $conn->close();
    sendResponse(false, 'Unable to prepare verification queue query.', [], [], 500);
}
if ($status === 'Pending Verification') {
    $stmt->bind_param('ssssii', $search, $pattern, $pattern, $pattern, $pageSize, $offset);
} else {
    $stmt->bind_param('sssssii', $status, $search, $pattern, $pattern, $pattern, $pageSize, $offset);
}
if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();
    sendResponse(false, 'Unable to fetch verification submissions.', [], [], 500);
}
$result = $stmt->get_result();
$submissions = [];
while ($row = $result->fetch_assoc()) {
    try {
        $detail = decodeCustomerPaymentSubmission((string) $row['submission_data']);
    } catch (Throwable $error) {
        $stmt->close();
        $conn->close();
        sendResponse(false, 'A saved verification submission could not be read.', [], [], 500);
    }
    $customer = array_replace(
        is_array($detail['step1'] ?? null) ? $detail['step1'] : [],
        is_array($detail['customer'] ?? null) ? $detail['customer'] : []
    );
    $payment = $detail['payment_part2'] ?? $detail['paymentPart2'] ?? $detail['step5'] ?? null;
    $paymentStatus = '';
    $vehicleDetails = $detail['vehicles'] ?? [];
    if (!is_array($vehicleDetails) || !$vehicleDetails) {
        $singleVehicle = $detail['vehicle'] ?? $detail['step2'] ?? null;
        $vehicleDetails = is_array($singleVehicle) ? [$singleVehicle] : [];
    }
    if (!is_array($payment) || !$payment) {
        foreach ($vehicleDetails as $vehicle) {
            if (!is_array($vehicle)) {
                continue;
            }
            if (is_array($vehicle['payment_part2'] ?? null) && $vehicle['payment_part2']) {
                $payment = $vehicle['payment_part2'];
                break;
            }
        }
    }
    if (is_array($payment)) {
        $paymentStatus = (string) ($payment['payment_status'] ?? '');
    }
    if ($paymentStatus === '') {
        $paymentPart1 = $detail['payment_part1'] ?? null;
        if (!is_array($paymentPart1) || !$paymentPart1) {
            $paymentPart1 = $detail['step4'] ?? null;
        }
        if (!is_array($paymentPart1) || !$paymentPart1) {
            foreach ($vehicleDetails as $vehicle) {
                if (!is_array($vehicle)) {
                    continue;
                }
                if (is_array($vehicle['payment_part1'] ?? null) && $vehicle['payment_part1']) {
                    $paymentPart1 = $vehicle['payment_part1'];
                    break;
                }
            }
        }
        if (is_array($paymentPart1) && $paymentPart1) {
            $payment = $paymentPart1;
            $paymentStatus = (string) (
                $paymentPart1['payment_status'] ??
                $paymentPart1['paymentStatus'] ??
                $detail['payment_status'] ??
                'Pending'
            );
        }
    }
    $vehicleCount = (int) ($row['vehicle_count'] ?? 0);
    if ($vehicleCount === 0) {
        $submittedVehicles = $detail['vehicles'] ?? null;
        if (!is_array($submittedVehicles) || !$submittedVehicles) {
            $submittedVehicles = is_array($detail['vehicle'] ?? null)
                ? [$detail['vehicle']]
                : [];
        }
        $vehicleCount = count(array_filter($submittedVehicles, 'is_array'));
    }
    $payment = is_array($payment) ? $payment : [];
    $platformName = trim((string) ($customer['platform_name'] ?? ''));
    if ($platformName === '' && (int) ($customer['platform_id'] ?? 0) > 0) {
        $platformStmt = $conn->prepare('SELECT platform_name FROM platforms WHERE id = ? LIMIT 1');
        if (!$platformStmt) {
            $stmt->close();
            $conn->close();
            sendResponse(false, 'Unable to prepare verification platform lookup.', [], [], 500);
        }
        $platformId = (int) $customer['platform_id'];
        $platformStmt->bind_param('i', $platformId);
        if (!$platformStmt->execute()) {
            $platformStmt->close();
            $stmt->close();
            $conn->close();
            sendResponse(false, 'Unable to resolve verification platform.', [], [], 500);
        }
        $platformName = (string) ($platformStmt->get_result()->fetch_assoc()['platform_name'] ?? '');
        $platformStmt->close();
    }
    $submissionType = 'Customer Payment';
    if (($detail['source'] ?? '') === 'courier') {
        $submissionType = 'Courier Request';
    } elseif (($detail['source'] ?? '') === 'payment_installment') {
        $submissionType = 'Payment Installment';
    } elseif (($detail['source'] ?? '') === 'customer_vehicle_addition') {
        $submissionType = 'Additional Customer Vehicle';
    } elseif (($detail['source'] ?? '') === 'crm_installation') {
        $submissionType = 'CRM Installation Device';
    }
    $submissions[] = [
        'id' => (int) $row['id'],
        'submission_type' => $submissionType,
        'submitter_user_id' => (int) $row['submitter_user_id'],
        'existing_customer_id' => $row['existing_customer_id'] !== null ? (int) $row['existing_customer_id'] : null,
        'status' => $row['status'],
        'customer_name' => (string) ($customer['username'] ?? $customer['customer_name'] ?? '—'),
        'primary_mobile_no' => (string) ($customer['primary_mobile_no'] ?? ''),
        'vehicle_count' => $vehicleCount,
        'platform_name' => $platformName,
        'location' => (string) ($customer['location'] ?? ''),
        'payment_status' => $paymentStatus,
        'total_sale_amount' => (float) (
            ($detail['source'] ?? '') === 'payment_installment'
                ? ($detail['payment_installment']['amount'] ?? 0)
                : ($payment['total_sale_amount'] ?? $payment['totalSaleAmount'] ?? 0)
        ),
        'submitted_by' => (string) ($row['submitted_by'] ?? ''),
        'reviewed_by' => (string) ($row['reviewed_by'] ?? ''),
        'submitted_at' => $row['submitted_at'] ?? $row['created_at'],
        'rejection_reason' => $row['rejection_reason'],
        'can_decide' => $canDecide && in_array($row['status'], ['Pending Verification', 'Applying'], true),
        'can_delete' => $canDelete && $row['status'] === 'Rejected'
    ];
}
$stmt->close();

$counts = [];
foreach ($allowedStatuses as $countStatus) {
    $countStmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM customer_payment_verification_submissions
         WHERE status = ?'
    );
    $countStmt->bind_param('s', $countStatus);
    $countStmt->execute();
    $counts[$countStatus] = (int) ($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();
}
$counts['Pending Verification'] += $counts['Applying'];
$conn->close();
sendResponse(true, 'Verification submissions fetched.', [
    'submissions' => $submissions,
    'counts' => $counts,
    'can_decide' => $canDecide,
    'pagination' => [
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
        'total_pages' => max(1, (int) ceil($total / $pageSize))
    ]
]);
