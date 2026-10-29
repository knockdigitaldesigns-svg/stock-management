<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed', [], [], 405);
}

$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$identifier = preg_replace('/\s+/', '', trim((string) ($_GET['identifier'] ?? '')));

if (!in_array($type, ['imei', 'sim'], true)) {
    sendResponse(false, 'Search type must be IMEI or SIM.', [], [], 400);
}

$validIdentifier = ctype_digit($identifier)
    && strlen($identifier) <= ($type === 'imei' ? 15 : 13)
    && ($type !== 'imei' || strlen($identifier) === 15)
    && ($type !== 'sim' || in_array(strlen($identifier), [10, 13], true));

if (!$validIdentifier) {
    sendResponse(false, $type === 'imei'
        ? 'Enter a valid 15-digit IMEI number.'
        : 'Enter a valid 10- or 13-digit SIM number.', [], [], 400);
}

$requiredPermission = $type === 'imei' ? 'devices.view' : 'sims.view';
$authUser = authenticate();
$userId = (int) ($authUser['user_id'] ?? 0);
if (!$userId || !userHasPermission($userId, $requiredPermission)) {
    sendResponse(false, 'You do not have permission to perform this action.', [], [], 403);
}
$canViewDevices = userHasPermission($userId, 'devices.view');
$canViewSims = userHasPermission($userId, 'sims.view');
$canViewCustomers = userHasPermission($userId, 'customers.view');

function lookupRows($conn, $sql, $types = '', $params = [])
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Unable to prepare inventory lookup query.');
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $message = $stmt->error;
        $stmt->close();
        throw new Exception('Unable to complete inventory lookup: ' . $message);
    }
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

function lookupIdClause($ids)
{
    return $ids ? implode(',', array_fill(0, count($ids), '?')) : '';
}

function lookupAddId(&$ids, $value)
{
    $id = (int) $value;
    if ($id > 0) {
        $ids[$id] = $id;
    }
}

function lookupHolderName($type, $dealerName, $technicianName)
{
    if ($type === 'dealer') {
        return $dealerName ?: null;
    }
    if ($type === 'technician') {
        return $technicianName ?: null;
    }
    return null;
}

