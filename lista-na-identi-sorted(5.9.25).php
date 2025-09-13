<?php
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: /jumis/index.html');
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$authApiUrl = 'http://192.168.88.25/api/Users/authwithtoken';
$orderApiUrl = 'http://192.168.88.25/api/Order/retrieve';
$setItemApiUrl = 'http://192.168.88.25/api/Ident/retrieve';

$authPayload = json_encode([
    "username" => "MS",
    "password" => "12345678",
    "companyDB" => "PB_MJD"
]);

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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $requestInfo = curl_getinfo($ch);

    curl_close($ch);

    error_log("cURL Request: " . $customRequest . " " . $url);
    if ($payload) {
        error_log("cURL Payload: " . $payload);
    }
    error_log("cURL Response Code: " . $httpCode);
    error_log("cURL Response: " . $response);
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
        $decodedError = json_decode($response, true);
        return $decodedError ?: ['error' => "API returned HTTP error " . $httpCode . " with response: " . ($response ?: 'No response body')];
    }
}

$authData = sendCurlRequest($authApiUrl, null, $authPayload);

if (!isset($authData['token'])) {
    die(json_encode(['error' => "Error: Token not found in authentication response. Authentication failed or API returned invalid response."]));
}

$token = $authData['token'];

function buildOrderPayload($date = null, $startDate = null, $endDate = null, $orderCode = null) {
    $customConditions = [
        "condition" => "ORD.acDocType IN (@param1, @param2, @param3, @param4, @param5)",
        "params" => ["0110", "0130", "0160", "0250", "0270"]
    ];

    if ($date) {
        $customConditions['condition'] .= " AND ORD.adDate = @param6";
        $customConditions['params'][] = $date;
    } elseif ($startDate && $endDate) {
        $startDateObj = DateTime::createFromFormat('d.m.Y', $startDate);
        $endDateObj = DateTime::createFromFormat('d.m.Y', $endDate);
        if ($startDateObj && $endDateObj) {
            $formattedStartDate = $startDateObj->format('Y-m-d');
            $formattedEndDate = $endDateObj->format('Y-m-d');
            $customConditions['condition'] .= " AND ORD.adDate >= @param6 AND ORD.adDate <= @param7";
            $customConditions['params'][] = $formattedStartDate;
            $customConditions['params'][] = $formattedEndDate;
        } else {
            error_log("Invalid date format received: startDate=$startDate, endDate=$endDate");
            return ['error' => 'Invalid date format'];
        }
    }

    if ($orderCode) {
        $customConditions = ["condition" => "ORD.acKeyView = @param1", "params" => [$orderCode]];
    }

    return json_encode([
        "start" => 0,
        "length" => 0,
        "fieldsToReturn" => "ORD.acKeyView, ORD.acReceiver, ORD.adDate, ORD.adDateValid, ORD.acDelivery, ORD.acNote, ORD.acStatus, ORD.adDeliveryDate, ORD.acDocType, ORD.acConsignee",
        "tableFKs" => [
            ["table" => "tHE_OrderItem", "join" => "Orderitem.acKey = ORD.acKey", "alias" => "Orderitem", "fieldsToReturn" => "acIdent, acName, anQty, acDept"],
            ["table" => "tHE_SetItem", "join" => "SetItem.acIdent = Orderitem.acIdent", "alias" => "SetItem", "fieldsToReturn" => "acIdent, ACCLASSIF"]
        ],
        "customConditions" => $customConditions,
        "sortColumn" => "ORD.adDate",
        "sortOrder" => "asc",
        "WithSubSelects" => 1,
        "tempTables" => []
    ]);
}

