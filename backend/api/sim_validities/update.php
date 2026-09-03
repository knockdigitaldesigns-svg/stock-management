<?php
require_once '../../config/database.php'; require_once '../../utils/response.php'; require_once '../../middleware/auth.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('sim_validity.edit'); $data = json_decode(file_get_contents('php://input')); $id = (int) ($data->id ?? 0); $monthsRaw = trim((string) ($data->months ?? ''));
if ($id <= 0) sendResponse(false, 'SIM validity ID is required.', [], [], 400); if (!preg_match('/^[1-9][0-9]*$/', $monthsRaw)) sendResponse(false, 'Number of months must be a positive integer.', [], [], 400);
$months = (int) $monthsRaw; $conn = (new Database())->getConnection(); if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$check = $conn->prepare('SELECT id FROM sim_validities WHERE months = ? AND id != ? LIMIT 1'); $check->bind_param('ii', $months, $id); $check->execute();
if ($check->get_result()->num_rows) { $check->close(); $conn->close(); sendResponse(false, 'SIM validity already exists.', [], [], 409); } $check->close();
$exists = $conn->prepare('SELECT id FROM sim_validities WHERE id = ? LIMIT 1'); $exists->bind_param('i', $id); $exists->execute(); if (!$exists->get_result()->num_rows) { $exists->close(); $conn->close(); sendResponse(false, 'SIM validity not found.', [], [], 404); } $exists->close();
$stmt = $conn->prepare('UPDATE sim_validities SET months = ? WHERE id = ?'); $stmt->bind_param('ii', $months, $id); $stmt->execute(); $stmt->close(); $conn->close(); sendResponse(true, 'SIM validity updated successfully.');
