<?php
require_once 'config.php';

header('Content-Type: application/json');

$zone_id = $_POST['zone_id'] ?? 0;
$client_id = $_POST['client_id'] ?? 0;

if (!$zone_id) {
    echo json_encode(['success' => false, 'error' => 'Zone ID is required']);
    exit;
}

// Get zone number
$stmt = $conn->prepare("SELECT zone_number FROM zoning_zone WHERE id = ?");
$stmt->bind_param("i", $zone_id);
$stmt->execute();
$result = $stmt->get_result();
$zone = $result->fetch_assoc();

if (!$zone) {
    echo json_encode(['success' => false, 'error' => 'Zone not found']);
    exit;
}

// Zone-based fixed reading date: Zone 1 = 3rd, Zone 2 = 4th, etc.
$zone_number = $zone['zone_number'];
$reading_date = $zone_number + 2;

echo json_encode(['success' => true, 'reading_date' => $reading_date, 'zone_number' => $zone_number]);
?>