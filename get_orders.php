<?php
session_start(); // Always start the session at the very beginning of your PHP files.

// Check if the user is NOT logged in or the session variable is not set to true
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // If not logged in, redirect to the login page.
    // Ensure this path is correct relative to your server root or domain.
    header('Location: /jumis/index.html'); // Redirect to your login page
    exit(); // Always exit after a header redirect
}

// Include your database connection file
    include_once "../jumis-tm/DB_connection.php";
    include_once "../jumis-tm/app/Model/User.php";

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to log errors to a file
function log_error($message) {
    $log_file = "error_log.txt";
    $timestamp = date("Y-m-d H:i:s");
    $log_message = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

// Define the path to your JSON file
$json_file = '../jumis/retrieved-data.json'; // Corrected path

if (file_exists($json_file)) {
    $orders_json = file_get_contents($json_file);
    log_error("Read data from file: " . $json_file);

    $data = json_decode($orders_json, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        $json_error_message = "Error decoding JSON: " . json_last_error_msg();
        log_error($json_error_message);
        echo $json_error_message;
        exit;
    } else {
        log_error("JSON decoding successful.");
        // Database connection check
        if ($conn) {
            log_error("Successfully connected to the database.");
        } else {
            $db_connection_error = "Error connecting to the database: " . mysqli_connect_error();
            log_error($db_connection_error);
            echo $db_connection_error;
            exit;
        }

        $conn->beginTransaction();

        try {
            // Check if the 'orders' key exists in the decoded data
            if (isset($data['orders']) && is_array($data['orders'])) {
                foreach ($data['orders'] as $order) {
                    $acKeyView = isset($order['acKeyView']) ? $order['acKeyView'] : '';

                    // Check if an order with this acKeyView already exists
                    $check_sql = "SELECT COUNT(*) FROM orders WHERE acKeyView = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bindParam(1, $acKeyView);
                    $check_stmt->execute();
                    $order_exists = (bool) $check_stmt->fetchColumn();
                    $check_stmt->closeCursor();

                    if (!$order_exists) {
                        log_error("Processing new order: " . json_encode($order, JSON_UNESCAPED_UNICODE)); // Log the order with Cyrillic characters

                        $acReceiver = isset($order['acReceiver']) ? $order['acReceiver'] : '';
                        $adDate = isset($order['adDate']) ? substr($order['adDate'], 0, 10) : null; // Extract date part
                        $adDateValid = isset($order['adDateValid']) ? substr($order['adDateValid'], 0, 10) : null; // Extract date part
                        $acDelivery = isset($order['acDelivery']) ? $order['acDelivery'] : null;
                        $acNote = isset($order['acNote']) ? $order['acNote'] : '';
                        $acStatus = isset($order['acStatus']) ? $order['acStatus'] : null;
                        $adDeliveryDate = isset($order['adDeliveryDate']) ? substr($order['adDeliveryDate'], 0, 10) : null; // Extract date part
                        $acDocType = isset($order['acDocType']) ? $order['acDocType'] : null;
                        $acConsignee = isset($order['acConsignee']) ? $order['acConsignee'] : null;
                        $created_at = date('Y-m-d H:i:s'); // Add created_at

                        // Use a prepared statement to prevent SQL injection
                        $sql_insert_order = "INSERT INTO orders (acKeyView, acReceiver, adDate, adDateValid, acDelivery, acNote, acStatus, adDeliveryDate, acDocType, acConsignee, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt_insert_order = $conn->prepare($sql_insert_order);
                        if ($stmt_insert_order) {
                            $stmt_insert_order->bindParam(1, $acKeyView);
                            $stmt_insert_order->bindParam(2, $acReceiver);
                            $stmt_insert_order->bindParam(3, $adDate);
                            $stmt_insert_order->bindParam(4, $adDateValid);
                            $stmt_insert_order->bindParam(5, $acDelivery);
                            $stmt_insert_order->bindParam(6, $acNote);
                            $stmt_insert_order->bindParam(7, $acStatus);
                            $stmt_insert_order->bindParam(8, $adDeliveryDate);
                            $stmt_insert_order->bindParam(9, $acDocType);
                            $stmt_insert_order->bindParam(10, $acConsignee);
                            $stmt_insert_order->bindParam(11, $created_at);
                            if ($stmt_insert_order->execute()) {
                                $order_id = $conn->lastInsertId(); // Use PDO's lastInsertId()
                                log_error("Inserted new order with ID: " . $order_id);

                                if (isset($order['Orderitem']) && is_array($order['Orderitem'])) {
                                    foreach ($order['Orderitem'] as $item) {
                                        $acIdent = isset($item['acIdent']) ? $item['acIdent'] : '';
                                        $acName = isset($item['acName']) ? $item['acName'] : '';
                                        $anQty = isset($item['anQty']) ? intval($item['anQty']) : 0;
                                        $acDept = isset($item['acDept']) ? $item['acDept'] : '';
                                        $ACCLASSIF = isset($item['ACCLASSIF']) ? $item['ACCLASSIF'] : '';

                                        // Use a prepared statement to prevent SQL injection
                                        $sql_insert_item = "INSERT INTO order_items (order_id, acIdent, acName, anQty, acDept, ACCLASSIF)
                                                              VALUES (?, ?, ?, ?, ?, ?)";
                                        $stmt_insert_item = $conn->prepare($sql_insert_item);
                                        if ($stmt_insert_item) {
                                            $stmt_insert_item->bindParam(1, $order_id);
                                            $stmt_insert_item->bindParam(2, $acIdent);
                                            $stmt_insert_item->bindParam(3, $acName);
                                            $stmt_insert_item->bindParam(4, $anQty, PDO::PARAM_INT); // Specify data type for integer
                                            $stmt_insert_item->bindParam(5, $acDept);
                                            $stmt_insert_item->bindParam(6, $ACCLASSIF);
                                            if ($stmt_insert_item->execute()) {
                                                log_error("Inserted item for order ID: " . $order_id);
                                            } else {
                                                $error_message = "Error inserting order item: " . print_r($stmt_insert_item->errorInfo(), true); // Use PDO's errorInfo()
                                                log_error($error_message);
                                                echo $error_message;
                                                $conn->rollBack();
                                                exit;
                                            }
                                            $stmt_insert_item->closeCursor(); // Close the statement
                                        } else {
                                            $error_message = "Error preparing item statement: " . print_r($conn->errorInfo(), true); // Use PDO's errorInfo()
                                            log_error($error_message);
                                            echo $error_message;
                                            $conn->rollBack();
                                            exit;
                                        }
                                    }
                                } else {
                                    log_error("Orderitem is not set or not an array for order_id: $order_id");
                                }
                            } else {
                                $error_message = "Error inserting into orders: " . print_r($stmt_insert_order->errorInfo(), true); // Use PDO's errorInfo()
                                log_error($error_message);
                                echo $error_message;
                                $conn->rollBack();
                                exit;
                            }
                            $stmt_insert_order->closeCursor(); // Close the statement
                        } else {
                            $error_message = "Error preparing order statement: " . print_r($conn->errorInfo(), true); // Use PDO's errorInfo()
                            log_error($error_message);
                            echo $error_message;
                            $conn->rollBack();
                            exit;
                        }
                    } else {
                        log_error("Order with acKeyView '{$acKeyView}' already exists. Skipping.");
                    }
                }
            } else {
                $no_orders_message = "No order data found in the JSON file.";
                log_error($no_orders_message);
                echo $no_orders_message;
                exit; // Stop processing if no orders found
            }

            $conn->commit();
            echo "Order import process completed. Check error log for details.";
        } catch (Exception $e) {
            $conn->rollBack();
            $error_message = "Exception: " . $e->getMessage();
            log_error($error_message);
            echo $error_message;
            exit;
        }
        // Retrieve orders for the current page load for the sidebar
        $sql_sidebar = "SELECT order_id, acKeyView, acReceiver FROM orders ORDER BY created_at DESC"; // Include order_id
        log_error("Sidebar SQL: " . $sql_sidebar); // Log the sidebar query
        $result_sidebar = $conn->query($sql_sidebar);

        if ($result_sidebar) {
            log_error("Sidebar query executed.");
            if ($result_sidebar->rowCount() > 0) { // Use rowCount() for PDO
                log_error("Number of rows returned: " . $result_sidebar->rowCount());
                $all_orders_sidebar = [];
                while ($row = $result_sidebar->fetch(PDO::FETCH_ASSOC)) {
                    $all_orders_sidebar[] = $row; // Now includes order_id
                    log_error("Fetched row for sidebar: " . json_encode($row, JSON_UNESCAPED_UNICODE)); // Log each fetched row
                }
                $_SESSION['orders_for_sidebar'] = $all_orders_sidebar; // Store the array with order_id
                log_error("Session variable set for sidebar: " . json_encode($_SESSION['orders_for_sidebar'], JSON_UNESCAPED_UNICODE)); // Log the session variable
            } else {
                log_error("No rows returned from sidebar query.");
                $_SESSION['orders_for_sidebar'] = array(); // Ensure the session variable is initialized as an empty array
            }
            $result_sidebar->closeCursor(); // Close the cursor for the result set
        } else {
            $error_message = "Error retrieving orders for sidebar: " . $sql_sidebar . "<br>" . print_r($conn->errorInfo(), true); // Use PDO's errorInfo()
            log_error($error_message);
            echo $error_message;
            // Don't exit here
        }
    }
} else {
    $file_missing_message = "Error: The file " . $json_file . " does not exist.";
    log_error($file_missing_message);
    echo $file_missing_message;
    exit; // Stop if the file doesn't exist.
}
//$conn->close(); Removed - PDO handles connection closing.
?>