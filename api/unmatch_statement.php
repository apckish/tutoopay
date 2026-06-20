<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$body = get_json_body();
$statement_id = intval($body['statement_id'] ?? 0);

if (!$statement_id) {
    json_error('statement_id is required');
}

$db = get_db();

// Get the statement
$stmt = $db->prepare("SELECT * FROM statements WHERE id = ?");
$stmt->bind_param("i", $statement_id);
$stmt->execute();
$statement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$statement) {
    $db->close();
    json_error('Statement not found', 404);
}

if ($statement['status'] !== 'matched') {
    $db->close();
    json_error('Statement is not matched (current status: ' . $statement['status'] . ')');
}

$payment_id = intval($statement['matched_payment_id']);

// Delete the associated payment record (only if it's still Approved/unpaid)
if ($payment_id) {
    $pstmt = $db->prepare("SELECT id, status, paid_status FROM payments WHERE id = ?");
    $pstmt->bind_param("i", $payment_id);
    $pstmt->execute();
    $payment = $pstmt->get_result()->fetch_assoc();
    $pstmt->close();

    if ($payment) {
        if ($payment['paid_status'] === 'paid') {
            $db->close();
            json_error('Cannot unmatch: payment has already been paid');
        }
        // Delete the payment record
        $del = $db->prepare("DELETE FROM payments WHERE id = ?");
        $del->bind_param("i", $payment_id);
        $del->execute();
        $del->close();

        // Delete associated payment logs
        $del_log = $db->prepare("DELETE FROM payment_logs WHERE payment_id = ?");
        $del_log->bind_param("i", $payment_id);
        $del_log->execute();
        $del_log->close();
    }
}

// Revert statement to unmatched
$stmt = $db->prepare("UPDATE statements SET status = 'unmatched', matched_payment_id = NULL WHERE id = ?");
$stmt->bind_param("i", $statement_id);
$stmt->execute();
$stmt->close();

$db->close();

json_response([
    'success' => true,
    'message' => 'Statement reverted to unmatched',
    'statement_id' => $statement_id,
]);
?>
