<?php

function getInventoryStatusDefinition(string $assetType): array
{
    $definitions = [
        'sim' => [
            'table' => 'sims',
            'asset_column' => 'sim_id',
            'label' => 'SIM',
            'customer_match' => '(cvd.sim_id_1 = asset.id OR cvd.sim_id_2 = asset.id OR BINARY cvd.sim_no_1 = BINARY asset.sim_no OR BINARY cvd.sim_no_2 = BINARY asset.sim_no)',
            'customer_assignment_sql' => 'SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.sim_id_1 = ? OR cvd.sim_id_2 = ? OR BINARY cvd.sim_no_1 = BINARY ? OR BINARY cvd.sim_no_2 = BINARY ? LIMIT 1',
            'courier_column' => 'sim_id',
        ],
        'device' => [
            'table' => 'devices',
            'asset_column' => 'device_id',
            'label' => 'Device',
            'customer_match' => '(cvd.device_id = asset.id OR BINARY cvd.imei_no = BINARY asset.imei_no)',
            'customer_assignment_sql' => 'SELECT 1 FROM customer_vehicle_details cvd WHERE cvd.device_id = ? OR BINARY cvd.imei_no = BINARY ? LIMIT 1',
            'courier_column' => 'device_id',
        ],
    ];

    if (!isset($definitions[$assetType])) {
        throw new InvalidArgumentException('Unsupported inventory asset type.');
    }

    return $definitions[$assetType];
}

function normalizeInventoryStatus(string $status, string $assetType): string
{
    $status = strtolower(trim($status));
    if ($status === 'active') {
        return 'available';
    }
    if ($status === 'deactive' || $status === 'expired') {
        return 'used';
    }
    if ($assetType === 'sim' && $status === 'safe custody') {
        return 'allocated';
    }

    return $status !== '' ? $status : 'available';
}

function lockInventoryStatusContext(mysqli $conn, string $assetType, int $assetId): ?array
{
    $definition = getInventoryStatusDefinition($assetType);
    $table = $definition['table'];
    $assetColumn = $definition['asset_column'];
    $customerMatch = $definition['customer_match'];
    $usageMatch = "
        EXISTS (
            SELECT 1
            FROM stock_transactions st
            WHERE st.{$assetColumn} = asset.id
              AND st.from_owner_type = sa.owner_type
              AND st.from_owner_id = sa.owner_id
              AND st.id = (
                  SELECT MAX(st_latest.id)
                  FROM stock_transactions st_latest
                  WHERE st_latest.{$assetColumn} = asset.id
                    AND st_latest.from_owner_type = sa.owner_type
                    AND st_latest.from_owner_id = sa.owner_id
              )
              AND st.transaction_type = 'USE'
        )";
    $sql = "
        SELECT asset.status AS stored_status,
               CASE
                   WHEN EXISTS (
                       SELECT 1
                       FROM customer_vehicle_details cvd
                       WHERE {$customerMatch}
                   ) OR {$usageMatch}
                   THEN 'used'
                   ELSE asset.status
               END AS effective_status
        FROM {$table} asset
        LEFT JOIN stock_allocations sa
            ON sa.{$assetColumn} = asset.id
           AND sa.id = (
               SELECT MAX(sa_latest.id)
               FROM stock_allocations sa_latest
               WHERE sa_latest.{$assetColumn} = asset.id
           )
        WHERE asset.id = ? AND asset.deleted_at IS NULL
        LIMIT 1
        FOR UPDATE";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inventory status lookup: ' . $conn->error);
    }
    $stmt->bind_param('i', $assetId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load current inventory status: ' . $error);
    }

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    return [
        'stored_status' => strtolower(trim((string) ($row['stored_status'] ?? 'available'))),
        'effective_status' => normalizeInventoryStatus((string) ($row['effective_status'] ?? 'available'), $assetType),
    ];
}

