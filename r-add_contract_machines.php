<?php
require_once 'config.php';

// Turn off error display but log them
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

// Get distinct cities FROM rental_zone_coordinates for dropdown
$cities_query = $conn->query("SELECT DISTINCT city FROM rental_zone_coordinates WHERE city IS NOT NULL AND city != '' ORDER BY city");
$cities = [];
while ($city_row = $cities_query->fetch_assoc()) {
    $cities[] = $city_row['city'];
}

// Handle AJAX request for barangay suggestions - THIS MUST COME FIRST
if (isset($_GET['get_barangays'])) {
    header('Content-Type: application/json');
    
    $city = trim($_GET['city'] ?? '');
    $search = trim($_GET['search'] ?? '');
    
    if (empty($city)) {
        echo json_encode(['success' => false, 'error' => 'City is required']);
        exit;
    }
    
    $query = "SELECT barangay FROM rental_zone_coordinates WHERE LOWER(city) = LOWER(?)";
    $params = [$city];
    $types = "s";
    
    if (!empty($search)) {
        $query .= " AND LOWER(barangay) LIKE LOWER(?)";
        $params[] = "%$search%";
        $types .= "s";
    }
    
    $query .= " ORDER BY barangay LIMIT 20";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
        exit;
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $barangays = [];
    while ($row = $result->fetch_assoc()) {
        $barangays[] = $row['barangay'];
    }
    
    echo json_encode(['success' => true, 'barangays' => $barangays]);
    exit;
}

// NOW validate contract and client
$contract_id = isset($_GET['contract_id']) ? intval($_GET['contract_id']) : 0;
$contract_type = isset($_GET['type']) ? $_GET['type'] : '';
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if (!$contract_id || !$client_id) {
    die("Invalid contract or client information.");
}

