<?php

function sendResponse($success, $message, $data = [], $errors = [], $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    
    $response = [
        "success" => $success,
        "message" => $message
    ];

    if (!empty($data)) {
        $response["data"] = $data;
    }

    if (!empty($errors)) {
        $response["errors"] = $errors;
    }

    echo json_encode($response);
    exit;
}

// Helper for handling preflight requests (CORS)
function handlePreflight() {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, DELETE, PUT, PATCH, OPTIONS');
        header('Access-Control-Allow-Headers: token, Content-Type, Authorization');
        header('Access-Control-Max-Age: 1728000');
        header('Content-Length: 0');
        header('Content-Type: text/plain');
        die();
    }
    
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, DELETE, PUT, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: token, Content-Type, Authorization');
}
?>
