<?php

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/customer_payment_verification.php';

handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$currentUser = authenticate();
requirePermission('crm_closures.add');
$userId = (int) ($currentUser['user_id'] ?? 0);
$data = json_decode(file_get_contents('php://input'), true);
$submissionId = (int) ($data['submission_id'] ?? 0);
if ($submissionId <= 0 || !is_array($data)) {
    sendResponse(false, 'A valid returned installation submission is required.', [], [], 400);
}

$customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
$lead = is_array($data['lead'] ?? null) ? $data['lead'] : [];
$vehicle = is_array($data['vehicle'] ?? null) ? $data['vehicle'] : [];
$installation = is_array($data['installation'] ?? null) ? $data['installation'] : [];
$payment = is_array($data['payment'] ?? null) ? $data['payment'] : [];
$leadName = trim((string) ($lead['lead_name'] ?? ''));
$customerNumber = trim((string) ($customer['username'] ?? ''));
$primaryMobile = trim((string) ($customer['primary_mobile_no'] ?? ''));
$secondaryMobile = trim((string) ($customer['secondary_mobile_no'] ?? ''));
$email = trim((string) ($customer['email'] ?? ''));
$location = trim((string) ($customer['location'] ?? ''));
$pincode = trim((string) ($customer['pincode'] ?? ''));
$platformId = (int) ($customer['platform_id'] ?? 0);
$vehicleNumber = strtoupper(trim((string) ($vehicle['vehicle_no'] ?? '')));
$vehicleType = trim((string) ($vehicle['vehicle_type'] ?? ''));
$deviceType = trim((string) ($vehicle['device_type'] ?? ''));
$imei = trim((string) ($vehicle['imei_no'] ?? ''));
$simNumber1 = trim((string) ($vehicle['sim_no_1'] ?? ''));
$simNumber2 = trim((string) ($vehicle['sim_no_2'] ?? ''));
$validityMonths = filter_var($vehicle['validity_months'] ?? null, FILTER_VALIDATE_INT);
$saleAmountInput = trim((string) ($payment['total_sale_amount'] ?? ''));
$installationDateTime = trim((string) ($installation['installation_datetime'] ?? ''));
$remarks = trim((string) ($installation['installation_remarks'] ?? ''));
$evidence = trim((string) ($installation['completion_evidence'] ?? ''));

if ($leadName === '' || mb_strlen($leadName) > 150 ||
    $customerNumber === '' || mb_strlen($customerNumber) > 100 ||
    !preg_match('/^\d{10}$/', $primaryMobile) ||
    ($secondaryMobile !== '' && (!preg_match('/^\d{10}$/', $secondaryMobile) || $secondaryMobile === $primaryMobile)) ||
    ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) ||
    $location === '' || mb_strlen($location) > 150 || !preg_match('/^\d{6}$/', $pincode) ||
    $platformId <= 0) {
    sendResponse(false, 'Correct the lead and customer contact details before resubmitting.', [], [], 400);
}
if ($vehicleNumber === '' || mb_strlen($vehicleNumber) > 30 ||
    $vehicleType === '' || $deviceType === '' ||
    !preg_match('/^\d{15}$/', $imei) ||
    !preg_match('/^\d{10}$|^\d{13}$/', $simNumber1) ||
    ($simNumber2 !== '' && (!preg_match('/^\d{10}$|^\d{13}$/', $simNumber2) || $simNumber2 === $simNumber1)) ||
    $validityMonths === false || $validityMonths <= 0 ||
    $saleAmountInput === '' || !is_numeric($saleAmountInput) ||
    (float) $saleAmountInput <= 0 || (float) $saleAmountInput > 9999999999.99) {
    sendResponse(false, 'Correct the vehicle, device, SIM, validity, and sale amount before resubmitting.', [], [], 400);
}
if (mb_strlen($remarks) > 2000 || mb_strlen($evidence) > 2000) {
    sendResponse(false, 'Installation remarks and evidence cannot exceed 2,000 characters each.', [], [], 400);
}
$dateTime = crmClosureParseInstallationDateTime($installationDateTime);
if (!$dateTime ||
    $dateTime->getTimestamp() > time()) {
    sendResponse(false, 'Enter a valid installation date and time that is not in the future.', [], [], 400);
}
$saleAmount = number_format((float) $saleAmountInput, 2, '.', '');

