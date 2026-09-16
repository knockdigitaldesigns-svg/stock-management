<?php

require_once '../../../config/database.php';
require_once '../../../utils/response.php';
require_once '../../../utils/audit.php';
require_once '../../../middleware/auth.php';

handlePreflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(
        false,
        'Method not allowed',
        [],
        [],
        405
    );
}

$currentUser = authenticate();

$data = json_decode(
    file_get_contents('php://input')
);

if (!$data) {
    sendResponse(
        false,
        'Invalid request data.',
        [],
        [],
        400
    );
}

$customerId = isset($data->customer_id)
    ? (int) $data->customer_id
    : 0;

$installationPersonType = trim(
    (string) (
        $data->installation_person_type ?? ''
    )
);

$installationPersonId = isset(
    $data->installation_person_id
)
    ? (int) $data->installation_person_id
    : 0;

$leadClosureId = isset(
    $data->lead_closure_id
)
    ? (int) $data->lead_closure_id
    : 0;

$installationDate = trim(
    (string) (
        $data->installation_date ?? ''
    )
);


/*
|--------------------------------------------------------------------------
| Basic validation
|--------------------------------------------------------------------------
*/

if ($customerId <= 0) {
    sendResponse(
        false,
        'Customer ID is required.',
        [],
        [],
        400
    );
}

$allowedPersonTypes = [
    'Technician',
    'Onsite Dealer',
    'Offsite Dealer'
];

if (
    !in_array(
        $installationPersonType,
        $allowedPersonTypes,
        true
    )
) {
    sendResponse(
        false,
        'Invalid installation person type.',
        [],
        [],
        400
    );
}

if ($installationPersonId <= 0) {
    sendResponse(
        false,
        'Installation Person is required.',
        [],
        [],
        400
    );
}

if ($leadClosureId <= 0) {
    sendResponse(
        false,
        'Lead Closure By is required.',
        [],
        [],
        400
    );
}