function inventoryStatusChangeBlocker(
    mysqli $conn,
    string $assetType,
    int $assetId,
    string $assetIdentifier,
    string $storedStatus
): ?string {
    $definition = getInventoryStatusDefinition($assetType);
    $assetColumn = $definition['asset_column'];
    $label = $definition['label'];
    $assignmentStmt = $conn->prepare($definition['customer_assignment_sql']);
    if (!$assignmentStmt) {
        throw new RuntimeException('Unable to prepare customer assignment check: ' . $conn->error);
    }

    if ($assetType === 'sim') {
        $assignmentStmt->bind_param('iiss', $assetId, $assetId, $assetIdentifier, $assetIdentifier);
    } else {
        $assignmentStmt->bind_param('is', $assetId, $assetIdentifier);
    }
    if (!$assignmentStmt->execute()) {
        $error = $assignmentStmt->error;
        $assignmentStmt->close();
        throw new RuntimeException('Unable to check customer assignments: ' . $error);
    }
    $hasCustomerAssignment = (bool) $assignmentStmt->get_result()->fetch_assoc();
    $assignmentStmt->close();
    if ($hasCustomerAssignment) {
        return "Status cannot be changed because this {$label} is assigned to a customer vehicle. Remove the assignment before changing its status.";
    }

    $allocationStmt = $conn->prepare("
        SELECT sa.owner_type, sa.software,
               (
                   SELECT st.transaction_type
                   FROM stock_transactions st
                   WHERE st.{$assetColumn} = ?
                     AND st.from_owner_type = sa.owner_type
                     AND st.from_owner_id = sa.owner_id
                   ORDER BY st.id DESC
                   LIMIT 1
               ) AS latest_transaction_type
        FROM stock_allocations sa
        WHERE sa.{$assetColumn} = ?
        ORDER BY sa.id DESC
        LIMIT 1");
    if (!$allocationStmt) {
        throw new RuntimeException('Unable to prepare stock assignment check: ' . $conn->error);
    }
    $allocationStmt->bind_param('ii', $assetId, $assetId);
    if (!$allocationStmt->execute()) {
        $error = $allocationStmt->error;
        $allocationStmt->close();
        throw new RuntimeException('Unable to check stock assignments: ' . $error);
    }
    $allocation = $allocationStmt->get_result()->fetch_assoc();
    $allocationStmt->close();
    $returnedToAvailableStock = $allocation
        && strtoupper((string) ($allocation['latest_transaction_type'] ?? '')) === 'RETURN'
        && strtolower($storedStatus) === 'available';
    if ($allocation && !$returnedToAvailableStock) {
        if (strtoupper((string) ($allocation['latest_transaction_type'] ?? '')) === 'USE') {
            return "Status cannot be changed because this {$label} is actively in use by its current holder. Return the item before changing its status.";
        }
        if (trim((string) ($allocation['software'] ?? '')) !== '') {
            return "Status cannot be changed because this {$label} is assigned to a dealer or technician with software. Release the assignment before changing its status.";
        }
        return "Status cannot be changed because this {$label} is allocated to a dealer or technician. Return the item before changing its status.";
    }

    $courierStmt = $conn->prepare("
        SELECT id
        FROM courier_requests
        WHERE {$definition['courier_column']} = ?
          AND (
              approval_status = 'Pending Approval'
              OR (approval_status = 'Approved' AND courier_status <> 'Reached')
          )
        LIMIT 1");
    if (!$courierStmt) {
        throw new RuntimeException('Unable to prepare courier reservation check: ' . $conn->error);
    }
    $courierStmt->bind_param('i', $assetId);
    if (!$courierStmt->execute()) {
        $error = $courierStmt->error;
        $courierStmt->close();
        throw new RuntimeException('Unable to check courier reservations: ' . $error);
    }
    $hasPendingCourier = (bool) $courierStmt->get_result()->fetch_assoc();
    $courierStmt->close();
    if ($hasPendingCourier) {
        return "Status cannot be changed because this {$label} is reserved for an active courier request. Resolve the courier request first.";
    }

    return null;
}

?>
