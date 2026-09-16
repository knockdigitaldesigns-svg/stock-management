<?php
require_once '../../config/database.php'; require_once '../../utils/response.php'; require_once '../../middleware/auth.php';
require_once '../../utils/audit.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
$currentUser = authenticate(); requirePermission('sim_validity.delete'); $data = json_decode(file_get_contents('php://input')); $id = (int) ($data->id ?? 0); if ($id <= 0) sendResponse(false, 'SIM validity ID is required.', [], [], 400);
$conn = (new Database())->getConnection(); if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$used = $conn->prepare('SELECT id FROM sims WHERE sim_validity_id = ? LIMIT 1'); $used->bind_param('i', $id); $used->execute();
if ($used->get_result()->num_rows) { $used->close(); $conn->close(); sendResponse(false, 'This SIM validity is already used by existing SIM records and cannot be deleted.', [], [], 409); } $used->close();
$oldStmt = $conn->prepare('SELECT * FROM sim_validities WHERE id = ? LIMIT 1'); $oldStmt->bind_param('i', $id); $oldStmt->execute(); $oldRecord = $oldStmt->get_result()->fetch_assoc(); $oldStmt->close(); if (!$oldRecord) { $conn->close(); sendResponse(false, 'SIM validity not found.', [], [], 404); }
$conn->begin_transaction(); try { writeDeleteSnapshot($conn, $id, 'SIM Validity', $oldRecord, $currentUser); $stmt = $conn->prepare('DELETE FROM sim_validities WHERE id = ?'); $stmt->bind_param('i', $id); if (!$stmt->execute() || !$stmt->affected_rows) throw new RuntimeException('SIM validity could not be deleted.'); $stmt->close(); $conn->commit(); $conn->close(); sendResponse(true, 'SIM validity deleted successfully.'); } catch (Throwable $e) { $conn->rollback(); if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close(); $conn->close(); sendResponse(false, $e->getMessage(), [], [], 500); }
