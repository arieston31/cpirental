<?php
require_once 'config.php';

// Turn off error display but log them
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

$client_id = $_GET['client_id'] ?? 0;

if (!$client_id) {
    // Redirect to view clients if no client_id provided
    header('Location: view_clients.php?error=no_client_id');
    exit;
}

// Get client info
$client_stmt = $conn->prepare("SELECT classification, company_name, status FROM zoning_clients WHERE id = ?");
if (!$client_stmt) {
    sendJsonError("Database prepare error: " . $conn->error);
}
$client_stmt->bind_param("i", $client_id);
if (!$client_stmt->execute()) {
    sendJsonError("Database execute error: " . $client_stmt->error);
}
$client_result = $client_stmt->get_result();
$client = $client_result->fetch_assoc();

if (!$client) {
    sendJsonError("Client not found!");
}

// Check if client is active
if ($client['status'] === 'INACTIVE') {
    sendJsonError("Cannot add machine to inactive client!");
}

// Function to send JSON error
function sendJsonError($message) {
    // Clear any output buffered
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// Function to send JSON success
function sendJsonSuccess($data = []) {
    // Clear any output buffered
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

// Function to calculate distance between two points (Haversine formula)
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // kilometers

    $lat1 = deg2rad($lat1);
    $lon1 = deg2rad($lon1);
    $lat2 = deg2rad($lat2);
    $lon2 = deg2rad($lon2);

    $latDelta = $lat2 - $lat1;
    $lonDelta = $lon2 - $lon1;

    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
        cos($lat1) * cos($lat2) * pow(sin($lonDelta / 2), 2)));
    
    return $angle * $earthRadius;
}

// Function to geocode address using OpenStreetMap Nominatim
function geocodeOSM($barangay, $city) {
    if (empty($city)) {
        return null;
    }
    
    $address = urlencode($barangay . ', ' . $city . ', Philippines');
    $url = "https://nominatim.openstreetmap.org/search?format=json&q={$address}&limit=1&countrycodes=PH";
    
    // Add user agent as required by Nominatim's ToS
    $options = [
        'http' => [
            'header' => "User-Agent: ZoningSystem/1.0 (contact@yourdomain.com)\r\n"
        ]
    ];
    
    $context = stream_context_create($options);
    
    try {
        $response = @file_get_contents($url, false, $context);
        
        if ($response === FALSE) {
            error_log("OSM Geocoding failed for: $barangay, $city");
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
            return [
                'latitude' => floatval($data[0]['lat']),
                'longitude' => floatval($data[0]['lon']),
                'display_name' => $data[0]['display_name'],
                'source' => 'osm'
            ];
        }
    } catch (Exception $e) {
        error_log("OSM Geocoding exception: " . $e->getMessage());
    }
    
    return null;
}

// Get city centroid (fallback when geocoding fails)
function getCityCentroid($city) {
    $cityCentroids = [
        'manila' => ['lat' => 14.5995, 'lon' => 120.9842],
        'quezon city' => ['lat' => 14.6760, 'lon' => 121.0437],
        'caloocan' => ['lat' => 14.6492, 'lon' => 120.9679],
        'pasig' => ['lat' => 14.5604, 'lon' => 121.0810],
        'mandaluyong' => ['lat' => 14.5794, 'lon' => 121.0359],
        'makati' => ['lat' => 14.5547, 'lon' => 121.0244],
        'taguig' => ['lat' => 14.5176, 'lon' => 121.0509],
        'pasay' => ['lat' => 14.5378, 'lon' => 121.0014],
        'parañaque' => ['lat' => 14.4793, 'lon' => 121.0198],
        'las piñas' => ['lat' => 14.4447, 'lon' => 120.9937],
        'valenzuela' => ['lat' => 14.7004, 'lon' => 120.9831],
        'san juan' => ['lat' => 14.6036, 'lon' => 121.0334],
        'marikina' => ['lat' => 14.6507, 'lon' => 121.1029],
        'muntinlupa' => ['lat' => 14.4138, 'lon' => 121.0452],
        'navotas' => ['lat' => 14.6667, 'lon' => 120.9500],
        'malabon' => ['lat' => 14.7525, 'lon' => 120.9822],
    ];
    
    $cityLower = strtolower(trim($city));
    
    foreach ($cityCentroids as $key => $coords) {
        if (strpos($cityLower, $key) !== false) {
            return [
                'latitude' => $coords['lat'],
                'longitude' => $coords['lon'],
                'source' => 'city_centroid'
            ];
        }
    }
    
    // Default to Manila centroid
    return [
        'latitude' => 14.5995,
        'longitude' => 120.9842,
        'source' => 'default_centroid'
    ];
}

