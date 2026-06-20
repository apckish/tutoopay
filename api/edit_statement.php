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

// Only allow editing unmatched statements (or manual entries)
if ($statement['status'] === 'paid') {
    $db->close();
    json_error('Cannot edit a paid statement');
}

// Build update fields
$updates = [];
$params = [];
$types = '';

if (isset($body['tx_date'])) {
    $updates[] = "tx_date = ?";
    $params[] = $body['tx_date'];
    $types .= 's';
}
if (isset($body['amount'])) {
    $amount = floatval($body['amount']);
    $updates[] = "gross = ?";
    $params[] = $amount;
    $types .= 'd';
    $updates[] = "net = ?";
    $params[] = $amount;
    $types .= 'd';
}
if (isset($body['currency'])) {
    $updates[] = "currency = ?";
    $params[] = $body['currency'];
    $types .= 's';
}
if (isset($body['source'])) {
    $updates[] = "source = ?";
    $params[] = $body['source'];
    $types .= 's';
}
if (isset($body['name'])) {
    $updates[] = "name = ?";
    $params[] = $body['name'];
    $types .= 's';
}
if (isset($body['from_email'])) {
    $updates[] = "from_email = ?";
    $params[] = $body['from_email'];
    $types .= 's';
}
if (isset($body['note'])) {
    $updates[] = "note = ?";
    $params[] = $body['note'];
    $types .= 's';
}
if (isset($body['tx_id'])) {
    $updates[] = "tx_id = ?";
    $params[] = $body['tx_id'];
    $types .= 's';
}

if (empty($updates)) {
    $db->close();
    json_error('No fields to update');
}

$params[] = $statement_id;
$types .= 'i';

$sql = "UPDATE statements SET " . implode(", ", $updates) . " WHERE id = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    $db->close();
    json_error('Failed to update statement: ' . $stmt->error);
}

$stmt->close();
$db->close();

json_response([
    'success' => true,
    'message' => 'Statement updated successfully',
    'statement_id' => $statement_id,
]);
?>
