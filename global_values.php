<?php
session_start(); // Always at the very top of the PHP file

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // If not logged in, return an error or redirect
    header('Content-Type: application/json'); // Ensure JSON header for JS fetch
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized. Please log in.']);
    // Optionally, you could redirect for non-AJAX requests, but for fetch, JSON error is better.
    // header('Location: /jumis/index.html');
    exit();
}

header("Content-Type: application/json; charset=UTF-8");
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- 1. Copy API URLs and Auth Payload from lista-na-identi-sorted.php ---
// These are essential for connecting to the Pantheon API
$authApiUrl = 'http://192.168.88.25/api/Users/authwithtoken';
$orderApiUrl = 'http://192.168.88.25/api/Order/retrieve';
$setItemApiUrl = 'http://192.168.88.25/api/Ident/retrieve';

$authPayload = json_encode([
    "username" => "MS",
    "password" => "12345678",
    "companyDB" => "PB_MJD"
]);

// --- 2. Copy the sendCurlRequest function from lista-na-identi-sorted.php ---
// This function handles all API communication
function sendCurlRequest($url, $token = null, $payload = null, $customRequest = "POST") {
    $ch = curl_init();
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $customRequest,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_FAILONERROR => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false, // Set to true in production if you have proper SSL
        CURLOPT_SSL_VERIFYHOST => false, // Set to 2 in production if you have proper SSL
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $requestInfo = curl_getinfo($ch); // Get all request info for logging
    curl_close($ch);

    error_log("cURL Request: " . $customRequest . " " . $url);
    if ($payload) {
        error_log("cURL Payload: " . $payload);
    }
    error_log("cURL Response Code: " . $httpCode);
    error_log("cURL Response: " . $response); // Log the full response
    if ($curlError) {
        error_log("cURL Error: " . $curlError);
    }
    error_log("cURL Request Info: " . json_encode($requestInfo));

    if ($curlError) {
        return null;
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decoding error: " . json_last_error_msg() . " for response: " . $response);
            return null;
        }
        return $decodedResponse;
    } else {
        // Return decoded error response if available, otherwise null
        return json_decode($response, true);
    }
}