// Function to get zone based on geographical location (barangay + city)
function getZoneFromLocation($barangay, $city, $conn) {
    $barangay = trim($barangay);
    $city = trim($city);
    
    if (empty($city)) {
        return getZoneFromCityFallback($city, $conn);
    }
    
    $stmt = $conn->prepare("
        SELECT bc.latitude, bc.longitude, bc.zone_id, z.zone_number, z.area_center
        FROM barangay_coordinates bc
        JOIN zoning_zone z ON bc.zone_id = z.id
        WHERE LOWER(bc.barangay) = LOWER(?) 
        AND LOWER(bc.city) = LOWER(?)
        AND bc.latitude IS NOT NULL 
        AND bc.longitude IS NOT NULL
        LIMIT 1
    ");
    
    if ($stmt) {
        $stmt->bind_param("ss", $barangay, $city);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return [
                'zone_id' => $row['zone_id'],
                'zone_number' => $row['zone_number'],
                'area_center' => $row['area_center'],
                'latitude' => $row['latitude'],
                'longitude' => $row['longitude'],
                'source' => 'database_cache'
            ];
        }
    }
    
    // If not in cache, try to geocode
    $coordinates = null;
    
    if (!empty($barangay) || !empty($city)) {
        // Try OpenStreetMap
        $coordinates = geocodeOSM($barangay, $city);
        
        // If OSM fails, use city centroid as fallback
        if (!$coordinates) {
            $coordinates = getCityCentroid($city);
        }
    }
    
    if ($coordinates) {
        // Find nearest zone using distance calculation
        $stmt = $conn->prepare("
            SELECT id, zone_number, area_center, latitude, longitude,
                   (6371 * acos(cos(radians(?)) * cos(radians(latitude)) 
                   * cos(radians(longitude) - radians(?)) 
                   + sin(radians(?)) * sin(radians(latitude)))) AS distance
            FROM zoning_zone 
            WHERE latitude IS NOT NULL 
            AND longitude IS NOT NULL
            ORDER BY distance ASC 
            LIMIT 1
        ");
        
        if ($stmt) {
            $stmt->bind_param("ddd", 
                $coordinates['latitude'], 
                $coordinates['longitude'],
                $coordinates['latitude']
            );
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $zone = $result->fetch_assoc();
                
                // Cache this result for future use
                if (!empty($barangay)) {
                    $insert_stmt = $conn->prepare("
                        INSERT INTO barangay_coordinates 
                        (barangay, city, latitude, longitude, zone_id) 
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                            latitude = VALUES(latitude),
                            longitude = VALUES(longitude),
                            zone_id = VALUES(zone_id),
                            last_updated = CURRENT_TIMESTAMP
                    ");
                    
                    if ($insert_stmt) {
                        $insert_stmt->bind_param("ssddi",
                            $barangay,
                            $city,
                            $coordinates['latitude'],
                            $coordinates['longitude'],
                            $zone['id']
                        );
                        $insert_stmt->execute();
                    }
                }
                
                return [
                    'zone_id' => $zone['id'],
                    'zone_number' => $zone['zone_number'],
                    'area_center' => $zone['area_center'],
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'],
                    'distance_km' => round($zone['distance'], 2),
                    'source' => $coordinates['source'] ?? 'geocoding'
                ];
            }
        }
    }
    
    // Final fallback: Use city-only matching
    return getZoneFromCityFallback($city, $conn);
}

// Simple city-based fallback
function getZoneFromCityFallback($city, $conn) {
    $city = strtolower(trim($city));
    $zone_mapping = [
        'manila' => 1,
        'caloocan' => 2,
        'quezon city' => 3,
        'pasig' => 6,
        'mandaluyong' => 7,
        'san juan' => 7,
        'makati' => 8,
        'taguig' => 9,
        'parañaque' => 10,
        'las piñas' => 10,
        'pasay' => 11,
        'valenzuela' => 12
    ];
    
    foreach ($zone_mapping as $key => $zone_num) {
        if (strpos($city, $key) !== false) {
            $stmt = $conn->prepare("SELECT id, zone_number, area_center FROM zoning_zone WHERE zone_number = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $zone_num);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($row = $result->fetch_assoc()) {
                    return [
                        'zone_id' => $row['id'],
                        'zone_number' => $row['zone_number'],
                        'area_center' => $row['area_center'],
                        'source' => 'city_fallback'
                    ];
                }
            }
        }
    }
    
    // Default to Zone 1 (Manila)
    $stmt = $conn->prepare("SELECT id, zone_number, area_center FROM zoning_zone WHERE zone_number = 1 LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $default = $result->fetch_assoc();
        
        return [
            'zone_id' => $default['id'] ?? 1,
            'zone_number' => $default['zone_number'] ?? 1,
            'area_center' => $default['area_center'] ?? 'Manila',
            'source' => 'default'
        ];
    }
    
    return [
        'zone_id' => 1,
        'zone_number' => 1,
        'area_center' => 'Manila',
        'source' => 'hardcoded_default'
    ];
}

