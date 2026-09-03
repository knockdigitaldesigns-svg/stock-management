<?php
require_once '../../config/database.php'; require_once '../../utils/response.php'; require_once '../../middleware/auth.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendResponse(false, 'Method not allowed', [], [], 405);
requirePermission('sim_validity.add'); $data = json_decode(file_get_contents('php://input')); $monthsRaw = trim((string) ($data->months ?? ''));
if (!preg_match('/^[1-9][0-9]*$/', $monthsRaw)) sendResponse(false, 'Number of months must be a positive integer.', [], [], 400);
$months = (int) $monthsRaw; $conn = (new Database())->getConnection(); if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$check = $conn->prepare('SELECT id FROM sim_validities WHERE months = ? LIMIT 1'); $check->bind_param('i', $months); $check->execute();
if ($check->get_result()->num_rows) { $check->close(); $conn->close(); sendResponse(false, 'SIM validity already exists.', [], [], 409); } $check->close();
$stmt = $conn->prepare("INSERT INTO sim_validities (months, status) VALUES (?, 'active')"); $stmt->bind_param('i', $months);
if (!$stmt->execute()) { $stmt->close(); $conn->close(); sendResponse(false, 'Failed to add SIM validity.', [], [], 500); }
$stmt->close(); $conn->close(); sendResponse(true, 'SIM validity added successfully.');