// --- New function to decode text from Pantheon API responses ---
function decodePantheonText($text) {
    if (!is_string($text)) {
        return $text;
    }

    // Decode escaped single quotes for Latin characters (Windows-1251)
    $decodedLatin = preg_replace_callback('/\\\\\'([0-9A-Fa-f]{2})/', function ($matches) {
        return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'Windows-1251');
    }, $text);

    // Decode Unicode escape sequences
    $fullyDecoded = preg_replace_callback('/\\\\u([0-9A-Fa-f]+)/', function ($matches) {
        $unicodeValue = intval($matches[1], 16); // Convert hex to int
        if ($unicodeValue >= 0 && $unicodeValue <= 0x10FFFF) {
            return mb_convert_encoding("&#" . $unicodeValue . ";", 'UTF-8', 'HTML-ENTITIES');
        } else {
            return ''; // Return empty string for invalid Unicode values
        }
    }, $decodedLatin);

    // Replace RTF formatting and other unwanted strings
    $fullyDecoded = str_replace('}\par \pard\plain\ql\sl275\slmult1\sa200{\fs22\cf0', '<br>', $fullyDecoded);
    $fullyDecoded = str_replace("Times New Roman CYR", "", $fullyDecoded);
    $fullyDecoded = str_replace("Times New Roman", "", $fullyDecoded);
    $fullyDecoded = str_replace(' { ', " <br> ", $fullyDecoded);
    $fullyDecoded = str_replace('{\\*', '', $fullyDecoded);
    $fullyDecoded = str_replace("Arial;", '', $fullyDecoded);
    $fullyDecoded = str_replace("Calibri;", '', $fullyDecoded);
    $fullyDecoded = str_replace("Times New Roman;", '', $fullyDecoded);
    $fullyDecoded = str_replace("Segoe UI;", '', $fullyDecoded);
    $fullyDecoded = str_replace("Verdana;", '', $fullyDecoded);
    $fullyDecoded = str_replace(" Normal;", '', $fullyDecoded);
    $fullyDecoded = str_replace(" Default Paragraph Font;", '', $fullyDecoded);
    $fullyDecoded = str_replace(" Line Number;", '', $fullyDecoded);
    $fullyDecoded = str_replace(" Hyperlink;", '', $fullyDecoded);
    $fullyDecoded = str_replace(" Normal Table;", '', $fullyDecoded);
    $fullyDecoded = str_replace(" Table Simple 1;", '', $fullyDecoded);
    $fullyDecoded = str_replace('}', " ", $fullyDecoded);
    $fullyDecoded = str_replace('{\cf2', "</b>", $fullyDecoded); // Closing bold tag
    $fullyDecoded = str_replace('{\b\cf2 ', "<b>", $fullyDecoded); // Opening bold tag
    $fullyDecoded = str_replace('\\line ', "\n", $fullyDecoded);
    $fullyDecoded = preg_replace('/\\\\[a-z]+\d*/', ' ', $fullyDecoded); // Remove remaining RTF commands like \fs22, \pard etc.
    $fullyDecoded = str_replace('{\* _dx_frag_StartFragment', "", $fullyDecoded);
    $fullyDecoded = str_replace(' {', "", $fullyDecoded);
    $fullyDecoded = str_replace('{ ', "", $fullyDecoded);
    $fullyDecoded = str_replace('; ', "", $fullyDecoded);
    $fullyDecoded = str_replace('{', "", $fullyDecoded);
    $fullyDecoded = str_replace(';', "", $fullyDecoded);
    $fullyDecoded = str_replace("\r\n", "", $fullyDecoded); // Remove Windows line endings
    $fullyDecoded = str_replace("\x00", "", $fullyDecoded); // Remove null bytes
    $fullyDecoded = preg_replace("/\s?Msftedit\s\d\.\d{2}\.\d{2}\.\d{4}/", '', $fullyDecoded); // Remove Msftedit signatures
    $fullyDecoded = preg_replace("/\s?Riched20\s\d{2}\.\d\.\d{5}/", '', $fullyDecoded); // Remove Riched20 signatures
    $fullyDecoded = preg_replace('/\s+/', ' ', $fullyDecoded); // Replace multiple spaces with a single space
    $fullyDecoded = trim($fullyDecoded); // Trim whitespace from ends

    return $fullyDecoded;
}


// --- 3. Authenticate with Pantheon API to get a token ---
$authData = sendCurlRequest($authApiUrl, null, $authPayload);
if (!isset($authData['token'])) {
    error_log("Error: Token not found in authentication response.");
    echo json_encode(['error' => 'Authentication failed. Could not retrieve token.']);
    exit();
}
$token = $authData['token'];
error_log("Successfully authenticated and retrieved token.");

$allDepartments = [];
$allClassifications = [];

// --- Fetch all distinct acDept values from Pantheon API (using OrderItem data) ---
$orderPayload = json_encode([
    "start" => 0,
    "length" => 9999999, // Request a very large length to get all orders
    "fieldsToReturn" => "ORD.acKeyView", // We only need minimal order data
    "tableFKs" => [
        [
            "table" => "tHE_OrderItem",
            "join" => "Orderitem.acKey = ORD.acKey",
            "alias" => "Orderitem",
            "fieldsToReturn" => "acDept, acName" // Added acName to be able to decode it if needed
        ]
    ],
    "sortColumn" => "ORD.adDate",
    "sortOrder" => "asc",
    "WithSubSelects" => 1,
    "tempTables" => []
]);

$orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);

