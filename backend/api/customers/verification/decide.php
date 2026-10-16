<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/customer_payment_verification.php';

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
                linked_payment_id, status, reviewer_user_id
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
    $oldStatus = (string) $row['status'];
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
        $newStatus = 'Applying';
        $storedReason = null;
        $message = 'Approval started. Complete the commit steps to finish approval.';
    } else {
        if ($oldStatus === 'Applying') {
            if (!empty($row['existing_customer_id'])) {
                throw new RuntimeException('This approval has started modifying an existing customer. It cannot be rejected automatically without risking approved customer data.');
            }

            $customerStmt = $conn->prepare(
                'SELECT id FROM customers WHERE verification_submission_id = ? FOR UPDATE'
            );
            if (!$customerStmt) {
                throw new RuntimeException('Unable to check customer records created by this submission.');
            }
            $customerStmt->bind_param('i', $submissionId);
            if (!$customerStmt->execute()) {
                $customerStmt->close();
                throw new RuntimeException('Unable to check customer records created by this submission.');
            }
            $customerId = (int) ($customerStmt->get_result()->fetch_assoc()['id'] ?? 0);
            $customerStmt->close();

            if ($customerId > 0) {
                if (!empty($row['linked_customer_id']) && (int) $row['linked_customer_id'] !== $customerId) {
                    throw new RuntimeException('The linked customer does not match this submission. Manual reconciliation is required.');
                }

                $customerVehicleStmt = $conn->prepare(
                    'SELECT COUNT(*) AS total
                     FROM customer_vehicle_details
                     WHERE customer_id = ?
                       AND (verification_submission_id IS NULL OR verification_submission_id <> ?)'
                );
                if (!$customerVehicleStmt) {
                    throw new RuntimeException('Unable to verify customer vehicle ownership.');
                }
                $customerVehicleStmt->bind_param('ii', $customerId, $submissionId);
                $customerVehicleStmt->execute();
                $unrelatedVehicles = (int) ($customerVehicleStmt->get_result()->fetch_assoc()['total'] ?? 0);
                $customerVehicleStmt->close();
                if ($unrelatedVehicles > 0) {
                    throw new RuntimeException('This customer now has vehicle records outside the pending submission. Manual reconciliation is required.');
                }
                if (!empty($row['linked_vehicle_id'])) {
                    $linkedVehicleStmt = $conn->prepare(
                        'SELECT id FROM customer_vehicle_details
                         WHERE id = ? AND customer_id = ? AND verification_submission_id = ?
                         LIMIT 1'
                    );
                    if (!$linkedVehicleStmt) {
                        throw new RuntimeException('Unable to verify the linked vehicle before rejection.');
                    }
                    $linkedVehicleId = (int) $row['linked_vehicle_id'];
                    $linkedVehicleStmt->bind_param('iii', $linkedVehicleId, $customerId, $submissionId);
                    $linkedVehicleStmt->execute();
                    $linkedVehicleFound = $linkedVehicleStmt->get_result()->num_rows === 1;
                    $linkedVehicleStmt->close();
                    if (!$linkedVehicleFound) {
                        throw new RuntimeException('The linked vehicle is not owned by this submission. Manual reconciliation is required.');
                    }
                }

                $paymentStmt = $conn->prepare(
                    'SELECT COUNT(*) AS total
                     FROM customer_payments
                     WHERE customer_id = ?
                        OR vehicle_id IN (SELECT id FROM customer_vehicle_details WHERE customer_id = ?)'
                );
                if (!$paymentStmt) {
                    throw new RuntimeException('Unable to check payments created for this customer.');
                }
                $paymentStmt->bind_param('ii', $customerId, $customerId);
                $paymentStmt->execute();
                $existingPayments = (int) ($paymentStmt->get_result()->fetch_assoc()['total'] ?? 0);
                $paymentStmt->close();
                if ($existingPayments > 0) {
                    throw new RuntimeException('Payment records already exist for this customer. Manual reconciliation is required before rejection.');
                }

                $dependentChecks = [
                    [
                        'sql' => 'SELECT COUNT(*) AS total FROM customer_cash_collections WHERE customer_id = ?',
                        'message' => 'Cash collection records already exist for this customer. Manual reconciliation is required before rejection.'
                    ],
                    [
                        'sql' => 'SELECT COUNT(*) AS total FROM customer_cash_settlements WHERE customer_id = ?',
                        'message' => 'Cash settlement records already exist for this customer. Manual reconciliation is required before rejection.'
                    ],
                    [
                        'sql' => 'SELECT COUNT(*) AS total FROM cash_collection_settlement_allocations WHERE customer_id = ?',
                        'message' => 'Settlement allocation records already exist for this customer. Manual reconciliation is required before rejection.'
                    ],
                    [
                        'sql' => 'SELECT COUNT(*) AS total FROM courier_requests WHERE customer_id = ?',
                        'message' => 'Courier records already refer to this customer. Manual reconciliation is required before rejection.'
                    ],
                    [
                        'sql' => 'SELECT COUNT(*) AS total
                                  FROM crm_lead_closure_forms
                                  WHERE customer_id = ?
                                     OR customer_vehicle_id IN (SELECT id FROM customer_vehicle_details WHERE customer_id = ?)
                                     OR customer_installation_id IN (SELECT id FROM customer_installations WHERE customer_id = ?)',
                        'parameters' => 3,
                        'message' => 'CRM closure records already refer to this customer. Manual reconciliation is required before rejection.'
                    ],
                    [
                        'sql' => 'SELECT COUNT(*) AS total FROM renewal_history WHERE customer_id = ?',
                        'parameters' => 1,
                        'message' => 'Renewal history already exists for this customer. Manual reconciliation is required before rejection.'
                    ]
                ];
                foreach ($dependentChecks as $check) {
                    $dependencyStmt = $conn->prepare($check['sql']);
                    if (!$dependencyStmt) {
                        throw new RuntimeException('Unable to verify related customer records before rejection.');
                    }
                    if ($check['parameters'] === 3) {
                        $dependencyStmt->bind_param('iii', $customerId, $customerId, $customerId);
                    } else {
                        $dependencyStmt->bind_param('i', $customerId);
                    }
                    if (!$dependencyStmt->execute()) {
                        $dependencyStmt->close();
                        throw new RuntimeException('Unable to verify related customer records before rejection.');
                    }
                    $dependentCount = (int) ($dependencyStmt->get_result()->fetch_assoc()['total'] ?? 0);
                    $dependencyStmt->close();
                    if ($dependentCount > 0) {
                        throw new RuntimeException($check['message']);
                    }
                }

                $deleteCustomer = $conn->prepare(
                    'DELETE FROM customers WHERE id = ? AND verification_submission_id = ?'
                );
                if (!$deleteCustomer) {
                    throw new RuntimeException('Unable to remove uncommitted customer records for rejection.');
                }
                $deleteCustomer->bind_param('ii', $customerId, $submissionId);
                if (!$deleteCustomer->execute() || $deleteCustomer->affected_rows !== 1) {
                    $deleteCustomer->close();
                    throw new RuntimeException('Unable to remove uncommitted customer records for rejection.');
                }
                $deleteCustomer->close();
            } else {
                $linkedRecords = [
                    [
                        'id' => (int) ($row['linked_customer_id'] ?? 0),
                        'sql' => 'SELECT id FROM customers WHERE id = ? FOR UPDATE',
                        'message' => 'The customer linked to this submission could not be verified as an uncommitted record.'
                    ],
                    [
                        'id' => (int) ($row['linked_vehicle_id'] ?? 0),
                        'sql' => 'SELECT id FROM customer_vehicle_details WHERE id = ? FOR UPDATE',
                        'message' => 'The vehicle linked to this submission could not be verified as an uncommitted record.'
                    ],
                    [
                        'id' => (int) ($row['linked_payment_id'] ?? 0),
                        'sql' => 'SELECT id FROM customer_payments WHERE id = ? FOR UPDATE',
                        'message' => 'The payment linked to this submission could not be verified as an uncommitted record.'
                    ]
                ];
                foreach ($linkedRecords as $linkedRecord) {
                    if ($linkedRecord['id'] <= 0) {
                        continue;
                    }
                    $linkedStmt = $conn->prepare($linkedRecord['sql']);
                    if (!$linkedStmt) {
                        throw new RuntimeException('Unable to verify linked records before rejection.');
                    }
                    $linkedStmt->bind_param('i', $linkedRecord['id']);
                    if (!$linkedStmt->execute()) {
                        $linkedStmt->close();
                        throw new RuntimeException('Unable to verify linked records before rejection.');
                    }
                    $recordExists = $linkedStmt->get_result()->num_rows > 0;
                    $linkedStmt->close();
                    if ($recordExists) {
                        throw new RuntimeException($linkedRecord['message']);
                    }
                }
            }
        } elseif ($oldStatus !== 'Pending Verification') {
            throw new RuntimeException('Only a pending or safely reversible approval can be rejected.');
        }
        $newStatus = 'Rejected';
        $storedReason = $reason;
        $message = 'Submission rejected.';
    }
    $stmt = $conn->prepare(
        'UPDATE customer_payment_verification_submissions
         SET status = ?, rejection_reason = ?, reviewer_user_id = ?,
             decided_at = IF(? = \'Rejected\', CURRENT_TIMESTAMP, decided_at),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    $stmt->bind_param('ssisi', $newStatus, $storedReason, $reviewerId, $newStatus, $submissionId);
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
