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

function customerVerificationCount(mysqli $conn, string $sql, string $types, array $values): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to verify dependent customer records: ' . $conn->error);
    }
    if ($types !== '') {
        $bindings = [$types];
        foreach ($values as $index => $value) {
            $bindings[] = &$values[$index];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindings);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to verify dependent customer records: ' . $error);
    }
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

function restoreCustomerVerificationSnapshot(mysqli $conn, int $customerId, array $submissionData): void
{
    if (empty($submissionData['customer_changed'])) {
        return;
    }
    $original = $submissionData['_rejection_snapshot']['customer'] ?? null;
    if (!is_array($original)) {
        throw new RuntimeException('Customer details cannot be safely restored because this applying submission has no rollback snapshot.');
    }
    $submitted = $submissionData['customer'] ?? $submissionData['step1'] ?? null;
    if (!is_array($submitted)) {
        throw new RuntimeException('Customer details cannot be safely restored because the submitted values are missing.');
    }

    $columns = [
        'platform_id' => (int) ($original['platform_id'] ?? 0),
        'username' => (string) ($original['username'] ?? ''),
        'primary_mobile_no' => (string) ($original['primary_mobile_no'] ?? ''),
        'secondary_mobile_no' => $original['secondary_mobile_no'] ?? null,
        'email' => $original['email'] ?? null,
        'location' => (string) ($original['location'] ?? ''),
        'pincode' => (string) ($original['pincode'] ?? ''),
        'status' => (string) ($original['status'] ?? 'Active'),
        'updated_at' => $original['updated_at'] ?? null
    ];
    $expected = [
        'platform_id' => (int) ($submitted['platform_id'] ?? 0),
        'username' => (string) ($submitted['username'] ?? ''),
        'primary_mobile_no' => (string) ($submitted['primary_mobile_no'] ?? ''),
        'secondary_mobile_no' => ($submitted['secondary_mobile_no'] ?? '') !== '' ? $submitted['secondary_mobile_no'] : null,
        'email' => ($submitted['email'] ?? '') !== '' ? $submitted['email'] : null,
        'location' => (string) ($submitted['location'] ?? ''),
        'pincode' => (string) ($submitted['pincode'] ?? ''),
        'status' => (string) ($submitted['status'] ?? 'Active')
    ];
    $originalProfile = $columns;
    unset($originalProfile['updated_at']);

    $stmt = $conn->prepare(
        'SELECT platform_id, username, primary_mobile_no, secondary_mobile_no,
                email, location, pincode, status, updated_at
         FROM customers WHERE id = ? FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to lock customer details before rejection.');
    }
    $stmt->bind_param('i', $customerId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load customer details before rejection: ' . $error);
    }
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$current) {
        throw new RuntimeException('The existing customer record is missing and cannot be restored.');
    }

    $matches = static function (array $left, array $right): bool {
        foreach ($right as $column => $value) {
            $leftValue = $left[$column] ?? null;
            if ($leftValue === null || $value === null) {
                if ($leftValue !== $value) {
                    return false;
                }
            } elseif ((string) $leftValue !== (string) $value) {
                return false;
            }
        }
        return true;
    };
    if ($matches($current, $originalProfile)
        && (string) ($current['updated_at'] ?? '') === (string) ($columns['updated_at'] ?? '')) {
        return;
    }
    if (!$matches($current, $expected) && !$matches($current, $originalProfile)) {
        throw new RuntimeException('Customer details changed after this submission started. Rejection was stopped to preserve unrelated edits.');
    }
    if ($matches($current, $originalProfile)) {
        $restoreTimestamp = $conn->prepare('UPDATE customers SET updated_at = ? WHERE id = ?');
        if (!$restoreTimestamp) {
            throw new RuntimeException('Unable to prepare customer timestamp restoration.');
        }
        $restoreTimestamp->bind_param('si', $columns['updated_at'], $customerId);
        if (!$restoreTimestamp->execute()) {
            $error = $restoreTimestamp->error;
            $restoreTimestamp->close();
            throw new RuntimeException('Unable to restore the existing customer update timestamp: ' . $error);
        }
        $restoreTimestamp->close();
        return;
    }

    $restore = $conn->prepare(
        'UPDATE customers
         SET platform_id = ?, username = ?, primary_mobile_no = ?,
             secondary_mobile_no = ?, email = ?, location = ?, pincode = ?,
             status = ?, updated_at = ?
         WHERE id = ?'
    );
    if (!$restore) {
        throw new RuntimeException('Unable to prepare customer detail restoration.');
    }
    $restore->bind_param(
        'issssssssi',
        $columns['platform_id'],
        $columns['username'],
        $columns['primary_mobile_no'],
        $columns['secondary_mobile_no'],
        $columns['email'],
        $columns['location'],
        $columns['pincode'],
        $columns['status'],
        $columns['updated_at'],
        $customerId
    );
    if (!$restore->execute()) {
        $error = $restore->error;
        $restore->close();
        throw new RuntimeException('Unable to restore existing customer details: ' . $error);
    }
    $restore->close();
}

