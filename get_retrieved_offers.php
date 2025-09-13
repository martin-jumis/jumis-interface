<?php
session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: /jumis/index.html');
    exit();
}

// The raw JSON files is OUTSIDE the web-accessible directory in a 'app_data' folder above 'public_html'
$jsonFilePath = '../../app_data/retrieved-offers.json';

if (!file_exists($jsonFilePath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Offers file not found.']);
    exit();
}

$jsonContent = file_get_contents($jsonFilePath);

header('Content-Type: application/json; charset=UTF-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

echo $jsonContent;

exit();
?>