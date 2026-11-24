<?php

function normalizeCrmLeadMobile($mobile) {
    $mobile = trim((string) $mobile);
    if ($mobile === '' || !preg_match('/^[0-9 +()-]+$/', $mobile)) {
        return null;
    }

    $normalized = preg_replace('/[ +()-]/', '', $mobile);
    return preg_match('/^\d{10}$/', $normalized) ? $normalized : null;
}

function crmLeadCampaignMobileExists($conn, $campaignId, $mobile, $excludeLeadId = null, $forUpdate = false) {
    $normalizedMobile = normalizeCrmLeadMobile($mobile);
    if ($campaignId === null || $normalizedMobile === null) {
        return false;
    }

    $sql =
        "SELECT id
         FROM crm_leads
         WHERE campaign_id = ?
           AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(mobile_no), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') = ?";
    if ($excludeLeadId !== null) {
        $sql .= ' AND id <> ?';
    }
    $sql .= ' LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $error = $conn->error;
        sendResponse(false, 'Unable to validate campaign mobile number: ' . $error, [], [], 500);
    }
    if ($excludeLeadId === null) {
        $stmt->bind_param('is', $campaignId, $normalizedMobile);
    } else {
        $stmt->bind_param('isi', $campaignId, $normalizedMobile, $excludeLeadId);
    }
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        sendResponse(false, 'Unable to validate campaign mobile number: ' . $error, [], [], 500);
    }

    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function crmLeadsHasSoftDeleteSchema($conn) {
    $result = $conn->query("SHOW COLUMNS FROM crm_leads LIKE 'deleted_at'");
    if (!$result) {
        $error = $conn->error;
        sendResponse(false, 'Unable to verify lead archive schema: ' . $error, [], [], 500);
    }
    return $result->num_rows > 0;
}

function crmLeadsIsEmployee($userId, $conn = null) {
    $ownsConnection = $conn === null;
    if ($ownsConnection) {
        $conn = (new Database())->getConnection();
        if (!$conn) {
            sendResponse(false, 'Unable to validate CRM lead access.', [], [], 500);
        }
    }

    $stmt = $conn->prepare(
        "SELECT COALESCE(NULLIF(r.role_name, ''), u.role, '') AS role_name
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        $error = $conn->error;
        if ($ownsConnection) {
            $conn->close();
        }
        sendResponse(false, 'Unable to validate CRM lead access: ' . $error, [], [], 500);
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        if ($ownsConnection) {
            $conn->close();
        }
        sendResponse(false, 'Unable to validate CRM lead access: ' . $error, [], [], 500);
    }

    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($ownsConnection) {
        $conn->close();
    }

    return strtolower(trim((string) ($user['role_name'] ?? ''))) === 'employee';
}

function crmLeadsShouldAutoAssignOnCreate($userId, $conn = null) {
    if (crmLeadsIsEmployee($userId, $conn)) {
        return true;
    }

    return !userHasPermission($userId, 'crm_leads.view') &&
        userHasPermission($userId, 'crm_leads.my_view');
}

function crmLeadsCanViewAll($userId, $conn = null) {
    return !crmLeadsIsEmployee($userId, $conn) &&
        userHasPermission($userId, 'crm_leads.view');
}
?>
