<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../middleware/auth.php';
require_once '../../../utils/customer_payment_verification.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requireAnyPermission(['customers.add', 'customers.edit']);
$userId = (int) ($currentUser['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true);
$submissionId = (int) ($data['submission_id'] ?? 0);
$submissionData = $data['submission_data'] ?? null;
if ($submissionId <= 0 || !is_array($submissionData)) {
    sendResponse(false, 'Submission ID and customer payment details are required.', [], [], 400);
}

$paymentCount = 0;
$requiresVerification = false;
$firstVerifiedPayment = null;
$payments = [];
if (is_array($submissionData['vehicles'] ?? null)) {
    foreach ($submissionData['vehicles'] as &$vehicle) {
        if (!empty($vehicle['changed']['paymentPart2']) && is_array($vehicle['payment_part2'] ?? null)) {
            $payments[] = &$vehicle['payment_part2'];
        }
    }
    unset($vehicle);
}
if (!$payments && is_array($submissionData['payment_part2'] ?? null)) {
    $payments[] = &$submissionData['payment_part2'];
}
foreach ($payments as &$payment) {
    $saleAmount = (float) ($payment['total_sale_amount'] ?? 0);
    $totalAmount = (float) ($payment['total_amount'] ?? 0);
    $amountPaid = (float) ($payment['amount_paid'] ?? 0);
    if ($saleAmount <= 0 || $totalAmount <= 0 || abs($totalAmount - $saleAmount) > 0.005) {
        sendResponse(false, 'Total charges must equal Total Sale Amount before verification.', [], [], 400);
    }
    if ($amountPaid < 0 || $amountPaid > $totalAmount) {
        sendResponse(false, 'Amount Paid must be between zero and Total Amount.', [], [], 400);
    }
    $computedStatus = $amountPaid >= $totalAmount
        ? 'Paid'
        : ($amountPaid > 0 ? 'Partially Paid' : 'Not Paid');
    $payment['amount_pending'] = round(max(0, $totalAmount - $amountPaid), 2);
    $payment['payment_status'] = $computedStatus;
    $paymentCount++;
    if (in_array($computedStatus, ['Paid', 'Partially Paid'], true)) {
        $requiresVerification = true;
        $firstVerifiedPayment = $firstVerifiedPayment ?? $payment;
    }
}
unset($payment);
if ($firstVerifiedPayment !== null) {
    $submissionData['payment_part2'] = $firstVerifiedPayment;
}
if ($paymentCount === 0 || !$requiresVerification) {
    sendResponse(false, 'Only submissions with a Paid or Partially Paid Payment Part 2 require verification.', [], [], 400);
}
$submissionJson = json_encode($submissionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($submissionJson === false || strlen($submissionJson) > 2000000) {
    sendResponse(false, 'Verification submission is too large or could not be encoded.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'SELECT submitter_user_id, status
         FROM customer_payment_verification_submissions
         WHERE id = ? FOR UPDATE'
    );
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $draft = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$draft || (int) $draft['submitter_user_id'] !== $userId) {
        throw new RuntimeException('Customer draft was not found for this user.');
    }
    if ($draft['status'] === 'Pending Verification') {
        $conn->commit();
        $conn->close();
        sendResponse(true, 'Payment details are already pending Admin verification.', ['submission_id' => $submissionId, 'status' => 'Pending Verification']);
    }
    if (!in_array($draft['status'], ['Draft', 'Rejected'], true)) {
        throw new RuntimeException('This customer submission cannot be submitted again.');
    }
    $stmt = $conn->prepare(
        "UPDATE customer_payment_verification_submissions
         SET submission_data = ?,
             status = 'Pending Verification',
             submitted_at = CURRENT_TIMESTAMP,
             rejection_reason = NULL,
             reviewer_user_id = NULL,
             decided_at = NULL,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $stmt->bind_param('si', $submissionJson, $submissionId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to submit payment for verification.');
    }
    $stmt->close();
    insertCustomerPaymentVerificationHistory($conn, $submissionId, $draft['status'], 'Pending Verification', null, $userId);
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Payment details submitted successfully for Admin verification.', ['submission_id' => $submissionId, 'status' => 'Pending Verification']);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