if ($installationDate === '') {
    sendResponse(
        false,
        'Installation Date is required.',
        [],
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| Date validation
|--------------------------------------------------------------------------
*/

if (
    !preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $installationDate
    )
) {
    sendResponse(
        false,
        'Invalid Installation Date.',
        [],
        [],
        400
    );
}

$installationDateObj =
    DateTime::createFromFormat(
        '!Y-m-d',
        $installationDate
    );

if (
    !$installationDateObj ||
    $installationDateObj->format('Y-m-d') !==
        $installationDate
) {
    sendResponse(
        false,
        'Invalid Installation Date.',
        [],
        [],
        400
    );
}

$currentDate = date('Y-m-d');

if ($installationDate > $currentDate) {
    sendResponse(
        false,
        'Future dates are not allowed.',
        [],
        [],
        400
    );
}


/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

$db = new Database();
$conn = $db->getConnection();

if (!$conn) {
    sendResponse(
        false,
        'Database connection failed.',
        [],
        [],
        500
    );
}

try {

    $conn->begin_transaction();


    /*
    |--------------------------------------------------------------------------
    | 1. Customer validation
    |--------------------------------------------------------------------------
    */

    $customerStmt = $conn->prepare(
        'SELECT id
         FROM customers
         WHERE id = ?
         LIMIT 1'
    );

    if (!$customerStmt) {
        throw new Exception(
            'Failed to prepare customer query.'
        );
    }

    $customerStmt->bind_param(
        'i',
        $customerId
    );

    if (!$customerStmt->execute()) {
        $customerStmt->close();

        throw new Exception(
            'Failed to validate customer.'
        );
    }

    $customerResult =
        $customerStmt->get_result();

    if ($customerResult->num_rows === 0) {
        $customerStmt->close();

        throw new Exception(
            'Customer not found.'
        );
    }

    $customerStmt->close();


    /*
    |--------------------------------------------------------------------------
    | 2. Installation Person validation
    |--------------------------------------------------------------------------
    |
    | Technician:
    |     technicians.id
    |
    | Onsite Dealer:
    |     dealers.id + installation_status = Onsite
    |
    | Offsite Dealer:
    |     dealers.id + installation_status = Offsite
    |
    | Not Willing:
    |     rejected
    |
    |--------------------------------------------------------------------------
    */

    if (
        $installationPersonType ===
        'Technician'
    ) {

        /*
         * IMPORTANT:
         * technicians table does NOT have status.
         */
        $personStmt = $conn->prepare(
            'SELECT id
             FROM technicians
             WHERE id = ?
             LIMIT 1'
        );

        if (!$personStmt) {
            throw new Exception(
                'Failed to prepare technician validation.'
            );
        }

        $personStmt->bind_param(
            'i',
            $installationPersonId
        );

        if (!$personStmt->execute()) {
            $personStmt->close();

            throw new Exception(
                'Failed to validate technician.'
            );
        }

        $personResult =
            $personStmt->get_result();

        if (
            $personResult->num_rows === 0
        ) {
            $personStmt->close();

            throw new Exception(
                'Selected technician does not exist.'
            );
        }

        $personStmt->close();

    } else {

        /*
         * Dealer validation.
         *
         * The dealers table uses installation_status,
         * NOT a generic status column.
         */
        $requiredInstallationStatus =
            $installationPersonType ===
            'Onsite Dealer'
                ? 'Onsite'
                : 'Offsite';

        $dealerStmt = $conn->prepare(
            'SELECT id,
                    installation_status
             FROM dealers
             WHERE id = ?
             LIMIT 1'
        );

        if (!$dealerStmt) {
            throw new Exception(
                'Failed to prepare dealer validation.'
            );
        }

        $dealerStmt->bind_param(
            'i',
            $installationPersonId
        );

        if (!$dealerStmt->execute()) {
            $dealerStmt->close();

            throw new Exception(
                'Failed to validate dealer.'
            );
        }

        $dealerResult =
            $dealerStmt->get_result();

        $dealer =
            $dealerResult->fetch_assoc();

        $dealerStmt->close();

        if (!$dealer) {
            throw new Exception(
                'Selected dealer does not exist.'
            );
        }

        $actualInstallationStatus =
            trim(
                (string) (
                    $dealer[
                        'installation_status'
                    ] ?? ''
                )
            );

        if (
            strcasecmp(
                $actualInstallationStatus,
                $requiredInstallationStatus
            ) !== 0
        ) {
            throw new Exception(
                $installationPersonType ===
                    'Onsite Dealer'
                    ? 'Selected dealer is not an Onsite Dealer.'
                    : 'Selected dealer is not an Offsite Dealer.'
            );
        }

    }


    /*
    |--------------------------------------------------------------------------
    | 3. Lead Closure validation
    |--------------------------------------------------------------------------
    |
    | Lead closure master has Active / Inactive.
    | Only Active can be selected.
    |--------------------------------------------------------------------------
    */

    $leadClosureStmt = $conn->prepare(
        "SELECT id
         FROM lead_closures
         WHERE id = ?
         AND (
             status IS NULL
             OR status = ''
             OR LOWER(status) = 'active'
         )
         LIMIT 1"
    );

    if (!$leadClosureStmt) {
        throw new Exception(
            'Failed to prepare lead closure validation.'
        );
    }

    $leadClosureStmt->bind_param(
        'i',
        $leadClosureId
    );

    if (!$leadClosureStmt->execute()) {
        $leadClosureStmt->close();

        throw new Exception(
            'Failed to validate lead closure.'
        );
    }

    $leadClosureResult =
        $leadClosureStmt->get_result();

    if (
        $leadClosureResult->num_rows === 0
    ) {
        $leadClosureStmt->close();

        throw new Exception(
            'Selected lead closure is inactive or does not exist.'
        );
    }

    $leadClosureStmt->close();


    /*
    |--------------------------------------------------------------------------
    | 4. Existing installation check
    |--------------------------------------------------------------------------
    |
    | One installation record per customer.
    |--------------------------------------------------------------------------
    */

    $checkStmt = $conn->prepare(
        'SELECT
            id,
            installation_person_type,
            installation_person_id,
            lead_closure_id,
            installation_date
         FROM customer_installations
         WHERE customer_id = ?
         LIMIT 1
         FOR UPDATE'
    );

    if (!$checkStmt) {
        throw new Exception(
            'Failed to prepare existing installation check.'
        );
    }

    $checkStmt->bind_param(
        'i',
        $customerId
    );

    if (!$checkStmt->execute()) {
        $checkStmt->close();

        throw new Exception(
            'Failed to check existing installation.'
        );
    }

    $existingResult =
        $checkStmt->get_result();

    $existingInstallation = null;

    if (
        $existingResult->num_rows > 0
    ) {
        $existingInstallation =
            $existingResult->fetch_assoc();
    }

    $checkStmt->close();


    /*
    |--------------------------------------------------------------------------
    | 5. UPDATE existing installation
    |--------------------------------------------------------------------------
    */

    if ($existingInstallation) {

        $installationId =
            (int) $existingInstallation['id'];

        writeChangedFields($conn, $installationId, 'Customer Installation', $existingInstallation, [
            'installation_person_type' => $installationPersonType,
            'installation_person_id' => $installationPersonId,
            'lead_closure_id' => $leadClosureId,
            'installation_date' => $installationDate
        ], $currentUser);

        $updateStmt = $conn->prepare(
            'UPDATE customer_installations
             SET installation_person_type = ?,
                 installation_person_id = ?,
                 lead_closure_id = ?,
                 installation_date = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );

        if (!$updateStmt) {
            throw new Exception(
                'Failed to prepare installation update.'
            );
        }

        $updateStmt->bind_param(
            'siisi',
            $installationPersonType,
            $installationPersonId,
            $leadClosureId,
            $installationDate,
            $installationId
        );

        if (!$updateStmt->execute()) {
            $updateStmt->close();

            throw new Exception(
                'Failed to update installation details.'
            );
        }

        $affectedRows =
            $updateStmt->affected_rows;

        $updateStmt->close();


        /*
        |--------------------------------------------------------------------------
        | 5b. Sync renewal record after installation update
        |--------------------------------------------------------------------------
        |
        | When installation_date changes, recalculate next_renewal_date
        | in customer_renewals based on validity_months.
        |
        | - If last_renewed_date is NULL (never renewed):
        |     next_renewal_date = new installation_date + validity_months
        |
        | - If last_renewed_date is set (already renewed):
        |     next_renewal_date stays based on last_renewed_date (no change)
        |
        | - If no renewal record exists, create one.
        |--------------------------------------------------------------------------
        */

        $renewalCheck = $conn->prepare(
            'SELECT id, last_renewed_date, validity_months
             FROM customer_renewals
             WHERE customer_id = ?
             LIMIT 1'
        );

        if ($renewalCheck) {
            $renewalCheck->bind_param('i', $customerId);
            $renewalCheck->execute();

            $renewalResult = $renewalCheck->get_result();
            $renewalRow = $renewalResult->fetch_assoc();

            $renewalCheck->close();

            if ($renewalRow) {

                /*
                 * Renewal record exists.
                 * Only recalculate if never renewed (last_renewed_date IS NULL).
                 */
                if (
                    $renewalRow['last_renewed_date'] === null ||
                    $renewalRow['last_renewed_date'] === ''
                ) {
                    $newRenewalDate = date(
                        'Y-m-d',
                        strtotime(
                            $installationDate .
                            ' +' . (int) $renewalRow['validity_months'] . ' months'
                        )
                    );

                    $renewalUpdate = $conn->prepare(
                        'UPDATE customer_renewals
                         SET installation_date = ?,
                             next_renewal_date = ?
                         WHERE id = ?'
                    );

                    if ($renewalUpdate) {
                        $renewalUpdate->bind_param(
                            'ssi',
                            $installationDate,
                            $newRenewalDate,
                            $renewalRow['id']
                        );

                        $renewalUpdate->execute();
                        $renewalUpdate->close();
                    }
                } else {

                    /*
                     * Already renewed — only update the stored installation_date
                     * but keep next_renewal_date based on last_renewed_date.
                     */
                    $renewalUpdate = $conn->prepare(
                        'UPDATE customer_renewals
                         SET installation_date = ?
                         WHERE id = ?'
                    );

                    if ($renewalUpdate) {
                        $renewalUpdate->bind_param(
                            'si',
                            $installationDate,
                            $renewalRow['id']
                        );

                        $renewalUpdate->execute();
                        $renewalUpdate->close();
                    }
                }

            } else {

                /*
                 * No renewal record exists.
                 * Create one using validity_months from customer_vehicle_details.
                 */
                $vehicleStmt = $conn->prepare(
                    'SELECT validity_months
                     FROM customer_vehicle_details
                     WHERE customer_id = ?
                       AND validity_months > 0
                     ORDER BY created_at DESC, id DESC
                     LIMIT 1'
                );

                if ($vehicleStmt) {
                    $vehicleStmt->bind_param('i', $customerId);
                    $vehicleStmt->execute();

                    $vehicleResult = $vehicleStmt->get_result();
                    $vehicleRow = $vehicleResult->fetch_assoc();

                    $vehicleStmt->close();

                    if ($vehicleRow) {
                        $validityMonths = (int) $vehicleRow['validity_months'];

                        $nextRenewalDate = date(
                            'Y-m-d',
                            strtotime(
                                $installationDate .
                                ' +' . $validityMonths . ' months'
                            )
                        );

                        /*
                         * Get default lifecycle settings.
                         */
                        $settingsResult = $conn->query(
                            'SELECT expired_to_safe_days, safe_to_deactive_days
                             FROM renewal_settings
                             ORDER BY id DESC
                             LIMIT 1'
                        );

                        $settings = $settingsResult
                            ? $settingsResult->fetch_assoc()
                            : null;

                        $expiredToSafe = (int) ($settings['expired_to_safe_days'] ?? 0);
                        $safeToDeactive = (int) ($settings['safe_to_deactive_days'] ?? 0);

                        $renewalInsert = $conn->prepare(
                            'INSERT INTO customer_renewals (
                                customer_id,
                                installation_date,
                                next_renewal_date,
                                validity_months,
                                sim_status,
                                expired_to_safe_days,
                                safe_to_deactive_days
                             ) VALUES (?, ?, ?, ?, \'Active\', ?, ?)'
                        );

                        if ($renewalInsert) {
                            $renewalInsert->bind_param(
                                'issiii',
                                $customerId,
                                $installationDate,
                                $nextRenewalDate,
                                $validityMonths,
                                $expiredToSafe,
                                $safeToDeactive
                            );

                            $renewalInsert->execute();
                            $renewalInsert->close();
                        }
                    }
                }
            }
        }

        $conn->commit();
        $conn->close();

        sendResponse(
            true,
            $affectedRows > 0
                ? 'Installation details updated successfully.'
                : 'Installation details already up to date.',
            [
                'customer_id' =>
                    $customerId,
                'installation_id' =>
                    $installationId,
                'installation_person_type' =>
                    $installationPersonType,
                'installation_person_id' =>
                    $installationPersonId,
                'action' =>
                    'update'
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | 6. INSERT new installation
    |--------------------------------------------------------------------------
    */

    $insertStmt = $conn->prepare(
        'INSERT INTO customer_installations (
            customer_id,
            installation_person_type,
            installation_person_id,
            lead_closure_id,
            installation_date
         ) VALUES (?, ?, ?, ?, ?)'
    );

    if (!$insertStmt) {
        throw new Exception(
            'Failed to prepare installation insert.'
        );
    }

    $insertStmt->bind_param(
        'isiis',
        $customerId,
        $installationPersonType,
        $installationPersonId,
        $leadClosureId,
        $installationDate
    );

    if (!$insertStmt->execute()) {
        $insertStmt->close();

        throw new Exception(
            'Failed to create installation details.'
        );
    }

    $installationId =
        $insertStmt->insert_id;

    $insertStmt->close();

    writeCreatedFields($conn, $installationId, 'Customer Installation', [
        'customer_id' => $customerId,
        'installation_person_type' => $installationPersonType,
        'installation_person_id' => $installationPersonId,
        'lead_closure_id' => $leadClosureId,
        'installation_date' => $installationDate
    ], $currentUser);


    /*
    |--------------------------------------------------------------------------
    | 6b. Auto-create renewal record for new installation
    |--------------------------------------------------------------------------
    |
    | When a new installation is created, automatically create a
    | customer_renewals record if one does not already exist and
    | the customer has vehicle details with validity_months > 0.
    |
    | next_renewal_date = installation_date + validity_months
    |--------------------------------------------------------------------------
    */

    $renewalExists = $conn->prepare(
        'SELECT id
         FROM customer_renewals
         WHERE customer_id = ?
         LIMIT 1'
    );

    if ($renewalExists) {
        $renewalExists->bind_param('i', $customerId);
        $renewalExists->execute();

        $renewalExistsResult = $renewalExists->get_result();
        $hasRenewal = $renewalExistsResult->num_rows > 0;

        $renewalExists->close();

        if (!$hasRenewal) {

            $vehicleStmt = $conn->prepare(
                'SELECT validity_months
                 FROM customer_vehicle_details
                 WHERE customer_id = ?
                   AND validity_months > 0
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1'
            );

            if ($vehicleStmt) {
                $vehicleStmt->bind_param('i', $customerId);
                $vehicleStmt->execute();

                $vehicleResult = $vehicleStmt->get_result();
                $vehicleRow = $vehicleResult->fetch_assoc();

                $vehicleStmt->close();

                if ($vehicleRow) {
                    $validityMonths = (int) $vehicleRow['validity_months'];

                    $nextRenewalDate = date(
                        'Y-m-d',
                        strtotime(
                            $installationDate .
                            ' +' . $validityMonths . ' months'
                        )
                    );

                    $settingsResult = $conn->query(
                        'SELECT expired_to_safe_days, safe_to_deactive_days
                         FROM renewal_settings
                         ORDER BY id DESC
                         LIMIT 1'
                    );

                    $settings = $settingsResult
                        ? $settingsResult->fetch_assoc()
                        : null;

                    $expiredToSafe = (int) ($settings['expired_to_safe_days'] ?? 0);
                    $safeToDeactive = (int) ($settings['safe_to_deactive_days'] ?? 0);

                    $renewalInsert = $conn->prepare(
                        'INSERT INTO customer_renewals (
                            customer_id,
                            installation_date,
                            next_renewal_date,
                            validity_months,
                            sim_status,
                            expired_to_safe_days,
                            safe_to_deactive_days
                         ) VALUES (?, ?, ?, ?, \'Active\', ?, ?)'
                    );

                    if ($renewalInsert) {
                        $renewalInsert->bind_param(
                            'issiii',
                            $customerId,
                            $installationDate,
                            $nextRenewalDate,
                            $validityMonths,
                            $expiredToSafe,
                            $safeToDeactive
                        );

                        $renewalInsert->execute();
                        $renewalInsert->close();
                    }
                }
            }
        }
    }

    $conn->commit();
    $conn->close();

    sendResponse(
        true,
        'Installation details saved successfully.',
        [
            'customer_id' =>
                $customerId,
            'installation_id' =>
                $installationId,
            'installation_person_type' =>
                $installationPersonType,
            'installation_person_id' =>
                $installationPersonId,
            'action' =>
                'insert'
        ]
    );

} catch (Throwable $e) {

    if ($conn) {
        $conn->rollback();
        $conn->close();
    }

    error_log(
        'Customer Installation Error: ' .
        $e->getMessage()
    );

    sendResponse(
        false,
        $e->getMessage(),
        [],
        [],
        400
    );
}

?>