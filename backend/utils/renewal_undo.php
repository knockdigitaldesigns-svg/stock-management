<?php

function renewalUndoSchemaReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    foreach (['previous_state_json', 'result_state_json', 'undone_at', 'undone_by', 'undo_reason'] as $column) {
        $result = $conn->query("SHOW COLUMNS FROM renewal_history LIKE '$column'");
        if (!$result) {
            throw new RuntimeException('Unable to check renewal undo schema: ' . $conn->error);
        }
        if ($result->num_rows === 0) {
            $ready = false;
            return $ready;
        }
    }

    $ready = true;
    return $ready;
}

function requireRenewalUndoSchema(mysqli $conn): void
{
    if (!renewalUndoSchemaReady($conn)) {
        throw new RuntimeException('Undo Renewal is unavailable until the renewal undo database migration has been applied.');
    }
}

function encodeRenewalUndoState(array $state): string
{
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to save the renewal state snapshot.');
    }
    return $json;
}

function renewalUndoStateMatches(array $current, array $expected, array $keys): bool
{
    foreach ($keys as $key) {
        $currentValue = $current[$key] ?? null;
        $expectedValue = $expected[$key] ?? null;
        if ($currentValue === null || $expectedValue === null) {
            if ($currentValue !== $expectedValue) {
                return false;
            }
        } elseif ((string)$currentValue !== (string)$expectedValue) {
            return false;
        }
    }
    return true;
}

function renewalUndoSnapshotHasFields(array $snapshot, array $fields): bool
{
    foreach ($fields as $field) {
        if (!array_key_exists($field, $snapshot)) {
            return false;
        }
    }
    return true;
}

function loadCustomerRenewalUndoState(mysqli $conn, int $renewalId, bool $forUpdate = false): array
{
    $sql = 'SELECT cr.id AS renewal_id, cr.customer_id, cr.vehicle_id, cr.installation_date,
                   cr.next_renewal_date, cr.validity_months, cr.sim_status,
                   cr.expired_to_safe_days, cr.safe_to_deactive_days, cr.last_renewed_date,
                   cr.safe_custody_date
            FROM customer_renewals cr
            WHERE cr.id = ?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare customer renewal state snapshot: ' . $conn->error);
    }
    $stmt->bind_param('i', $renewalId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load customer renewal state snapshot: ' . $error);
    }
    $state = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$state) {
        throw new RuntimeException('Customer renewal record was not found.');
    }
    $installationSql = 'SELECT id AS installation_record_id,
                               installation_date AS linked_installation_date
                        FROM customer_installations
                        WHERE vehicle_id = ? AND customer_id = ?
                        LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $installationStmt = $conn->prepare($installationSql);
    if (!$installationStmt) {
        throw new RuntimeException('Unable to prepare linked installation snapshot: ' . $conn->error);
    }
    $vehicleId = (int)$state['vehicle_id'];
    $customerId = (int)$state['customer_id'];
    $installationStmt->bind_param('ii', $vehicleId, $customerId);
    if (!$installationStmt->execute()) {
        $error = $installationStmt->error;
        $installationStmt->close();
        throw new RuntimeException('Unable to load linked installation snapshot: ' . $error);
    }
    $installation = $installationStmt->get_result()->fetch_assoc();
    $installationStmt->close();
    $state = array_merge($state, $installation ?: [
        'installation_record_id' => null,
        'linked_installation_date' => null
    ]);
    $state['entity'] = 'customer';
    return $state;
}

function customerRenewalUndoStateFields(): array
{
    return [
        'renewal_id',
        'customer_id',
        'vehicle_id',
        'installation_date',
        'next_renewal_date',
        'validity_months',
        'sim_status',
        'expired_to_safe_days',
        'safe_to_deactive_days',
        'last_renewed_date',
        'safe_custody_date',
        'installation_record_id',
        'linked_installation_date'
    ];
}

function loadDealerSimUndoState(mysqli $conn, int $allocationId, bool $forUpdate = false): array
{
    $sql = "SELECT sa.id AS allocation_id, sa.sim_id, sa.owner_id AS dealer_id,
                   sa.sim_activation_date AS activation_date,
                   sa.sim_validity_id AS validity_id,
                   COALESCE(sa.sim_validity_id, s.sim_validity_id) AS effective_validity_id,
                   effective_validity.months AS effective_validity_months,
                   sa.sim_expiry_date AS expiry_date,
                   sa.sim_deactivation_date AS deactivation_date,
                   sa.sim_reactivation_date AS reactivation_date,
                   sa.sim_status AS status
            FROM stock_allocations sa
            INNER JOIN sims s ON s.id = sa.sim_id
            LEFT JOIN sim_validities effective_validity
              ON effective_validity.id = COALESCE(sa.sim_validity_id, s.sim_validity_id)
            WHERE sa.id = ? AND sa.owner_type = 'dealer' AND sa.sim_id IS NOT NULL" .
            ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare dealer SIM state snapshot: ' . $conn->error);
    }
    $stmt->bind_param('i', $allocationId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load dealer SIM state snapshot: ' . $error);
    }
    $state = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$state) {
        throw new RuntimeException('Dealer SIM allocation was not found.');
    }
    $state['entity'] = 'dealer';
    return $state;
}

function dealerSimUndoStateFields(): array
{
    return [
        'allocation_id',
        'sim_id',
        'dealer_id',
        'activation_date',
        'validity_id',
        'effective_validity_id',
        'effective_validity_months',
        'expiry_date',
        'deactivation_date',
        'reactivation_date',
        'status'
    ];
}

function normalizeDealerSimUndoSnapshot(mysqli $conn, array $snapshot): array
{
    if (array_key_exists('effective_validity_id', $snapshot)
        && array_key_exists('effective_validity_months', $snapshot)) {
        return $snapshot;
    }
    if (!array_key_exists('validity_id', $snapshot) || $snapshot['validity_id'] === null) {
        throw new RuntimeException('This renewal snapshot does not contain enough information to restore the effective SIM validity safely.');
    }
    $validityId = (int)$snapshot['validity_id'];
    $stmt = $conn->prepare('SELECT months FROM sim_validities WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare SIM validity snapshot lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $validityId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load SIM validity snapshot: ' . $error);
    }
    $validity = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$validity) {
        throw new RuntimeException('The previous SIM validity no longer exists and cannot be restored safely.');
    }
    $snapshot['effective_validity_id'] = $validityId;
    $snapshot['effective_validity_months'] = (int)$validity['months'];
    return $snapshot;
}
