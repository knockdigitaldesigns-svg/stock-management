<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/customer_payment_verification.php';
require_once '../../../utils/audit.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}
$reviewer = requireSuperAdminCustomerPaymentVerificationReviewer();
$reviewerId = (int) ($reviewer['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true);
$submissionId = (int) ($data['submission_id'] ?? 0);
$action = strtolower(trim((string) ($data['action'] ?? '')));
$reason = trim((string) ($data['reason'] ?? ''));
if ($submissionId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    sendResponse(false, 'A valid submission and decision are required.', [], [], 400);
}
if ($action === 'reject' && $reason === '') {
    sendResponse(false, 'A rejection reason is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
try {
    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'SELECT submitter_user_id, existing_customer_id, linked_customer_id, linked_vehicle_id,
                linked_payment_id, status, reviewer_user_id, submission_data
         FROM customer_payment_verification_submissions
         WHERE id = ? FOR UPDATE'
    );
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('Verification submission not found.');
    }
    $submissionData = decodeCustomerPaymentSubmission((string) $row['submission_data']);
    $isCourierSubmission = ($submissionData['source'] ?? '') === 'courier';
    $courierRequestIds = [];
    if ($isCourierSubmission) {
        foreach (($submissionData['courier_request_ids'] ?? []) as $courierRequestId) {
            $courierRequestId = (int) $courierRequestId;
            if ($courierRequestId > 0) {
                $courierRequestIds[] = $courierRequestId;
            }
        }
        $courierRequestIds = array_values(array_unique($courierRequestIds));
        if (!$courierRequestIds) {
            throw new RuntimeException('Courier verification has no linked courier requests.');
        }
    }
    $oldStatus = (string) $row['status'];
    $submissionJson = null;
    if ($action === 'approve') {
        if ($oldStatus === 'Approved') {
            $conn->commit();
            $conn->close();
            sendResponse(true, 'Submission is already approved.', ['status' => 'Approved']);
        }
        if ($oldStatus === 'Applying') {
            if ((int) ($row['reviewer_user_id'] ?? 0) !== $reviewerId) {
                $transfer = $conn->prepare(
                    "UPDATE customer_payment_verification_submissions
                     SET reviewer_user_id = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND status = 'Applying'"
                );
                if (!$transfer) {
                    throw new RuntimeException('Unable to assign approval to the Super Admin.');
                }
                $transfer->bind_param('ii', $reviewerId, $submissionId);
                if (!$transfer->execute()) {
                    $transfer->close();
                    throw new RuntimeException('Unable to assign approval to the Super Admin.');
                }
                $transfer->close();
                insertCustomerPaymentVerificationHistory(
                    $conn,
                    $submissionId,
                    'Applying',
                    'Applying',
                    'Approval resumed by Super Admin.',
                    $reviewerId
                );
            }
            $conn->commit();
            $conn->close();
            sendResponse(true, 'Approval can be resumed.', ['status' => 'Applying']);
        }
        if ($oldStatus !== 'Pending Verification' && $oldStatus !== 'Applying') {
            throw new RuntimeException('Only a pending submission can be approved.');
        }
        if ($oldStatus === 'Pending Verification' && (int) ($row['existing_customer_id'] ?? 0) > 0) {
            $customerId = (int) $row['existing_customer_id'];
            $customerStmt = $conn->prepare(
                'SELECT id, platform_id, username, primary_mobile_no, secondary_mobile_no,
                        email, location, pincode, status, created_at, updated_at
                 FROM customers WHERE id = ? FOR UPDATE'
            );
            if (!$customerStmt) {
                throw new RuntimeException('Unable to capture existing customer details before approval.');
            }
            $customerStmt->bind_param('i', $customerId);
            if (!$customerStmt->execute()) {
                $error = $customerStmt->error;
                $customerStmt->close();
                throw new RuntimeException('Unable to capture existing customer details before approval: ' . $error);
            }
            $originalCustomer = $customerStmt->get_result()->fetch_assoc();
            $customerStmt->close();
            if (!$originalCustomer) {
                throw new RuntimeException('The existing customer record was not found before approval.');
            }
            $submissionData['_rejection_snapshot']['customer'] = $originalCustomer;
            $submissionJson = json_encode($submissionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($submissionJson === false) {
                throw new RuntimeException('Unable to save the existing customer rollback snapshot.');
            }
        }
        $newStatus = 'Applying';
        $storedReason = null;
        $message = 'Approval started. Complete the commit steps to finish approval.';
    } else {
        if ($oldStatus === 'Applying') {
            if ($isCourierSubmission) {
                $finalizedIds = array_map(
                    'intval',
                    is_array($submissionData['courier_finalized_request_ids'] ?? null)
                        ? $submissionData['courier_finalized_request_ids']
                        : []
                );
                if (array_intersect($courierRequestIds, $finalizedIds)) {
                    throw new RuntimeException('Courier assets have already been committed and this verification cannot be rejected safely. Resume approval instead.');
                }
            }
            rejectCustomerPaymentVerificationApplication(
                $conn,
                $submissionId,
                (int) ($row['existing_customer_id'] ?? 0),
                (int) ($row['linked_customer_id'] ?? 0),
                (int) ($row['linked_vehicle_id'] ?? 0),
                $submissionData
            );
        } elseif ($oldStatus !== 'Pending Verification') {
            throw new RuntimeException('Only a pending or safely reversible approval can be rejected.');
        }
        if ($isCourierSubmission) {
            $idList = implode(',', array_map('intval', $courierRequestIds));
            $requestsStmt = $conn->prepare(
                "SELECT id, device_id, sim_id, approval_status
                 FROM courier_requests WHERE id IN ({$idList}) FOR UPDATE"
            );
            if (!$requestsStmt || !$requestsStmt->execute()) {
                throw new RuntimeException('Unable to lock the linked courier requests for rejection.');
            }
            $courierRows = $requestsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $requestsStmt->close();
            if (count($courierRows) !== count($courierRequestIds)) {
                throw new RuntimeException('A linked courier request could not be found.');
            }
            foreach ($courierRows as $courierRow) {
                if ($courierRow['approval_status'] === 'Approved') {
                    continue;
                }
                if ($courierRow['approval_status'] === 'Pending Approval') {
                    $rejectRequest = $conn->prepare(
                        "UPDATE courier_requests SET approval_status = 'Rejected', updated_at = CURRENT_TIMESTAMP WHERE id = ?"
                    );
                    $courierRequestId = (int) $courierRow['id'];
                    $rejectRequest->bind_param('i', $courierRequestId);
                    if (!$rejectRequest->execute()) {
                        $rejectRequest->close();
                        throw new RuntimeException('Unable to reject a linked courier request.');
                    }
                    $rejectRequest->close();
                    writeAudit(
                        $conn,
                        $courierRequestId,
                        'Courier',
                        'Verification Rejected',
                        'approval_status',
                        'Pending Approval',
                        'Rejected',
                        $reviewer
                    );
                }
            }
            foreach ($courierRows as $courierRow) {
                if ($courierRow['approval_status'] !== 'Pending Approval') {
                    continue;
                }
                foreach ([
                    ['devices', 'device_id', $courierRow['device_id'] ?? null],
                    ['sims', 'sim_id', $courierRow['sim_id'] ?? null]
                ] as [$table, $assetColumn, $assetId]) {
                    $assetId = (int) $assetId;
                    if ($assetId <= 0) {
                        continue;
                    }
                    $assetStmt = $conn->prepare(
                        "SELECT id FROM courier_requests
                         WHERE {$assetColumn} = ? AND approval_status = 'Pending Approval' LIMIT 1"
                    );
                    $assetStmt->bind_param('i', $assetId);
                    if (!$assetStmt->execute()) {
                        $assetStmt->close();
                        throw new RuntimeException('Unable to check remaining asset reservations.');
                    }
                    $hasOtherPendingRequest = $assetStmt->get_result()->num_rows > 0;
                    $assetStmt->close();
                    if (!$hasOtherPendingRequest) {
                        $releaseStmt = $conn->prepare(
                            "UPDATE {$table} SET status = 'available' WHERE id = ? AND status = 'reserved'"
                        );
                        $releaseStmt->bind_param('i', $assetId);
                        if (!$releaseStmt->execute()) {
                            $releaseStmt->close();
                            throw new RuntimeException('Unable to release a rejected courier asset reservation.');
                        }
                        $releaseStmt->close();
                    }
                }
            }
        }
        $newStatus = 'Rejected';
        $storedReason = $reason;
        $message = 'Submission rejected.';
    }
    $stmt = $conn->prepare(
        'UPDATE customer_payment_verification_submissions
         SET status = ?, rejection_reason = ?, reviewer_user_id = ?,
             decided_at = IF(? = \'Rejected\', CURRENT_TIMESTAMP, decided_at),
             submission_data = COALESCE(?, submission_data),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    $stmt->bind_param('ssissi', $newStatus, $storedReason, $reviewerId, $newStatus, $submissionJson, $submissionId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to save verification decision.');
    }
    $stmt->close();
    insertCustomerPaymentVerificationHistory($conn, $submissionId, $oldStatus, $newStatus, $storedReason, $reviewerId);
    $conn->commit();
    $conn->close();
    sendResponse(true, $message, ['status' => $newStatus]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
