<?php
session_start(); // Always start the session at the very beginning of your PHP files.

// Check if the user is NOT logged in or the session variable is not set to true
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    // If not logged in, redirect to the login page.
    // Ensure this path is correct relative to your server root or domain.
    header('Location: /jumis/index.html'); // Redirect to your login page
    exit(); // Always exit after a header redirect
}

// include_once "../jumis-tm/DB_connection.php";

// if (isset($_GET['order_id']) && is_numeric($_GET['order_id'])) {
//     $order_id = intval($_GET['order_id']);

//     $sql = "SELECT acIdent, acName FROM order_items WHERE order_id = :order_id";
//     $stmt = $conn->prepare($sql);
//     $stmt->bindParam(':order_id', $order_id, PDO::PARAM_INT);
//     $stmt->execute();
//     $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

//     header('Content-Type: application/json');
//     echo json_encode($items);
// } else {
//     header('HTTP/1.1 400 Bad Request');
//     echo json_encode(['error' => 'Invalid order ID']);
// }

include_once "../jumis-tm/DB_connection.php";
session_start();
$orderId = $_GET['order_id'];
$userId = isset($_GET['user_id']) ? $_GET['user_id'] : 0; // 0 for not logged in

// Include Task and Notification models (assuming they are in ../jumis-tm/Model/)
include_once "../jumis-tm/Model/Task.php";
include_once "../jumis-tm/Model/Notification.php";

if (isset($_GET['order_id']) && is_numeric($_GET['order_id'])) {
    $order_id = intval($_GET['order_id']);

    try {
        $sql = "SELECT oi.item_id, oi.acIdent, oi.acName
                  FROM order_items oi
                  WHERE oi.order_id = :orderId";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':orderId', $orderId, PDO::PARAM_INT);
        $stmt->execute();
        $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($orderItems as $orderItem) {
            $item_id = $orderItem['item_id'];
            $acIdent = $orderItem['acIdent'];
            $acName = $orderItem['acName'];

            $has_tasks_for_user = 0; // Default: no tasks for user
            if ($userId != 0) {
                // Check for task assignment for this item and user
                $sqlCheckTask = "SELECT t.id
                                    FROM tasks t
                                    JOIN task_assignments ta ON t.id = ta.task_id
                                    WHERE t.order_id = :orderId
                                    AND t.item_id = :itemId
                                    AND ta.user_id = :userId";

                $stmtCheckTask = $conn->prepare($sqlCheckTask);
                $stmtCheckTask->bindParam(':orderId', $orderId, PDO::PARAM_INT);
                $stmtCheckTask->bindParam(':itemId', $item_id, PDO::PARAM_INT);
                $stmtCheckTask->bindParam(':userId', $userId, PDO::PARAM_INT);
                $stmtCheckTask->execute();
                $taskId = $stmtCheckTask->fetchColumn(); // Get the task ID if it exists

                if ($taskId) {
                    $has_tasks_for_user = 1; // Task found for user
                }
            }

            $items[] = [
                'item_id' => $item_id,
                'acIdent' => $acIdent,
                'acName' => $acName,
                'has_tasks_for_user' => $has_tasks_for_user,
            ];
        }

        // --- DEBUGGING OUTPUT ---
        error_log("get_order_items.php - orderId: " . $orderId . ", userId: " . $userId);
        error_log("get_order_items.php - Results: " . print_r($items, true));
        // --- END DEBUGGING OUTPUT ---

        header('Content-Type: application/json');
        echo json_encode($items);

        // --- Notification Creation ---
        //check if the user is admin.
        if ($_SESSION['role'] == 'admin') {
            // Fetch tasks for this order.  Important:  Modified to fetch *all* tasks for the order.
            $sql_tasks = "SELECT t.id, t.title, ta.user_id
                          FROM tasks t
                          JOIN task_assignments ta ON t.id = ta.task_id
                          WHERE t.order_id = :orderId";
            $stmt_tasks = $conn->prepare($sql_tasks);
            $stmt_tasks->bindParam(':orderId', $orderId, PDO::PARAM_INT);
            $stmt_tasks->execute();
            $tasks = $stmt_tasks->fetchAll(PDO::FETCH_ASSOC);

            // Iterate through the tasks and create notifications.
            foreach ($tasks as $task) {
                $task_id = $task['id'];
                $task_title = $task['title'];
                $assigned_user_id = $task['user_id'];

                $notification_message = "Доделена е нова задача: " . $task_title;
                $notification_type = "Нова задача е доделена";
                // Use the insert_notification function.
                insert_notification($conn, $notification_message, $assigned_user_id, $notification_type);
                error_log("Notification created for task ID: $task_id, user ID: $assigned_user_id, message: $notification_message");
            }
        }
        // --- End Notification Creation ---

    } catch (PDOException $e) {
        error_log("Error in get_order_items.php: " . $e->getMessage());
        echo json_encode(array('error' => 'Failed to fetch order items: ' . $e->getMessage())); // Include the error message
    }
} else {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Invalid order ID']);
}
?>