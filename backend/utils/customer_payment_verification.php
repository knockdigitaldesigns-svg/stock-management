<?php

require_once __DIR__ . '/../middleware/auth.php';

const CUSTOMER_PAYMENT_VERIFICATION_PERMISSION = 'customer_payment_verification.review';

function requireCustomerPaymentVerificationReviewer(): array
{
    ensurePermissionDefinitions([CUSTOMER_PAYMENT_VERIFICATION_PERMISSION]);
    $user = authenticate();
    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId <= 0) {
        sendResponse(false, 'Invalid user session.', [], [], 401);
    }

    $conn = (new Database())->getConnection();
    if (!$conn) {
        sendResponse(false, 'Database connection failed.', [], [], 500);
    }
    $stmt = $conn->prepare(
        'SELECT COALESCE(r.role_name, u.role) AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        $conn->close();
        sendResponse(false, 'Unable to verify reviewer role.', [], [], 500);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $roleName = (string) ($stmt->get_result()->fetch_assoc()['role_name'] ?? '');
    $stmt->close();
    $conn->close();

    $role = strtolower(trim($roleName));
    $isAdmin = in_array($role, ['admin', 'super admin', 'super_admin'], true);
    if (!$isAdmin || !userHasPermission($userId, CUSTOMER_PAYMENT_VERIFICATION_PERMISSION)) {
        sendResponse(false, 'Only authorized Admin or Super Admin users can review customer payments.', [], [], 403);
    }

    return $user;
}

function requireSuperAdminCustomerPaymentVerificationReviewer(): array
{
    $user = requireCustomerPaymentVerificationReviewer();
    $userId = (int) ($user['user_id'] ?? 0);
    if (!$userId || !isSuperAdminUser($userId)) {
        sendResponse(false, 'Only a Super Admin can approve or reject customer payment submissions.', [], [], 403);
    }

    return $user;
}

function insertCustomerPaymentVerificationHistory(
    mysqli $conn,
    int $submissionId,
    ?string $oldStatus,
    string $newStatus,
    ?string $reason,
    int $changedBy
): void {
    $stmt = $conn->prepare(
        'INSERT INTO customer_payment_verification_history
            (submission_id, old_status, new_status, reason, changed_by_user_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare verification history record.');
    }
    $stmt->bind_param('isssi', $submissionId, $oldStatus, $newStatus, $reason, $changedBy);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to record verification history.');
    }
    $stmt->close();
}

function decodeCustomerPaymentSubmission(string $json): array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('Stored verification submission is invalid.');
    }
    return $data;
}

function assertCustomerVerificationCommitAccess(mysqli $conn, int $submissionId, int $userId): void
{
    if ($submissionId <= 0) {
        throw new RuntimeException('Verification submission ID is required.');
    }
    $stmt = $conn->prepare(
        'SELECT submitter_user_id, reviewer_user_id, status
         FROM customer_payment_verification_submissions
         WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to verify submission access.');
    }
    $stmt->bind_param('i', $submissionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $isOwnerDraft = $row && (int) $row['submitter_user_id'] === $userId && $row['status'] === 'Draft';
    $isReviewerApplying = $row && (int) ($row['reviewer_user_id'] ?? 0) === $userId && $row['status'] === 'Applying';
    if (!$isOwnerDraft && !$isReviewerApplying) {
        throw new RuntimeException('This verification submission is not available for commit.');
    }
}
