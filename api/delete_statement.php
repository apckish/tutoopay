<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$body = get_json_body();
$statement_id = intval($body['statement_id'] ?? 0);
$bulk_ids = $body['statement_ids'] ?? null;

$db = get_db();

// Bulk delete
if (is_array($bulk_ids) && count($bulk_ids) > 0) {
    $ids = array_map('intval', $bulk_ids);
    $ids = array_filter($ids, function($id) { return $id > 0; });
    if (empty($ids)) json_error('No valid IDs');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    // Unmatch any matched statements first
    $stmt = $db->prepare("UPDATE statements SET status = 'unmatched', matched_payment_id = NULL WHERE id IN ($placeholders) AND status = 'matched'");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $unmatched = $stmt->affected_rows;
    $stmt->close();

    // Delete
    $stmt = $db->prepare("DELETE FROM statements WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
    $db->close();

    json_response([
        'success' => true,
        'message' => "$deleted statement(s) deleted" . ($unmatched > 0 ? " ($unmatched unmatched first)" : ""),
        'deleted' => $deleted,
    ]);
}

// Single delete
if (!$statement_id) {
    json_error('statement_id is required');
}

$stmt = $db->prepare("SELECT id, status, matched_payment_id FROM statements WHERE id = ?");
$stmt->bind_param("i", $statement_id);
$stmt->execute();
$statement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$statement) {
    $db->close();
    json_error('Statement not found', 404);
}

// Unmatch if matched
if ($statement['status'] === 'matched' && $statement['matched_payment_id']) {
    $stmt = $db->prepare("UPDATE statements SET status = 'unmatched', matched_payment_id = NULL WHERE id = ?");
    $stmt->bind_param("i", $statement_id);
    $stmt->execute();
    $stmt->close();
}

$stmt = $db->prepare("DELETE FROM statements WHERE id = ?");
$stmt->bind_param("i", $statement_id);
$stmt->execute();
$stmt->close();
$db->close();

json_response([
    'success' => true,
    'message' => 'Statement deleted',
    'statement_id' => $statement_id,
]);
?>