$processOrders = function (&$orders) use (&$setItemMap, &$missingData) {
    foreach ($orders as &$item) {
        $filteredOrderItems = [];
        if (isset($item['Orderitem']) && is_array($item['Orderitem'])) {
            foreach ($item['Orderitem'] as $orderItem) {
                $itemKey = $item['acKeyView'] . '-' . $orderItem['acIdent'];
                $acIdent = $orderItem['acIdent'] ?? null;
                if ($acIdent && isset($setItemMap[$acIdent])) {
                    $orderItem['ACCLASSIF'] = $setItemMap[$acIdent];
                } else {
                    $orderItem['ACCLASSIF'] = "";
                    file_put_contents('missing-acclassif.log', "Missing ACCLASSIF for acIdent: $acIdent\n", FILE_APPEND);
                    $missingData[] = ['orderKey' => $item['acKeyView'], 'field' => 'ACCLASSIF', 'orderItem' => $orderItem,];
                }
                if ($orderItem['acDept'] === "") {
                    $missingData[] = ['orderKey' => $item['acKeyView'], 'field' => 'acDept', 'orderItem' => $orderItem,];
                }
                $filteredOrderItems[] = $orderItem;
            }
            $item['Orderitem'] = $filteredOrderItems;
        }
    }
    unset($item);
};

function cleanOrderItemsForSave(array $order): array {
    if (isset($order['Orderitem']) && is_array($order['Orderitem'])) {
        foreach ($order['Orderitem'] as &$item) {
            unset($item['tempId']);
            $originalAcDeptValue = $item['acDept'] ?? null;
            if (!empty($originalAcDeptValue) && !isset($item['acDept2'])) {
                $item['acDept2'] = $originalAcDeptValue;
            }
            unset($item['acDept']);
            $keysToClean = [];
            for ($i = 0; $i < 10; $i++) {
                $key = ($i === 0) ? 'acDept' : "acDept{$i}";
                if (isset($item[$key]) && !in_array($key, ['acDept2', 'acDept3', 'acDept4', 'acDept5', 'acDept6'])) {
                    $keysToClean[] = $key;
                }
            }
            foreach ($keysToClean as $key) {
                unset($item[$key]);
            }
        }
        unset($item);
    }

    if (isset($order['acNote']) && is_string($order['acNote'])) {
        $note = $order['acNote'];

        // Existing cleaning operations
        $note = preg_replace('/\{\\?\\\\[^{}]+(?:\s[^{}]+)?\}/', '', $note);
        $note = preg_replace('/\\\\[a-z]+\d*\s?/i', '', $note);
        $note = preg_replace('/\\\\[a-z]+\s?/i', '', $note);
        $note = str_replace(['{', '}'], '', $note);

        $note = preg_replace_callback('/\\\\\'([0-9A-Fa-f]{2})/', function ($matches) {
            return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'Windows-1251');
        }, $note);
        $note = preg_replace_callback('/\\\\u([0-9A-Fa-f]+)\??/', function ($matches) {
            $unicodeChar = mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UCS-2BE');
            return $unicodeChar;
        }, $note);

        $note = str_replace('\\line', "", $note);
        $note = str_replace('\\par', "", $note);

        $note = str_ireplace(
            ["Times New Roman CYR", "Times New Roman", "Arial;", "Calibri;", "Segoe UI;", "Verdana;", " Normal;", " Default Paragraph Font;", " Line Number;", " Hyperlink;", " Normal Table;", " Table Simple 1;", "Msftedit", "Riched20", "{\\* _dx_frag_StartFragment", "_dx_frag_StartFragment", "Table Simple 1;\\*", "Normal Table;\\*", "Hyperlink;\\*", "Line Number;\\*", "Default Paragraph Font;\\*", "Normal;\\*", "\\*", ";;;", ";;"],
            '',
            $note
        );
        $note = str_replace('{\cf2', '<br>', $note);

        // Remove bold tag if it's not actually used for styling and just leftover
        $note = str_replace('{\b\cf2 ', '<b>', $note);

        // --- NEW LOGIC FOR ADDING NEWLINES ---
        // Add a newline before "- "
        $note = preg_replace('/(?<!\n)-\s/', "\n- ", $note); // Ensure no double newlines

        // Add a newline before numbered lists (e.g., "1. ", "2. ", "3. "...)
        // This regex looks for a number followed by a dot and a space, ensuring it's at the start of a line
        // or preceded by a space that isn't already a newline.
        $note = preg_replace('/(?<=[^\n]|^)\s*(\d+\.\s)/', "\n$1", $note);
        $note = preg_replace('/^\s*(\d+\.\s)/', "$1", $note); // Clean up any leading newlines created at the very start

        // Collapse multiple newlines into single newlines, but preserve double newlines if intended
        $note = preg_replace('/\n{3,}/', "\n\n", $note);

        // Remove control characters (non-printable)
        $note = preg_replace('/[[:cntrl:]]/', '', $note);

        // Collapse multiple spaces into single space, but be careful after adding newlines
        // This should probably be done *before* adding newlines for bullet points if they were separated by many spaces.
        // Given the current order, it might collapse newlines if they are followed by many spaces and then a new bullet.
        // Re-evaluating the order: it's better to clean RTF, convert, add newlines, then finally normalize spaces.
        // Let's keep it here for now as it was in your original code.
        $note = preg_replace('/\s+/',' ', $note);


        // Final encoding check
        if (!mb_check_encoding($note, 'UTF-8')) {
            $detected_encoding = mb_detect_encoding($note, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
            if ($detected_encoding && $detected_encoding !== 'UTF-8') {
                $note = mb_convert_encoding($note, 'UTF-8', $detected_encoding);
            }
        }
        $order['acNote'] = $note;
    }
    return $order;
}

