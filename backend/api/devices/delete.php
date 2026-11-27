<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../utils/audit.php';
require_once '../../middleware/auth.php';

function deviceDeleteSelectIds(mysqli $conn, string $sql, string $types, array $values): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare linked device record lookup.');
    }
    if ($values && !$stmt->bind_param($types, ...$values)) {
        $stmt->close();
        throw new RuntimeException('Unable to bind linked device record lookup.');
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load linked device records.');
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static function (array $row): int {
        return (int) reset($row);
    }, $rows);
}

function deviceDeleteRowsByIds(mysqli $conn, string $table, string $column, array $ids): void
{
    $allowed = [
        'stock_allocations' => ['id'],
        'stock_transactions' => ['id'],
        'stock_transfers' => ['id'],
        'courier_requests' => ['id'],
        'customer_vehicle_details' => ['id'],
        'customer_installations' => ['id'],
        'customer_payments' => ['id'],
        'customer_cash_collections' => ['id'],
        'customer_payment_verification_submissions' => ['id'],
        'crm_closure_installation_devices' => ['id'],
        'cash_collection_settlements' => ['id'],
        'history' => ['id']
    ];
    if (!$ids || !in_array($column, $allowed[$table] ?? [], true)) {
        return;
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
        return $id > 0;
    })));
    if (!$ids) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
    if (!$stmt) {
        throw new RuntimeException("Unable to prepare linked {$table} cleanup.");
    }
    $types = str_repeat('i', count($ids));
    if (!$stmt->bind_param($types, ...$ids) || !$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException("Unable to remove linked {$table} records.");
    }
    $stmt->close();
}

function deviceDeleteHistoryRows(mysqli $conn, string $module, array $ids): void
{
    foreach (array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
        return $id > 0;
    })) as $id) {
        $stmt = $conn->prepare('DELETE FROM history WHERE module = ? AND customer_id = ?');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare linked device history cleanup.');
        }
        $stmt->bind_param('si', $module, $id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to remove linked device history.');
        }
        $stmt->close();
    }
}

function deviceRedactHistoryImei(mysqli $conn, string $imei): void
{
    $stmt = $conn->prepare(
        "UPDATE history
         SET old_value = REPLACE(old_value, ?, '[removed]'),
             new_value = REPLACE(new_value, ?, '[removed]')
         WHERE LOCATE(?, old_value) > 0 OR LOCATE(?, new_value) > 0"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare device history redaction.');
    }
    $stmt->bind_param('ssss', $imei, $imei, $imei, $imei);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to redact the deleted device IMEI from history.');
    }
    $stmt->close();
}

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$currentUser = authenticate();
requirePermission('devices.delete');
$isSuperAdmin = isSuperAdminUser((int) ($currentUser['user_id'] ?? 0));

$data = json_decode(file_get_contents('php://input'));
$id = (int) ($data->id ?? 0);
if ($id <= 0) {
    sendResponse(false, 'Device ID is required', [], [], 400);
}

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed', [], [], 500);
}

$conn->begin_transaction();
$stmt = null;

