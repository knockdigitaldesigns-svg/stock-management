<?php

function normalizeTransactionId($value): string
{
    $transactionId = (string) ($value ?? '');
    if ($transactionId !== '' && !preg_match('/^[0-9]{6}$/', $transactionId)) {
        throw new InvalidArgumentException('Transaction ID must contain exactly 6 digits.');
    }
    return $transactionId;
}

function reserveTransactionId(mysqli $conn, string $transactionId, string $sourceType, $sourceId): void
{
    $transactionId = normalizeTransactionId($transactionId);
    $sourceId = (string) $sourceId;

    $removeOld = $conn->prepare('DELETE FROM transaction_id_registry WHERE source_type = ? AND source_id = ? AND transaction_id <> ?');
    if (!$removeOld) {
        throw new RuntimeException('Unable to prepare transaction ID registry update: ' . $conn->error);
    }
    $removeOld->bind_param('sss', $sourceType, $sourceId, $transactionId);
    if (!$removeOld->execute()) {
        $error = $removeOld->error;
        $removeOld->close();
        throw new RuntimeException('Unable to update transaction ID registry: ' . $error);
    }
    $removeOld->close();

    if ($transactionId === '') {
        $remove = $conn->prepare('DELETE FROM transaction_id_registry WHERE source_type = ? AND source_id = ?');
        if (!$remove) {
            throw new RuntimeException('Unable to prepare transaction ID registry cleanup: ' . $conn->error);
        }
        $remove->bind_param('ss', $sourceType, $sourceId);
        if (!$remove->execute()) {
            $error = $remove->error;
            $remove->close();
            throw new RuntimeException('Unable to clear transaction ID registry: ' . $error);
        }
        $remove->close();
        return;
    }

    $lookup = $conn->prepare('SELECT source_type, source_id FROM transaction_id_registry WHERE transaction_id = ?');
    if (!$lookup) {
        throw new RuntimeException('Unable to prepare transaction ID lookup: ' . $conn->error);
    }
    $lookup->bind_param('s', $transactionId);
    if (!$lookup->execute()) {
        $error = $lookup->error;
        $lookup->close();
        throw new RuntimeException('Unable to look up transaction ID: ' . $error);
    }
    $existing = $lookup->get_result()->fetch_assoc();
    $lookup->close();
    if ($existing) {
        if ($existing['source_type'] === $sourceType && $existing['source_id'] === $sourceId) {
            return;
        }
        throw new InvalidArgumentException('Transaction ID is already in use.');
    }

    $insert = $conn->prepare('INSERT INTO transaction_id_registry (transaction_id, source_type, source_id) VALUES (?, ?, ?)');
    if (!$insert) {
        throw new RuntimeException('Unable to prepare transaction ID reservation: ' . $conn->error);
    }
    $insert->bind_param('sss', $transactionId, $sourceType, $sourceId);
    if (!$insert->execute()) {
        $errorCode = $insert->errno;
        $error = $insert->error;
        $insert->close();
        if ($errorCode === 1062) {
            throw new InvalidArgumentException('Transaction ID is already in use.');
        }
        throw new RuntimeException('Unable to reserve transaction ID: ' . $error);
    }
    $insert->close();
}

function transactionIdTableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect transaction ID source tables: ' . $conn->error);
    }
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to inspect transaction ID source tables: ' . $error);
    }
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function transactionIdColumnExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to inspect transaction ID source columns: ' . $conn->error);
    }
    $column = 'transaction_id';
    $stmt->bind_param('ss', $table, $column);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to inspect transaction ID source columns: ' . $error);
    }
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function backfillTransactionIdRegistry(mysqli $conn): void
{
    if (!$conn->query("CREATE TABLE IF NOT EXISTS transaction_id_registry (
        transaction_id CHAR(6) NOT NULL PRIMARY KEY,
        source_type VARCHAR(64) NOT NULL,
        source_id VARCHAR(64) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_transaction_registry_source (source_type, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")) {
        throw new RuntimeException('Unable to create transaction ID registry: ' . $conn->error);
    }

    $tables = [
        'stock_allocations',
        'dealer_sim_allocation_payments',
        'customer_payments',
        'renewal_history',
        'cash_collection_settlements',
        'customer_cash_collections',
        'customer_cash_settlements'
    ];
    $mirrorRows = [];
    if (transactionIdTableExists($conn, 'cash_collection_settlement_allocations')
        && transactionIdTableExists($conn, 'cash_collection_settlements')) {
        $mirrors = $conn->query("SELECT DISTINCT a.collection_id, s.transaction_id
            FROM cash_collection_settlement_allocations a
            INNER JOIN cash_collection_settlements s ON s.id = a.settlement_id
            WHERE s.transaction_id IS NOT NULL AND s.transaction_id <> ''");
        if (!$mirrors) {
            throw new RuntimeException('Unable to audit settlement transaction ID mirrors: ' . $conn->error);
        }
        while ($mirror = $mirrors->fetch_assoc()) {
            $mirrorRows[(string) $mirror['collection_id'] . ':' . (string) $mirror['transaction_id']] = true;
        }
        $mirrors->free();
    }

    $entries = [];
    foreach ($tables as $table) {
        if (!transactionIdTableExists($conn, $table)) {
            if ($table !== 'customer_cash_settlements') {
                throw new RuntimeException("Required transaction ID source table $table is missing.");
            }
            continue;
        }
        if ($table === 'dealer_sim_allocation_payments') {
            $rows = $conn->query("SELECT id, allocation_id, transaction_id, is_legacy_snapshot FROM dealer_sim_allocation_payments WHERE transaction_id IS NOT NULL AND transaction_id <> '' ORDER BY id");
            if (!$rows) {
                throw new RuntimeException('Unable to audit transaction IDs in dealer SIM payment history: ' . $conn->error);
            }
            while ($row = $rows->fetch_assoc()) {
                $id = (string) $row['transaction_id'];
                $recordId = (string) $row['id'];
                $sourceType = (int) $row['is_legacy_snapshot'] === 1 ? 'stock_allocations' : 'dealer_sim_allocation_payments';
                $sourceId = $sourceType === 'stock_allocations' ? (string) $row['allocation_id'] : $recordId;
                if (!preg_match('/^[0-9]{6}$/', $id)) {
                    throw new RuntimeException("Invalid existing transaction ID in dealer_sim_allocation_payments#$recordId; expected exactly six numeric digits. No values were changed.");
                }
                if (isset($entries[$id])) {
                    $previous = $entries[$id];
                    if ($previous['source_type'] === $sourceType && $previous['source_id'] === $sourceId) {
                        continue;
                    }
                    throw new RuntimeException("Duplicate existing transaction ID {$id} in {$previous['source_type']}#{$previous['source_id']} and dealer_sim_allocation_payments#$recordId. No values were changed.");
                }
                $entries[$id] = ['source_type' => $sourceType, 'source_id' => $sourceId];
            }
            $rows->free();
            continue;
        }
        if (!transactionIdColumnExists($conn, $table)) {
            if ($table !== 'customer_cash_settlements') {
                throw new RuntimeException("Required transaction ID column $table.transaction_id is missing.");
            }
            continue;
        }
        $rows = $conn->query("SELECT id, transaction_id FROM `$table` WHERE transaction_id IS NOT NULL AND transaction_id <> '' ORDER BY id");
        if (!$rows) {
            throw new RuntimeException("Unable to audit transaction IDs in $table: " . $conn->error);
        }
        while ($row = $rows->fetch_assoc()) {
            $id = (string) $row['transaction_id'];
            $recordId = (string) $row['id'];
            if ($table === 'customer_cash_collections' && isset($mirrorRows[$recordId . ':' . $id])) {
                continue;
            }
            if ($table === 'stock_allocations') {
                $paymentMirror = $conn->prepare("SELECT 1 FROM dealer_sim_allocation_payments WHERE allocation_id = ? AND transaction_id = ? LIMIT 1");
                if (!$paymentMirror) {
                    throw new RuntimeException('Unable to check SIM payment transaction mirrors: ' . $conn->error);
                }
                $paymentMirror->bind_param('is', $recordId, $id);
                if (!$paymentMirror->execute()) {
                    $error = $paymentMirror->error;
                    $paymentMirror->close();
                    throw new RuntimeException('Unable to check SIM payment transaction mirrors: ' . $error);
                }
                $isPaymentMirror = $paymentMirror->get_result()->num_rows > 0;
                $paymentMirror->close();
                if ($isPaymentMirror) {
                    continue;
                }
            }
            if (!preg_match('/^[0-9]{6}$/', $id)) {
                throw new RuntimeException("Invalid existing transaction ID in {$table}#{$recordId}; expected exactly six numeric digits. No values were changed.");
            }
            if (isset($entries[$id])) {
                $previous = $entries[$id];
                throw new RuntimeException("Duplicate existing transaction ID {$id} in {$previous['source_type']}#{$previous['source_id']} and {$table}#{$recordId}. No values were changed.");
            }
            $entries[$id] = ['source_type' => $table, 'source_id' => $recordId];
        }
        $rows->free();
    }

    $conn->begin_transaction();
    try {
        $insert = $conn->prepare('INSERT IGNORE INTO transaction_id_registry (transaction_id, source_type, source_id) VALUES (?, ?, ?)');
        if (!$insert) {
            throw new RuntimeException('Unable to prepare transaction ID backfill: ' . $conn->error);
        }
        $verify = $conn->prepare('SELECT source_type, source_id FROM transaction_id_registry WHERE transaction_id = ?');
        if (!$verify) {
            $insert->close();
            throw new RuntimeException('Unable to prepare transaction ID backfill verification: ' . $conn->error);
        }
        foreach ($entries as $id => $source) {
            $insert->bind_param('sss', $id, $source['source_type'], $source['source_id']);
            if (!$insert->execute()) {
                throw new RuntimeException('Unable to backfill transaction ID ' . $id . ': ' . $insert->error);
            }
            $verify->bind_param('s', $id);
            if (!$verify->execute()) {
                throw new RuntimeException('Unable to verify transaction ID ' . $id . ': ' . $verify->error);
            }
            $saved = $verify->get_result()->fetch_assoc();
            if (!$saved || $saved['source_type'] !== $source['source_type'] || $saved['source_id'] !== $source['source_id']) {
                throw new RuntimeException('Transaction ID registry conflicts with existing data for ' . $id . '. No values were changed.');
            }
        }
        $verify->close();
        $insert->close();

        foreach ($tables as $table) {
            if (!transactionIdTableExists($conn, $table)) {
                continue;
            }
            if (!transactionIdColumnExists($conn, $table)) {
                continue;
            }
            if (!$conn->query("ALTER TABLE `$table` MODIFY transaction_id CHAR(6) DEFAULT NULL")) {
                throw new RuntimeException("Unable to enforce six-digit storage for $table.transaction_id: " . $conn->error);
            }
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

?>