$saveJson = function ($data, $filePath) {
    $directory = dirname($filePath);
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0755, true)) {
            $phpError = error_get_last();
            error_log("Error creating directory {$directory}: " . ($phpError['message'] ?? 'Unknown error'));
            return ['error' => "Error creating directory: " . $directory];
        }
    }

    $json_data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json_data === false) {
        $error_message = json_last_error_msg();
        error_log("JSON encoding error for {$filePath}: " . $error_message);
        return ['error' => "JSON encoding error: " . $error_message];
    }

    $fileHandle = @fopen($filePath, 'w');
    if ($fileHandle === false) {
        $phpError = error_get_last();
        error_log("Error opening file for writing {$filePath}: " . ($phpError['message'] ?? 'Unknown error'));
        return ['error' => "Error opening file for writing: " . $filePath];
    }

    if (!flock($fileHandle, LOCK_EX | LOCK_NB)) {
        fclose($fileHandle);
        error_log("Failed to acquire file lock for {$filePath}. Another process might be writing.");
        return ['error' => "Error acquiring file lock for: " . $filePath . ". File might be in use."];
    }

    $writeResult = fwrite($fileHandle, $json_data);
    flock($fileHandle, LOCK_UN);
    fclose($fileHandle);

    if ($writeResult === false) {
        $phpError = error_get_last();
        error_log("Error writing data to file {$filePath}: " . ($phpError['message'] ?? 'Unknown error'));
        return ['error' => "Error saving data to file: " . $filePath];
    }

    return ['success' => true];
};

$input = json_decode(file_get_contents('php://input'), true);

if (isset($_GET['action'])) {
    if ($_GET['action'] === 'order_suggestions') {
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
        $limit = 999999999; // Set a large limit to retrieve all suggestions

        $orderPayload = json_encode([
            "start" => 0,
            "length" => 0,
            "fieldsToReturn" => "ORD.acKeyView, ORD.adDate, ORD.acReceiver",
            "customConditions" => [
                "condition" => "ORD.acDocType IN (@param1, @param2, @param3, @param4, @param5)",
                "params" => ["0110", "0130", "0160", "0250", "0270"]
            ],
            "sortColumn" => "ORD.adDate",
            "sortOrder" => "desc",
            "WithSubSelects" => 1,
            "tempTables" => []
        ]);

        $orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);

        if ($orderData && is_array($orderData)) {
            $suggestions = array_map(function ($order) {
                return ['acKeyView' => $order['acKeyView'], 'acReceiver' => $order['acReceiver']];
            }, $orderData);
            echo json_encode(['suggestions' => $suggestions]);
            exit();
        } else {
            echo json_encode(['suggestions' => []]);
            exit();
        }
    }
}

