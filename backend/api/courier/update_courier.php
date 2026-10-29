<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../utils/customer_payment_verification.php';
require_once '../../utils/courier_customer_verification.php';
require_once '../../middleware/auth.php';
require_once __DIR__ . '/setup_table.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
ensurePermissionDefinitions(['courier.edit', 'courier.approve', 'courier.view']);
$userId = (int) ($currentUser['user_id'] ?? 0);
$isSuperAdmin = isSuperAdminUser($userId);
$userPermissions = getUserPermissions($userId);
if (!$isSuperAdmin && !in_array('courier.edit', $userPermissions, true) && !in_array('courier.approve', $userPermissions, true)) {
    sendResponse(false, 'Unauthorized: You do not have permission to update courier tracking details.', [], [], 403);
}

$data = json_decode(file_get_contents('php://input'));
$id = (int) ($data->id ?? 0);
if ($id <= 0) {
    sendResponse(false, 'Courier Request ID is required', [], [], 400);
}

$courierSendVia = trim((string) ($data->courier_send_via ?? ''));
$validSendVias = ['St Courier', 'ST Courier', 'Professional', 'Delhivery', 'DTDC', 'Trackon'];
if ($courierSendVia === '' || !in_array($courierSendVia, $validSendVias, true)) {
    sendResponse(false, 'Courier Send Via is required and must be St Courier, Professional, Delhivery, DTDC, or Trackon.', [], [], 400);
}
$trackingId = trim((string) ($data->tracking_id ?? ''));
$courierStatus = trim((string) ($data->courier_status ?? ''));
$validStatuses = ['Pending', 'Reached', 'Not Reached'];
if ($courierStatus === '' || !in_array($courierStatus, $validStatuses, true)) {
    sendResponse(false, 'Courier Status is required and must be Pending, Reached, or Not Reached.', [], [], 400);
}
$courierReason = trim((string) ($data->courier_reason ?? ''));
if ($courierStatus === 'Not Reached' && $courierReason === '') {
    sendResponse(false, 'Reason is required when Courier Status is Not Reached.', [], [], 400);
}
if ($courierStatus !== 'Not Reached') {
    $courierReason = '';
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

try {
    $conn->begin_transaction();
    $fetch = $conn->prepare('SELECT * FROM courier_requests WHERE id = ? FOR UPDATE');
    if (!$fetch) {
        throw new RuntimeException('Unable to prepare courier request lookup.');
    }
    $fetch->bind_param('i', $id);
    if (!$fetch->execute()) {
        $fetch->close();
        throw new RuntimeException('Unable to load courier request.');
    }
    $selected = $fetch->get_result()->fetch_assoc();
    $fetch->close();
    if (!$selected) {
        throw new RuntimeException('Courier request not found.');
    }
    if ($selected['approval_status'] === 'Rejected') {
        throw new RuntimeException('Courier tracking details cannot be updated for rejected requests.');
    }
    if ($courierStatus === 'Reached' && $selected['approval_status'] !== 'Approved') {
        throw new RuntimeException('A courier must be approved before it can be marked Reached.');
    }

    $requestCode = trim((string) ($selected['request_code'] ?? ''));
    if ($requestCode !== '') {
        $group = $conn->prepare('SELECT * FROM courier_requests WHERE request_code = ? ORDER BY id FOR UPDATE');
        if (!$group) {
            throw new RuntimeException('Unable to prepare courier group lookup.');
        }
        $group->bind_param('s', $requestCode);
    } else {
        $group = $conn->prepare('SELECT * FROM courier_requests WHERE id = ? FOR UPDATE');
        if (!$group) {
            throw new RuntimeException('Unable to prepare courier request group lookup.');
        }
        $group->bind_param('i', $id);
    }
    if (!$group->execute()) {
        $group->close();
        throw new RuntimeException('Unable to load the courier request group.');
    }
    $rows = $group->get_result()->fetch_all(MYSQLI_ASSOC);
    $group->close();
    if (!$rows) {
        throw new RuntimeException('Courier request group not found.');
    }
    foreach ($rows as $row) {
        if ($row['approval_status'] === 'Rejected') {
            throw new RuntimeException('Courier tracking details cannot be updated for rejected requests.');
        }
        if ($courierStatus === 'Reached' && $row['approval_status'] !== 'Approved') {
            throw new RuntimeException('All courier requests in the group must be approved before marking them Reached.');
        }
    }

    $update = $conn->prepare(
        'UPDATE courier_requests
         SET courier_send_via = ?, tracking_id = ?, courier_status = ?, courier_reason = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE request_code = ?'
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare courier tracking update.');
    }
    if ($requestCode !== '') {
        $update->bind_param('sssss', $courierSendVia, $trackingId, $courierStatus, $courierReason, $requestCode);
    } else {
        $update->close();
        $update = $conn->prepare(
            'UPDATE courier_requests
             SET courier_send_via = ?, tracking_id = ?, courier_status = ?, courier_reason = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        if (!$update) {
            throw new RuntimeException('Unable to prepare courier tracking update.');
        }
        $update->bind_param('ssssi', $courierSendVia, $trackingId, $courierStatus, $courierReason, $id);
    }
    if (!$update->execute()) {
        $update->close();
        throw new RuntimeException('Failed to update courier tracking information.');
    }
    $update->close();

    foreach ($rows as $row) {
        writeChangedFields($conn, (int) $row['id'], 'Courier', $row, [
            'courier_send_via' => $courierSendVia,
            'tracking_id' => $trackingId,
            'courier_status' => $courierStatus,
            'courier_reason' => $courierReason
        ], $currentUser);
    }

    $verificationSubmissionId = 0;
    if ($courierStatus === 'Reached' && ($rows[0]['courier_to_person'] ?? '') === 'Customer') {
        foreach ($rows as $row) {
            $stored = json_decode((string) ($row['new_customer_data'] ?? ''), true);
            $verificationSubmissionId = max(
                $verificationSubmissionId,
                (int) ($stored['_verification']['submission_id'] ?? 0)
            );
        }
        if ($verificationSubmissionId <= 0) {
            $submitterUserId = (int) ($rows[0]['requested_by_user_id'] ?? 0);
            $verificationSubmissionId = createCourierCustomerVerification(
                $conn,
                $rows,
                $submitterUserId > 0 ? $submitterUserId : $userId
            );
        }
    }

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Courier tracking information updated successfully.', [
        'id' => $id,
        'courier_send_via' => $courierSendVia,
        'tracking_id' => $trackingId,
        'courier_status' => $courierStatus,
        'courier_reason' => $courierReason,
        'verification_submission_id' => $verificationSubmissionId ?: null
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 500);
}