function lookupAssetStatus($asset, $assetType, $allocations, $transfers, $transactions, $vehicles)
{
    $id = (int) $asset['id'];
    $assetAllocations = array_values(array_filter($allocations, static function ($row) use ($assetType, $id) {
        return (int) ($row[$assetType . '_id'] ?? 0) === $id;
    }));
    $assetTransfers = array_values(array_filter($transfers, static function ($row) use ($assetType, $id) {
        return (int) ($row[$assetType . '_id'] ?? 0) === $id;
    }));
    $assetTransactions = array_values(array_filter($transactions, static function ($row) use ($assetType, $id) {
        return (int) ($row[$assetType . '_id'] ?? 0) === $id;
    }));
    $assetVehicles = array_values(array_filter($vehicles, static function ($row) use ($asset, $assetType) {
        if ($assetType === 'device') {
            return (int) ($row['device_id'] ?? 0) === (int) $asset['id']
                || (string) ($row['imei_no'] ?? '') === (string) ($asset['imei_no'] ?? '');
        }
        return (int) ($row['sim_id_1'] ?? 0) === (int) $asset['id']
            || (int) ($row['sim_id_2'] ?? 0) === (int) $asset['id']
            || (string) ($row['sim_no_1'] ?? '') === (string) ($asset['sim_no'] ?? '')
            || (string) ($row['sim_no_2'] ?? '') === (string) ($asset['sim_no'] ?? '');
    }));

    usort($assetAllocations, static function ($a, $b) {
        return [$b['created_at'] ?? '', (int) $b['id']] <=> [$a['created_at'] ?? '', (int) $a['id']];
    });
    usort($assetTransfers, static function ($a, $b) {
        return [$b['created_at'] ?? '', (int) $b['id']] <=> [$a['created_at'] ?? '', (int) $a['id']];
    });
    usort($assetTransactions, static function ($a, $b) {
        return (int) $b['id'] <=> (int) $a['id'];
    });

    $allocation = $assetAllocations[0] ?? null;
    $transfer = $assetTransfers[0] ?? null;
    $holderType = $allocation['owner_type'] ?? ($transfer['to_owner_type'] ?? null);
    $holderId = $allocation['owner_id'] ?? ($transfer['to_owner_id'] ?? null);
    $currentOwnerTransactions = $holderType === null
        ? $assetTransactions
        : array_values(array_filter($assetTransactions, static function ($row) use ($holderType, $holderId) {
            return ($row['from_owner_type'] ?? null) === $holderType
                && (int) ($row['from_owner_id'] ?? 0) === (int) $holderId;
        }));
    $latestOwnerTransaction = $currentOwnerTransactions[0] ?? null;
    $usedTransaction = $latestOwnerTransaction
        && ($latestOwnerTransaction['transaction_type'] ?? '') === 'USE';
    $returnedToStock = $latestOwnerTransaction
        && ($latestOwnerTransaction['transaction_type'] ?? '') === 'RETURN';
    $currentAllocation = $returnedToStock && strtolower((string) ($asset['status'] ?? '')) === 'available'
        ? null
        : $allocation;
    $transferAppliesToCurrentAllocation = $allocation && $transfer
        && (int) ($transfer['allocation_id'] ?? 0) === (int) $allocation['id'];

    $holderName = $allocation
        ? lookupHolderName($allocation['owner_type'], $allocation['dealer_name'], $allocation['technician_name'])
        : ($transfer['to_owner_name'] ?? null);
    if (!$currentAllocation && $returnedToStock) {
        $holderType = null;
        $holderId = null;
        $holderName = null;
    }
    $status = strtolower(trim((string) ($asset['status'] ?? '')));
    $hasTransferMismatch = $transferAppliesToCurrentAllocation
        && (($transfer['to_owner_type'] ?? '') !== ($allocation['owner_type'] ?? '')
            || (int) ($transfer['to_owner_id'] ?? 0) !== (int) ($allocation['owner_id'] ?? 0));

    $hasRecordedInstallation = false;
    foreach ($assetVehicles as $vehicle) {
        if (!empty($vehicle['installation_date'])) {
            $hasRecordedInstallation = true;
            break;
        }
    }

    if (!empty($asset['deleted_at'])) {
        $label = 'Archived';
    } elseif ($assetType === 'device' && $assetVehicles) {
        $label = 'Used';
    } elseif ($assetType !== 'device' && $assetVehicles) {
        $label = $hasRecordedInstallation ? 'Installed' : 'Used / Customer linked';
    } elseif ($assetType === 'sim' && $allocation && strtolower((string) $allocation['sim_status']) === 'deactive') {
        $label = 'Inactive';
    } elseif ($status === 'used' || $usedTransaction) {
        $label = 'Used';
    } elseif ($status === 'allocated') {
        if ($transferAppliesToCurrentAllocation) {
            $label = 'Transferred';
        } elseif ($allocation || $transfer) {
            $label = 'Allocated';
        } else {
            $label = 'Allocated (holder not recorded)';
        }
    } elseif ($status === 'available' && $returnedToStock) {
        $label = 'Available in stock';
    } elseif ($status === 'available' && ($assetAllocations || $assetTransfers || $assetTransactions)) {
        $label = 'Status conflict';
    } elseif ($status === 'available') {
        $label = 'Available in stock';
    } elseif ($status !== '') {
        $label = ucfirst($status);
    } else {
        $label = 'Status not recorded';
    }

    $location = null;
    if ($currentAllocation) {
        $location = $holderType === 'dealer'
            ? trim((string) ($currentAllocation['dealer_location'] ?? ''))
            : ($holderType === 'technician' ? trim((string) ($currentAllocation['technician_location'] ?? '')) : '');
    } elseif ($transfer && !$returnedToStock) {
        $location = trim((string) ($transfer['to_owner_location'] ?? ''));
    }
    if ($assetVehicles) {
        $holderType = 'customer';
        if (count($assetVehicles) === 1) {
            $customerVehicle = $assetVehicles[0];
            $holderId = (int) $customerVehicle['customer_id'];
            $holderName = $customerVehicle['customer_name'] ?: 'Customer';
            $location = trim((string) ($customerVehicle['customer_location'] ?? ''));
        } else {
            $holderId = null;
            $holderName = 'Multiple customer vehicle links';
            $customerLocations = array_map(
                static function ($vehicle) {
                    return trim((string) ($vehicle['customer_location'] ?? ''));
                },
                $assetVehicles
            );
            $uniqueCustomerLocations = array_values(array_unique(array_filter($customerLocations)));
            $location = count($uniqueCustomerLocations) === 1
                && count($customerLocations) === count($assetVehicles)
                ? $uniqueCustomerLocations[0]
                : '';
        }
    }
    $location = $location !== '' && $location !== null
        ? ucwords(strtolower($location))
        : 'Not Available';

    return [
        'status_label' => $label,
        'raw_status' => $asset['status'] ?? null,
        'current_holder_type' => $holderType,
        'current_holder_id' => $holderId ? (int) $holderId : null,
        'current_holder_name' => $holderName,
        'current_location' => $location,
        'current_allocation' => $currentAllocation,
        'current_transfer' => $transfer,
        'allocation_count' => count($assetAllocations),
        'has_transfer_mismatch' => $hasTransferMismatch,
        'is_installed' => $hasRecordedInstallation
    ];
}

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

