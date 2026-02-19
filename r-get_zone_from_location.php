<?php
require_once 'config.php';

header('Content-Type: application/json');

$barangay = $_POST['barangay'] ?? '';
$city = $_POST['city'] ?? '';
$client_id = $_POST['client_id'] ?? 0;

if (empty($barangay) || empty($city)) {
    echo json_encode(['success' => false, 'error' => 'Barangay and city are required']);
    exit;
}

// Function to calculate distance between two points (Haversine formula)
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371;
    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);
    $latDelta = $lat2 - $lat1;
    $lonDelta = $lon2 - $lon1;
    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($lat1) * cos($lat2) * pow(sin($lonDelta / 2), 2)));
    return $angle * $earthRadius;
}

// First, check if barangay exists in zone_coordinates
$stmt = $conn->prepare("
    SELECT latitude, longitude 
    FROM rental_zone_coordinates 
    WHERE LOWER(barangay) = LOWER(?) AND LOWER(city) = LOWER(?)
    LIMIT 1
");

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    exit;
}

$stmt->bind_param("ss", $barangay, $city);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Barangay not found in database
    echo json_encode(['success' => false, 'error' => 'barangay_not_found']);
    exit;
}

// Get the coordinates of the searched barangay
$location = $result->fetch_assoc();
$lat = floatval($location['latitude']);
$lng = floatval($location['longitude']);

// Find nearest zone using distance calculation
$zone_stmt = $conn->prepare("
    SELECT id, zone_number, area_center, reading_date, latitude, longitude,
           (6371 * acos(cos(radians(?)) * cos(radians(latitude)) 
           * cos(radians(longitude) - radians(?)) 
           + sin(radians(?)) * sin(radians(latitude)))) AS distance
    FROM rental_zoning_zone 
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL
    ORDER BY distance ASC 
    LIMIT 1
");

if (!$zone_stmt) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    exit;
}

$zone_stmt->bind_param("ddd", $lat, $lng, $lat);
$zone_stmt->execute();
$zone_result = $zone_stmt->get_result();

if ($zone_result->num_rows > 0) {
    $zone = $zone_result->fetch_assoc();
    $distance = calculateDistance($lat, $lng, $zone['latitude'], $zone['longitude']);
    
    echo json_encode([
        'success' => true,
        'zone_id' => $zone['id'],
        'zone_number' => $zone['zone_number'],
        'area_center' => $zone['area_center'],
        'reading_date' => $zone['reading_date'],
        'latitude' => $lat,
        'longitude' => $lng,
        'zone_lat' => $zone['latitude'],
        'zone_lon' => $zone['longitude'],
        'distance_km' => round($distance, 2),
        'source' => 'zone_coordinates'
    ]);
    exit;
} else {
    echo json_encode(['success' => false, 'error' => 'No zones found in the database']);
    exit;
}
?>