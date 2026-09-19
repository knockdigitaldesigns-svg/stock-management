<?php
function checkDealerPendingThreshold($conn, $dealerId, $hasDevices = true) {
    if (!$hasDevices || !$dealerId) {
        return ['allowed' => true];
    }

    $stmt = $conn->prepare("SELECT d.installation_status, d.threshold_amount, GREATEST(0, COALESCE(SUM(sa.total_amount), 0) - COALESCE(SUM(sa.amount_paid), 0)) AS current_pending FROM dealers d LEFT JOIN stock_allocations sa ON sa.owner_type = 'dealer' AND sa.owner_id = d.id WHERE d.id = ? GROUP BY d.id");
    $stmt->bind_param("i", $dealerId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res) {
        return ['allowed' => true];
    }

    $thresholdRaw = $res['threshold_amount'];

    if ($thresholdRaw !== null && $thresholdRaw !== '' && is_numeric($thresholdRaw)) {
        $threshold = (float) $thresholdRaw;
        $pending = (float) $res['current_pending'];

        if ($pending >= $threshold) {
            return [
                'allowed' => false,
                'error_code' => 'THRESHOLD_EXCEEDED',
                'pending_amount' => $pending,
                'threshold_amount' => $threshold,
                'message' => 'Your pending amount exceeds the threshold amount. Clear the pending to buy GPS device.'
            ];
        }
    }

    return ['allowed' => true];
}