if (isset($input['action'])) {
    if ($input['action'] === 'order_search') {
        $orderCode = $input['order_code'];
        $orderPayload = buildOrderPayload(null, null, null, $orderCode);
        $orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);

        if ($orderData && is_array($orderData)) {
            foreach ($orderData as &$order) {
                $order = cleanOrderItemsForSave($order);
            }
            unset($order); // Unset reference after loop

            // Fetch SetItem data for ACCLASSIF
            $setItemPayload = json_encode(["start" => 0, "length" => 0, "fieldsToReturn" => "*"]);
            $setItemData = sendCurlRequest($setItemApiUrl, $token, $setItemPayload);

            $setItemMap = [];
            if (!empty($setItemData) && is_array($setItemData)) {
                foreach ($setItemData as $setItem) {
                    if (isset($setItem['acIdent']) && isset($setItem['ACCLASSIF'])) {
                        $setItemMap[$setItem['acIdent']] = $setItem['ACCLASSIF'];
                    }
                }
            }

            // Apply ACCLASSIF to order items
            foreach ($orderData as &$order) {
                if (isset($order['Orderitem']) && is_array($order['Orderitem'])) {
                    foreach ($order['Orderitem'] as &$orderItem) {
                        if (isset($orderItem['acIdent']) && isset($setItemMap[$orderItem['acIdent']])) {
                            $orderItem['ACCLASSIF'] = $setItemMap[$orderItem['acIdent']];
                        } else {
                            $orderItem['ACCLASSIF'] = "";
                        }
                    }
                    unset($orderItem); // Unset reference after loop
                }
            }
            unset($order); // Unset reference after loop

            echo json_encode(['orders' => $orderData]);
        } else {
            echo json_encode(['error' => 'Нема резултати.']);
        }
        exit();
    } elseif ($input['action'] === 'import_offers') {
        $selectedOfferKeys = isset($input['selected_offers']) ? $input['selected_offers'] : [];

        if (empty($selectedOfferKeys)) {
            error_log("Import offers called with no selected offers.");
            echo json_encode(['success' => true, 'message' => 'Нема одбрани понуди за импорт.']);
            exit();
        }

        $offersFilePath = dirname(__DIR__) . '/app_data/retrieved-offers.json';
        $retrievedDataFilePath = dirname(__DIR__) . '/app_data/retrieved-data.json';

        $offersData = ['offers' => []];
        if (file_exists($offersFilePath)) {
            $offersContent = file_get_contents($offersFilePath);
            if ($offersContent !== false) {
                $decodedOffers = json_decode($offersContent, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decodedOffers['offers']) && is_array($decodedOffers['offers'])) {
                    $offersData['offers'] = $decodedOffers['offers'];
                }
            }
        }

        $importedOffers = [];
        $setItemMap = [];

        $setItemPayload = json_encode(["start" => 0, "length" => 0, "fieldsToReturn" => "*"]);
        $setItemData = sendCurlRequest($setItemApiUrl, $token, $setItemPayload);

        if (!empty($setItemData) && is_array($setItemData)) {
            foreach ($setItemData as $setItem) {
                if (isset($setItem['acIdent']) && isset($setItem['ACCLASSIF'])) {
                    $setItemMap[$setItem['acIdent']] = $setItem['ACCLASSIF'];
                }
            }
        }

        foreach ($selectedOfferKeys as $offerKey) {
            $orderPayload = buildOrderPayload(null, null, null, $offerKey);
            $orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);

            if ($orderData && is_array($orderData) && !empty($orderData)) {
                $importedOrder = $orderData[0]; // Assuming one order per key
                if (isset($importedOrder['Orderitem']) && is_array($importedOrder['Orderitem'])) {
                    foreach ($importedOrder['Orderitem'] as &$orderItem) {
                        $acIdent = $orderItem['acIdent'] ?? null;
                        if ($acIdent && isset($setItemMap[$acIdent])) {
                            $orderItem['ACCLASSIF'] = $setItemMap[$acIdent];
                        } else {
                            $orderItem['ACCLASSIF'] = "";
                        }
                        $originalAcDeptValue = $orderItem['acDept'] ?? null;
                        unset($orderItem['acDept']); // Remove original acDept
                        if (!empty($originalAcDeptValue)) {
                            $orderItem['acDept2'] = $originalAcDeptValue; // Store in acDept2
                        }
                    }
                    // Filter out any items marked to be skipped (if _skip_ was used)
                    $importedOrder['Orderitem'] = array_filter($importedOrder['Orderitem'], function ($oi) {
                        return !isset($oi['_skip_']);
                    });
                    $importedOrder['Orderitem'] = array_values($importedOrder['Orderitem']); // Re-index array
                    unset($orderItem); // Unset reference after loop
                }
                $importedOffers[] = $importedOrder;
            } else {
                error_log("Error: Could not re-fetch order with acKeyView: {$offerKey}. API Response: " . json_encode($orderData));
            }
        }

        // Filter out successfully imported offers from the existing offers data
        $finalOffersToKeep = [];
        $selectedOfferKeysMap = array_flip($selectedOfferKeys); // For efficient lookup

        foreach ($offersData['offers'] as $offer) {
            if (!isset($selectedOfferKeysMap[$offer['acKeyView']])) {
                $finalOffersToKeep[] = $offer;
            } else {
                // Check if this offer was actually imported successfully
                $wasImportedSuccessfully = false;
                foreach ($importedOffers as $successImportedOffer) {
                    if (isset($successImportedOffer['acKeyView']) && isset($offer['acKeyView']) && $successImportedOffer['acKeyView'] === $offer['acKeyView']) {
                        $wasImportedSuccessfully = true;
                        break;
                    }
                }
                if (!$wasImportedSuccessfully) {
                    $finalOffersToKeep[] = $offer; // If it was supposed to be imported but failed, keep it.
                }
            }
        }

        // Clean offers before saving them back
        $cleanedFinalOffersToKeep = [];
        foreach ($finalOffersToKeep as $offer) {
            $cleanedFinalOffersToKeep[] = cleanOrderItemsForSave($offer);
        }

        $saveResultOffers = $saveJson(['offers' => $cleanedFinalOffersToKeep], $offersFilePath);
        if (isset($saveResultOffers['error'])) {
            error_log("Error saving remaining offers to {$offersFilePath}: " . $saveResultOffers['error']);
            echo json_encode(['success' => false, 'message' => 'Грешка при зачувување на понудите: ' . $saveResultOffers['error']]);
            exit();
        }

        // Add newly imported offers to retrieved-data.json
        $newRetrievedOrdersForSave = [];
        foreach ($importedOffers as $newOrder) {
            $cleanedOrder = cleanOrderItemsForSave($newOrder); // Ensure imported orders are cleaned
            $newRetrievedOrdersForSave[] = $cleanedOrder;
        }

        $saveResultData = $saveJson(['orders' => $newRetrievedOrdersForSave], $retrievedDataFilePath);
        if (isset($saveResultData['error'])) {
            error_log("Error saving imported offers to {$retrievedDataFilePath}: " . $saveResultData['error']);
            echo json_encode(['success' => false, 'message' => 'Грешка при преместување на нарачки: ' . $saveResultData['error']]);
            exit();
        }

        echo json_encode(['success' => true, 'message' => 'Одбраните понуди успешно импортирани.', 'orders' => $newRetrievedOrdersForSave]);
        exit();
    }
}

