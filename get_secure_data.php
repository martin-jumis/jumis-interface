<?php
// Set headers for JSON response and no caching
header('Content-Type: application/json');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- Define the secure path to your data file ---
// CORRECTED PATH: Goes up one level to 'public_html/', then into 'app_data/'
$secureDataFilePath = __DIR__ . '/../app_data/retrieved-data.json';
// If this file is in 'task-manager/public_html/jumis/', this resolves to:
// 'task-manager/public_html/app_data/retrieved-data.json' which is correct.

// --- API Credentials (Replace with secure methods in production) ---
$valid_username = 'api_pantheon_tm';
$valid_password = 'JumisTM25';

// Get the raw POST data (Ensure you are using POST requests from Postman!)
$input_json = file_get_contents('php://input');
$request_data = json_decode($input_json, true);

// --- DEBUGGING START (Keep these for troubleshooting) ---
error_log("get_secure_data.php: Raw input JSON: " . ($input_json === false ? "Failed to read input" : $input_json));
error_log("get_secure_data.php: Decoded request data: " . json_encode($request_data));
error_log("get_secure_data.php: Attempting to access file: " . $secureDataFilePath);
// --- DEBUGGING END ---

// Check if JSON decoding was successful and data exists
if (json_last_error() !== JSON_ERROR_NONE || empty($request_data)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid JSON input or empty request.', 'details' => json_last_error_msg()]);
    exit();
}

// Extract username and password from the request
$username = $request_data['username'] ?? '';
$password = $request_data['password'] ?? '';

// --- Authenticate the user ---
if ($username === $valid_username && $password === $valid_password) {
    // Authentication successful, now read the data file
    if (file_exists($secureDataFilePath)) {
        $data_content = file_get_contents($secureDataFilePath);

        // --- DEBUGGING START (Keep these for troubleshooting) ---
        error_log("get_secure_data.php: Content read from file (first 500 chars): " . substr($data_content, 0, 500) . (strlen($data_content) > 500 ? '...' : ''));
        // --- DEBUGGING END ---

        // Check for file read errors
        if ($data_content === false) {
            http_response_code(500); // Internal Server Error
            echo json_encode(['error' => 'Failed to read data file.']);
            exit();
        }

        // Try to decode the JSON content of the data file
        $decoded_data = json_decode($data_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(500); // Internal Server Error
            echo json_encode(['error' => 'Data file is corrupted or contains invalid JSON.', 'details' => json_last_error_msg()]);
            exit();
        }

        // Return the data
        http_response_code(200); // OK
        echo json_encode($decoded_data); // Return the content of retrieved-data.json
    } else {
        http_response_code(404); // Not Found
        // Make sure to show the path it tried to access for easier debugging
        echo json_encode(['error' => 'Data file not found at: ' . $secureDataFilePath]);
    }
} else {
    // Authentication failed
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Invalid username or password.']);
}
?>