if ($orderData && is_array($orderData)) {
    $tempDepts = [];
    foreach ($orderData as &$order) { // Use & for reference to modify the array
        if (isset($order['Orderitem']) && is_array($order['Orderitem'])) {
            foreach ($order['Orderitem'] as &$orderItem) { // Use & for reference
                // Decode acName for each order item
                if (isset($orderItem['acName'])) {
                    $orderItem['acName'] = decodePantheonText($orderItem['acName']);
                }

                // Collect values from acDept and potentially acDept1-acDept5
                for ($i = 0; $i <= 5; $i++) {
                    $colName = ($i == 0) ? 'acDept' : 'acDept' . $i;
                    if (isset($orderItem[$colName]) && !empty($orderItem[$colName])) {
                        // Decode department names if they contain special characters
                        $decodedDept = decodePantheonText($orderItem[$colName]);
                        $tempDepts[] = $decodedDept;
                    }
                }
            }
        }
    }
    // Add predefined default values and ensure uniqueness and sort
    $defaultDepts = ["Производство РАМНИ ПОВРШИНИ", "Производство ТАПЕТАРИЈА", "Производство СТОЛИЧАРА", "Производство ФАРБАРА", "Производство БРАВАРИЈА", "Производство МАГАЦИН"];
    $allDepartments = array_unique(array_merge($tempDepts, $defaultDepts));
    sort($allDepartments); // Sort alphabetically
    error_log("Fetched " . count($allDepartments) . " distinct departments from Pantheon API.");
} else {
    error_log("Error or no order data received from Pantheon API for departments: " . json_encode($orderData));
    // Fallback to defaults if API call fails
    $allDepartments = ["Производство РАМНИ ПОВРШИНИ", "Производство ТАПЕТАРИЈА", "Производство СТОЛИЧАРА", "Производство ФАРБАРА", "Производство БРАВАРИЈА", "Производство МАГАЦИН"];
}

// --- Fetch all distinct ACCLASSIF values from Pantheon API (using SetItem data) ---
$setItemPayload = json_encode([
    "start" => 0,
    "length" => 9999999, // Request a very large length to get all SetItems
    "fieldsToReturn" => "acIdent, acName, ACCLASSIF" // Added acName to be able to decode it if needed
]);

$setItemData = sendCurlRequest($setItemApiUrl, $token, $setItemPayload);

if ($setItemData && is_array($setItemData)) {
    $tempClassifs = [];
    foreach ($setItemData as &$setItem) { // Use & for reference to modify the array
        // Decode acName for each set item
        if (isset($setItem['acName'])) {
            $setItem['acName'] = decodePantheonText($setItem['acName']);
        }
        if (isset($setItem['ACCLASSIF']) && !empty($setItem['ACCLASSIF'])) {
            // Decode classification names
            $decodedClassif = decodePantheonText($setItem['ACCLASSIF']);
            $tempClassifs[] = $decodedClassif;
        }
    }
    // Add predefined default values and ensure uniqueness and sort
    $defaultClassifs = ["Агол", "БИР", "ГРТ", "ДВО", "ДУШ", "КАУ", "КМ", "КОМ", "КОНСТ", "КРЕ", "КУЈ", "Лежалка", "Лежај", "НАТ", "ОГЛ", "ОПЕРАЦИИ", "ПЕР", "ПЛА", "СТО", "ТАБ", "ТМ", "ТРО", "ТС", "ФИО", "ФОТ"];
    $allClassifications = array_unique(array_merge($tempClassifs, $defaultClassifs));
    sort($allClassifications); // Sort alphabetically
    error_log("Fetched " . count($allClassifications) . " distinct classifications from Pantheon API.");
} else {
    error_log("Error or no SetItem data received from Pantheon API for classifications: " . json_encode($setItemData));
    // Fallback to defaults if API call fails
    $allClassifications = ["Агол", "БИР", "ГРТ", "ДВО", "ДУШ", "КАУ", "КМ", "КОМ", "КОНСТ", "КРЕ", "КУЈ", "Лежалка", "Лежај", "НАТ", "ОГЛ", "ОПЕРАЦИИ", "ПЕР", "ПЛА", "СТО", "ТАБ", "ТМ", "ТРО", "ТС", "ФИО", "ФОТ"];
}

// Return all fetched data as a JSON object
// Ensure the JSON encoding of departments and classifications handles UTF-8 correctly
echo json_encode([
    'departments' => $allDepartments,
    'classifications' => $allClassifications
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>