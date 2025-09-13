<?php
// Set the content type to JSON
header('Content-Type: application/json');

// Define the path to the JSON file relative to the project root
// IMPORTANT: This path assumes get_retrieved_data.php is in task-manager/public_html/jumis/
// and the data file is in task-manager/app_data/
$jsonFilePath = '../../app_data/retrieved-data.json'; 

// Check if the file exists
if (file_exists($jsonFilePath)) {
    // Read the file content
    $jsonData = file_get_contents($jsonFilePath);

    // Check if data was read successfully and is not empty
    if ($jsonData === false || $jsonData === '') {
        echo json_encode(['error' => 'Could not read data from file or file is empty.']);
    } else {
        // Decode the JSON data
        $data = json_decode($jsonData, true);

        // Check for JSON decoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['error' => 'Error decoding JSON: ' . json_last_error_msg()]);
        } else {
            echo $jsonData; // Output the raw JSON data
        }
    }
} else {
    // If the file does not exist, return an error message
    echo json_encode(['error' => 'retrieved-data.json not found at the specified path.']);
}
?>