// Function to get zone-based fixed reading date
function getZoneReadingDate($zone_number) {
    // Zone 1 = 3rd, Zone 2 = 4th, Zone 3 = 5th, ... Zone 12 = 14th
    // Formula: reading_date = zone_number + 2
    return $zone_number + 2;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug log POST data
    error_log("===== ADD MACHINE FORM SUBMISSION =====");
    error_log("Client ID: $client_id");
    error_log("POST Data received:");
    foreach ($_POST as $key => $value) {
        if (is_array($value)) {
            error_log("  $key: Array with " . count($value) . " elements");
        } else {
            error_log("  $key: " . ($value === '' ? '(empty string)' : $value));
        }
    }
    
    $installation_type = $_POST['installation_type'] ?? '';
    $machine_count = $_POST['machine_count'] ?? 1;
    
    error_log("Installation type from POST: '$installation_type'");
    error_log("Machine count from POST: '$machine_count'");
    
    // Validate installation type
    if (empty($installation_type)) {
        sendJsonError("Installation type is required. Please select Single or Multiple.");
    }
    
    if (!in_array($installation_type, ['SINGLE', 'MULTIPLE'])) {
        sendJsonError("Invalid installation type: '$installation_type'. Please select Single or Multiple.");
    }
    
    $errors = [];
    $machines = [];
    
    if ($installation_type === 'SINGLE') {
        // Debug single machine fields
        error_log("Processing SINGLE installation");
        error_log("Street number: " . ($_POST['street_number'] ?? 'NOT SET'));
        error_log("Street name: " . ($_POST['street_name'] ?? 'NOT SET'));
        error_log("Barangay: " . ($_POST['barangay'] ?? 'NOT SET'));
        error_log("City: " . ($_POST['city'] ?? 'NOT SET'));
        error_log("Machine number: " . ($_POST['machine_number'] ?? 'NOT SET'));
        
        // Validate required fields
        $required_fields = ['street_number', 'street_name', 'city', 'machine_number'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = "Please fill in " . str_replace('_', ' ', $field);
            }
        }
        
        if (empty($errors)) {
            $machines[] = [
                'street_number' => $_POST['street_number'] ?? '',
                'street_name' => $_POST['street_name'] ?? '',
                'barangay' => $_POST['barangay'] ?? '',
                'city' => $_POST['city'] ?? '',
                'machine_number' => $_POST['machine_number'] ?? '',
                'department' => $_POST['department'] ?? '',
                'reading_date' => $_POST['reading_date'] ?? null,
                'processing_period' => $_POST['processing_period'] ?? 10,
                'collection_date' => $_POST['collection_date'] ?? null
            ];
        }
    } else if ($installation_type === 'MULTIPLE') {
        error_log("Processing MULTIPLE installation");
        $machine_count = intval($machine_count);
        error_log("Machine count (int): $machine_count");
        
        if ($machine_count < 2) {
            $errors[] = "For multiple machines, please enter at least 2";
        } else {
            for ($i = 0; $i < $machine_count; $i++) {
                error_log("Checking machine $i:");
                error_log("  Street number[$i]: " . ($_POST['street_number'][$i] ?? 'NOT SET'));
                error_log("  Street name[$i]: " . ($_POST['street_name'][$i] ?? 'NOT SET'));
                error_log("  Barangay[$i]: " . ($_POST['barangay'][$i] ?? 'NOT SET'));
                error_log("  City[$i]: " . ($_POST['city'][$i] ?? 'NOT SET'));
                error_log("  Machine number[$i]: " . ($_POST['machine_number'][$i] ?? 'NOT SET'));
                
                if (empty($_POST['street_number'][$i]) || empty($_POST['street_name'][$i]) || empty($_POST['city'][$i])) {
                    $errors[] = "Please fill in all address fields for Machine " . ($i + 1);
                    continue;
                }
                
                if (empty($_POST['machine_number'][$i])) {
                    $errors[] = "Machine number is required for Machine " . ($i + 1);
                    continue;
                }
                
                $machines[] = [
                    'street_number' => $_POST['street_number'][$i] ?? '',
                    'street_name' => $_POST['street_name'][$i] ?? '',
                    'barangay' => $_POST['barangay'][$i] ?? '',
                    'city' => $_POST['city'][$i] ?? '',
                    'machine_number' => $_POST['machine_number'][$i] ?? '',
                    'department' => $_POST['department'][$i] ?? '',
                    'reading_date' => $_POST['reading_date'][$i] ?? null,
                    'processing_period' => $_POST['processing_period'][$i] ?? 10,
                    'collection_date' => $_POST['collection_date'][$i] ?? null
                ];
            }
        }
    }
    
    if (!empty($errors)) {
        error_log("Validation errors: " . implode(', ', $errors));
        sendJsonError(implode('<br>', $errors));
    }
    
    error_log("Number of machines to insert: " . count($machines));
    
    // Insert machines
    $success_count = 0;
    foreach ($machines as $index => $machine) {
        error_log("Inserting machine $index: " . $machine['machine_number']);
        
        // Get zone based on barangay and city using geographical proximity
        $zone_data = getZoneFromLocation($machine['barangay'], $machine['city'], $conn);
        $zone_id = $zone_data['zone_id'];
        $zone_number = $zone_data['zone_number'];
        
        error_log("  Zone assigned: #{$zone_number} (ID: {$zone_id}) via {$zone_data['source']}");
        if (isset($zone_data['distance_km'])) {
            error_log("  Distance to zone center: {$zone_data['distance_km']} km");
        }
        
        $reading_date = null;
        $processing_period = null;
        $collection_date = null;
        
        // For PRIVATE clients, use zone-based fixed reading date
        if ($client['classification'] === 'PRIVATE') {
            $reading_date = getZoneReadingDate($zone_number);
            $processing_period = intval($machine['processing_period']) ?: 10;
            $collection_date = $reading_date + $processing_period;
            if ($collection_date > 31) {
                $collection_date -= 31;
            }
            
            error_log("  Private client - Reading: $reading_date (Zone-based), Process: $processing_period, Collect: $collection_date");
        } else {
            // For government clients, they can choose any date
            $reading_date = intval($machine['reading_date']);
            $processing_period = intval($machine['processing_period']);
            $collection_date = intval($machine['collection_date']);
            error_log("  Government client - Reading: $reading_date, Process: $processing_period, Collect: $collection_date");
        }
        
        // Insert into zoning_machine table with ACTIVE status
        $stmt = $conn->prepare("INSERT INTO zoning_machine (client_id, installation_type, street_number, street_name, barangay, city, machine_number, department, reading_date, processing_period, collection_date, zone_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE')");
        
        if (!$stmt) {
            error_log("  Prepare failed: " . $conn->error);
            sendJsonError('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("isssssssiiii", 
            $client_id, 
            $installation_type, 
            $machine['street_number'], 
            $machine['street_name'], 
            $machine['barangay'], 
            $machine['city'], 
            $machine['machine_number'], 
            $machine['department'], 
            $reading_date, 
            $processing_period, 
            $collection_date, 
            $zone_id
        );
        
        if ($stmt->execute()) {
            $success_count++;
            error_log("  Machine inserted successfully");
        } else {
            error_log("  Execute failed: " . $stmt->error);
            sendJsonError('Failed to insert machine: ' . $stmt->error);
        }
    }
    
    error_log("Insertion complete. Successfully added: $success_count machines");
    sendJsonSuccess(['count' => $success_count]);
}