try {
    $checkStmt = $conn->prepare('SELECT * FROM devices WHERE id = ? FOR UPDATE');
    if (!$checkStmt) {
        throw new RuntimeException('Unable to prepare device lookup.');
    }
    $checkStmt->bind_param('i', $id);
    if (!$checkStmt->execute()) {
        throw new RuntimeException('Unable to load device.');
    }
    $device = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$device) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'Device not found', [], [], 404);
    }

    if (!$isSuperAdmin && in_array(strtolower((string) $device['status']), ['allocated', 'used'], true)) {
        $conn->rollback();
        $conn->close();
        sendResponse(false, 'This device is currently ' . $device['status'] . ' and cannot be deleted.', [], [], 400);
    }

    $imei = (string) $device['imei_no'];
    $vehicleIds = deviceDeleteSelectIds(
        $conn,
        'SELECT id FROM customer_vehicle_details
         WHERE device_id = ? OR BINARY imei_no = BINARY ?
         FOR UPDATE',
        'is',
        [$id, $imei]
    );
    $vehiclePlaceholders = implode(',', array_fill(0, count($vehicleIds), '?'));
    $crmDeviceRows = deviceDeleteSelectIds(
        $conn,
        'SELECT id FROM crm_closure_installation_devices
         WHERE device_id = ? OR BINARY imei_no = BINARY ?
         FOR UPDATE',
        'is',
        [$id, $imei]
    );
    $crmRowsStmt = $conn->prepare(
        'SELECT id, closure_id, customer_installation_id, verification_submission_id
         FROM crm_closure_installation_devices
         WHERE id IN (' . ($crmDeviceRows ? implode(',', array_fill(0, count($crmDeviceRows), '?')) : '0') . ')
         FOR UPDATE'
    );
    if (!$crmRowsStmt) {
        throw new RuntimeException('Unable to prepare CRM installation cleanup lookup.');
    }
    if ($crmDeviceRows && !$crmRowsStmt->bind_param(str_repeat('i', count($crmDeviceRows)), ...$crmDeviceRows)) {
        $crmRowsStmt->close();
        throw new RuntimeException('Unable to bind CRM installation cleanup lookup.');
    }
    if (!$crmRowsStmt->execute()) {
        $crmRowsStmt->close();
        throw new RuntimeException('Unable to load CRM installation cleanup records.');
    }
    $crmRows = $crmRowsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $crmRowsStmt->close();
    $crmInstallationIds = array_map(static function (array $row): int {
        return (int) ($row['customer_installation_id'] ?? 0);
    }, $crmRows);
    $verificationIds = array_map(static function (array $row): int {
        return (int) ($row['verification_submission_id'] ?? 0);
    }, $crmRows);
    $legacyInstallationIds = deviceDeleteSelectIds(
        $conn,
        'SELECT customer_installation_id
         FROM crm_lead_closure_forms
         WHERE device_id = ? AND customer_installation_id IS NOT NULL
         FOR UPDATE',
        'i',
        [$id]
    );
    if ($vehicleIds) {
        $installations = deviceDeleteSelectIds(
            $conn,
            "SELECT id FROM customer_installations
             WHERE vehicle_id IN ({$vehiclePlaceholders})
             FOR UPDATE",
            str_repeat('i', count($vehicleIds)),
            $vehicleIds
        );
    } else {
        $installations = [];
    }
    $installationIds = array_values(array_unique(array_merge(
        $installations,
        $crmInstallationIds,
        $legacyInstallationIds
    )));

    $paymentIds = [];
    if ($vehicleIds) {
        $paymentIds = deviceDeleteSelectIds(
            $conn,
            "SELECT id FROM customer_payments WHERE vehicle_id IN ({$vehiclePlaceholders}) FOR UPDATE",
            str_repeat('i', count($vehicleIds)),
            $vehicleIds
        );
    }
    $verificationConditions = [];
    $verificationTypes = '';
    $verificationValues = [];
    if ($vehicleIds) {
        $verificationConditions[] = 'linked_vehicle_id IN (' . $vehiclePlaceholders . ')';
        $verificationTypes .= str_repeat('i', count($vehicleIds));
        array_push($verificationValues, ...$vehicleIds);
    }
    $verificationConditions[] = "JSON_VALID(submission_data)
        AND JSON_SEARCH(
            submission_data,
            'one',
            ?,
            NULL,
            '$.vehicle.imei_no',
            '$.vehicles[*].imei_no',
            '$.step2.imei_no'
        ) IS NOT NULL";
    $verificationTypes .= 's';
    $verificationValues[] = $imei;
    if ($paymentIds) {
        $paymentPlaceholders = implode(',', array_fill(0, count($paymentIds), '?'));
        $verificationConditions[] = "linked_payment_id IN ({$paymentPlaceholders})";
        $verificationTypes .= str_repeat('i', count($paymentIds));
        array_push($verificationValues, ...$paymentIds);
    }
    if ($verificationIds) {
        $verificationConditions[] = 'id IN (' . implode(',', array_fill(0, count($verificationIds), '?')) . ')';
        $verificationTypes .= str_repeat('i', count($verificationIds));
        array_push($verificationValues, ...$verificationIds);
    }
    if ($verificationConditions) {
        $verificationIds = array_values(array_unique(array_merge(
            $verificationIds,
            deviceDeleteSelectIds(
                $conn,
                'SELECT id FROM customer_payment_verification_submissions WHERE ' .
                    implode(' OR ', $verificationConditions) . ' FOR UPDATE',
                $verificationTypes,
                $verificationValues
            )
        )));
    }

    $cashCollectionIds = [];
    $cashConditions = [];
    $cashTypes = '';
    $cashValues = [];
    if ($paymentIds) {
        $paymentPlaceholders = implode(',', array_fill(0, count($paymentIds), '?'));
        $cashConditions[] = "payment_id IN ({$paymentPlaceholders})";
        $cashTypes .= str_repeat('i', count($paymentIds));
        array_push($cashValues, ...$paymentIds);
    }
    if ($installationIds) {
        $installationPlaceholders = implode(',', array_fill(0, count($installationIds), '?'));
        $cashConditions[] = "installation_id IN ({$installationPlaceholders})";
        $cashTypes .= str_repeat('i', count($installationIds));
        array_push($cashValues, ...$installationIds);
    }
    if ($cashConditions) {
        $cashCollectionIds = deviceDeleteSelectIds(
            $conn,
            'SELECT id FROM customer_cash_collections WHERE ' . implode(' OR ', $cashConditions) . ' FOR UPDATE',
            $cashTypes,
            $cashValues
        );
    }
    if ($cashCollectionIds) {
        $cashPlaceholders = implode(',', array_fill(0, count($cashCollectionIds), '?'));
        $settlementIds = deviceDeleteSelectIds(
            $conn,
            "SELECT DISTINCT settlement_id FROM cash_collection_settlement_allocations
             WHERE collection_id IN ({$cashPlaceholders})",
            str_repeat('i', count($cashCollectionIds)),
            $cashCollectionIds
        );
        if ($settlementIds) {
            $settlementPlaceholders = implode(',', array_fill(0, count($settlementIds), '?'));
            $settlementsWithOtherCollections = deviceDeleteSelectIds(
                $conn,
                "SELECT DISTINCT settlement_id FROM cash_collection_settlement_allocations
                 WHERE settlement_id IN ({$settlementPlaceholders})
                   AND collection_id NOT IN ({$cashPlaceholders})",
                str_repeat('i', count($settlementIds) + count($cashCollectionIds)),
                array_merge($settlementIds, $cashCollectionIds)
            );
            if ($settlementsWithOtherCollections) {
                throw new DomainException(
                    'This device has a cash collection included in a settlement with other customer records. ' .
                    'That mixed settlement must be resolved before permanent deletion.'
                );
            }
            deviceDeleteRowsByIds($conn, 'cash_collection_settlements', 'id', $settlementIds);
        }
    }
    if (!$isSuperAdmin && ($vehicleIds || $installationIds || $paymentIds || $crmDeviceRows)) {
        throw new DomainException(
            'Only a Super Admin can permanently delete a device linked to customer or installation records.'
        );
    }

    $allocationIds = deviceDeleteSelectIds(
        $conn,
        'SELECT id FROM stock_allocations WHERE device_id = ? FOR UPDATE',
        'i',
        [$id]
    );
    $transferConditions = ['device_id = ?'];
    $transferTypes = 'i';
    $transferValues = [$id];
    if ($allocationIds) {
        $allocationPlaceholders = implode(',', array_fill(0, count($allocationIds), '?'));
        $transferConditions[] = "allocation_id IN ({$allocationPlaceholders})";
        $transferTypes .= str_repeat('i', count($allocationIds));
        array_push($transferValues, ...$allocationIds);
    }
    $transferIds = deviceDeleteSelectIds(
        $conn,
        'SELECT id FROM stock_transfers WHERE ' . implode(' OR ', $transferConditions) . ' FOR UPDATE',
        $transferTypes,
        $transferValues
    );
    $courierIds = deviceDeleteSelectIds(
        $conn,
        'SELECT id FROM courier_requests WHERE device_id = ? FOR UPDATE',
        'i',
        [$id]
    );

    foreach ([
        ['Stock Allocation', $allocationIds],
        ['Dealer Stock Allocation', $allocationIds],
        ['Stock Allocation Payment', $allocationIds],
        ['Stock Transfer', $transferIds],
        ['Customer Vehicle Details', $vehicleIds],
        ['Customer Installation', $installationIds],
        ['Customer Payment', $paymentIds],
        ['Customer Cash Collection', $cashCollectionIds],
        ['Courier', $courierIds],
        ['Device', [$id]]
    ] as [$module, $recordIds]) {
        deviceDeleteHistoryRows($conn, $module, $recordIds);
    }
    deviceRedactHistoryImei($conn, $imei);
    writeDeleteSnapshot($conn, $id, 'Device', [
        'id' => $id,
        'status' => $device['status'],
        'deleted_permanently' => true
    ], $currentUser);

    deviceDeleteRowsByIds($conn, 'customer_payment_verification_submissions', 'id', $verificationIds);
    deviceDeleteRowsByIds($conn, 'customer_cash_collections', 'id', $cashCollectionIds);
    deviceDeleteRowsByIds($conn, 'customer_payments', 'id', $paymentIds);
    deviceDeleteRowsByIds($conn, 'customer_installations', 'id', $installationIds);
    if ($vehicleIds) {
        $vehicleDelete = $conn->prepare(
            "DELETE FROM customer_vehicle_details
             WHERE id IN ({$vehiclePlaceholders})"
        );
        if (!$vehicleDelete) {
            throw new RuntimeException('Unable to prepare linked customer vehicle cleanup.');
        }
        if (!$vehicleDelete->bind_param(str_repeat('i', count($vehicleIds)), ...$vehicleIds) ||
            !$vehicleDelete->execute()) {
            $vehicleDelete->close();
            throw new RuntimeException('Unable to remove linked customer vehicle records.');
        }
        $vehicleDelete->close();
    }
    deviceDeleteRowsByIds($conn, 'crm_closure_installation_devices', 'id', $crmDeviceRows);
    deviceDeleteRowsByIds($conn, 'courier_requests', 'id', $courierIds);
    deviceDeleteRowsByIds($conn, 'stock_transfers', 'id', $transferIds);
    deviceDeleteRowsByIds($conn, 'stock_transactions', 'id', deviceDeleteSelectIds(
        $conn,
        'SELECT id FROM stock_transactions WHERE device_id = ? FOR UPDATE',
        'i',
        [$id]
    ));
    deviceDeleteRowsByIds($conn, 'stock_allocations', 'id', $allocationIds);

    $stmt = $conn->prepare('DELETE FROM devices WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare device deletion.');
    }
    $stmt->bind_param('i', $id);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        throw new RuntimeException('Failed to delete device.');
    }
    $stmt->close();
    $stmt = null;
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Device permanently deleted successfully.', ['permanently_deleted' => true]);
} catch (DomainException $error) {
    $conn->rollback();
    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 409);
} catch (Throwable $error) {
    $conn->rollback();
    if ($stmt instanceof mysqli_stmt) {
        $stmt->close();
    }
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 500);
}
?>
