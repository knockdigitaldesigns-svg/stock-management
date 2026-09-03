<?php
require_once '../../config/database.php'; require_once '../../utils/response.php'; require_once '../../middleware/auth.php';
handlePreflight();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendResponse(false, 'Method not allowed', [], [], 405);
requireAnyPermission(['sim_validity.view', 'sims.view']);
$conn = (new Database())->getConnection(); if (!$conn) sendResponse(false, 'Database connection failed', [], [], 500);
$result = $conn->query("SELECT id, months, status, created_at, updated_at FROM sim_validities WHERE status = 'active' ORDER BY months ASC");
$validities = [];
while ($result && ($row = $result->fetch_assoc())) { $row['months'] = (int) $row['months']; $validities[] = $row; }
$conn->close(); sendResponse(true, 'SIM validities fetched successfully', ['validities' => $validities]);