// If we reach here, it's a GET request, so output the HTML form
// Clear any output buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Machine</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .container { background: #f5f5f5; padding: 20px; border-radius: 5px; }
        h2 { color: #333; border-bottom: 2px solid #2196F3; padding-bottom: 10px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        input[readonly] { background: #f5f5f5; color: #666; }
        button { background: #2196F3; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #1976D2; }
        button:disabled { background: #ccc; cursor: not-allowed; }
        .machine-entry { border: 1px solid #ddd; padding: 15px; margin: 10px 0; border-radius: 4px; background: white; }
        .machine-entry h4 { margin-top: 0; color: #666; }
        .hidden { display: none; }
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .inline-fields { display: flex; gap: 10px; }
        .inline-fields > div { flex: 1; }
        .zone-display { background: #e3f2fd; padding: 10px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #2196F3; }
        .zone-badge { display: inline-block; padding: 4px 8px; background: #2196F3; color: white; border-radius: 4px; font-weight: bold; }
        .info-text { color: #666; font-size: 0.9em; }
        .schedule-display { background: #fff3cd; padding: 10px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .required { color: #dc3545; }
        .loading { color: #666; font-style: italic; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Add Machine for: <?php echo htmlspecialchars($client['company_name']); ?></h2>
        <p><strong>Client ID:</strong> <?php echo $client_id; ?></p>
        <p><strong>Classification:</strong> 
            <span style="color: <?php echo $client['classification'] === 'GOVERNMENT' ? '#2e7d32' : '#1565c0'; ?>; font-weight: bold;">
                <?php echo $client['classification']; ?>
            </span>
        </p>
        <p><strong>Status:</strong> 
            <span style="color: <?php echo $client['status'] === 'ACTIVE' ? '#4CAF50' : '#f44336'; ?>; font-weight: bold;">
                <?php echo $client['status']; ?>
            </span>
        </p>
        
        <form id="machineForm">
            <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
            <input type="hidden" id="classification" value="<?php echo $client['classification']; ?>">
            
            <!-- MOVE THIS INSIDE THE FORM -->
            <div class="form-group">
                <label for="installation_type">Installation Type <span class="required">*</span></label>
                <select id="installation_type" name="installation_type" onchange="toggleInstallationType()" required>
                    <option value="">Select Type</option>
                    <option value="SINGLE">Single Machine</option>
                    <option value="MULTIPLE">Multiple Machines</option>
                </select>
            </div>
            
            <div id="singleMachine" class="hidden">
                <div class="machine-entry">
                    <h4>Machine Details</h4>
                    
                    <!-- Zone display area -->
                    <div id="zoneDisplay" class="zone-display hidden">
                        <strong>📍 Zone Assignment:</strong> <span id="zoneInfo"></span>
                    </div>
                    
                    <!-- Schedule display area -->
                    <div id="scheduleDisplay" class="schedule-display hidden">
                        <strong>📅 Schedule:</strong>
                        <div id="scheduleInfo"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Installation Address <span class="required">*</span></label>
                        <div class="inline-fields">
                            <div>
                                <label>Street Number <span class="required">*</span></label>
                                <input type="text" name="street_number" id="street_number" onblur="updateZoneAndSchedule()">
                            </div>
                            <div>
                                <label>Street Name <span class="required">*</span></label>
                                <input type="text" name="street_name" id="street_name" onblur="updateZoneAndSchedule()">
                            </div>
                        </div>
                        <div class="inline-fields" style="margin-top: 10px;">
                            <div>
                                <label>Barangay <span class="required">*</span></label>
                                <input type="text" name="barangay" id="barangay" onblur="updateZoneAndSchedule()">
                                <small class="info-text">Important for accurate zone assignment</small>
                            </div>
                            <div>
                                <label>City <span class="required">*</span></label>
                                <input type="text" name="city" id="city" onblur="updateZoneAndSchedule()">
                                <small class="info-text">Zone is determined by geographical location</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="machine_number">Machine Number <span class="required">*</span></label>
                        <input type="text" id="machine_number" name="machine_number">
                    </div>
                    
                    <div class="form-group">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department">
                    </div>
                    
                    <?php if ($client['classification'] === 'PRIVATE'): ?>
                        <!-- Hidden fields for private clients (will be populated by JavaScript) -->
                        <input type="hidden" id="assigned_reading_date" name="reading_date" value="">
                        <input type="hidden" id="assigned_collection_date" name="collection_date" value="">
                    <?php endif; ?>
                    
                    <!-- Processing Period (Always editable) -->
                    <div class="form-group">
                        <label for="processing_period">Processing Period (days) <span class="required">*</span></label>
                        <input type="number" id="processing_period" name="processing_period" min="1" max="31" value="10" onchange="updateCollectionDate()">
                        <small class="info-text">Default is 10 days. Collection date = Reading date + Processing period</small>
                    </div>
                    
                    <?php if ($client['classification'] === 'GOVERNMENT'): ?>
                        <!-- Government clients enter dates manually -->
                        <div class="form-group">
                            <label for="reading_date_manual">Reading Date (1-31) <span class="required">*</span></label>
                            <input type="number" id="reading_date_manual" name="reading_date" min="1" max="31" onchange="calculateGovernmentCollectionDate()">
                        </div>
                        
                        <div class="form-group">
                            <label for="collection_date_manual">Collection Date</label>
                            <input type="number" id="collection_date_manual" name="collection_date" min="1" max="31">
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div id="multipleMachines" class="hidden">
                <div class="form-group">
                    <label for="machine_count">How many machines? <span class="required">*</span></label>
                    <input type="number" id="machine_count" name="machine_count" min="2" max="100" onchange="generateMachineFields()">
                    <small class="info-text">Enter number of machines (2 to 100)</small>
                </div>
                <div id="machineFields"></div>
            </div>
            
            <button type="submit" id="submitBtn">Add Machine(s)</button>
        </form>
        <div id="message" class="message"></div>
    </div>
    
    <script>
    // Form submission handler
    document.addEventListener('DOMContentLoaded', function() {
        const machineForm = document.getElementById('machineForm');
        const submitBtn = document.getElementById('submitBtn');
        const installationTypeSelect = document.getElementById('installation_type');
        
        console.log('DOM loaded, form found:', !!machineForm);
        console.log('Installation type select element:', installationTypeSelect);
        
        if (machineForm) {
            machineForm.addEventListener('submit', function(e) {
                e.preventDefault();
                console.log('Form submitted!');
                
                // Test form data collection
                console.log('Testing form data collection:');
                const testFormData = new FormData(machineForm);
                for (let [key, value] of testFormData.entries()) {
                    console.log(key, '=', value);
                }
                
                // Get installation type
                const installationType = installationTypeSelect.value;
                console.log('Installation type from select:', installationType);
                
                if (!installationType) {
                    showMessage('Please select installation type', 'error');
                    return false;
                }
                
                // Validate based on installation type
                let isValid = true;
                let errorMessage = '';
                
                if (installationType === 'SINGLE') {
                    console.log('Validating SINGLE installation...');
                    // Validate single machine fields
                    const requiredFields = ['street_number', 'street_name', 'barangay', 'city', 'machine_number'];
                    for (const fieldId of requiredFields) {
                        const field = document.getElementById(fieldId);
                        console.log(`Field ${fieldId}:`, field ? field.value : 'NOT FOUND');
                        if (field && !field.value.trim()) {
                            isValid = false;
                            errorMessage = `Please fill in ${fieldId.replace('_', ' ')}`;
                            break;
                        }
                    }
                    
                    // For private clients, validate processing period
                    if (isValid) {
                        const classification = document.getElementById('classification').value;
                        const processingPeriod = document.getElementById('processing_period');
                        if (processingPeriod && (!processingPeriod.value || parseInt(processingPeriod.value) < 1 || parseInt(processingPeriod.value) > 31)) {
                            isValid = false;
                            errorMessage = 'Processing period must be between 1-31 days';
                        }
                        
                        // For government clients, validate manual dates
                        if (classification === 'GOVERNMENT') {
                            const readingDate = document.getElementById('reading_date_manual');
                            if (readingDate && (!readingDate.value || parseInt(readingDate.value) < 1 || parseInt(readingDate.value) > 31)) {
                                isValid = false;
                                errorMessage = 'Reading date must be between 1-31';
                            }
                        }
                    }
                } else if (installationType === 'MULTIPLE') {
                    console.log('Validating MULTIPLE installation...');
                    // Validate multiple machines
                    const machineCount = document.getElementById('machine_count');
                    console.log('Machine count value:', machineCount ? machineCount.value : 'NOT FOUND');
                    
                    if (!machineCount.value || parseInt(machineCount.value) < 2) {
                        isValid = false;
                        errorMessage = 'For multiple machines, please enter at least 2';
                    } else if (parseInt(machineCount.value) > 100) {
                        isValid = false;
                        errorMessage = 'Maximum number of machines is 100';
                    } else {
                        const count = parseInt(machineCount.value);
                        console.log('Checking', count, 'machines...');
                        // Check if all machine fields are filled
                        for (let i = 0; i < count; i++) {
                            const barangayField = document.getElementById(`barangay_${i}`);
                            const cityField = document.getElementById(`city_${i}`);
                            const machineNumberField = document.querySelector(`[name="machine_number[${i}]"]`);
                            
                            console.log(`Machine ${i} - Barangay:`, barangayField ? barangayField.value : 'NOT FOUND');
                            console.log(`Machine ${i} - City:`, cityField ? cityField.value : 'NOT FOUND');
                            console.log(`Machine ${i} - Machine number:`, machineNumberField ? machineNumberField.value : 'NOT FOUND');
                            
                            if ((barangayField && !barangayField.value.trim()) || 
                                (cityField && !cityField.value.trim()) || 
                                (machineNumberField && !machineNumberField.value.trim())) {
                                isValid = false;
                                errorMessage = `Please fill in all required fields for Machine ${i + 1}`;
                                break;
                            }
                        }
                    }
                }
                
                if (!isValid) {
                    showMessage(errorMessage, 'error');
                    return false;
                }
                
                // Show loading state with better message for large numbers
                submitBtn.disabled = true;
                if (installationType === 'MULTIPLE') {
                    const count = document.getElementById('machine_count').value;
                    submitBtn.textContent = `Adding ${count} machines...`;
                } else {
                    submitBtn.textContent = 'Adding machine...';
                }
                
                // Create FormData
                const formData = new FormData(machineForm);
                
                // Debug: Log ALL form data
                console.log('=== ALL FORM DATA BEING SUBMITTED ===');
                let entryCount = 0;
                for (let [key, value] of formData.entries()) {
                    console.log(`${key}: ${value}`);
                    entryCount++;
                }
                console.log(`Total entries: ${entryCount}`);
                console.log('=== END FORM DATA ===');
                
                // Submit via fetch
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    console.log('Response received:', response);
                    console.log('Response URL:', response.url);
                    
                    // First check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        // If we got redirected (status 302, 301, etc.), handle it
                        if (response.redirected) {
                            console.log('Response was redirected to:', response.url);
                            throw new Error('Server redirected to: ' . response.url);
                        }
                        
                        // Otherwise, try to read the text to see what happened
                        return response.text().then(text => {
                            console.error('Non-JSON response (first 500 chars):', text.substring(0, 500));
                            throw new Error('Server returned non-JSON response');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Parsed response data:', data);
                    
                    if (data.success) {
                        const count = data.count || 1;
                        showMessage(`✅ Success! ${count} machine(s) added successfully. Redirecting...`, 'success');
                        
                        // Redirect after 2 seconds
                        setTimeout(() => {
                            window.location.href = 'view_machines.php?msg=added';
                        }, 2000);
                    } else {
                        showMessage(`❌ Error: ${data.error || 'Failed to add machine(s)'}`, 'error');
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Add Machine(s)';
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    showMessage(`❌ Error: ${error.message}. Please check PHP error logs.`, 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Add Machine(s)';
                });
                
                return false;
            });
        } else {
            console.error('Form with ID "machineForm" not found!');
        }
    });

    function toggleInstallationType() {
        const type = document.getElementById('installation_type').value;
        const singleMachine = document.getElementById('singleMachine');
        const multipleMachines = document.getElementById('multipleMachines');
        
        console.log('Installation type changed to:', type);
        
        // Remove required attributes from hidden form elements
        if (type === 'MULTIPLE') {
            // Remove required attributes from single machine fields
            const singleFields = ['street_number', 'street_name', 'barangay', 'city', 'machine_number'];
            singleFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.removeAttribute('required');
                    field.removeAttribute('name'); // Remove name to prevent submission
                }
            });
            
            singleMachine.classList.add('hidden');
            multipleMachines.classList.remove('hidden');
            
            // Add required to machine count
            const machineCountInput = document.getElementById('machine_count');
            machineCountInput.setAttribute('required', 'required');
            
        } else if (type === 'SINGLE') {
            // Add required attributes back to single machine fields
            const singleFields = ['street_number', 'street_name', 'barangay', 'city', 'machine_number'];
            singleFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.setAttribute('required', 'required');
                    field.setAttribute('name', fieldId); // Restore name for submission
                }
            });
            
            singleMachine.classList.remove('hidden');
            multipleMachines.classList.add('hidden');
            
            // Remove required from machine count and clear fields
            const machineCountInput = document.getElementById('machine_count');
            machineCountInput.removeAttribute('required');
            machineCountInput.value = '';
            document.getElementById('machineFields').innerHTML = '';
        } else {
            // No type selected
            singleMachine.classList.add('hidden');
            multipleMachines.classList.add('hidden');
        }
    }

    async function updateZoneAndSchedule() {
        const barangay = document.getElementById('barangay').value;
        const city = document.getElementById('city').value;
        const classification = document.getElementById('classification').value;
        
        console.log('updateZoneAndSchedule called with:', { barangay, city });
        
        if (city.trim() && barangay.trim()) {
            // Show loading message
            const zoneInfo = document.getElementById('zoneInfo');
            zoneInfo.innerHTML = '<span class="loading">Calculating best zone...</span>';
            document.getElementById('zoneDisplay').classList.remove('hidden');
            
            try {
                console.log('Calling API for zone...');
                
                // Call API to get zone based on barangay + city
                const formData = new FormData();
                formData.append('barangay', barangay);
                formData.append('city', city);
                formData.append('client_id', <?php echo $client_id; ?>);
                
                const response = await fetch('get_zone_from_location.php', {
                    method: 'POST',
                    body: formData
                });
                
                console.log('API Response status:', response.status);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('API Response data:', data);
                
                if (data.success) {
                    zoneInfo.innerHTML = `
                        <strong>Zone ${data.zone_number}</strong> - ${data.area_center}<br>
                        <small>Assigned via: ${data.source}${data.distance_km ? ' • Distance: ' + data.distance_km + ' km' : ''}</small>
                    `;
                    
                    // For PRIVATE clients, show schedule based on zone
                    if (classification === 'PRIVATE' && data.zone_number) {
                        console.log('Getting reading date for zone:', data.zone_number);
                        const readingDate = getZoneReadingDate(data.zone_number);
                        const processingPeriod = parseInt(document.getElementById('processing_period').value) || 10;
                        let collectionDate = readingDate + processingPeriod;
                        if (collectionDate > 31) {
                            collectionDate -= 31;
                        }
                        
                        document.getElementById('scheduleInfo').innerHTML = `
                            <div>📅 Reading Date: <strong>Day ${readingDate}</strong> (Fixed for Zone ${data.zone_number})</div>
                            <div>⏱️ Processing Period: <strong>${processingPeriod} days</strong> (Editable)</div>
                            <div>📦 Collection Date: <strong>Day ${collectionDate}</strong> (Auto-calculated)</div>
                        `;
                        
                        // Set hidden fields for form submission
                        document.getElementById('assigned_reading_date').value = readingDate;
                        document.getElementById('assigned_collection_date').value = collectionDate;
                        
                        document.getElementById('scheduleDisplay').classList.remove('hidden');
                    }
                } else {
                    console.error('API returned error:', data.error);
                    zoneInfo.innerHTML = `Zone assignment failed: ${data.error || 'Unknown error'}`;
                    document.getElementById('scheduleDisplay').classList.add('hidden');
                }
            } catch (error) {
                console.error('Error in updateZoneAndSchedule:', error);
                zoneInfo.innerHTML = 'Error calculating zone: ' + error.message;
                document.getElementById('scheduleDisplay').classList.add('hidden');
            }
        } else {
            console.log('City or barangay empty, hiding zone display');
            document.getElementById('zoneDisplay').classList.add('hidden');
            document.getElementById('scheduleDisplay').classList.add('hidden');
        }
    }

    // Function to get zone-based reading date
    function getZoneReadingDate(zoneNumber) {
        return zoneNumber + 2; // Zone 1 = 3rd, Zone 2 = 4th, etc.
    }

    function updateCollectionDate() {
        const classification = document.getElementById('classification').value;
        
        if (classification === 'PRIVATE') {
            updateZoneAndSchedule();
        }
    }

    function calculateGovernmentCollectionDate() {
        const readingDate = parseInt(document.getElementById('reading_date_manual').value) || 0;
        const processingPeriod = parseInt(document.getElementById('processing_period').value) || 10;
        let collectionDate = readingDate + processingPeriod;
        
        if (collectionDate > 31) {
            collectionDate -= 31;
        }
        
        document.getElementById('collection_date_manual').value = collectionDate;
    }

    function generateMachineFields() {
        const count = parseInt(document.getElementById('machine_count').value) || 0;
        const container = document.getElementById('machineFields');
        const classification = document.getElementById('classification').value;
        
        // Clear previous fields
        container.innerHTML = '';
        
        // Validate minimum value
        if (count < 2) {
            showMessage('For multiple machines, please enter at least 2', 'error');
            return;
        }
        
        if (count > 100) {
            showMessage('Maximum number of machines is 100', 'error');
            document.getElementById('machine_count').value = 100;
            generateMachineFields(); // Regenerate with max value
            return;
        }
        
        // Show loading message for large numbers
        if (count > 20) {
            container.innerHTML = `<div class="message">Generating ${count} machine forms... Please wait.</div>`;
            // Use setTimeout to allow UI to update
            setTimeout(() => {
                createMachineForms(count, classification, container);
            }, 10);
        } else {
            createMachineForms(count, classification, container);
        }
    }

    function createMachineForms(count, classification, container) {
        container.innerHTML = ''; // Clear any loading message
        
        for (let i = 0; i < count; i++) {
            const machineHTML = `
                <div class="machine-entry" id="machineEntry${i}">
                    <h4>Machine ${i + 1}</h4>
                    
                    <!-- Zone display for each machine -->
                    <div id="zoneDisplay${i}" class="zone-display hidden">
                        <strong>📍 Zone Assignment:</strong> <span id="zoneInfo${i}"></span>
                    </div>
                    
                    <!-- Schedule display for each machine -->
                    <div id="scheduleDisplay${i}" class="schedule-display hidden">
                        <strong>📅 Schedule:</strong>
                        <div id="scheduleInfo${i}"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Installation Address <span class="required">*</span></label>
                        <div class="inline-fields">
                            <div>
                                <label>Street Number <span class="required">*</span></label>
                                <input type="text" name="street_number[${i}]" id="street_number_${i}" onblur="updateMultipleZoneInfo(${i})">
                            </div>
                            <div>
                                <label>Street Name <span class="required">*</span></label>
                                <input type="text" name="street_name[${i}]" id="street_name_${i}" onblur="updateMultipleZoneInfo(${i})">
                            </div>
                        </div>
                        <div class="inline-fields" style="margin-top: 10px;">
                            <div>
                                <label>Barangay <span class="required">*</span></label>
                                <input type="text" name="barangay[${i}]" id="barangay_${i}" onblur="updateMultipleZoneInfo(${i})">
                            </div>
                            <div>
                                <label>City <span class="required">*</span></label>
                                <input type="text" name="city[${i}]" id="city_${i}" onblur="updateMultipleZoneInfo(${i})">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Machine Number <span class="required">*</span></label>
                        <input type="text" name="machine_number[${i}]" id="machine_number_${i}">
                    </div>
                    
                    <div class="form-group">
                        <label>Department</label>
                        <input type="text" name="department[${i}]">
                    </div>
                    
                    <!-- Processing Period (Always editable) -->
                    <div class="form-group">
                        <label>Processing Period (days) <span class="required">*</span></label>
                        <input type="number" name="processing_period[${i}]" id="processing_period_${i}" min="1" max="31" value="10" onchange="updateMultipleCollectionDate(${i})">
                    </div>
                    
                    ${classification === 'GOVERNMENT' ? `
                        <div class="form-group">
                            <label>Reading Date (1-31) <span class="required">*</span></label>
                            <input type="number" name="reading_date[${i}]" id="reading_date_${i}" min="1" max="31">
                        </div>
                        
                        <div class="form-group">
                            <label>Collection Date <span class="required">*</span></label>
                            <input type="number" name="collection_date[${i}]" id="collection_date_${i}" min="1" max="31">
                        </div>
                    ` : `
                        <!-- Hidden fields for private clients -->
                        <input type="hidden" name="reading_date[${i}]" id="assigned_reading_date_${i}" value="">
                        <input type="hidden" name="collection_date[${i}]" id="assigned_collection_date_${i}" value="">
                    `}
                </div>
            `;
            
            container.innerHTML += machineHTML;
            
            // Add event listeners for government clients
            if (classification === 'GOVERNMENT') {
                setTimeout(() => {
                    const readingDateField = document.getElementById(`reading_date_${i}`);
                    const processingPeriodField = document.getElementById(`processing_period_${i}`);
                    const collectionDateField = document.getElementById(`collection_date_${i}`);
                    
                    if (readingDateField && processingPeriodField && collectionDateField) {
                        readingDateField.addEventListener('input', function() {
                            calculateMultipleGovernmentCollectionDate(i);
                        });
                        processingPeriodField.addEventListener('input', function() {
                            calculateMultipleGovernmentCollectionDate(i);
                        });
                        
                        // Set initial collection date
                        calculateMultipleGovernmentCollectionDate(i);
                    }
                }, 100);
            }
        }
    }

    // Function to calculate collection date for multiple government machines
    function calculateMultipleGovernmentCollectionDate(index) {
        const readingDate = parseInt(document.getElementById(`reading_date_${index}`).value) || 0;
        const processingPeriod = parseInt(document.getElementById(`processing_period_${index}`).value) || 10;
        let collectionDate = readingDate + processingPeriod;
        
        if (collectionDate > 31) {
            collectionDate -= 31;
        }
        
        const collectionDateField = document.getElementById(`collection_date_${index}`);
        if (collectionDateField) {
            collectionDateField.value = collectionDate;
        }
    }

    async function updateMultipleZoneInfo(index) {
        const barangayInput = document.getElementById(`barangay_${index}`);
        const cityInput = document.getElementById(`city_${index}`);
        const barangay = barangayInput.value;
        const city = cityInput.value;
        const zoneDisplay = document.getElementById(`zoneDisplay${index}`);
        const zoneInfo = document.getElementById(`zoneInfo${index}`);
        const scheduleDisplay = document.getElementById(`scheduleDisplay${index}`);
        const scheduleInfo = document.getElementById(`scheduleInfo${index}`);
        const classification = document.getElementById('classification').value;
        
        if (city.trim() && barangay.trim()) {
            zoneInfo.innerHTML = '<span class="loading">Calculating best zone...</span>';
            zoneDisplay.classList.remove('hidden');
            
            try {
                const response = await fetch('get_zone_from_location.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `barangay=${encodeURIComponent(barangay)}&city=${encodeURIComponent(city)}&client_id=<?php echo $client_id; ?>`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    zoneInfo.innerHTML = `
                        <strong>Zone ${data.zone_number}</strong> - ${data.area_center}<br>
                        <small>${data.source}${data.distance_km ? ' • ' + data.distance_km + ' km' : ''}</small>
                    `;
                    
                    // For PRIVATE clients, show schedule based on zone
                    if (classification === 'PRIVATE' && data.zone_number) {
                        const readingDate = getZoneReadingDate(data.zone_number);
                        const processingPeriodInput = document.getElementById(`processing_period_${index}`);
                        const processingPeriod = parseInt(processingPeriodInput.value) || 10;
                        let collectionDate = readingDate + processingPeriod;
                        if (collectionDate > 31) {
                            collectionDate -= 31;
                        }
                        
                        scheduleInfo.innerHTML = `
                            <div>📅 Reading Date: <strong>Day ${readingDate}</strong> (Fixed for Zone ${data.zone_number})</div>
                            <div>⏱️ Processing: <strong>${processingPeriod} days</strong></div>
                            <div>📦 Collection: <strong>Day ${collectionDate}</strong></div>
                        `;
                        
                        // Set hidden fields
                        document.getElementById(`assigned_reading_date_${index}`).value = readingDate;
                        document.getElementById(`assigned_collection_date_${index}`).value = collectionDate;
                        
                        scheduleDisplay.classList.remove('hidden');
                    }
                } else {
                    zoneInfo.textContent = 'Zone assignment failed';
                    scheduleDisplay.classList.add('hidden');
                }
            } catch (error) {
                console.error('Error:', error);
                zoneInfo.textContent = 'Error calculating zone';
                scheduleDisplay.classList.add('hidden');
            }
        } else {
            zoneDisplay.classList.add('hidden');
            scheduleDisplay.classList.add('hidden');
        }
    }

    async function updateMultipleCollectionDate(index) {
        const classification = document.getElementById('classification').value;
        
        if (classification === 'PRIVATE') {
            await updateMultipleZoneInfo(index);
        } else if (classification === 'GOVERNMENT') {
            calculateMultipleGovernmentCollectionDate(index);
        }
    }

    function showMessage(text, type) {
        const messageDiv = document.getElementById('message');
        if (!messageDiv) {
            console.error('Message div not found!');
            return;
        }
        
        messageDiv.className = 'message ' + type;
        messageDiv.innerHTML = text;
        messageDiv.style.display = 'block';
        
        // Scroll to message
        messageDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // Initialize event listeners for government clients (single machine)
    <?php if ($client['classification'] === 'GOVERNMENT'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const processingPeriod = document.getElementById('processing_period');
        const readingDateManual = document.getElementById('reading_date_manual');
        
        if (processingPeriod && readingDateManual) {
            processingPeriod.addEventListener('input', calculateGovernmentCollectionDate);
            readingDateManual.addEventListener('input', calculateGovernmentCollectionDate);
            
            // Trigger initial calculation
            calculateGovernmentCollectionDate();
        }
    });
    <?php endif; ?>

    console.log('JavaScript loaded for add_machine.php');
    </script>
</body>
</html>