<?php
header('Content-Type: application/json');
session_start(); // Start the session to store login status

// For simplicity, hardcoded credentials. In a real app, fetch from DB.
$users = [
    'laste' => '123',
    'ivan' => '123',
    'goran' => '123',
    'dragan' => '123',
    'ivica' => '123',
    'dijana' => '123',
    'toni' => '123',
    'nikola' => '123',
    'viktor' => '123',
    'martin' => '123',
    'deni' => '123'
];

$input = json_decode(file_get_contents('php://input'), true);

if (isset($input['action']) && $input['action'] === 'login') {
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    if (isset($users[$username]) && $users[$username] === $password) {
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $username;
        echo json_encode(['success' => true, 'message' => 'Login successful!']);
    } else {
        $_SESSION['loggedin'] = false;
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    }
    exit;
} elseif (isset($_GET['action']) && $_GET['action'] === 'check_session') {
    if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
        echo json_encode(['loggedin' => true, 'username' => $_SESSION['username']]);
    } else {
        echo json_encode(['loggedin' => false]);
    }
    exit;
} elseif (isset($input['action']) && $input['action'] === 'logout') {
    session_unset(); // Unset all session variables
    session_destroy(); // Destroy the session
    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
    exit;
}

// If no specific action, return not found or unauthorized
http_response_code(400);
echo json_encode(['error' => 'Invalid action or request.']);
?>