try {
    $deviceIds = [];
    $simIds = [];
    $devices = [];
    $sims = [];

    if ($type === 'imei') {
        $devices = lookupRows($conn,
            'SELECT d.id, d.purchase_date, d.device_model_id, d.imei_no, d.status, d.deleted_at, d.created_at, d.updated_at,
                    dt.device_type AS model_name
             FROM devices d
             LEFT JOIN device_types dt ON dt.id = d.device_model_id
             WHERE d.imei_no = ?
             ORDER BY d.id',
            's',
            [$identifier]
        );
        foreach ($devices as $device) {
            lookupAddId($deviceIds, $device['id']);
        }
    } else {
        $sims = lookupRows($conn,
            'SELECT s.id, s.purchase_date, s.sim_no, s.sim_type, s.sim_validity_id, s.status, s.deleted_at, s.created_at, s.updated_at
             FROM sims s
             WHERE s.sim_no = ?
             ORDER BY s.id',
            's',
            [$identifier]
        );
        foreach ($sims as $sim) {
            lookupAddId($simIds, $sim['id']);
        }
    }

    $vehicles = [];
    $allocations = [];
    do {
        $oldDeviceCount = count($deviceIds);
        $oldSimCount = count($simIds);
        $conditions = [];
        $params = [];
        $types = '';

        if ($deviceIds) {
            $ids = array_values($deviceIds);
            $conditions[] = 'cvd.device_id IN (' . lookupIdClause($ids) . ')';
            $params = array_merge($params, $ids);
            $types .= str_repeat('i', count($ids));
        }
        if ($simIds) {
            $ids = array_values($simIds);
            $conditions[] = '(cvd.sim_id_1 IN (' . lookupIdClause($ids) . ') OR cvd.sim_id_2 IN (' . lookupIdClause($ids) . '))';
            $params = array_merge($params, $ids, $ids);
            $types .= str_repeat('i', count($ids) * 2);
        }
        $conditions[] = 'cvd.imei_no = ?';
        $params[] = $identifier;
        $types .= 's';
        $conditions[] = '(cvd.sim_no_1 = ? OR cvd.sim_no_2 = ?)';
        $params[] = $identifier;
        $params[] = $identifier;
        $types .= 'ss';

        $vehicles = lookupRows($conn,
            'SELECT cvd.id, cvd.customer_id, cvd.vehicle_no, cvd.vehicle_type_id,
                    cvd.device_id, cvd.device_model_id, cvd.imei_no, cvd.sim_id_1, cvd.sim_no_1,
                    cvd.sim_id_2, cvd.sim_no_2, cvd.validity_months, cvd.created_at,
                    c.username AS customer_name, c.primary_mobile_no, c.secondary_mobile_no,
                    c.location AS customer_location,
                    p.platform_name, vt.vehicle_type, dt.device_type AS device_model,
                    ci.installation_date, ci.installation_person_type,
                    CASE WHEN ci.installation_person_type = "Dealer" THEN dealer.dealer_name
                         WHEN ci.installation_person_type = "Technician" THEN tech.technician_name
                         ELSE NULL END AS installation_person,
                    cr.id AS renewal_id, cr.next_renewal_date, cr.last_renewed_date,
                    cr.validity_months AS renewal_validity_months,
                    cr.sim_status AS renewal_sim_status
             FROM customer_vehicle_details cvd
             LEFT JOIN customers c ON c.id = cvd.customer_id
             LEFT JOIN platforms p ON p.id = c.platform_id
             LEFT JOIN vehicle_types vt ON vt.id = cvd.vehicle_type_id
             LEFT JOIN device_types dt ON dt.id = cvd.device_model_id
             LEFT JOIN customer_installations ci ON ci.id = (
                 SELECT latest_ci.id FROM customer_installations latest_ci
                 WHERE latest_ci.vehicle_id = cvd.id
                 ORDER BY latest_ci.created_at DESC, latest_ci.id DESC LIMIT 1
             )
             LEFT JOIN dealers dealer ON dealer.id = ci.installation_person_id AND ci.installation_person_type = "Dealer"
             LEFT JOIN technicians tech ON tech.id = ci.installation_person_id AND ci.installation_person_type = "Technician"
             LEFT JOIN customer_renewals cr ON cr.vehicle_id = cvd.id
             WHERE ' . implode(' OR ', $conditions) . '
             ORDER BY cvd.created_at, cvd.id',
            $types,
            $params
        );
        foreach ($vehicles as $vehicle) {
            lookupAddId($deviceIds, $vehicle['device_id']);
            lookupAddId($simIds, $vehicle['sim_id_1']);
            lookupAddId($simIds, $vehicle['sim_id_2']);
        }

        $allocationConditions = [];
        $allocationParams = [];
        $allocationTypes = '';
        if ($deviceIds) {
            $ids = array_values($deviceIds);
            $allocationConditions[] = 'sa.device_id IN (' . lookupIdClause($ids) . ')';
            $allocationParams = array_merge($allocationParams, $ids);
            $allocationTypes .= str_repeat('i', count($ids));
        }
        if ($simIds) {
            $ids = array_values($simIds);
            $allocationConditions[] = 'sa.sim_id IN (' . lookupIdClause($ids) . ')';
            $allocationParams = array_merge($allocationParams, $ids);
            $allocationTypes .= str_repeat('i', count($ids));
        }
        if ($allocationConditions) {
            $allocations = lookupRows($conn,
                'SELECT sa.id, sa.owner_type, sa.owner_id, sa.device_id, sa.sim_id, sa.allocation_type,
                        sa.allocation_date, sa.created_at, sa.updated_at, sa.notes, sa.software,
                        sa.sim_given_date, sa.sim_activation_date, sa.sim_expiry_date, sa.sim_deactivation_date,
                        sa.sim_reactivation_date, sa.sim_status, COALESCE(sa.sim_validity_id, lookup_sim.sim_validity_id) AS sim_validity_id,
                        sv.months AS sim_validity_months,
                        d.dealer_name, d.location AS dealer_location,
                        t.technician_name, t.location AS technician_location
                 FROM stock_allocations sa
                 LEFT JOIN dealers d ON d.id = sa.owner_id AND sa.owner_type = "dealer"
                 LEFT JOIN technicians t ON t.id = sa.owner_id AND sa.owner_type = "technician"
                 LEFT JOIN sims lookup_sim ON lookup_sim.id = sa.sim_id
                 LEFT JOIN sim_validities sv ON sv.id = COALESCE(sa.sim_validity_id, lookup_sim.sim_validity_id)
                 WHERE ' . implode(' OR ', $allocationConditions) . '
                 ORDER BY sa.created_at, sa.id',
                $allocationTypes,
                $allocationParams
            );
            foreach ($allocations as $allocation) {
                lookupAddId($deviceIds, $allocation['device_id']);
                lookupAddId($simIds, $allocation['sim_id']);
            }
        }
    } while (count($deviceIds) !== $oldDeviceCount || count($simIds) !== $oldSimCount);

    $deviceIds = array_values($deviceIds);
    $simIds = array_values($simIds);

    if ($deviceIds) {
        $devices = lookupRows($conn,
            'SELECT d.id, d.purchase_date, d.device_model_id, d.imei_no, d.status, d.deleted_at, d.created_at, d.updated_at,
                    dt.device_type AS model_name
             FROM devices d
             LEFT JOIN device_types dt ON dt.id = d.device_model_id
             WHERE d.id IN (' . lookupIdClause($deviceIds) . ')
             ORDER BY d.id',
            str_repeat('i', count($deviceIds)),
            $deviceIds
        );
    }
    if ($simIds) {
        $sims = lookupRows($conn,
            'SELECT s.id, s.purchase_date, s.sim_no, s.sim_type, s.sim_validity_id, s.status, s.deleted_at, s.created_at, s.updated_at
             FROM sims s
             WHERE s.id IN (' . lookupIdClause($simIds) . ')
             ORDER BY s.id',
            str_repeat('i', count($simIds)),
            $simIds
        );
    }

    $assetConditions = [];
    $assetParams = [];
    $assetTypes = '';
    if ($deviceIds) {
        $assetConditions[] = 'st.device_id IN (' . lookupIdClause($deviceIds) . ')';
        $assetParams = array_merge($assetParams, $deviceIds);
        $assetTypes .= str_repeat('i', count($deviceIds));
    }
    if ($simIds) {
        $assetConditions[] = 'st.sim_id IN (' . lookupIdClause($simIds) . ')';
        $assetParams = array_merge($assetParams, $simIds);
        $assetTypes .= str_repeat('i', count($simIds));
    }

    $transfers = $assetConditions ? lookupRows($conn,
        'SELECT st.id, st.allocation_id, st.device_id, st.sim_id, st.from_owner_type, st.from_owner_id,
                st.from_owner_name, st.to_owner_type, st.to_owner_id, st.to_owner_name,
                COALESCE(to_dealer.location, to_tech.location) AS to_owner_location,
                st.transfer_date, st.previous_status, st.new_status, st.notes, st.created_at
         FROM stock_transfers st
         LEFT JOIN dealers to_dealer ON to_dealer.id = st.to_owner_id AND st.to_owner_type = "dealer"
         LEFT JOIN technicians to_tech ON to_tech.id = st.to_owner_id AND st.to_owner_type = "technician"
         WHERE ' . implode(' OR ', $assetConditions) . '
         ORDER BY st.created_at, st.id',
        $assetTypes,
        $assetParams
    ) : [];

    $transactions = $assetConditions ? lookupRows($conn,
        'SELECT st.id, st.device_id, st.sim_id, st.from_owner_type, st.from_owner_id,
                st.to_owner_type, st.to_owner_id, st.transaction_type, st.usage_type,
                st.transaction_date, st.notes, st.created_at,
                COALESCE(from_dealer.dealer_name, from_tech.technician_name) AS from_owner_name,
                COALESCE(to_dealer.dealer_name, to_tech.technician_name) AS to_owner_name
         FROM stock_transactions st
         LEFT JOIN dealers from_dealer ON from_dealer.id = st.from_owner_id AND st.from_owner_type = "dealer"
         LEFT JOIN technicians from_tech ON from_tech.id = st.from_owner_id AND st.from_owner_type = "technician"
         LEFT JOIN dealers to_dealer ON to_dealer.id = st.to_owner_id AND st.to_owner_type = "dealer"
         LEFT JOIN technicians to_tech ON to_tech.id = st.to_owner_id AND st.to_owner_type = "technician"
         WHERE ' . implode(' OR ', $assetConditions) . '
         ORDER BY st.transaction_date, st.id',
        $assetTypes,
        $assetParams
    ) : [];

    $warnings = [];
    $renewalEvents = [];
    $recordedRenewalDates = [];
    $renewalIds = [];
    foreach ($vehicles as $vehicle) {
        lookupAddId($renewalIds, $vehicle['renewal_id'] ?? null);
    }
    if ($canViewCustomers && $renewalIds) {
        $renewalTable = $conn->query("SHOW TABLES LIKE 'renewal_history'");
        if (!$renewalTable) {
            throw new Exception('Unable to verify renewal history availability.');
        }
        if ($renewalTable->num_rows > 0) {
            $renewalIds = array_values($renewalIds);
            $renewalEvents = lookupRows($conn,
                'SELECT id, renewal_id, customer_id, action_type, action_date, old_status, new_status,
                        old_validity_months, new_validity_months, old_renewal_date, new_renewal_date,
                        transaction_id, notes
                 FROM renewal_history
                 WHERE renewal_id IN (' . lookupIdClause($renewalIds) . ')
                 ORDER BY action_date, id',
                str_repeat('i', count($renewalIds)),
                $renewalIds
            );
            foreach ($renewalEvents as $renewalEvent) {
                if (in_array($renewalEvent['action_type'], ['Renew SIM', 'Reactivate SIM'], true)) {
                    $recordedRenewalDates[(int) $renewalEvent['renewal_id'] . ':' . $renewalEvent['action_date']] = true;
                }
            }
        } else {
            $warnings[] = 'SIM renewal history is not available; only current renewal details can be shown.';
        }
    }

    if (count($devices) > 1) {
        $warnings[] = 'Multiple device records use this IMEI. Verify the records before relying on the current holder.';
    }
    if (count($sims) > 1) {
        $warnings[] = 'Multiple SIM records use this SIM number. Verify the records before relying on the current holder.';
    }
    $vehicleRecordIds = array_unique(array_map(
        static function ($vehicle) {
            return (int) $vehicle['id'];
        },
        $vehicles
    ));
    if (count($vehicleRecordIds) > 1) {
        $warnings[] = 'This identifier is linked to multiple customer vehicle records.';
    }
    foreach ($vehicles as $vehicle) {
        foreach ([
            ['device_id', 'imei_no', $devices, 'Device'],
            ['sim_id_1', 'sim_no_1', $sims, 'SIM 1'],
            ['sim_id_2', 'sim_no_2', $sims, 'SIM 2']
        ] as [$idKey, $numberKey, $masterRecords, $label]) {
            $linkedId = (int) ($vehicle[$idKey] ?? 0);
            if ($linkedId <= 0 || empty($vehicle[$numberKey])) {
                continue;
            }
            foreach ($masterRecords as $masterRecord) {
                if ((int) $masterRecord['id'] === $linkedId
                    && (string) $masterRecord[$numberKey === 'imei_no' ? 'imei_no' : 'sim_no'] !== (string) $vehicle[$numberKey]) {
                    $warnings[] = $label . ' number conflicts with the linked inventory record for vehicle record #' . $vehicle['id'] . '.';
                }
            }
        }
    }
    $events = [];
    $addEvent = static function ($date, $action, $previousHolder = null, $newHolder = null, $reference = null, $notes = null) use (&$events) {
        if (!$date) {
            return;
        }
        $events[] = [
            'date' => $date,
            'action' => $action,
            'previous_holder' => $previousHolder,
            'new_holder' => $newHolder,
            'reference' => $reference,
            'notes' => $notes
        ];
    };

    foreach ($devices as &$device) {
        $device['id'] = (int) $device['id'];
        $deviceAllocations = array_values(array_filter($allocations, static fn($row) => (int) ($row['device_id'] ?? 0) === $device['id']));
        $deviceTransfers = array_values(array_filter($transfers, static fn($row) => (int) ($row['device_id'] ?? 0) === $device['id']));
        $deviceTransactions = array_values(array_filter($transactions, static fn($row) => (int) ($row['device_id'] ?? 0) === $device['id']));
        $device['tracking'] = lookupAssetStatus($device, 'device', $allocations, $transfers, $transactions, $vehicles);
        if ($device['tracking']['allocation_count'] > 1) {
            $warnings[] = 'Device ' . $device['imei_no'] . ' has multiple allocation records; the latest record is treated as current.';
        }
        if ($device['tracking']['has_transfer_mismatch']) {
            $warnings[] = 'The latest transfer destination conflicts with the latest allocation holder for device ' . $device['imei_no'] . '.';
        }
        $addEvent($device['purchase_date'], 'Device inward', null, null, null, $device['model_name']);

        foreach ($deviceAllocations as $allocation) {
            $transferForAllocation = array_values(array_filter($deviceTransfers, static fn($row) => (int) ($row['allocation_id'] ?? 0) === (int) $allocation['id']));
            usort($transferForAllocation, static fn($a, $b) => [$a['created_at'] ?? '', (int) $a['id']] <=> [$b['created_at'] ?? '', (int) $b['id']]);
            $holder = $transferForAllocation
                ? $transferForAllocation[0]['from_owner_name']
                : lookupHolderName($allocation['owner_type'], $allocation['dealer_name'], $allocation['technician_name']);
            $addEvent($allocation['allocation_date'], 'Stock allocation', null, $holder, 'Allocation #' . $allocation['id'], $allocation['notes']);
        }
        foreach ($deviceTransfers as $transfer) {
            $addEvent($transfer['transfer_date'], 'Stock transfer', $transfer['from_owner_name'], $transfer['to_owner_name'], 'Transfer #' . $transfer['id'], $transfer['notes']);
        }
        foreach ($deviceTransactions as $transaction) {
            $previousHolder = $transaction['from_owner_name']
                ?: ($transaction['from_owner_type'] ? ucfirst($transaction['from_owner_type']) . ' #' . $transaction['from_owner_id'] : null);
            $newHolder = $transaction['to_owner_name']
                ?: ($transaction['usage_type'] ? 'Used for ' . $transaction['usage_type'] : null);
            $addEvent($transaction['transaction_date'], 'Stock ' . strtolower($transaction['transaction_type']), $previousHolder, $newHolder, 'Transaction #' . $transaction['id'], $transaction['notes']);
        }
    }
    unset($device);

    foreach ($sims as &$sim) {
        $sim['id'] = (int) $sim['id'];
        $simAllocations = array_values(array_filter($allocations, static fn($row) => (int) ($row['sim_id'] ?? 0) === $sim['id']));
        $simTransfers = array_values(array_filter($transfers, static fn($row) => (int) ($row['sim_id'] ?? 0) === $sim['id']));
        $simTransactions = array_values(array_filter($transactions, static fn($row) => (int) ($row['sim_id'] ?? 0) === $sim['id']));
        $sim['tracking'] = lookupAssetStatus($sim, 'sim', $allocations, $transfers, $transactions, $vehicles);
        if ($sim['tracking']['allocation_count'] > 1) {
            $warnings[] = 'SIM ' . $sim['sim_no'] . ' has multiple allocation records; the latest record is treated as current.';
        }
        if ($sim['tracking']['has_transfer_mismatch']) {
            $warnings[] = 'The latest transfer destination conflicts with the latest allocation holder for SIM ' . $sim['sim_no'] . '.';
        }
        $addEvent($sim['purchase_date'], 'SIM inward', null, null, null, $sim['sim_type']);

        foreach ($simAllocations as $allocation) {
            $transferForAllocation = array_values(array_filter($simTransfers, static fn($row) => (int) ($row['allocation_id'] ?? 0) === (int) $allocation['id']));
            usort($transferForAllocation, static fn($a, $b) => [$a['created_at'] ?? '', (int) $a['id']] <=> [$b['created_at'] ?? '', (int) $b['id']]);
            $holder = $transferForAllocation
                ? $transferForAllocation[0]['from_owner_name']
                : lookupHolderName($allocation['owner_type'], $allocation['dealer_name'], $allocation['technician_name']);
            $addEvent($allocation['allocation_date'], 'Stock allocation', null, $holder, 'Allocation #' . $allocation['id'], $allocation['notes']);
            $addEvent($allocation['sim_activation_date'], 'SIM activated', null, $holder, 'Allocation #' . $allocation['id'], null);
            $addEvent($allocation['sim_deactivation_date'], 'SIM deactivated', $holder, null, 'Allocation #' . $allocation['id'], null);
            $addEvent($allocation['sim_reactivation_date'], 'SIM reactivated', null, $holder, 'Allocation #' . $allocation['id'], null);
            $addEvent($allocation['sim_expiry_date'], 'SIM validity expired', $holder, null, 'Allocation #' . $allocation['id'], null);
        }
        foreach ($simTransfers as $transfer) {
            $addEvent($transfer['transfer_date'], 'Stock transfer', $transfer['from_owner_name'], $transfer['to_owner_name'], 'Transfer #' . $transfer['id'], $transfer['notes']);
        }
        foreach ($simTransactions as $transaction) {
            $previousHolder = $transaction['from_owner_name']
                ?: ($transaction['from_owner_type'] ? ucfirst($transaction['from_owner_type']) . ' #' . $transaction['from_owner_id'] : null);
            $newHolder = $transaction['to_owner_name']
                ?: ($transaction['usage_type'] ? 'Used for ' . $transaction['usage_type'] : null);
            $addEvent($transaction['transaction_date'], 'Stock ' . strtolower($transaction['transaction_type']), $previousHolder, $newHolder, 'Transaction #' . $transaction['id'], $transaction['notes']);
        }
    }
    unset($sim);

    foreach ($devices as $device) {
        if (($device['tracking']['status_label'] ?? '') === 'Status conflict') {
            $warnings[] = 'Device ' . $device['imei_no'] . ' is marked available despite allocation history.';
        }
    }
    foreach ($sims as $sim) {
        if (($sim['tracking']['status_label'] ?? '') === 'Status conflict') {
            $warnings[] = 'SIM ' . $sim['sim_no'] . ' is marked available despite allocation history.';
        }
    }

    foreach ($vehicles as $vehicle) {
        $vehicleLabel = $canViewCustomers
            ? trim(($vehicle['customer_name'] ?? 'Customer') . ' / ' . ($vehicle['vehicle_no'] ?? 'Vehicle'))
            : 'Customer vehicle association';
        $vehicleReference = $canViewCustomers ? 'Vehicle #' . $vehicle['id'] : null;
        $addEvent($vehicle['created_at'], 'Customer / vehicle association', null, $vehicleLabel, $vehicleReference, null);
        $addEvent(
            $vehicle['installation_date'],
            'Installation',
            null,
            $canViewCustomers ? $vehicle['installation_person'] : null,
            $vehicleReference,
            $canViewCustomers ? $vehicle['vehicle_no'] : null
        );
        if ($canViewCustomers
            && !isset($recordedRenewalDates[(int) ($vehicle['renewal_id'] ?? 0) . ':' . ($vehicle['last_renewed_date'] ?? '')])) {
            $addEvent($vehicle['last_renewed_date'], 'SIM renewed', null, $vehicle['customer_name'], 'Customer #' . $vehicle['customer_id'], null);
        }
    }
    foreach ($renewalEvents as $renewalEvent) {
        $eventNotes = trim(implode(' · ', array_filter([
            $renewalEvent['old_status'] ? 'Previous: ' . $renewalEvent['old_status'] : null,
            $renewalEvent['new_status'] ? 'New: ' . $renewalEvent['new_status'] : null,
            $renewalEvent['old_validity_months'] ? 'Validity: ' . $renewalEvent['old_validity_months'] . ' months' : null,
            $renewalEvent['new_validity_months'] ? 'Renewed for: ' . $renewalEvent['new_validity_months'] . ' months' : null,
            $renewalEvent['new_renewal_date'] ? 'Next renewal: ' . $renewalEvent['new_renewal_date'] : null,
            $renewalEvent['transaction_id'] ? 'Transaction: ' . $renewalEvent['transaction_id'] : null,
            $renewalEvent['notes'] ?: null
        ])));
        $addEvent(
            $renewalEvent['action_date'],
            $renewalEvent['action_type'],
            $renewalEvent['old_status'],
            $renewalEvent['new_status'],
            'Renewal #' . $renewalEvent['renewal_id'],
            $eventNotes
        );
    }

    $uniqueEvents = [];
    foreach ($events as $event) {
        $uniqueEvents[serialize($event)] = $event;
    }
    $events = array_values($uniqueEvents);
    usort($events, static function ($a, $b) {
        return [$a['date'], $a['action'], $a['reference'] ?? ''] <=> [$b['date'], $b['action'], $b['reference'] ?? ''];
    });
    $warnings = array_values(array_unique($warnings));
    $found = $devices || $sims || $vehicles;
    $responseDevices = $canViewDevices ? $devices : [];
    $responseSims = $canViewSims ? $sims : [];
    $responseVehicles = $vehicles;
    if (!$canViewCustomers) {
        foreach ($responseDevices as &$assetRecord) {
            if (($assetRecord['tracking']['current_holder_type'] ?? '') === 'customer') {
                $assetRecord['tracking']['current_holder_id'] = null;
                $assetRecord['tracking']['current_holder_name'] = 'Customer association recorded';
                $assetRecord['tracking']['current_location'] = 'Not Available';
            }
        }
        unset($assetRecord);
        foreach ($responseSims as &$assetRecord) {
            if (($assetRecord['tracking']['current_holder_type'] ?? '') === 'customer') {
                $assetRecord['tracking']['current_holder_id'] = null;
                $assetRecord['tracking']['current_holder_name'] = 'Customer association recorded';
                $assetRecord['tracking']['current_location'] = 'Not Available';
            }
        }
        unset($assetRecord);
    }
    foreach ($responseVehicles as &$vehicle) {
        if (!$canViewCustomers) {
            unset(
                $vehicle['customer_id'],
                $vehicle['customer_name'],
                $vehicle['primary_mobile_no'],
                $vehicle['secondary_mobile_no'],
                $vehicle['customer_location'],
                $vehicle['platform_name'],
                $vehicle['vehicle_no'],
                $vehicle['vehicle_type'],
                $vehicle['installation_person'],
                $vehicle['next_renewal_date'],
                $vehicle['last_renewed_date'],
                $vehicle['renewal_validity_months'],
                $vehicle['renewal_sim_status']
            );
        }
        if (!$canViewDevices) {
            unset($vehicle['device_id'], $vehicle['device_model_id'], $vehicle['device_model'], $vehicle['imei_no']);
        }
        if (!$canViewSims) {
            unset($vehicle['sim_id_1'], $vehicle['sim_no_1'], $vehicle['sim_id_2'], $vehicle['sim_no_2']);
        }
    }
    unset($vehicle);

    if (!$found) {
        $conn->close();
        sendResponse(true, 'No matching device, SIM, or customer vehicle record was found.', [
            'found' => false,
            'type' => $type,
            'identifier' => $identifier,
            'devices' => [],
            'sims' => [],
            'vehicles' => [],
            'timeline' => [],
            'warnings' => []
        ]);
    }

    $conn->close();
    sendResponse(true, 'Inventory tracking records fetched.', [
        'found' => true,
        'type' => $type,
        'identifier' => $identifier,
        'devices' => $responseDevices,
        'sims' => $responseSims,
        'vehicles' => $responseVehicles,
        'allocations' => $allocations,
        'transfers' => $transfers,
        'transactions' => $transactions,
        'timeline' => $events,
        'warnings' => $warnings
    ]);
} catch (Throwable $e) {
    $conn->close();
    error_log('Inventory lookup failed: ' . $e->getMessage());
    sendResponse(false, 'Unable to retrieve inventory tracking information.', [], [], 500);
}
?>
