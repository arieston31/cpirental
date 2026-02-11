<?php
require_once 'config.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? intval($data['id']) : 0;
$status = isset($data['status']) ? $conn->real_escape_string($data['status']) : '';

if (!$id || !$status) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

$sql = "UPDATE contracts SET status = '$status', updated_at = NOW() WHERE id = $id";

if ($conn->query($sql)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}
?>