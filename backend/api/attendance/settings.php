<?php
require_once '../../config/database.php';
require_once '../../utils/response.php';
require_once '../../middleware/auth.php';

handlePreflight();
$payload = authenticate();
if (!userHasPermission((int) ($payload['user_id'] ?? 0), 'attendance.settings')) {
    sendResponse(false, 'You do not have permission to perform this action.', [], [], 403);
}
$conn = (new Database())->getConnection();
if (!$conn) {
    sendResponse(false, 'Database connection failed.', [], [], 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $settings = getCurrentAttendanceSettings($conn);
        $conn->close();
        sendResponse(true, 'Attendance settings loaded.', [
            'settings' => [
                'office_start_time' => substr((string) $settings['office_start_time'], 0, 5),
                'office_end_time' => substr((string) $settings['office_end_time'], 0, 5),
                'full_day_hours' => (float) $settings['full_day_hours'],
                'half_day_hours' => (float) $settings['half_day_hours'],
                'inactivity_minutes' => (int) $settings['inactivity_minutes'],
                'timezone' => (string) $settings['timezone'],
                'updated_at' => $settings['updated_at'] ?? null
            ]
        ]);
    } catch (Throwable $error) {
        $conn->close();
        error_log('Unable to load attendance settings: ' . $error->getMessage());
        sendResponse(false, 'Unable to load attendance settings.', [], [], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $conn->close();
    sendResponse(false, 'Method not allowed.', [], [], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $conn->close();
    sendResponse(false, 'Invalid attendance settings payload.', [], [], 400);
}

$officeStart = trim((string) ($data['office_start_time'] ?? ''));
$officeEnd = trim((string) ($data['office_end_time'] ?? ''));
$fullDayHours = $data['full_day_hours'] ?? null;
$halfDayHours = $data['half_day_hours'] ?? null;
$inactivityMinutes = filter_var($data['inactivity_minutes'] ?? null, FILTER_VALIDATE_INT);
$timezone = trim((string) ($data['timezone'] ?? ''));
$timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
$timezoneIdentifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);

if (
    !preg_match($timePattern, $officeStart)
    || !preg_match($timePattern, $officeEnd)
    || $officeStart >= $officeEnd
    || !is_numeric($fullDayHours)
    || !is_numeric($halfDayHours)
    || (float) $halfDayHours <= 0
    || (float) $fullDayHours <= (float) $halfDayHours
    || (float) $fullDayHours > 24
    || $inactivityMinutes === false
    || $inactivityMinutes < 1
    || $inactivityMinutes > 1440
    || !in_array($timezone, $timezoneIdentifiers, true)
) {
    $conn->close();
    sendResponse(false, 'Enter valid office hours, thresholds, inactivity time, and timezone.', [], [], 400);
}

$officeStart .= ':00';
$officeEnd .= ':00';
$fullDayHours = (float) $fullDayHours;
$halfDayHours = (float) $halfDayHours;
$actorId = (int) ($payload['user_id'] ?? 0);

try {
    $conn->begin_transaction();
    getCurrentAttendanceSettings($conn);
    $new = [
        'office_start_time' => $officeStart,
        'office_end_time' => $officeEnd,
        'full_day_hours' => $fullDayHours,
        'half_day_hours' => $halfDayHours,
        'inactivity_minutes' => $inactivityMinutes,
        'timezone' => $timezone
    ];
    $update = $conn->prepare(
        'UPDATE attendance_settings
         SET office_start_time = ?, office_end_time = ?, full_day_hours = ?, half_day_hours = ?,
             inactivity_minutes = ?, timezone = ?, updated_by_user_id = ?
         WHERE id = 1'
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare attendance settings update: ' . $conn->error);
    }
    $update->bind_param(
        'ssddisi',
        $new['office_start_time'],
        $new['office_end_time'],
        $new['full_day_hours'],
        $new['half_day_hours'],
        $new['inactivity_minutes'],
        $new['timezone'],
        $actorId
    );
    if (!$update->execute()) {
        $error = $update->error;
        $update->close();
        throw new RuntimeException('Unable to update attendance settings: ' . $error);
    }
    $update->close();

    $history = $conn->prepare(
        'INSERT INTO attendance_settings_history
            (office_start_time, office_end_time, full_day_hours, half_day_hours, inactivity_minutes, timezone, changed_by_user_id, effective_at_utc)
         VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
    );
    if (!$history) {
        throw new RuntimeException('Unable to prepare attendance settings history: ' . $conn->error);
    }
    $history->bind_param(
        'ssddisi',
        $new['office_start_time'],
        $new['office_end_time'],
        $new['full_day_hours'],
        $new['half_day_hours'],
        $new['inactivity_minutes'],
        $new['timezone'],
        $actorId
    );
    if (!$history->execute()) {
        $error = $history->error;
        $history->close();
        throw new RuntimeException('Unable to save attendance settings history: ' . $error);
    }
    $history->close();
    $conn->commit();
    $conn->close();
    sendResponse(true, 'Attendance settings saved.', ['settings' => $new]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    error_log('Unable to save attendance settings: ' . $error->getMessage());
    sendResponse(false, 'Unable to save attendance settings.', [], [], 500);
}
?>