if (isset($_POST['save_data'])) {
    $data = json_decode($_POST['save_data'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decoding error for save_data: " . json_last_error_msg());
        echo json_encode(['success' => false, 'message' => 'JSON decoding error: ' . json_last_error_msg()]);
        exit();
    }

    $file = dirname(__DIR__) . '/app_data/retrieved-data.json';

    // Clean acNote field for each order before saving
    if (isset($data['orders']) && is_array($data['orders'])) {
        foreach ($data['orders'] as &$order) {
            $order = cleanOrderItemsForSave($order);
        }
        unset($order); // Unset reference after loop
    }

    $saveResult = $saveJson($data, $file);

    if (isset($saveResult['error'])) {
        error_log("Error saving data to file: " . $saveResult['error']);
        echo json_encode(['success' => false, 'message' => 'Грешка при зачувување на податоците: ' . $saveResult['error']]);
    } else {
        echo json_encode(['success' => true, 'message' => 'Податоците успешно зачувани.']);
    }
    exit();
}

if (isset($_POST['save_offers'])) {
    $newOffers = json_decode($_POST['save_offers'], true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decoding error for save_offers: " . json_last_error_msg());
        echo json_encode(['success' => false, 'message' => 'JSON decoding error: ' . json_last_error_msg()]);
        exit();
    }

    $offersFilePath = dirname(__DIR__) . '/app_data/retrieved-offers.json';
    $existingOffers = [];

    if (file_exists($offersFilePath)) {
        $offersContent = file_get_contents($offersFilePath);
        if ($offersContent !== false) {
            $decodedOffers = json_decode($offersContent, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decodedOffers['offers']) && is_array($decodedOffers['offers'])) {
                $existingOffers = $decodedOffers['offers'];
            }
        }
    }

    // Clean new offers before merging and saving
    $cleanedNewOffers = [];
    foreach ($newOffers as $offer) {
        $cleanedNewOffers[] = cleanOrderItemsForSave($offer);
    }

    $allOffers = $existingOffers;
    foreach ($cleanedNewOffers as $newOffer) {
        $found = false;
        foreach ($allOffers as $existingOffer) {
            if (isset($existingOffer['acKeyView']) && isset($newOffer['acKeyView']) && $existingOffer['acKeyView'] === $newOffer['acKeyView']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $allOffers[] = $newOffer;
        }
    }

    $saveResult = $saveJson(['offers' => $allOffers], $offersFilePath);

    if (isset($saveResult['error'])) {
        error_log("Error saving offers data: " . $saveResult['error']);
        echo json_encode(['success' => false, 'message' => 'Грешка при зачувување на понудите: ' . $saveResult['error']]);
    } else {
        echo json_encode(['success' => true, 'message' => 'Понудите успешно зачувани.']);
    }
    exit();
}

// Default order retrieval logic if no specific action is requested
$date = isset($_POST['date']) ? $_POST['date'] : null;
$startDate = isset($_POST['start_date']) ? $_POST['start_date'] : null;
$endDate = isset($_POST['end_date']) ? $_POST['end_date'] : null;
$orderCode = isset($_POST['order_code']) ? $_POST['order_code'] : null;

$orderPayload = null;
if ($orderCode) {
    $orderPayload = buildOrderPayload(null, null, null, $orderCode);
} elseif ($date) {
    $orderPayload = buildOrderPayload($date);
} elseif ($startDate && $endDate) {
    $orderPayload = buildOrderPayload(null, $startDate, $endDate);
} else {
    // If no date or date range is provided, default to today's date
    $today = new DateTime();
    $orderPayload = buildOrderPayload($today->format('Y-m-d'));
}

if (isset($orderPayload['error'])) {
    http_response_code(400);
    echo json_encode(['error' => $orderPayload['error']]);
    exit();
}

$orderData = sendCurlRequest($orderApiUrl, $token, $orderPayload);

// Debugging: Log the API response before processing
file_put_contents('debug-api-response.json', json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));


