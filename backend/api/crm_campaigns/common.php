<?php

function campaignManagerColumnExists($conn) {
    $result = $conn->query(
        "SELECT COUNT(*) AS column_count
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'crm_campaigns'
           AND column_name = 'manager_user_id'"
    );

    if (!$result) {
        $error = $conn->error;
        $conn->close();
        sendResponse(false, 'Unable to verify campaign database schema: ' . $error, [], [], 500);
    }

    $row = $result->fetch_assoc();
    return (int) ($row['column_count'] ?? 0) > 0;
}

function requireCampaignManagerColumn($conn) {
    if (!campaignManagerColumnExists($conn)) {
        $conn->close();
        sendResponse(
            false,
            'Campaign manager support is not installed. Apply the additive CRM migration to add crm_campaigns.manager_user_id.',
            [],
            [],
            503
        );
    }
}
?>
