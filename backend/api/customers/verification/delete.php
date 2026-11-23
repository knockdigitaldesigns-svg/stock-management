<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/customer_payment_verification.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

requireSuperAdminCustomerPaymentVerificationReviewer();
$data = json_decode(file_get_contents('php://input'), true);
$submissionId = is_array($data) ? (int) ($data['submission_id'] ?? 0) : 0;
if ($submissionId <= 0) {
    sendResponse(false, 'A valid verification submission is required.', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    $conn->begin_transaction();
    $stmt = $conn->prepare(
        'SELECT status, submission_data
         FROM customer_payment_verification_submissions
         WHERE id = ?
         FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare verification record lookup.');
    }
    $stmt->bind_param('i', $submissionId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load verification record.');
    }
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$submission) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Verification record not found.', [], [], 404);
    }
    if ($submission['status'] !== 'Rejected') {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Only rejected verification records can be deleted.', [], [], 409);
    }

    $submissionData = decodeCustomerPaymentSubmission((string) $submission['submission_data']);
    if (($submissionData['source'] ?? '') === 'courier') {
        $requestIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($submissionData['courier_request_ids'] ?? null)
                ? $submissionData['courier_request_ids']
                : []),
            static function (int $requestId): bool {
                return $requestId > 0;
            }
        )));
        $requestLookup = $conn->prepare(
            'SELECT new_customer_data
             FROM courier_requests
             WHERE id = ?
             FOR UPDATE'
        );
        $requestUpdate = $conn->prepare(
            'UPDATE courier_requests
             SET new_customer_data = ?
             WHERE id = ?'
        );
        if (!$requestLookup || !$requestUpdate) {
            throw new RuntimeException('Unable to prepare linked courier request cleanup.');
        }
        foreach ($requestIds as $requestId) {
            $requestLookup->bind_param('i', $requestId);
            if (!$requestLookup->execute()) {
                throw new RuntimeException('Unable to load a linked courier request.');
            }
            $request = $requestLookup->get_result()->fetch_assoc();
            if (!$request) {
                throw new RuntimeException('A linked courier request could not be found.');
            }
            $workflow = json_decode((string) ($request['new_customer_data'] ?? ''), true);
            if (!is_array($workflow)) {
                throw new RuntimeException('A linked courier workflow is invalid.');
            }
            if ((int) ($workflow['_verification']['submission_id'] ?? 0) !== $submissionId) {
                continue;
            }
            unset($workflow['_verification']);
            $workflowJson = json_encode($workflow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($workflowJson === false) {
                throw new RuntimeException('Unable to update a linked courier workflow.');
            }
            $requestUpdate->bind_param('si', $workflowJson, $requestId);
            if (!$requestUpdate->execute()) {
                throw new RuntimeException('Unable to clear a linked courier verification reference.');
            }
        }
        $requestLookup->close();
        $requestUpdate->close();
    }

    foreach (['customers', 'customer_vehicle_details'] as $table) {
        $clearReference = $conn->prepare(
            "UPDATE {$table} SET verification_submission_id = NULL WHERE verification_submission_id = ?"
        );
        if (!$clearReference) {
            throw new RuntimeException('Unable to prepare verification reference cleanup.');
        }
        $clearReference->bind_param('i', $submissionId);
        if (!$clearReference->execute()) {
            $clearReference->close();
            throw new RuntimeException('Unable to clear references to the deleted verification record.');
        }
        $clearReference->close();
    }

    $delete = $conn->prepare(
        "DELETE FROM customer_payment_verification_submissions
         WHERE id = ? AND status = 'Rejected'"
    );
    if (!$delete) {
        throw new RuntimeException('Unable to prepare verification record deletion.');
    }
    $delete->bind_param('i', $submissionId);
    if (!$delete->execute() || $delete->affected_rows !== 1) {
        $delete->close();
        throw new RuntimeException('Unable to delete verification record.');
    }
    $delete->close();

    $conn->commit();
    $conn->close();
    sendResponse(true, 'Rejected verification record deleted successfully.', ['submission_id' => $submissionId]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 500);
}