function rejectCustomerPaymentVerificationApplication(
    mysqli $conn,
    int $submissionId,
    int $existingCustomerId,
    int $linkedCustomerId,
    int $linkedVehicleId,
    array $submissionData
): void {
    $customerId = $existingCustomerId > 0 ? $existingCustomerId : $linkedCustomerId;
    $taggedCustomerStmt = $conn->prepare(
        'SELECT id FROM customers WHERE verification_submission_id = ? FOR UPDATE'
    );
    if (!$taggedCustomerStmt) {
        throw new RuntimeException('Unable to check customer records created by this submission.');
    }
    $taggedCustomerStmt->bind_param('i', $submissionId);
    if (!$taggedCustomerStmt->execute()) {
        $error = $taggedCustomerStmt->error;
        $taggedCustomerStmt->close();
        throw new RuntimeException('Unable to check customer records created by this submission: ' . $error);
    }
    $taggedCustomer = $taggedCustomerStmt->get_result()->fetch_assoc();
    $taggedCustomerStmt->close();
    $newCustomerId = (int) ($taggedCustomer['id'] ?? 0);

    if ($newCustomerId > 0) {
        if ($customerId > 0 && $customerId !== $newCustomerId) {
            throw new RuntimeException('The customer created by this submission does not match its linked customer.');
        }
        $customerId = $newCustomerId;
    }
    if ($customerId <= 0) {
        $taggedVehicleCustomer = customerVerificationCount(
            $conn,
            'SELECT COUNT(DISTINCT customer_id) AS total FROM customer_vehicle_details WHERE verification_submission_id = ?',
            'i',
            [$submissionId]
        );
        if ($taggedVehicleCustomer > 1) {
            throw new RuntimeException('The submission vehicles belong to multiple customers; rejection requires manual reconciliation.');
        }
        $stmt = $conn->prepare(
            'SELECT customer_id FROM customer_vehicle_details
             WHERE verification_submission_id = ? LIMIT 1 FOR UPDATE'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to resolve the customer for this submission.');
        }
        $stmt->bind_param('i', $submissionId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to resolve the customer for this submission: ' . $error);
        }
        $customerId = (int) ($stmt->get_result()->fetch_assoc()['customer_id'] ?? 0);
        $stmt->close();
    }

    if ($customerId > 0 && $existingCustomerId > 0 && $linkedCustomerId > 0 && $linkedCustomerId !== $existingCustomerId) {
        throw new RuntimeException('The linked customer does not match the existing customer in this submission.');
    }

    $existingVehicleEdits = [];
    $vehicles = is_array($submissionData['vehicles'] ?? null)
        ? $submissionData['vehicles']
        : (!empty($submissionData['vehicle']) ? [$submissionData['vehicle']] : []);
    foreach ($vehicles as $vehicle) {
        if (!is_array($vehicle)) {
            continue;
        }
        $changedVehicle = !empty($vehicle['changed']['vehicle'])
            || (!isset($submissionData['vehicles']) && !empty($submissionData['vehicle']));
        $vehicleId = (int) ($vehicle['vehicle_record_id'] ?? $vehicle['id'] ?? 0);
        if ($changedVehicle && $vehicleId > 0) {
            $existingVehicleEdits[] = $vehicleId;
        }
    }

    $vehiclesStmt = $conn->prepare(
        'SELECT id, device_id, sim_id_1, sim_id_2
         FROM customer_vehicle_details
         WHERE verification_submission_id = ? AND customer_id = ?
         ORDER BY id FOR UPDATE'
    );
    if (!$vehiclesStmt) {
        throw new RuntimeException('Unable to lock vehicle records created by this submission.');
    }
    $vehiclesStmt->bind_param('ii', $submissionId, $customerId);
    if (!$vehiclesStmt->execute()) {
        $error = $vehiclesStmt->error;
        $vehiclesStmt->close();
        throw new RuntimeException('Unable to load vehicle records created by this submission: ' . $error);
    }
    $result = $vehiclesStmt->get_result();
    $vehiclesToRemove = [];
    while ($vehicle = $result->fetch_assoc()) {
        $vehiclesToRemove[] = $vehicle;
    }
    $vehiclesStmt->close();

    $ownedVehicleIds = array_map(static fn(array $vehicle): int => (int) $vehicle['id'], $vehiclesToRemove);
    if ($linkedVehicleId > 0 && !in_array($linkedVehicleId, $ownedVehicleIds, true) && in_array($linkedVehicleId, $existingVehicleEdits, true)) {
        throw new RuntimeException('This submission modified an existing approved vehicle. Its device and SIM history cannot be safely rolled back automatically.');
    }
    foreach ($existingVehicleEdits as $vehicleId) {
        if (!in_array($vehicleId, $ownedVehicleIds, true)) {
            throw new RuntimeException('This submission modified an existing approved vehicle. Its device and SIM history cannot be safely rolled back automatically.');
        }
    }
    if ($linkedVehicleId > 0 && !in_array($linkedVehicleId, $ownedVehicleIds, true)) {
        $linkedStmt = $conn->prepare(
            'SELECT id FROM customer_vehicle_details WHERE id = ? AND customer_id = ? FOR UPDATE'
        );
        if (!$linkedStmt) {
            throw new RuntimeException('Unable to verify the linked vehicle before rejection.');
        }
        $linkedStmt->bind_param('ii', $linkedVehicleId, $customerId);
        if (!$linkedStmt->execute()) {
            $error = $linkedStmt->error;
            $linkedStmt->close();
            throw new RuntimeException('Unable to verify the linked vehicle before rejection: ' . $error);
        }
        $linkedVehicleExists = $linkedStmt->get_result()->num_rows > 0;
        $linkedStmt->close();
        if (!$linkedVehicleExists) {
            throw new RuntimeException('The linked vehicle belongs to a different customer or no longer exists.');
        }
    }

    foreach ($vehiclesToRemove as $vehicle) {
        $vehicleId = (int) $vehicle['id'];
        if (customerVerificationCount(
            $conn,
            'SELECT COUNT(*) AS total FROM crm_lead_closure_forms WHERE customer_vehicle_id = ?',
            'i',
            [$vehicleId]
        ) > 0 || customerVerificationCount(
            $conn,
            'SELECT COUNT(*) AS total FROM support_tickets WHERE vehicle_id = ?',
            'i',
            [$vehicleId]
        ) > 0) {
            throw new RuntimeException('A pending vehicle has operational records that are not safe to remove. Rejection was rolled back.');
        }

        $installationStmt = $conn->prepare(
            'SELECT id FROM customer_installations WHERE customer_id = ? AND vehicle_id = ? FOR UPDATE'
        );
        if (!$installationStmt) {
            throw new RuntimeException('Unable to verify pending vehicle installations.');
        }
        $installationStmt->bind_param('ii', $customerId, $vehicleId);
        if (!$installationStmt->execute()) {
            $error = $installationStmt->error;
            $installationStmt->close();
            throw new RuntimeException('Unable to verify pending vehicle installations: ' . $error);
        }
        $installationResult = $installationStmt->get_result();
        $installationIds = [];
        while ($installation = $installationResult->fetch_assoc()) {
            $installationIds[] = (int) $installation['id'];
        }
        $installationStmt->close();
        foreach ($installationIds as $installationId) {
            if (customerVerificationCount(
                $conn,
                'SELECT COUNT(*) AS total FROM crm_lead_closure_forms WHERE customer_installation_id = ?',
                'i',
                [$installationId]
            ) > 0 || customerVerificationCount(
                $conn,
                'SELECT COUNT(*) AS total FROM customer_cash_collections WHERE installation_id = ?',
                'i',
                [$installationId]
            ) > 0) {
                throw new RuntimeException('A pending installation is referenced by operational records. Rejection was rolled back.');
            }
        }

        $renewalStmt = $conn->prepare(
            'SELECT id FROM customer_renewals WHERE customer_id = ? AND vehicle_id = ? FOR UPDATE'
        );
        if (!$renewalStmt) {
            throw new RuntimeException('Unable to verify pending vehicle renewal records.');
        }
        $renewalStmt->bind_param('ii', $customerId, $vehicleId);
        if (!$renewalStmt->execute()) {
            $error = $renewalStmt->error;
            $renewalStmt->close();
            throw new RuntimeException('Unable to verify pending vehicle renewal records: ' . $error);
        }
        $renewalResult = $renewalStmt->get_result();
        $renewalIds = [];
        while ($renewal = $renewalResult->fetch_assoc()) {
            $renewalIds[] = (int) $renewal['id'];
        }
        $renewalStmt->close();
        foreach ($renewalIds as $renewalId) {
            if (customerVerificationCount(
                $conn,
                'SELECT COUNT(*) AS total FROM renewal_history WHERE renewal_id = ?',
                'i',
                [$renewalId]
            ) > 0) {
                throw new RuntimeException('A pending vehicle renewal has history and cannot be safely removed.');
            }
            $deleteRenewal = $conn->prepare('DELETE FROM customer_renewals WHERE id = ? AND customer_id = ? AND vehicle_id = ?');
            if (!$deleteRenewal) {
                throw new RuntimeException('Unable to prepare pending renewal cleanup.');
            }
            $deleteRenewal->bind_param('iii', $renewalId, $customerId, $vehicleId);
            if (!$deleteRenewal->execute()) {
                $error = $deleteRenewal->error;
                $deleteRenewal->close();
                throw new RuntimeException('Unable to remove pending renewal records: ' . $error);
            }
            $deleteRenewal->close();
        }

        $paymentStmt = $conn->prepare(
            'SELECT id FROM customer_payments WHERE customer_id = ? AND vehicle_id = ? FOR UPDATE'
        );
        if (!$paymentStmt) {
            throw new RuntimeException('Unable to verify pending vehicle payment records.');
        }
        $paymentStmt->bind_param('ii', $customerId, $vehicleId);
        if (!$paymentStmt->execute()) {
            $error = $paymentStmt->error;
            $paymentStmt->close();
            throw new RuntimeException('Unable to verify pending vehicle payment records: ' . $error);
        }
        $paymentResult = $paymentStmt->get_result();
        $paymentIds = [];
        while ($payment = $paymentResult->fetch_assoc()) {
            $paymentIds[] = (int) $payment['id'];
        }
        $paymentStmt->close();
        foreach ($paymentIds as $paymentId) {
            if (customerVerificationCount(
                $conn,
                'SELECT COUNT(*) AS total FROM customer_cash_collections WHERE payment_id = ?',
                'i',
                [$paymentId]
            ) > 0) {
                throw new RuntimeException('A pending vehicle payment has cash collection records and cannot be safely removed.');
            }
            $deletePayment = $conn->prepare('DELETE FROM customer_payments WHERE id = ? AND customer_id = ? AND vehicle_id = ?');
            if (!$deletePayment) {
                throw new RuntimeException('Unable to prepare pending payment cleanup.');
            }
            $deletePayment->bind_param('iii', $paymentId, $customerId, $vehicleId);
            if (!$deletePayment->execute()) {
                $error = $deletePayment->error;
                $deletePayment->close();
                throw new RuntimeException('Unable to remove pending payment records: ' . $error);
            }
            $deletePayment->close();
        }

        foreach ($installationIds as $installationId) {
            $deleteInstallation = $conn->prepare(
                'DELETE FROM customer_installations WHERE id = ? AND customer_id = ? AND vehicle_id = ?'
            );
            if (!$deleteInstallation) {
                throw new RuntimeException('Unable to prepare pending installation cleanup.');
            }
            $deleteInstallation->bind_param('iii', $installationId, $customerId, $vehicleId);
            if (!$deleteInstallation->execute()) {
                $error = $deleteInstallation->error;
                $deleteInstallation->close();
                throw new RuntimeException('Unable to remove pending installation records: ' . $error);
            }
            $deleteInstallation->close();
        }

        $deleteVehicle = $conn->prepare(
            'DELETE FROM customer_vehicle_details
             WHERE id = ? AND customer_id = ? AND verification_submission_id = ?'
        );
        if (!$deleteVehicle) {
            throw new RuntimeException('Unable to prepare pending vehicle cleanup.');
        }
        $deleteVehicle->bind_param('iii', $vehicleId, $customerId, $submissionId);
        if (!$deleteVehicle->execute() || $deleteVehicle->affected_rows !== 1) {
            $error = $deleteVehicle->error;
            $deleteVehicle->close();
            throw new RuntimeException('Unable to remove pending vehicle details: ' . $error);
        }
        $deleteVehicle->close();
    }

    if ($customerId > 0 && $existingCustomerId > 0) {
        restoreCustomerVerificationSnapshot($conn, $customerId, $submissionData);
        return;
    }
    if ($newCustomerId <= 0) {
        return;
    }

    $customerReferences = [
        'SELECT COUNT(*) AS total FROM customer_vehicle_details WHERE customer_id = ?',
        'SELECT COUNT(*) AS total FROM customer_installations WHERE customer_id = ?',
        'SELECT COUNT(*) AS total FROM customer_payments WHERE customer_id = ?',
        'SELECT COUNT(*) AS total FROM customer_renewals WHERE customer_id = ?',
        'SELECT COUNT(*) AS total FROM customer_cash_collections WHERE customer_id = ?',
        'SELECT COUNT(*) AS total FROM customer_cash_settlements WHERE customer_id = ?',
        'SELECT COUNT(*) AS total FROM cash_collection_settlement_allocations WHERE customer_id = ?',
        'SELECT COUNT(*) AS total FROM courier_requests WHERE customer_id = ?',
        'SELECT COUNT(*) AS total FROM crm_lead_closure_forms WHERE customer_id = ?',
        'SELECT COUNT(*) AS total FROM support_tickets WHERE customer_id = ?'
    ];
    foreach ($customerReferences as $sql) {
        if (customerVerificationCount($conn, $sql, 'i', [$customerId]) > 0) {
            throw new RuntimeException('The new customer has records outside the pending submission. Rejection was rolled back to protect shared or operational data.');
        }
    }
    if (customerVerificationCount(
        $conn,
        "SELECT COUNT(*) AS total FROM customer_payment_verification_submissions
         WHERE linked_customer_id = ? AND id <> ? AND status IN ('Applying', 'Approved', 'Committed')",
        'ii',
        [$customerId, $submissionId]
    ) > 0) {
        throw new RuntimeException('The new customer is linked to another active or approved submission and cannot be removed.');
    }
    $deleteCustomer = $conn->prepare(
        'DELETE FROM customers WHERE id = ? AND verification_submission_id = ?'
    );
    if (!$deleteCustomer) {
        throw new RuntimeException('Unable to prepare removal of the new customer record.');
    }
    $deleteCustomer->bind_param('ii', $customerId, $submissionId);
    if (!$deleteCustomer->execute() || $deleteCustomer->affected_rows !== 1) {
        $error = $deleteCustomer->error;
        $deleteCustomer->close();
        throw new RuntimeException('Unable to remove the customer created by this submission: ' . $error);
    }
    $deleteCustomer->close();
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
