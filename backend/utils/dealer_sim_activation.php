<?php

function calculateDealerSimExpiryDate(string $activationDate, int $validityMonths): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $activationDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$date
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $date->format('Y-m-d') !== $activationDate
        || $validityMonths <= 0) {
        throw new InvalidArgumentException('A valid date and positive SIM validity are required to calculate expiry.');
    }

    $targetMonth = ((int) $date->format('n') - 1) + $validityMonths;
    $year = (int) $date->format('Y') + intdiv($targetMonth, 12);
    $month = ($targetMonth % 12) + 1;
    $daysInTargetMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    $day = min((int) $date->format('j'), $daysInTargetMonth);

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function calculateDealerSimReactivationExpiryDate(
    string $reactivationDate,
    string $previousExpiryDate,
    int $validityMonths
): string {
    $reactivation = DateTimeImmutable::createFromFormat('!Y-m-d', $reactivationDate);
    $reactivationErrors = DateTimeImmutable::getLastErrors();
    $previousExpiry = DateTimeImmutable::createFromFormat('!Y-m-d', $previousExpiryDate);
    $previousExpiryErrors = DateTimeImmutable::getLastErrors();

    if (!$reactivation
        || ($reactivationErrors !== false && ($reactivationErrors['warning_count'] > 0 || $reactivationErrors['error_count'] > 0))
        || $reactivation->format('Y-m-d') !== $reactivationDate) {
        throw new InvalidArgumentException('A valid reactivation date is required.');
    }
    if (!$previousExpiry
        || ($previousExpiryErrors !== false && ($previousExpiryErrors['warning_count'] > 0 || $previousExpiryErrors['error_count'] > 0))
        || $previousExpiry->format('Y-m-d') !== $previousExpiryDate) {
        throw new InvalidArgumentException('A valid previous expiry date is required.');
    }

    if ($reactivation <= $previousExpiry) {
        return $previousExpiryDate;
    }

    return calculateDealerSimExpiryDate($reactivationDate, $validityMonths);
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
         SET sim_given_date = ?,
             sim_activation_date = ?,
             sim_validity_id = ?,
             sim_expiry_date = ?,
             sim_deactivation_date = ?,
             sim_reactivation_date = ?,
             sim_status = ?
         WHERE id = ?'
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to prepare SIM lifecycle update.');
    }

    $givenDate = $newLifecycle['sim_given_date'] ?? $oldAllocation['sim_given_date'] ?? null;
    $updateStmt->bind_param(
        'ssissssi',
        $givenDate,
        $newLifecycle['sim_activation_date'],
        $newLifecycle['sim_validity_id'],
        $newLifecycle['sim_expiry_date'],
        $newLifecycle['sim_deactivation_date'],
        $newLifecycle['sim_reactivation_date'],
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
