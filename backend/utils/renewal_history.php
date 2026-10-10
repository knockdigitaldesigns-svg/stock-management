<?php

function ensureRenewalHistoryPaymentActionType(mysqli $conn): void
{
    $actionTypeColumn = $conn->query("SHOW COLUMNS FROM renewal_history LIKE 'action_type'");
    if (!$actionTypeColumn) {
        throw new RuntimeException('Unable to inspect renewal history action type: ' . $conn->error);
    }
    $actionTypeDefinition = $actionTypeColumn->fetch_assoc();
    if (!$actionTypeDefinition) {
        throw new RuntimeException('Renewal history action type column is missing.');
    }

    if (stripos((string)$actionTypeDefinition['Type'], 'enum(') === 0
        && !$conn->query('ALTER TABLE renewal_history MODIFY action_type VARCHAR(50) NOT NULL')) {
        throw new RuntimeException('Unable to enable renewal payment history records: ' . $conn->error);
    }

    $sourceHistoryColumn = $conn->query("SHOW COLUMNS FROM renewal_history LIKE 'source_history_id'");
    if (!$sourceHistoryColumn) {
        throw new RuntimeException('Unable to inspect renewal payment history links: ' . $conn->error);
    }
    if ($sourceHistoryColumn->num_rows === 0) {
        return;
    }

    $repairPayments = $conn->query(
        "UPDATE renewal_history payment
         INNER JOIN renewal_history charge ON charge.id = payment.source_history_id
         SET payment.action_type = 'Renewal Payment'
         WHERE payment.action_type = ''
           AND payment.payment_amount = 0
           AND payment.amount_paid > 0
           AND charge.action_type IN ('Renew SIM', 'Reactivate SIM', 'Safe Custody')"
    );
    if (!$repairPayments) {
        throw new RuntimeException('Unable to restore renewal payment history records: ' . $conn->error);
    }
}
?>