$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}
if (!$conn->begin_transaction()) {
    $error = $conn->error;
    $conn->close();
    sendResponse(false, 'Unable to begin installation correction: ' . $error, [], [], 500);
}

try {
    $submissionStmt = $conn->prepare(
        'SELECT s.submitter_user_id, s.status, s.submission_data, s.linked_customer_id,
                s.linked_vehicle_id, s.linked_payment_id,
                item.id AS installation_device_id, item.closure_id, item.device_sequence,
                item.device_id, item.sim_id_1, item.sim_id_2, item.customer_vehicle_id,
                item.customer_installation_id, item.verification_status,
                cf.lead_id, cf.installation_person_type, cf.installation_person_id,
                COALESCE(NULLIF(l.assigned_user_id, 0), NULLIF(l.created_by_user_id, 0),
                         cf.submitted_by_user_id) AS lead_owner_user_id
         FROM customer_payment_verification_submissions s
         INNER JOIN crm_closure_installation_devices item
            ON item.verification_submission_id = s.id
         INNER JOIN crm_lead_closure_forms cf ON cf.id = item.closure_id
         INNER JOIN crm_leads l ON l.id = cf.lead_id
         WHERE s.id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$submissionStmt) {
        throw new RuntimeException('Unable to prepare returned installation lookup: ' . $conn->error);
    }
    $submissionStmt->bind_param('i', $submissionId);
    if (!$submissionStmt->execute()) {
        $error = $submissionStmt->error;
        $submissionStmt->close();
        throw new RuntimeException('Unable to load returned installation: ' . $error);
    }
    $record = $submissionStmt->get_result()->fetch_assoc();
    $submissionStmt->close();
    if (!$record || $record['status'] !== 'Rejected' ||
        ($record['lead_owner_user_id'] !== null && (int) $record['lead_owner_user_id'] !== $userId)) {
        throw new DomainException('Only the employee assigned to this lead can edit and resubmit this rejected installation.');
    }
    $submissionData = decodeCustomerPaymentSubmission((string) $record['submission_data']);
    if (($submissionData['source'] ?? '') !== 'crm_installation') {
        throw new DomainException('This correction endpoint only accepts CRM installation submissions.');
    }
    $installationDeviceId = (int) $record['installation_device_id'];
    $closureId = (int) $record['closure_id'];
    $leadId = (int) $record['lead_id'];
    $customerId = (int) ($record['linked_customer_id'] ?? 0);
    $vehicleId = (int) ($record['linked_vehicle_id'] ?? 0);
    $paymentId = (int) ($record['linked_payment_id'] ?? 0);
    if ($installationDeviceId <= 0 || $closureId <= 0 || $customerId <= 0 || $vehicleId <= 0 || $paymentId <= 0) {
        throw new DomainException('The rejected installation has incomplete Customer Management links and cannot be resubmitted.');
    }

    $platformStmt = $conn->prepare("SELECT id FROM platforms WHERE id = ? AND status = 'Active' LIMIT 1 FOR UPDATE");
    if (!$platformStmt) {
        throw new RuntimeException('Unable to prepare platform validation.');
    }
    $platformStmt->bind_param('i', $platformId);
    if (!$platformStmt->execute() || $platformStmt->get_result()->num_rows !== 1) {
        $platformStmt->close();
        throw new DomainException('Select an active platform.');
    }
    $platformStmt->close();

    $customerStmt = $conn->prepare(
        'SELECT * FROM customers WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$customerStmt) {
        throw new RuntimeException('Unable to prepare CRM customer correction.');
    }
    $customerStmt->bind_param('i', $customerId);
    if (!$customerStmt->execute()) {
        $customerStmt->close();
        throw new RuntimeException('Unable to load CRM customer correction record.');
    }
    $oldCustomer = $customerStmt->get_result()->fetch_assoc();
    $customerStmt->close();
    if (!$oldCustomer) {
        throw new DomainException('The linked customer record no longer exists.');
    }
    $duplicateCustomer = $conn->prepare(
        'SELECT id FROM customers
         WHERE platform_id = ? AND LOWER(TRIM(username)) = LOWER(TRIM(?)) AND id <> ?
         LIMIT 1 FOR UPDATE'
    );
    if (!$duplicateCustomer) {
        throw new RuntimeException('Unable to prepare customer-number duplicate validation.');
    }
    $duplicateCustomer->bind_param('isi', $platformId, $customerNumber, $customerId);
    if (!$duplicateCustomer->execute()) {
        $duplicateCustomer->close();
        throw new RuntimeException('Unable to verify customer-number uniqueness.');
    }
    if ($duplicateCustomer->get_result()->num_rows > 0) {
        $duplicateCustomer->close();
        throw new DomainException('Another customer already uses this customer number on the selected platform.');
    }
    $duplicateCustomer->close();

    $vehicleTypeId = crmClosureDeviceMasterId($conn, 'vehicle_types', 'vehicle_type', $vehicleType);
    $deviceTypeId = crmClosureDeviceMasterId($conn, 'device_types', 'device_type', $deviceType);
    if (!$vehicleTypeId || !$deviceTypeId) {
        throw new DomainException('Select active vehicle and device types.');
    }
    $validityStmt = $conn->prepare(
        "SELECT id FROM sim_validities
         WHERE months = ? AND LOWER(COALESCE(status, 'active')) = 'active'
         LIMIT 1 FOR UPDATE"
    );
    if (!$validityStmt) {
        throw new RuntimeException('Unable to prepare SIM validity validation.');
    }
    $validityStmt->bind_param('i', $validityMonths);
    if (!$validityStmt->execute() || $validityStmt->get_result()->num_rows !== 1) {
        $validityStmt->close();
        throw new DomainException('Select an active SIM/service validity.');
    }
    $validityStmt->close();

    $vehicleStmt = $conn->prepare(
        'SELECT * FROM customer_vehicle_details
         WHERE id = ? AND customer_id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$vehicleStmt) {
        throw new RuntimeException('Unable to prepare installed vehicle correction.');
    }
    $vehicleStmt->bind_param('ii', $vehicleId, $customerId);
    if (!$vehicleStmt->execute()) {
        $vehicleStmt->close();
        throw new RuntimeException('Unable to load installed vehicle correction record.');
    }
    $oldVehicle = $vehicleStmt->get_result()->fetch_assoc();
    $vehicleStmt->close();
    if (!$oldVehicle || (int) $record['customer_vehicle_id'] !== $vehicleId) {
        throw new DomainException('The linked vehicle record does not match the rejected installation.');
    }
    $duplicateVehicle = $conn->prepare(
        'SELECT id FROM customer_vehicle_details
         WHERE UPPER(TRIM(vehicle_no)) = UPPER(TRIM(?)) AND id <> ?
         LIMIT 1 FOR UPDATE'
    );
    if (!$duplicateVehicle) {
        throw new RuntimeException('Unable to prepare vehicle-registration duplicate validation.');
    }
    $duplicateVehicle->bind_param('si', $vehicleNumber, $vehicleId);
    if (!$duplicateVehicle->execute()) {
        $duplicateVehicle->close();
        throw new RuntimeException('Unable to verify vehicle-registration uniqueness.');
    }
    if ($duplicateVehicle->get_result()->num_rows > 0) {
        $duplicateVehicle->close();
        throw new DomainException('Another customer vehicle already uses this registration number.');
    }
    $duplicateVehicle->close();
    $stagedVehicle = $conn->prepare(
        'SELECT id FROM crm_closure_installation_devices
         WHERE closure_id = ? AND vehicle_number = ? AND id <> ? LIMIT 1 FOR UPDATE'
    );
    if (!$stagedVehicle) {
        throw new RuntimeException('Unable to prepare per-order vehicle duplicate validation.');
    }
    $stagedVehicle->bind_param('isi', $record['closure_id'], $vehicleNumber, $installationDeviceId);
    if (!$stagedVehicle->execute()) {
        $stagedVehicle->close();
        throw new RuntimeException('Unable to verify per-order vehicle registration.');
    }
    if ($stagedVehicle->get_result()->num_rows > 0) {
        $stagedVehicle->close();
        throw new DomainException('Another device in this order already uses this vehicle registration.');
    }
    $stagedVehicle->close();

    $installationId = (int) $record['customer_installation_id'];
    $installationStmt = $conn->prepare(
        'SELECT * FROM customer_installations
         WHERE id = ? AND customer_id = ? AND vehicle_id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$installationStmt) {
        throw new RuntimeException('Unable to prepare installation correction.');
    }
    $installationStmt->bind_param('iii', $installationId, $customerId, $vehicleId);
    if (!$installationStmt->execute()) {
        $installationStmt->close();
        throw new RuntimeException('Unable to load installation correction record.');
    }
    $oldInstallation = $installationStmt->get_result()->fetch_assoc();
    $installationStmt->close();
    if (!$oldInstallation) {
        throw new DomainException('The linked installation record no longer exists.');
    }
    $paymentStmt = $conn->prepare(
        'SELECT * FROM customer_payments
         WHERE id = ? AND customer_id = ? AND vehicle_id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$paymentStmt) {
        throw new RuntimeException('Unable to prepare payment correction.');
    }
    $paymentStmt->bind_param('iii', $paymentId, $customerId, $vehicleId);
    if (!$paymentStmt->execute()) {
        $paymentStmt->close();
        throw new RuntimeException('Unable to load payment correction record.');
    }
    $oldPayment = $paymentStmt->get_result()->fetch_assoc();
    $paymentStmt->close();
    if (!$oldPayment) {
        throw new DomainException('The linked payment record no longer exists.');
    }

    $deviceId = (int) $record['device_id'];
    $simId1 = (int) $record['sim_id_1'];
    $simId2 = $record['sim_id_2'] !== null ? (int) $record['sim_id_2'] : null;
    if (
        strcasecmp((string) $oldVehicle['imei_no'], $imei) !== 0 ||
        (string) $oldVehicle['sim_no_1'] !== $simNumber1 ||
        (string) ($oldVehicle['sim_no_2'] ?? '') !== $simNumber2
    ) {
        throw new DomainException('Installed IMEI and SIM identifiers cannot be changed after physical installation. Correct the physical installation and inventory record before resubmitting.');
    }
    $deviceModelStmt = $conn->prepare('SELECT device_model_id FROM devices WHERE id = ? LIMIT 1 FOR UPDATE');
    if (!$deviceModelStmt) {
        throw new RuntimeException('Unable to prepare installed device model validation.');
    }
    $deviceModelStmt->bind_param('i', $deviceId);
    if (!$deviceModelStmt->execute() ||
        (int) ($deviceModelStmt->get_result()->fetch_assoc()['device_model_id'] ?? 0) !== $deviceTypeId) {
        $deviceModelStmt->close();
        throw new DomainException('The selected device type must match the physically installed device.');
    }
    $deviceModelStmt->close();

    $dateTimeValue = $dateTime->format('Y-m-d H:i:s');
    $installationDate = $dateTime->format('Y-m-d');
    $locationValue = $location;
    $secondaryValue = $secondaryMobile !== '' ? $secondaryMobile : null;
    $emailValue = $email !== '' ? $email : null;
    $simNumber2Value = $simNumber2 !== '' ? $simNumber2 : null;
    $customerChanges = [
        'platform_id' => $platformId,
        'username' => $customerNumber,
        'primary_mobile_no' => $primaryMobile,
        'secondary_mobile_no' => $secondaryValue,
        'email' => $emailValue,
        'location' => $locationValue,
        'pincode' => $pincode,
        'verification_status' => 'Pending'
    ];
    writeChangedFields($conn, $customerId, 'Customer', $oldCustomer, $customerChanges, $currentUser);
    $updateCustomer = $conn->prepare(
        "UPDATE customers
         SET platform_id = ?, username = ?, primary_mobile_no = ?,
             secondary_mobile_no = ?, email = ?, location = ?, pincode = ?,
             verification_status = 'Pending', updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    if (!$updateCustomer) {
        throw new RuntimeException('Unable to prepare customer correction update.');
    }
    $updateCustomer->bind_param(
        'issssssi',
        $platformId,
        $customerNumber,
        $primaryMobile,
        $secondaryValue,
        $emailValue,
        $locationValue,
        $pincode,
        $customerId
    );
    if (!$updateCustomer->execute()) {
        $updateCustomer->close();
        throw new RuntimeException('Unable to update customer correction details.');
    }
    $updateCustomer->close();

    $vehicleChanges = [
        'vehicle_no' => $vehicleNumber,
        'vehicle_type_id' => $vehicleTypeId,
        'device_id' => $deviceId,
        'device_model_id' => $deviceTypeId,
        'imei_no' => $imei,
        'sim_id_1' => $simId1,
        'sim_no_1' => $simNumber1,
        'sim_id_2' => $simId2,
        'sim_no_2' => $simNumber2Value,
        'validity_months' => $validityMonths
    ];
    writeChangedFields($conn, $vehicleId, 'Customer Vehicle Details', $oldVehicle, $vehicleChanges, $currentUser);
    $updateVehicle = $conn->prepare(
        'UPDATE customer_vehicle_details
         SET vehicle_no = ?, vehicle_type_id = ?, device_id = ?, device_model_id = ?,
             imei_no = ?, sim_id_1 = ?, sim_no_1 = ?, sim_id_2 = ?, sim_no_2 = ?,
             validity_months = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND customer_id = ?'
    );
    if (!$updateVehicle) {
        throw new RuntimeException('Unable to prepare vehicle correction update.');
    }
    $updateVehicle->bind_param(
        'siiisisisiii',
        $vehicleNumber,
        $vehicleTypeId,
        $deviceId,
        $deviceTypeId,
        $imei,
        $simId1,
        $simNumber1,
        $simId2,
        $simNumber2Value,
        $validityMonths,
        $vehicleId,
        $customerId
    );
    if (!$updateVehicle->execute()) {
        $updateVehicle->close();
        throw new RuntimeException('Unable to update vehicle correction details.');
    }
    $updateVehicle->close();

    $installationChanges = [
        'installation_date' => $installationDate,
        'installation_datetime' => $dateTimeValue,
        'installation_remarks' => $remarks,
        'completion_evidence' => $evidence
    ];
    writeChangedFields($conn, $installationId, 'Customer Installation', $oldInstallation, $installationChanges, $currentUser);
    $updateInstallation = $conn->prepare(
        'UPDATE customer_installations
         SET installation_date = ?, installation_datetime = ?,
             installation_remarks = ?, completion_evidence = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND customer_id = ? AND vehicle_id = ?'
    );
    if (!$updateInstallation) {
        throw new RuntimeException('Unable to prepare installation correction update.');
    }
    $updateInstallation->bind_param(
        'ssssiii',
        $installationDate,
        $dateTimeValue,
        $remarks,
        $evidence,
        $installationId,
        $customerId,
        $vehicleId
    );
    if (!$updateInstallation->execute()) {
        $updateInstallation->close();
        throw new RuntimeException('Unable to update installation correction details.');
    }
    $updateInstallation->close();

    $paymentChanges = ['total_sale_amount' => (float) $saleAmount];
    writeChangedFields($conn, $paymentId, 'Customer Payment', $oldPayment, $paymentChanges, $currentUser);
    $updatePayment = $conn->prepare(
        'UPDATE customer_payments SET total_sale_amount = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND customer_id = ? AND vehicle_id = ?'
    );
    if (!$updatePayment) {
        throw new RuntimeException('Unable to prepare payment correction update.');
    }
    $updatePayment->bind_param('diii', $saleAmount, $paymentId, $customerId, $vehicleId);
    if (!$updatePayment->execute()) {
        $updatePayment->close();
        throw new RuntimeException('Unable to update payment correction details.');
    }
    $updatePayment->close();

    $itemStmt = $conn->prepare(
        "UPDATE crm_closure_installation_devices
         SET vehicle_number = ?, vehicle_type_id = ?, device_model_id = ?, sale_amount = ?,
             imei_no = ?, sim_no_1 = ?, sim_no_2 = ?, validity_months = ?,
             installation_datetime = ?, installation_remarks = ?, completion_evidence = ?,
             verification_status = 'Pending Verification', verification_rejection_reason = NULL
         WHERE id = ? AND verification_submission_id = ? AND installation_status = 'Completed'"
    );
    if (!$itemStmt) {
        throw new RuntimeException('Unable to prepare installation progress correction.');
    }
    $itemStmt->bind_param(
        'siidsssisssii',
        $vehicleNumber,
        $vehicleTypeId,
        $deviceTypeId,
        $saleAmount,
        $imei,
        $simNumber1,
        $simNumber2Value,
        $validityMonths,
        $dateTimeValue,
        $remarks,
        $evidence,
        $installationDeviceId,
        $submissionId
    );
    if (!$itemStmt->execute() || $itemStmt->affected_rows !== 1) {
        $itemStmt->close();
        throw new RuntimeException('Unable to save corrected installation progress.');
    }
    $itemStmt->close();

    $leadStmt = $conn->prepare(
        'SELECT lead_name, mobile_no, alternate_mobile_no, email, location, pincode
         FROM crm_leads WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$leadStmt) {
        throw new RuntimeException('Unable to prepare original lead correction.');
    }
    $leadStmt->bind_param('i', $leadId);
    if (!$leadStmt->execute()) {
        $leadStmt->close();
        throw new RuntimeException('Unable to load original lead correction record.');
    }
    $oldLead = $leadStmt->get_result()->fetch_assoc() ?: [];
    $leadStmt->close();
    $leadChanges = [
        'lead_name' => $leadName,
        'mobile_no' => $primaryMobile,
        'alternate_mobile_no' => $secondaryValue,
        'email' => $emailValue,
        'location' => $location,
        'pincode' => $pincode
    ];
    writeChangedFields($conn, $leadId, 'CRM Lead', $oldLead, $leadChanges, $currentUser);
    $updateLead = $conn->prepare(
        'UPDATE crm_leads
         SET lead_name = ?, mobile_no = ?, alternate_mobile_no = ?,
             email = ?, location = ?, pincode = ?, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    if (!$updateLead) {
        throw new RuntimeException('Unable to prepare lead correction update.');
    }
    $updateLead->bind_param(
        'ssssssi',
        $leadName,
        $primaryMobile,
        $secondaryValue,
        $emailValue,
        $location,
        $pincode,
        $leadId
    );
    if (!$updateLead->execute()) {
        $updateLead->close();
        throw new RuntimeException('Unable to update original lead details.');
    }
    $updateLead->close();

    $updateClosure = $conn->prepare(
        "UPDATE crm_lead_closure_forms
         SET platform_id = ?, username = ?, location = ?, pincode = ?,
             rejection_reason = NULL, submission_status = 'Installation In Progress'
         WHERE id = ?"
    );
    if (!$updateClosure) {
        throw new RuntimeException('Unable to prepare CRM closure correction.');
    }
    $updateClosure->bind_param('isssi', $platformId, $customerNumber, $location, $pincode, $closureId);
    if (!$updateClosure->execute()) {
        $updateClosure->close();
        throw new RuntimeException('Unable to update CRM closure details.');
    }
    $updateClosure->close();

    $submissionData['customer'] = [
        'platform_id' => $platformId,
        'username' => $customerNumber,
        'primary_mobile_no' => $primaryMobile,
        'secondary_mobile_no' => $secondaryMobile,
        'email' => $email,
        'location' => $location,
        'pincode' => $pincode,
        'status' => 'Active'
    ];
    $submissionData['vehicle'] = [
        'vehicle_no' => $vehicleNumber,
        'vehicle_type_id' => $vehicleTypeId,
        'vehicle_type' => $vehicleType,
        'device_model_id' => $deviceTypeId,
        'device_model' => $deviceType,
        'device_id' => $deviceId,
        'imei_no' => $imei,
        'sim_id_1' => $simId1,
        'sim_no_1' => $simNumber1,
        'sim_id_2' => $simId2,
        'sim_no_2' => $simNumber2Value,
        'validity_months' => $validityMonths,
        'vehicle_record_id' => $vehicleId,
        'installation' => [
            'installation_person_type' => $record['installation_person_type'] === 'Technician' ? 'Technician' : 'Dealer',
            'installation_person_id' => (int) $record['installation_person_id'],
            'lead_closure_id' => 0,
            'installation_date' => $installationDate,
            'installation_datetime' => $dateTimeValue,
            'installation_remarks' => $remarks,
            'completion_evidence' => $evidence
        ]
    ];
    $submissionData['payment_part1'] = [
        'total_sale_amount' => (float) $saleAmount,
        'payment_status' => 'Pending'
    ];
    $submissionData['existing_customer_id'] = $customerId;
    $submissionJson = json_encode($submissionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($submissionJson === false) {
        throw new RuntimeException('Unable to encode corrected Customer Verification data.');
    }
    $updateSubmission = $conn->prepare(
        "UPDATE customer_payment_verification_submissions
         SET existing_customer_id = ?, status = 'Pending Verification',
             submission_data = ?, rejection_reason = NULL, reviewer_user_id = NULL,
             submitted_at = CURRENT_TIMESTAMP, decided_at = NULL,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = 'Rejected'"
    );
    if (!$updateSubmission) {
        throw new RuntimeException('Unable to prepare verification resubmission.');
    }
    $updateSubmission->bind_param('isi', $customerId, $submissionJson, $submissionId);
    if (!$updateSubmission->execute() || $updateSubmission->affected_rows !== 1) {
        $updateSubmission->close();
        throw new DomainException('This verification request changed before it could be resubmitted.');
    }
    $updateSubmission->close();
    insertCustomerPaymentVerificationHistory(
        $conn,
        $submissionId,
        'Rejected',
        'Pending Verification',
        'Corrected by the assigned CRM lead owner and resubmitted.',
        $userId
    );
    $readNotification = $conn->prepare(
        "UPDATE crm_notifications
         SET is_read = 1
         WHERE user_id = ? AND related_entity_type = 'lead_closure'
           AND related_entity_id = ? AND title = 'Installation verification rejected'"
    );
    if (!$readNotification) {
        throw new RuntimeException('Unable to prepare correction notification update.');
    }
    $readNotification->bind_param('ii', $userId, $closureId);
    if (!$readNotification->execute()) {
        $readNotification->close();
        throw new RuntimeException('Unable to mark the installation rejection notification as read.');
    }
    $readNotification->close();

    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit corrected installation: ' . $conn->error);
    }
    $conn->close();
    sendResponse(true, 'Corrected installation returned to Customer Verification.', [
        'submission_id' => $submissionId,
        'status' => 'Pending Verification'
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    sendResponse(false, $error->getMessage(), [], [], 400);
}
?>
