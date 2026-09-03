<?php
require_once '../../config/database.php'; require_once '../../utils/response.php'; require_once '../../middleware/auth.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('sim_validity.delete'); $data = json_decode(file_get_contents('php://input')); $id = (int) ($data->id ?? 0); if ($id <= 0) sendResponse(false, 'SIM validity ID is required.', [], [], 400);
$conn = (new Database())->getConnection(); if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$used = $conn->prepare('SELECT id FROM sims WHERE sim_validity_id = ? LIMIT 1'); $used->bind_param('i', $id); $used->execute();
if ($used->get_result()->num_rows) { $used->close(); $conn->close(); sendResponse(false, 'This SIM validity is already used by existing SIM records and cannot be deleted.', [], [], 409); } $used->close();
$stmt = $conn->prepare('DELETE FROM sim_validities WHERE id = ?'); $stmt->bind_param('i', $id); $stmt->execute(); if (!$stmt->affected_rows) { $stmt->close(); $conn->close(); sendResponse(false, 'SIM validity not found.', [], [], 404); } $stmt->close(); $conn->close(); sendResponse(true, 'SIM validity deleted successfully.');