if (empty($orderData) || (isset($orderData['error']) && !empty($orderData['error']))) {
    http_response_code(404);
    $errorMessage = "Нема нарачки за избраниот критериум.";
    if (isset($orderData['error'])) {
        $errorMessage = "Грешка од системот: " . $orderData['error'];
    }
    echo json_encode(['message' => $errorMessage, 'error' => $errorMessage]);
    exit();
}

// Fetch SetItem data for ACCLASSIF mapping
$setItemPayload = json_encode(["start" => 0, "length" => 0, "fieldsToReturn" => "*"]);
$setItemData = sendCurlRequest($setItemApiUrl, $token, $setItemPayload);

$setItemMap = [];
if (!empty($setItemData) && is_array($setItemData)) {
    foreach ($setItemData as $setItem) {
        if (isset($setItem['acIdent']) && isset($setItem['ACCLASSIF'])) {
            $setItemMap[$setItem['acIdent']] = $setItem['ACCLASSIF'];
        }
    }
}

$missingData = [];
// Process order items to add ACCLASSIF and handle acDept transformation
$processOrders($orderData); // This function processes orderData by reference

$filteredOrders = [];
$offers = [];
$processedOrderKeys = []; // To prevent duplicate orders if API returns them

foreach ($orderData as $item) {
    $orderKey = $item['acKeyView'];
    if (in_array($orderKey, $processedOrderKeys)) {
        continue; // Skip if already processed this order
    }

    $item = cleanOrderItemsForSave($item); // Clean acNote here as well

    if (isset($item['Orderitem']) && is_array($item['Orderitem'])) {
        foreach ($item['Orderitem'] as &$orderItem) {
            $acIdent = $orderItem['acIdent'] ?? null;
            if ($acIdent && isset($setItemMap[$acIdent])) {
                $orderItem['ACCLASSIF'] = $setItemMap[$acIdent];
            } else {
                $orderItem['ACCLASSIF'] = "";
            }
            // Move original acDept to acDept2
            $originalAcDeptValue = $orderItem['acDept'] ?? null;
            unset($orderItem['acDept']); // Remove original acDept
            if (!empty($originalAcDeptValue)) {
                $orderItem['acDept2'] = $originalAcDeptValue; // Store in acDept2
            }
        }
        // Filter out any items marked to be skipped (if _skip_ was used)
        $item['Orderitem'] = array_filter($item['Orderitem'], function ($oi) {
            return !isset($oi['_skip_']);
        });
        $item['Orderitem'] = array_values($item['Orderitem']); // Re-index array
        unset($orderItem); // Unset reference after loop
    }

    if (isset($item['acStatus']) && $item['acStatus'] === 'П') {
        $offers[] = $item;
    } else {
        $filteredOrders[] = $item;
    }
    $processedOrderKeys[] = $orderKey;
}