// Get contract and client information
$contract_query = $conn->query("
    SELECT c.*, cl.classification, cl.company_name, cl.status 
    FROM rental_contracts c
    JOIN rental_clients cl ON c.client_id = cl.id
    WHERE c.id = $contract_id
");
$contract_data = $contract_query->fetch_assoc();

if (!$contract_data) {
    die("Contract not found.");
}

// Check if client is active
if ($contract_data['status'] === 'INACTIVE') {
    die("Cannot add machine to inactive client!");
}

// Function to send JSON error
function sendJsonError($message) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// Function to send JSON success
function sendJsonSuccess($data = []) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

// Function to get zone-based fixed reading date
function getZoneReadingDate($zone_number) {
    return $zone_number + 2;
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

// Function to get zone from barangay and city using zone_coordinates
function getZoneFromBarangay($barangay, $city, $conn) {
    // First, check if barangay exists in zone_coordinates
    $stmt = $conn->prepare("
        SELECT latitude, longitude 
        FROM rental_zone_coordinates 
        WHERE LOWER(barangay) = LOWER(?) AND LOWER(city) = LOWER(?)
        LIMIT 1
    ");
    
    if (!$stmt) {
        return ['success' => false, 'error' => 'Database error'];
    }
    
    $stmt->bind_param("ss", $barangay, $city);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return ['success' => false, 'error' => 'barangay_not_found'];
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
        FROM rental_zoning_zones 
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL
        ORDER BY distance ASC 
        LIMIT 1
    ");
    
    if (!$zone_stmt) {
        return ['success' => false, 'error' => 'Database error'];
    }
    
    $zone_stmt->bind_param("ddd", $lat, $lng, $lat);
    $zone_stmt->execute();
    $zone_result = $zone_stmt->get_result();
    
    if ($zone_result->num_rows > 0) {
        $zone = $zone_result->fetch_assoc();
        $distance = calculateDistance($lat, $lng, $zone['latitude'], $zone['longitude']);
        
        return [
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
        ];
    }
    
    return ['success' => false, 'error' => 'No zones found'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_machines'])) {
    $conn->begin_transaction();
    
    try {
        // Check if machine_type exists and is array
        if (!isset($_POST['machine_type']) || !is_array($_POST['machine_type'])) {
            throw new Exception("No machine data received.");
        }
        
        $machine_count = count($_POST['machine_type']);
        $highest_reading_date = 0;
        $success_count = 0;
        $errors = [];
        
        // Create upload directory if not exists
        $upload_dir = 'uploads/dr_pos/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        for ($i = 0; $i < $machine_count; $i++) {
            // Validate required fields
            if (empty($_POST['barangay'][$i]) || empty($_POST['city'][$i]) || empty($_POST['machine_number'][$i])) {
                $errors[] = "Barangay, City, and Machine Number are required for Machine " . ($i + 1);
                continue;
            }
            
            // Get zone data from barangay and city using zone_coordinates
            $barangay = trim($_POST['barangay'][$i]);
            $city = trim($_POST['city'][$i]);
            
            $zone_result = getZoneFromBarangay($barangay, $city, $conn);
            
            if (!$zone_result['success']) {
                if ($zone_result['error'] === 'barangay_not_found') {
                    $errors[] = "Barangay '{$barangay}' in '{$city}' not found in database. Please contact administrator.";
                } else {
                    $errors[] = "Could not determine zone for Machine " . ($i + 1) . ": " . $zone_result['error'];
                }
                continue;
            }
            
            $zone_id = $zone_result['zone_id'];
            $zone_number = $zone_result['zone_number'];
            $area_center = $conn->real_escape_string($zone_result['area_center']);
            
            // Get reading date
            $user_reading_date = isset($_POST['reading_date'][$i]) ? intval($_POST['reading_date'][$i]) : 0;
            $recommended_reading_date = getZoneReadingDate($zone_number);
            
            // Determine if reading date is aligned or misaligned
            $reading_date_remarks = ($user_reading_date == $recommended_reading_date) 
                ? 'aligned reading date' 
                : 'mis-aligned reading date';
            
            // Track highest reading date for collection date calculation
            if ($user_reading_date > $highest_reading_date) {
                $highest_reading_date = $user_reading_date;
            }
            
            // Get machine details - escape all string values
            $machine_type = $conn->real_escape_string($_POST['machine_type'][$i]);
            $machine_model = $conn->real_escape_string($_POST['machine_model'][$i]);
            $machine_brand = $conn->real_escape_string($_POST['machine_brand'][$i]);
            $machine_serial = $conn->real_escape_string($_POST['machine_serial_number'][$i]);
            $machine_number = $conn->real_escape_string($_POST['machine_number'][$i]);
            $department = isset($_POST['department'][$i]) ? $conn->real_escape_string($_POST['department'][$i]) : '';
            $mono_meter_start = intval($_POST['mono_meter_start'][$i]);
            
            // Handle color meter start - set to NULL if not COLOR or empty
            $color_meter_start = 'NULL';
            if ($machine_type == 'COLOR' && isset($_POST['color_meter_start'][$i]) && $_POST['color_meter_start'][$i] !== '') {
                $color_meter_start = intval($_POST['color_meter_start'][$i]);
            }
            
            // Address fields
            $building_number = $conn->real_escape_string($_POST['building_number'][$i]);
            $street_name = $conn->real_escape_string($_POST['street_name'][$i]);
            $barangay = $conn->real_escape_string($_POST['barangay'][$i]);
            $city = $conn->real_escape_string($_POST['city'][$i]);
            
            // Comments
            $comments = isset($_POST['comments'][$i]) 
                ? $conn->real_escape_string($_POST['comments'][$i]) 
                : '';
            
            // Handle DR/POS file uploads
            $dr_pos_files = [];
            $dr_pos_file_count = 0;
            
            if (isset($_FILES['dr_pos_files']['name'][$i])) {
                $file_count = count($_FILES['dr_pos_files']['name'][$i]);
                
                for ($j = 0; $j < $file_count; $j++) {
                    if ($_FILES['dr_pos_files']['error'][$i][$j] == 0) {
                        $tmp_name = $_FILES['dr_pos_files']['tmp_name'][$i][$j];
                        $original_name = $_FILES['dr_pos_files']['name'][$i][$j];
                        
                        // Check file type - only PDF
                        $file_type = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                        if ($file_type != 'pdf') {
                            $errors[] = "File " . ($j + 1) . " for Machine " . ($i + 1) . " is not a PDF. Only PDF files are allowed.";
                            continue;
                        }
                        
                        // Check file size (max 10MB)
                        if ($_FILES['dr_pos_files']['size'][$i][$j] > 10 * 1024 * 1024) {
                            $errors[] = "File " . ($j + 1) . " for Machine " . ($i + 1) . " exceeds 10MB limit.";
                            continue;
                        }
                        
                        // Generate unique filename
                        $timestamp = time();
                        $unique_id = uniqid();
                        $safe_filename = preg_replace("/[^a-zA-Z0-9.]/", "_", $original_name);
                        $filename = "dr_pos_{$contract_id}_{$machine_count}_{$timestamp}_{$unique_id}_{$safe_filename}";
                        $filepath = $upload_dir . $filename;
                        
                        if (move_uploaded_file($tmp_name, $filepath)) {
                            $dr_pos_files[] = $filepath;
                            $dr_pos_file_count++;
                        }
                    }
                }
            }
            
            $dr_pos_files_str = !empty($dr_pos_files) ? "'" . $conn->real_escape_string(implode(',', $dr_pos_files)) . "'" : 'NULL';
            
            // Build SQL statement
            $sql = "INSERT INTO rental_contract_machines (
                contract_id, client_id, department, machine_type, machine_model, machine_brand,
                machine_serial_number, machine_number, mono_meter_start, color_meter_start,
                building_number, street_name, barangay, city, zone_id, zone_number,
                area_center, reading_date, reading_date_remarks, comments, 
                dr_pos_files, dr_pos_file_count, status, datecreated, createdby
            ) VALUES (
                $contract_id, 
                $client_id, 
                " . ($department ? "'$department'" : "NULL") . ", 
                '$machine_type', 
                '$machine_model',
                '$machine_brand', 
                '$machine_serial', 
                '$machine_number', 
                $mono_meter_start,
                $color_meter_start, 
                '$building_number', 
                '$street_name', 
                '$barangay',
                '$city', 
                $zone_id, 
                $zone_number, 
                '$area_center', 
                $user_reading_date,
                '$reading_date_remarks', 
                " . ($comments ? "'$comments'" : "NULL") . ", 
                $dr_pos_files_str, 
                $dr_pos_file_count, 
                'ACTIVE', 
                NOW(), 
                NULL
            )";
            
            // Log the SQL for debugging
            error_log("Insert SQL: " . $sql);
            
            if ($conn->query($sql)) {
                $success_count++;
                error_log("Machine $i inserted successfully. ID: " . $conn->insert_id . ", Zone: $zone_number, DR/POS files: $dr_pos_file_count");
            } else {
                $errors[] = "Failed to insert Machine " . ($i + 1) . ": " . $conn->error;
                error_log("Failed to insert machine $i: " . $conn->error);
                error_log("SQL: " . $sql);
            }
        }
        
        // Calculate and update collection date for PRIVATE clients
        if ($contract_data['classification'] == 'PRIVATE' && $highest_reading_date > 0) {
            $processing_period = intval($contract_data['collection_processing_period']);
            
            // Calculate collection date based on highest reading date
            $collection_date = $highest_reading_date + $processing_period;
            
            // Convert to calendar days (30 days per month)
            if ($collection_date > 31) {
                $collection_date -= 31;
            }
            
            // Update contract with collection date
            $conn->query("
                UPDATE rental_contracts 
                SET collection_date = $collection_date 
                WHERE id = $contract_id
            ");
            
            error_log("Updated contract $contract_id with collection date: $collection_date");
        }
        
        $conn->commit();
        
        if (!empty($errors)) {
            sendJsonSuccess([
                'success_count' => $success_count,
                'warnings' => $errors,
                'redirect' => 'r-view_contracts.php'
            ]);
        } else {
            sendJsonSuccess([
                'success_count' => $success_count,
                'redirect' => 'r-view_contracts.php'
            ]);
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Transaction error: " . $e->getMessage());
        sendJsonError("Failed to save machines: " . $e->getMessage());
    }
}

// If we reach here, it's a GET request, so output the HTML form
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Get client classification for JavaScript
$classification = $contract_data['classification'];
$company_name = htmlspecialchars($contract_data['company_name']);
$contract_number = htmlspecialchars($contract_data['contract_number']);

// Determine max machines based on contract type
$max_machines = ($contract_type == 'SINGLE CONTRACT') ? 1 : 100;
$default_machines = ($contract_type == 'SINGLE CONTRACT') ? 1 : 2;

// Encode cities for JavaScript
$cities_json = json_encode($cities);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Contract Machines</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h2 { color: #2c3e50; margin-bottom: 10px; }
        h3 { color: #34495e; margin: 20px 0 10px; border-bottom: 1px solid #bdc3c7; padding-bottom: 5px; }
        h4 { color: #2c3e50; margin: 15px 0 10px; font-size: 16px; }
        .contract-info { background: #e3f2fd; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #2196F3; }
        .machine-entry { 
            background: #f8f9fa; padding: 20px; margin-bottom: 20px; border-radius: 8px;
            border-left: 4px solid #2196F3; border: 1px solid #ddd;
        }
        .form-row { display: flex; gap: 15px; margin-bottom: 15px; flex-wrap: wrap; }
        .form-group { 
            flex: 1 1 calc(50% - 15px); 
            min-width: 200px; 
            position: relative;
        }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #34495e; font-size: 13px; }
        .required:after { content: " *"; color: #e74c3c; }
        input[type="text"], input[type="number"], select, textarea {
            width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;
        }
        input:focus, select:focus, textarea:focus { 
            border-color: #2196F3; outline: none; box-shadow: 0 0 3px rgba(33,150,243,0.3);
        }
        input[readonly] { background: #f5f5f5; color: #666; }
        select:disabled {
            background: #f5f5f5;
            cursor: not-allowed;
        }
        .btn-add, .btn-submit { 
            background: #27ae60; color: white; padding: 10px 20px; border: none; 
            border-radius: 4px; cursor: pointer; font-size: 14px; transition: background 0.3s;
        }
        .btn-add:hover { background: #229954; }
        .btn-submit { background: #2196F3; padding: 12px 30px; font-size: 16px; margin-top: 20px; }
        .btn-submit:hover { background: #1976D2; }
        .btn-remove { 
            background: #e74c3c; color: white; border: none; padding: 5px 10px; 
            border-radius: 3px; cursor: pointer; float: right;
        }
        .btn-remove:hover { background: #c0392b; }
        .info-text { font-size: 12px; color: #7f8c8d; margin-top: 3px; }
        .zone-display { 
            background: #e8f5e9; padding: 12px; border-radius: 4px; margin: 10px 0;
            border-left: 4px solid #4CAF50; font-size: 13px;
            position: relative;
        }
        .zone-display.misaligned {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
        }
        .schedule-display {
            background: #e3f2fd; padding: 12px; border-radius: 4px; margin: 10px 0;
            border-left: 4px solid #2196F3; font-size: 13px;
        }
        .aligned-badge {
            display: inline-block; padding: 3px 8px; border-radius: 3px;
            font-size: 11px; font-weight: bold; margin-left: 10px;
        }
        .aligned-badge.aligned { background: #d4edda; color: #155724; }
        .aligned-badge.misaligned { background: #fff3cd; color: #856404; }
        .hidden { display: none; }
        .loading-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #2196F3;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 5px;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .message { 
            padding: 15px; margin: 20px 0; border-radius: 5px;
            display: none; position: fixed; top: 20px; right: 20px;
            z-index: 1000; box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .source-badge {
            display: inline-block;
            padding: 2px 6px;
            background: #e9ecef;
            border-radius: 3px;
            font-size: 10px;
            color: #495057;
            margin-left: 8px;
        }
        
        /* DR/POS Upload Styles */
        .drpos-upload-area {
            border: 2px dashed #e67e22;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            background: #fff8e1;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        
        .drpos-upload-area:hover {
            border-color: #d35400;
            background: #ffecb3;
        }
        
        .drpos-upload-area.dragover {
            border-color: #27ae60;
            background: #d4edda;
        }
        
        .drpos-icon {
            font-size: 24px;
            color: #e67e22;
            margin-bottom: 5px;
        }
        
        .drpos-file-list {
            margin-top: 10px;
            max-height: 150px;
            overflow-y: auto;
        }
        
        .drpos-file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 10px;
            background: white;
            border-radius: 4px;
            margin-bottom: 5px;
            border-left: 3px solid #e67e22;
            font-size: 12px;
        }
        
        .drpos-file-name {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .drpos-remove-file {
            color: #e74c3c;
            cursor: pointer;
            font-weight: bold;
            padding: 0 5px;
        }
        
        .drpos-remove-file:hover {
            color: #c0392b;
        }
        
        .file-count-badge {
            display: inline-block;
            background: #e67e22;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 8px;
        }
        
        /* Autocomplete Styles */
        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            max-height: 200px;
            overflow-y: auto;
            background: white;
            border: 2px solid #2196F3;
            border-top: none;
            border-radius: 0 0 8px 8px;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .autocomplete-item {
            padding: 8px 12px;
            cursor: pointer;
            transition: background 0.2s;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .autocomplete-item:hover {
            background: #e3f2fd;
        }
        
        .autocomplete-item:last-child {
            border-bottom: none;
        }
        
        .autocomplete-item.active {
            background: #bbdefb;
        }
        
        .no-suggestions {
            padding: 10px;
            color: #7f8c8d;
            text-align: center;
            font-style: italic;
        }
        
        /* Barangay error */
        .barangay-error {
            color: #e74c3c;
            font-size: 12px;
            margin-top: 5px;
            padding: 5px;
            background: #fdeaea;
            border-radius: 4px;
            display: none;
        }
        
        .validation-success {
            color: #27ae60;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }
        
        .hint-text {
            font-size: 11px;
            color: #7f8c8d;
            margin-top: 3px;
            display: flex;
            align-items: center;
            gap: 3px;
        }
        
        /* Checkmark Animation */
        .checkmark {
            display: inline-block;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #27ae60;
            transform: scale(0);
            animation: checkmark-pop 0.3s ease forwards;
            position: relative;
            margin-right: 5px;
            flex-shrink: 0;
        }

        .checkmark::after {
            content: '';
            position: absolute;
            top: 4px;
            left: 7px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        @keyframes checkmark-pop {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        /* Success badge for zone info */
        .success-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #d4edda;
            color: #155724;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 5px;
        }

        /* Zone info container */
        .zone-info-content {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .zone-details {
            display: flex;
            align-items: center;
            gap: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Add Machine Details</h2>
        
        <div class="contract-info">
            <strong>Contract #:</strong> <?php echo $contract_number; ?><br>
            <strong>Client:</strong> <?php echo $company_name; ?><br>
            <strong>Classification:</strong> 
            <span style="color: <?php echo $classification === 'GOVERNMENT' ? '#2e7d32' : '#1565c0'; ?>; font-weight: bold;">
                <?php echo $classification; ?>
            </span><br>
            <strong>Contract Type:</strong> <?php echo $contract_type; ?><br>
            <?php if ($contract_type == 'SINGLE CONTRACT'): ?>
                <span style="color: #e67e22;">Note: Single contract - you can only add one machine</span>
            <?php else: ?>
                <span style="color: #27ae60;">Note: Umbrella contract - you can add multiple machines</span>
            <?php endif; ?>
        </div>
        
        <form id="machinesForm" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="contract_id" value="<?php echo $contract_id; ?>">
            <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
            <input type="hidden" id="classification" value="<?php echo $classification; ?>">
            
            <?php if ($contract_type == 'UMBRELLA'): ?>
            <div class="form-group">
                <label for="machine_count">Number of Machines <span class="required">*</span></label>
                <input type="number" id="machine_count" name="machine_count" 
                       min="2" max="<?php echo $max_machines; ?>" value="<?php echo $default_machines; ?>"
                       onchange="generateMachineFields()">
                <div class="info-text">Enter number of machines to add (2 to <?php echo $max_machines; ?>)</div>
            </div>
            <?php endif; ?>
            
            <div id="machinesContainer">
                <!-- Machine entries will be added here -->
            </div>
            
            <?php if ($contract_type == 'UMBRELLA'): ?>
                <button type="button" class="btn-add" onclick="addMachineEntry()">+ Add Another Machine</button>
            <?php endif; ?>
            
            <div style="text-align: center;">
                <button type="submit" id="submitBtn" class="btn-submit">Save All Machines</button>
            </div>
        </form>
    </div>
    
    <div id="message" class="message"></div>
    
    <script>
        let machineCount = 0;
        const maxMachines = <?php echo $max_machines; ?>;
        const contractType = '<?php echo $contract_type; ?>';
        const classification = '<?php echo $classification; ?>';
        const clientId = <?php echo $client_id; ?>;
        const cities = <?php echo $cities_json; ?>;
        
        // Store validation status and autocomplete timeouts for each machine
        const zoneValidationStatus = {};
        const searchTimeouts = {};
        
        // Initialize form based on contract type
        document.addEventListener('DOMContentLoaded', function() {
            if (contractType === 'SINGLE CONTRACT') {
                addMachineEntry();
            } else {
                generateMachineFields();
            }
            
            document.getElementById('machinesForm').addEventListener('submit', handleFormSubmit);
            
            // Close suggestions when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.barangay-autocomplete')) {
                    for (let i = 0; i < machineCount; i++) {
                        const suggestions = document.getElementById(`barangay_suggestions_${i}`);
                        if (suggestions) {
                            suggestions.style.display = 'none';
                        }
                    }
                }
            });
        });
        
        function generateMachineFields() {
            const count = parseInt(document.getElementById('machine_count')?.value || 2);
            if (count < 2 && contractType !== 'SINGLE CONTRACT') {
                showMessage('Please enter at least 2 machines for umbrella contract', 'error');
                return;
            }
            
            if (count > maxMachines) {
                showMessage(`Maximum ${maxMachines} machines allowed`, 'error');
                return;
            }
            
            const container = document.getElementById('machinesContainer');
            container.innerHTML = '';
            machineCount = 0;
            
            for (let i = 0; i < count; i++) {
                addMachineEntry();
            }
        }
        
        function addMachineEntry() {
            if (machineCount >= maxMachines) {
                alert(maxMachines == 1 ? 'Single contract can only have one machine.' : 'Maximum machines reached.');
                return;
            }
            
            const container = document.getElementById('machinesContainer');
            const entry = document.createElement('div');
            entry.className = 'machine-entry';
            entry.id = `machine_${machineCount}`;
            
            const currentIndex = machineCount;
            
            // Initialize validation status
            zoneValidationStatus[currentIndex] = false;
            
            // Build city options
            let cityOptions = '<option value="">-- Select a city --</option>';
            cities.forEach(city => {
                cityOptions += `<option value="${city}">${city}</option>`;
            });
            
            entry.innerHTML = `
                <h3 style="display: inline-block;">Machine #${currentIndex + 1}</h3>
                ${currentIndex > 0 ? `<button type="button" class="btn-remove" onclick="removeMachineEntry(${currentIndex})">Remove</button>` : ''}
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Machine Type</label>
                        <select name="machine_type[${currentIndex}]" id="machine_type_${currentIndex}" onchange="toggleColorFields(${currentIndex})" required>
                            <option value="">Select Type</option>
                            <option value="MONOCHROME">MONOCHROME</option>
                            <option value="COLOR">COLOR</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="required">Machine Model</label>
                        <input type="text" name="machine_model[${currentIndex}]" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Machine Brand</label>
                        <input type="text" name="machine_brand[${currentIndex}]" required>
                    </div>
                    <div class="form-group">
                        <label class="required">Serial Number</label>
                        <input type="text" name="machine_serial_number[${currentIndex}]" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Machine Number</label>
                        <input type="text" name="machine_number[${currentIndex}]" required>
                    </div>
                    <div class="form-group">
                        <label>Department/Office</label>
                        <input type="text" name="department[${currentIndex}]" placeholder="e.g., Finance Dept, HR Office, etc.">
                        <div class="info-text">Specify the department or office where this machine is installed</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Mono Meter Start</label>
                        <input type="number" name="mono_meter_start[${currentIndex}]" required>
                    </div>
                    <div id="color_fields_${currentIndex}" class="hidden" style="flex: 1;">
                        <label class="required">Color Meter Start</label>
                        <input type="number" name="color_meter_start[${currentIndex}]">
                    </div>
                </div>
                
                <h4 style="margin-top: 20px; margin-bottom: 15px;">Installation Address</h4>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Building/Unit Number</label>
                        <input type="text" name="building_number[${currentIndex}]" required>
                    </div>
                    <div class="form-group">
                        <label class="required">Street Name</label>
                        <input type="text" name="street_name[${currentIndex}]" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">City / Municipality</label>
                        <select name="city[${currentIndex}]" id="city_${currentIndex}" 
                                onchange="onCityChange(${currentIndex})" required>
                            ${cityOptions}
                        </select>
                    </div>
                    <div class="form-group barangay-autocomplete" style="position: relative;">
                        <label class="required">Barangay</label>
                        <input type="text" name="barangay[${currentIndex}]" id="barangay_${currentIndex}" 
                               placeholder="Start typing to search barangay..." 
                               autocomplete="off"
                               disabled
                               oninput="onBarangayInput(${currentIndex})"
                               onkeydown="handleBarangayKeydown(${currentIndex}, event)"
                               required>
                        <div id="barangay_suggestions_${currentIndex}" class="autocomplete-suggestions"></div>
                        <div id="barangay_error_${currentIndex}" class="barangay-error"></div>
                        <div class="hint-text">
                            <span>🔍</span> Type at least 2 characters to see suggestions
                        </div>
                    </div>
                </div>
                
                <div id="zone_display_${currentIndex}" class="zone-display hidden">
                    <div id="zone_loading_${currentIndex}" class="loading-spinner"></div>
                    <div id="zone_info_${currentIndex}" style="display: inline-block;"></div>
                    <span id="source_badge_${currentIndex}" class="source-badge hidden"></span>
                </div>
                
                <div id="schedule_display_${currentIndex}" class="schedule-display hidden">
                    <span id="schedule_info_${currentIndex}"></span>
                </div>
                
                <div id="validation_status_${currentIndex}" class="validation-success"></div>
                
                <input type="hidden" name="zone_id[${currentIndex}]" id="zone_id_${currentIndex}">
                <input type="hidden" name="zone_number[${currentIndex}]" id="zone_number_${currentIndex}">
                <input type="hidden" name="area_center[${currentIndex}]" id="area_center_${currentIndex}">
                <input type="hidden" id="recommended_reading_date_${currentIndex}" value="">
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Reading Date (1-31)</label>
                        <input type="number" name="reading_date[${currentIndex}]" id="reading_date_${currentIndex}" 
                               min="1" max="31" onchange="checkReadingDateAlignment(${currentIndex})" required>
                        <div class="info-text">Recommended: <span id="recommended_date_display_${currentIndex}"></span></div>
                    </div>
                    <div class="form-group">
                        <label>Comments</label>
                        <textarea name="comments[${currentIndex}]" rows="2" placeholder="Enter any comments or notes about this machine..."></textarea>
                    </div>
                </div>
                
                <!-- DR/POS Receipt Upload Section -->
                <div style="margin-top: 20px; padding: 15px; background: #fff8e1; border-radius: 8px; border-left: 4px solid #e67e22;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <span style="font-size: 20px;">🧾</span>
                        <span style="font-weight: bold; color: #e67e22;">DR/POS Receipts (Optional)</span>
                        <span id="file_count_${currentIndex}" class="file-count-badge" style="display: none;">0 files</span>
                    </div>
                    
                    <div id="drpos_upload_${currentIndex}" class="drpos-upload-area" onclick="document.getElementById('drpos_input_${currentIndex}').click()">
                        <div class="drpos-icon">📄</div>
                        <div style="font-weight: 600; color: #e67e22;">Click or drag PDF files here</div>
                        <div style="font-size: 11px; color: #7f8c8d; margin-top: 5px;">
                            Supported format: PDF only (Max 10MB per file)
                        </div>
                        <input type="file" name="dr_pos_files[${currentIndex}][]" id="drpos_input_${currentIndex}" 
                               accept=".pdf,application/pdf" multiple style="display: none;">
                    </div>
                    
                    <div id="drpos_file_list_${currentIndex}" class="drpos-file-list"></div>
                    <div class="info-text" style="margin-top: 10px;">
                        Upload DR (Delivery Receipt) or POS (Point of Sale) receipts related to this machine.
                    </div>
                </div>
                
                <div id="alignment_status_${currentIndex}" style="margin-top: 10px;"></div>
            `;
            
            container.appendChild(entry);
            
            // Add drag and drop listeners for DR/POS
            const uploadArea = document.getElementById(`drpos_upload_${currentIndex}`);
            const fileInput = document.getElementById(`drpos_input_${currentIndex}`);
            
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('dragover');
            });
            
            uploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });
            
            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('dragover');
                const files = e.dataTransfer.files;
                fileInput.files = files;
                handleDRPOSFileSelect(currentIndex, files);
            });
            
            fileInput.addEventListener('change', function(e) {
                handleDRPOSFileSelect(currentIndex, this.files);
            });
            
            machineCount++;
        }
        
        function onCityChange(index) {
            const citySelect = document.getElementById(`city_${index}`);
            const barangayInput = document.getElementById(`barangay_${index}`);
            const city = citySelect.value;
            
            if (city) {
                barangayInput.disabled = false;
                barangayInput.value = '';
                barangayInput.focus();
                document.getElementById(`barangay_suggestions_${index}`).style.display = 'none';
                
                // Reset validation
                zoneValidationStatus[index] = false;
                document.getElementById(`validation_status_${index}`).style.display = 'none';
                
                // Hide zone display
                const zoneDisplay = document.getElementById(`zone_display_${index}`);
                zoneDisplay.classList.add('hidden');
                
                // Clear zone info
                document.getElementById(`zone_info_${index}`).innerHTML = '';
                document.getElementById(`zone_loading_${index}`).style.display = 'none';
            } else {
                barangayInput.disabled = true;
                barangayInput.value = '';
            }
        }
        
        function onBarangayInput(index) {
            const searchTerm = document.getElementById(`barangay_${index}`).value.trim();
            const city = document.getElementById(`city_${index}`).value;
            
            if (!city) {
                alert('Please select a city first');
                document.getElementById(`barangay_${index}`).value = '';
                return;
            }
            
            clearTimeout(searchTimeouts[index]);
            
            if (searchTerm.length >= 2) {
                searchTimeouts[index] = setTimeout(() => {
                    fetchBarangaySuggestions(searchTerm, city, index);
                }, 300);
            } else {
                document.getElementById(`barangay_suggestions_${index}`).style.display = 'none';
            }
        }
        
        function fetchBarangaySuggestions(search, city, index) {
            console.log('Fetching suggestions for:', search, 'in', city);
            
            fetch(`r-add_contract_machines.php?get_barangays=1&city=${encodeURIComponent(city)}&search=${encodeURIComponent(search)}`)
                .then(response => {
                    console.log('Response status:', response.status);
                    return response.json();
                })
                .then(data => {
                    console.log('Suggestions data:', data);
                    
                    const suggestionsDiv = document.getElementById(`barangay_suggestions_${index}`);
                    
                    if (data.success && data.barangays.length > 0) {
                        let html = '';
                        data.barangays.forEach(barangay => {
                            html += `<div class="autocomplete-item" onclick="selectBarangay('${barangay.replace(/'/g, "\\'")}', ${index})">${barangay}</div>`;
                        });
                        suggestionsDiv.innerHTML = html;
                        suggestionsDiv.style.display = 'block';
                    } else {
                        suggestionsDiv.innerHTML = '<div class="no-suggestions">No matching barangay found</div>';
                        suggestionsDiv.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error fetching suggestions:', error);
                });
        }
        
        function selectBarangay(barangay, index) {
            document.getElementById(`barangay_${index}`).value = barangay;
            document.getElementById(`barangay_suggestions_${index}`).style.display = 'none';
            
            // Trigger zone check after selecting
            setTimeout(() => {
                getZoneInfo(index);
            }, 100);
        }
        
        function handleBarangayKeydown(index, e) {
            const suggestions = document.getElementById(`barangay_suggestions_${index}`);
            const items = suggestions.querySelectorAll('.autocomplete-item');
            
            if (items.length === 0) return;
            
            const active = suggestions.querySelector('.autocomplete-item.active');
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!active) {
                    items[0].classList.add('active');
                } else {
                    const next = active.nextElementSibling;
                    if (next && next.classList.contains('autocomplete-item')) {
                        active.classList.remove('active');
                        next.classList.add('active');
                    }
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!active) {
                    items[items.length - 1].classList.add('active');
                } else {
                    const prev = active.previousElementSibling;
                    if (prev && prev.classList.contains('autocomplete-item')) {
                        active.classList.remove('active');
                        prev.classList.add('active');
                    }
                }
            } else if (e.key === 'Enter' && active) {
                e.preventDefault();
                selectBarangay(active.textContent, index);
            } else if (e.key === 'Escape') {
                suggestions.style.display = 'none';
            }
        }
        
        function handleDRPOSFileSelect(index, files) {
            if (files.length > 0) {
                const fileList = document.getElementById(`drpos_file_list_${index}`);
                const fileCountBadge = document.getElementById(`file_count_${index}`);
                
                let html = '';
                let validFileCount = 0;
                
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    
                    // Validate file type
                    if (file.type !== 'application/pdf') {
                        alert(`File "${file.name}" is not a PDF. Only PDF files are allowed.`);
                        continue;
                    }
                    
                    // Validate file size (10MB)
                    if (file.size > 10 * 1024 * 1024) {
                        alert(`File "${file.name}" exceeds the 10MB size limit.`);
                        continue;
                    }
                    
                    validFileCount++;
                    
                    const fileSize = (file.size / 1024).toFixed(1) + ' KB';
                    html += `
                        <div class="drpos-file-item" id="drpos_file_${index}_${i}">
                            <div class="drpos-file-name">
                                <span style="color: #e67e22;">📄</span>
                                <span style="font-weight: 500;">${file.name}</span>
                                <span style="font-size: 10px; color: #7f8c8d;">${fileSize}</span>
                            </div>
                            <span class="drpos-remove-file" onclick="removeDRPOSFile(${index}, ${i})">✕</span>
                        </div>
                    `;
                }
                
                fileList.innerHTML = html;
                
                if (validFileCount > 0) {
                    fileCountBadge.style.display = 'inline-block';
                    fileCountBadge.textContent = `${validFileCount} file(s)`;
                } else {
                    fileCountBadge.style.display = 'none';
                }
            }
        }
        
        function removeDRPOSFile(index, fileIndex) {
            // Remove from UI
            const fileItem = document.getElementById(`drpos_file_${index}_${fileIndex}`);
            if (fileItem) {
                fileItem.remove();
            }
            
            const fileList = document.getElementById(`drpos_file_list_${index}`);
            const remainingFiles = fileList.children.length;
            const fileCountBadge = document.getElementById(`file_count_${index}`);
            
            // Update file count badge
            if (remainingFiles > 0) {
                fileCountBadge.textContent = `${remainingFiles} file(s)`;
            } else {
                fileCountBadge.style.display = 'none';
            }
        }
        
        function removeMachineEntry(index) {
            const entry = document.getElementById(`machine_${index}`);
            if (entry) {
                entry.remove();
                renumberMachines();
            }
        }
        
        function renumberMachines() {
            const entries = document.querySelectorAll('.machine-entry');
            machineCount = entries.length;
            
            entries.forEach((entry, index) => {
                entry.id = `machine_${index}`;
                const header = entry.querySelector('h3');
                if (header) header.innerHTML = `Machine #${index + 1}`;
                
                const removeBtn = entry.querySelector('.btn-remove');
                if (removeBtn) {
                    removeBtn.style.display = index === 0 ? 'none' : 'inline-block';
                    removeBtn.setAttribute('onclick', `removeMachineEntry(${index})`);
                }
                
                // Update all name attributes
                const inputs = entry.querySelectorAll('[name*="["]');
                inputs.forEach(input => {
                    const name = input.getAttribute('name');
                    const newName = name.replace(/\[\d+\]/, `[${index}]`);
                    input.setAttribute('name', newName);
                });
                
                // Update IDs
                entry.querySelectorAll('[id]').forEach(el => {
                    const id = el.getAttribute('id');
                    const newId = id.replace(/_\d+$/, `_${index}`);
                    el.setAttribute('id', newId);
                });
            });
        }
        
        function toggleColorFields(index) {
            const select = document.getElementById(`machine_type_${index}`);
            const colorFields = document.getElementById(`color_fields_${index}`);
            
            if (select && colorFields) {
                colorFields.style.display = select.value === 'COLOR' ? 'block' : 'none';
                const colorInput = colorFields.querySelector('input');
                if (colorInput) colorInput.required = select.value === 'COLOR';
            }
        }
        
        function getZoneInfo(index) {
            const barangay = document.getElementById(`barangay_${index}`).value.trim();
            const city = document.getElementById(`city_${index}`).value;
            const barangayError = document.getElementById(`barangay_error_${index}`);
            const validationStatus = document.getElementById(`validation_status_${index}`);
            
            if (!city || !barangay) return;
            
            const zoneDisplay = document.getElementById(`zone_display_${index}`);
            const zoneInfo = document.getElementById(`zone_info_${index}`);
            const zoneLoading = document.getElementById(`zone_loading_${index}`);
            const sourceBadge = document.getElementById(`source_badge_${index}`);
            
            // Show the zone display container
            zoneDisplay.classList.remove('hidden');
            
            // Show loading spinner and clear previous content
            zoneLoading.style.display = 'inline-block';
            zoneLoading.innerHTML = ''; // Clear any existing spinner HTML
            zoneInfo.innerHTML = 'Checking barangay in database...';
            sourceBadge.classList.add('hidden');
            barangayError.style.display = 'none';
            validationStatus.style.display = 'none';
            
            const formData = new FormData();
            formData.append('barangay', barangay);
            formData.append('city', city);
            formData.append('client_id', clientId);
            formData.append('check_zone', '1');
            
            fetch('r-get_zone_from_location.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Hide loading spinner
                zoneLoading.style.display = 'none';
                
                if (data.success) {
                    // Zone found successfully
                    document.getElementById(`zone_id_${index}`).value = data.zone_id;
                    document.getElementById(`zone_number_${index}`).value = data.zone_number;
                    document.getElementById(`area_center_${index}`).value = data.area_center;
                    
                    const recommendedDate = parseInt(data.zone_number) + 2;
                    document.getElementById(`recommended_reading_date_${index}`).value = recommendedDate;
                    document.getElementById(`recommended_date_display_${index}`).innerHTML = 
                        `<strong>Day ${recommendedDate}</strong> (Zone ${data.zone_number})`;
                    
                    const readingDateField = document.getElementById(`reading_date_${index}`);
                    if (!readingDateField.value) {
                        readingDateField.value = recommendedDate;
                    }
                    
                    // Source is always FROM rental_zone_coordinates now
                    let sourceText = '📍 From database';

                    // Show zone info with checkmark
                    zoneInfo.innerHTML = `
                        <div class="zone-info-content">
                            <div class="zone-details">
                                <span class="checkmark"></span>
                                <div>
                                    <strong>Zone ${data.zone_number}</strong> - ${data.area_center}<br>
                                    <small>Distance: ${data.distance_km} km</small>
                                </div>
                            </div>
                            <div class="success-badge">
                                <span>✓ Validated</span>
                            </div>
                        </div>
                    `;

                    sourceBadge.innerHTML = sourceText;
                    sourceBadge.classList.remove('hidden');
                    
                    validationStatus.innerHTML = '✅ Barangay validated';
                    validationStatus.style.display = 'block';
                    validationStatus.style.color = '#27ae60';
                    
                    zoneValidationStatus[index] = true;
                    
                    if (classification === 'PRIVATE') {
                        showPrivateSchedule(index, data.zone_number);
                    }
                    
                    checkReadingDateAlignment(index);
                } else {
                    // Barangay not found
                    zoneInfo.innerHTML = 'Barangay not found in database';
                    zoneDisplay.classList.add('misaligned');
                    
                    barangayError.innerHTML = '❌ This barangay is not in the database. Please contact administrator.';
                    barangayError.style.display = 'block';
                    
                    validationStatus.innerHTML = '❌ Barangay not validated';
                    validationStatus.style.display = 'block';
                    validationStatus.style.color = '#e74c3c';
                    
                    zoneValidationStatus[index] = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                zoneLoading.style.display = 'none';
                zoneInfo.innerHTML = 'Error checking barangay. Please try again.';
                zoneDisplay.classList.add('misaligned');
                
                zoneValidationStatus[index] = false;
            });
        }
        
        function showPrivateSchedule(index, zoneNumber) {
            const readingDate = parseInt(zoneNumber) + 2;
            const scheduleDisplay = document.getElementById(`schedule_display_${index}`);
            const scheduleInfo = document.getElementById(`schedule_info_${index}`);
            
            scheduleInfo.innerHTML = `
                <strong>📅 Auto-generated Schedule (Private Client)</strong><br>
                Reading Date: <strong>Day ${readingDate}</strong> (Fixed for Zone ${zoneNumber})<br>
                <small class="info-text">You can still edit the reading date if needed</small>
            `;
            
            scheduleDisplay.classList.remove('hidden');
        }
        
        function checkReadingDateAlignment(index) {
            const readingDateField = document.getElementById(`reading_date_${index}`);
            const recommendedDate = document.getElementById(`recommended_reading_date_${index}`).value;
            const alignmentStatus = document.getElementById(`alignment_status_${index}`);
            const zoneDisplay = document.getElementById(`zone_display_${index}`);
            
            if (readingDateField && recommendedDate) {
                const userDate = parseInt(readingDateField.value);
                const recDate = parseInt(recommendedDate);
                
                if (!isNaN(userDate) && !isNaN(recDate)) {
                    if (userDate === recDate) {
                        alignmentStatus.innerHTML = `<span class="aligned-badge aligned">✓ ALIGNED READING DATE</span>`;
                        zoneDisplay.classList.remove('misaligned');
                    } else {
                        alignmentStatus.innerHTML = `<span class="aligned-badge misaligned">⚠️ MIS-ALIGNED READING DATE</span>`;
                        zoneDisplay.classList.add('misaligned');
                    }
                }
            }
        }
        
        function handleFormSubmit(e) {
            e.preventDefault();
            
            // Check if all machines have valid barangays
            let allValid = true;
            for (let i = 0; i < machineCount; i++) {
                if (!zoneValidationStatus[i]) {
                    allValid = false;
                    showMessage(`Machine #${i + 1} has invalid barangay. Please check.`, 'error');
                    break;
                }
            }
            
            if (!allValid) {
                return;
            }
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="loading-spinner"></span> Saving...';
            
            const formData = new FormData(e.target);
            formData.append('submit_machines', '1');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        console.error('Non-JSON response:', text.substring(0, 500));
                        throw new Error('Server returned non-JSON response');
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showMessage(`✅ Success! ${data.success_count || 0} machine(s) added successfully.`, 'success');
                    setTimeout(() => {
                        window.location.href = data.redirect || 'r-view_contracts.php';
                    }, 2000);
                } else {
                    showMessage(`❌ Error: ${data.error || 'Failed to add machines'}`, 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Save All Machines';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage(`❌ Error: ${error.message}`, 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Save All Machines';
            });
        }
        
        function showMessage(text, type) {
            const msgDiv = document.getElementById('message');
            msgDiv.className = 'message ' + type;
            msgDiv.innerHTML = text;
            msgDiv.style.display = 'block';
            
            setTimeout(() => {
                msgDiv.style.display = 'none';
            }, 5000);
        }
    </script>
</body>
</html>