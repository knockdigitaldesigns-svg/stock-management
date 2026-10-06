<?php

function calculateDealerSimExpiryDate(string $activationDate, int $validityMonths): string
{
    return (new DateTimeImmutable($activationDate))
        ->modify('+' . $validityMonths . ' months')
        ->format('Y-m-d');
}

function saveDealerSimLifecycle(
    mysqli $conn,
    int $allocationId,
    array $oldAllocation,
    array $newLifecycle,
    array $currentUser
): void {
    writeChangedFields(
        $conn,
        $allocationId,
        'Stock Allocation SIM Lifecycle',
        $oldAllocation,
        $newLifecycle,
        $currentUser
    );

    $updateStmt = $conn->prepare(
        'UPDATE stock_allocations
         SET sim_activation_date = ?,
             sim_validity_id = ?,
             sim_expiry_date = ?,
             sim_deactivation_date = ?,
             sim_status = ?
         WHERE id = ?'
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to prepare SIM lifecycle update.');
    }

    $updateStmt->bind_param(
        'sisssi',
        $newLifecycle['sim_activation_date'],
        $newLifecycle['sim_validity_id'],
        $newLifecycle['sim_expiry_date'],
        $newLifecycle['sim_deactivation_date'],
        $newLifecycle['sim_status'],
        $allocationId
    );
    if (!$updateStmt->execute()) {
        $error = $updateStmt->error;
        $updateStmt->close();
        throw new RuntimeException('Failed to update SIM lifecycle: ' . $error);
    }
    $updateStmt->close();
}
?>