// Save filtered orders to retrieved-data.json
$retrievedDataFilePath = dirname(__DIR__) . '/app_data/retrieved-data.json';
$cleanedFilteredOrders = [];
foreach($filteredOrders as $order){
    $cleanedFilteredOrders[] = cleanOrderItemsForSave($order); // Ensure all orders are cleaned before saving
}
$saveResultData = $saveJson(['orders' => $cleanedFilteredOrders], $retrievedDataFilePath);

if (isset($saveResultData['error'])) {
    error_log("Error saving filtered orders to {$retrievedDataFilePath}: " . $saveResultData['error']);
    echo json_encode(['error' => 'Грешка при зачувување на нарачките: ' . $saveResultData['error']]);
    exit();
}

// Save offers to retrieved-offers.json
$offersFilePath = dirname(__DIR__) . '/app_data/retrieved-offers.json';
$existingOffers = [];
if (file_exists($offersFilePath)) {
    $offersContent = file_get_contents($offersFilePath);
    if ($offersContent !== false) {
        $decodedOffers = json_decode($offersContent, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decodedOffers['offers']) && is_array($decodedOffers['offers'])) {
            $existingOffers = $decodedOffers['offers'];
        }
    }
}

$allOffers = $existingOffers;
foreach ($offers as $newOffer) {
    $found = false;
    foreach ($allOffers as $existingOffer) {
        if (isset($existingOffer['acKeyView']) && isset($newOffer['acKeyView']) && $existingOffer['acKeyView'] === $newOffer['acKeyView']) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $allOffers[] = cleanOrderItemsForSave($newOffer); // Ensure new offers are cleaned before adding
    }
}

$saveResultOffers = $saveJson(['offers' => $allOffers], $offersFilePath);

if (isset($saveResultOffers['error'])) {
    error_log("Error saving offers to {$offersFilePath}: " . $saveResultOffers['error']);
    echo json_encode(['error' => 'Грешка при зачувување на понудите: ' . $saveResultOffers['error']]);
    exit();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false); // Corrected second header call
header("Pragma: no-cache");

echo json_encode(['orders' => $cleanedFilteredOrders]);

?>