<?php
require_once 'config.php';
session_start();

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

// NOW validate machine ID
$machine_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$machine_id) {
    die("Machine ID is required.");
}

// Get machine data with contract and client info
$machine_query = $conn->query("
    SELECT cm.*, c.contract_number, c.type_of_contract, c.has_colored_machines,
           cl.classification, cl.company_name
    FROM rental_contract_machines cm
    JOIN rental_contracts c ON cm.contract_id = c.id
    JOIN rental_clients cl ON cm.client_id = cl.id
    WHERE cm.id = $machine_id
");
$machine = $machine_query->fetch_assoc();

if (!$machine) {
    die("Machine not found.");
}

// Get all zones for reference
$zones_query = $conn->query("SELECT * FROM rental_zoning_zone ORDER BY zone_number");
$zones = [];
while ($zone = $zones_query->fetch_assoc()) {
    $zones[] = $zone;
}

// Get all distinct cities FROM rental_zone_coordinates for dropdown
$cities_query = $conn->query("SELECT DISTINCT city FROM rental_zone_coordinates WHERE city IS NOT NULL AND city != '' ORDER BY city");
$cities = [];
while ($city_row = $cities_query->fetch_assoc()) {
    $cities[] = $city_row['city'];
}

// Handle AJAX request for barangay suggestions
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Update machine details
    $machine_type = $_POST['machine_type'];
    $machine_model = $conn->real_escape_string($_POST['machine_model']);
    $machine_brand = $conn->real_escape_string($_POST['machine_brand']);
    $machine_serial_number = $conn->real_escape_string($_POST['machine_serial_number']);
    $machine_number = $conn->real_escape_string($_POST['machine_number']);
    $department = $conn->real_escape_string($_POST['department']);
    $mono_meter_start = intval($_POST['mono_meter_start']);
    $color_meter_start = ($machine_type == 'COLOR' && !empty($_POST['color_meter_start'])) ? intval($_POST['color_meter_start']) : 'NULL';
    
    // Address fields
    $building_number = $conn->real_escape_string($_POST['building_number']);
    $street_name = $conn->real_escape_string($_POST['street_name']);
    $barangay = $conn->real_escape_string($_POST['barangay']);
    $city = $conn->real_escape_string($_POST['city']);
    
    // Zone fields
    $zone_id = intval($_POST['zone_id']);
    $zone_number = intval($_POST['zone_number']);
    $area_center = $conn->real_escape_string($_POST['area_center']);
    $reading_date = intval($_POST['reading_date']);
    
    // Get recommended reading date
    $recommended_reading_date = $zone_number + 2;
    $reading_date_remarks = ($reading_date == $recommended_reading_date) ? 'aligned reading date' : 'mis-aligned reading date';
    
    // Comments and status
    $comments = $conn->real_escape_string($_POST['comments']);
    $status = $_POST['status'];
    
    $update_sql = "UPDATE rental_contract_machines SET 
                    machine_type = '$machine_type',
                    machine_model = '$machine_model',
                    machine_brand = '$machine_brand',
                    machine_serial_number = '$machine_serial_number',
                    machine_number = '$machine_number',
                    department = '$department',
                    mono_meter_start = $mono_meter_start,
                    color_meter_start = $color_meter_start,
                    building_number = '$building_number',
                    street_name = '$street_name',
                    barangay = '$barangay',
                    city = '$city',
                    zone_id = $zone_id,
                    zone_number = $zone_number,
                    area_center = '$area_center',
                    reading_date = $reading_date,
                    reading_date_remarks = '$reading_date_remarks',
                    comments = '$comments',
                    status = '$status',
                    updated_at = NOW()
                    WHERE id = $machine_id";
    
    if ($conn->query($update_sql)) {
        $success = "Machine updated successfully!";
        
        // Refresh machine data
        $machine_query = $conn->query("
            SELECT cm.*, c.contract_number, c.type_of_contract, c.has_colored_machines,
                   cl.classification, cl.company_name
            FROM rental_contract_machines cm
            JOIN rental_contracts c ON cm.contract_id = c.id
            JOIN rental_clients cl ON cm.client_id = cl.id
            WHERE cm.id = $machine_id
        ");
        $machine = $machine_query->fetch_assoc();
        
        // Update contract collection date if client is PRIVATE
        if ($machine['classification'] == 'PRIVATE') {
            // Get highest reading date from all machines in this contract
            $highest_date_query = $conn->query("
                SELECT MAX(reading_date) as max_date 
                FROM rental_contract_machines 
                WHERE contract_id = {$machine['contract_id']} AND status = 'ACTIVE'
            ");
            $highest = $highest_date_query->fetch_assoc();
            $highest_reading_date = $highest['max_date'];
            
            // Get contract processing period
            $contract_query = $conn->query("
                SELECT collection_processing_period 
                FROM rental_contracts 
                WHERE id = {$machine['contract_id']}
            ");
            $contract_data = $contract_query->fetch_assoc();
            $processing_period = $contract_data['collection_processing_period'];
            
            // Calculate new collection date
            $collection_date = $highest_reading_date + $processing_period;
            if ($collection_date > 31) {
                $collection_date -= 31;
            }
            
            // Update contract
            $conn->query("
                UPDATE rental_contracts 
                SET collection_date = $collection_date 
                WHERE id = {$machine['contract_id']}
            ");
        }
    } else {
        $error = "Error updating machine: " . $conn->error;
    }
}

// Function to get zone reading date
function getZoneReadingDate($zone_number) {
    return $zone_number + 2;
}

$recommended_date = getZoneReadingDate($machine['zone_number']);

// Encode cities for JavaScript
$cities_json = json_encode($cities);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Machine - <?php echo htmlspecialchars($machine['machine_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f4f6f9; 
            padding: 20px; 
        }
        .container { 
            max-width: 900px; 
            margin: 0 auto; 
            background: white; 
            padding: 30px; 
            border-radius: 10px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
        }
        h1 { 
            color: #2c3e50; 
            margin-bottom: 10px; 
        }
        h2 { 
            color: #34495e; 
            font-size: 18px; 
            margin: 25px 0 15px; 
            border-bottom: 1px solid #bdc3c7; 
            padding-bottom: 8px; 
        }
        .contract-header {
            background: #e3f2fd; 
            padding: 15px; 
            border-radius: 8px; 
            margin-bottom: 30px;
            border-left: 4px solid #3498db;
        }
        .form-row { 
            display: flex; 
            gap: 20px; 
            margin-bottom: 20px; 
            flex-wrap: wrap; 
        }
        .form-group { 
            flex: 1 1 calc(50% - 20px); 
            min-width: 250px;
            position: relative;
        }
        label { 
            display: block; 
            margin-bottom: 5px; 
            font-weight: bold; 
            color: #34495e; 
        }
        input[type="text"], input[type="number"], select, textarea {
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            font-size: 14px;
        }
        input:focus, select:focus, textarea:focus { 
            border-color: #3498db; 
            outline: none; 
            box-shadow: 0 0 5px rgba(52,152,219,0.3);
        }
        .btn {
            background: #3498db; 
            color: white; 
            padding: 12px 30px; 
            border: none;
            border-radius: 5px; 
            font-size: 16px; 
            cursor: pointer; 
            transition: background 0.3s;
        }
        .btn:hover { 
            background: #2980b9; 
        }
        .btn-secondary {
            background: #95a5a6; 
            margin-left: 10px;
        }
        .btn-secondary:hover { 
            background: #7f8c8d; 
        }
        .btn-success {
            background: #27ae60;
        }
        .btn-success:hover {
            background: #229954;
        }
        .success {
            background: #d4edda; 
            color: #155724; 
            padding: 15px; 
            border-radius: 5px;
            margin-bottom: 20px; 
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da; 
            color: #721c24; 
            padding: 15px; 
            border-radius: 5px;
            margin-bottom: 20px; 
            border: 1px solid #f5c6cb;
        }
        .info-text { 
            font-size: 12px; 
            color: #7f8c8d; 
            margin-top: 5px; 
        }
        .zone-info {
            background: #e8f5e9; 
            padding: 15px; 
            border-radius: 8px; 
            margin: 20px 0;
            border-left: 4px solid #4CAF50;
        }
        .zone-info.misaligned {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
        }
        .recommended-badge {
            display: inline-block; 
            padding: 5px 10px; 
            border-radius: 20px;
            font-size: 12px; 
            font-weight: bold; 
            margin-left: 10px;
        }
        .recommended-badge.aligned { 
            background: #d4edda; 
            color: #155724; 
        }
        .recommended-badge.misaligned { 
            background: #fff3cd; 
            color: #856404; 
        }
        .status-select {
            padding: 10px; 
            border-radius: 5px; 
            font-weight: bold;
        }
        .department-field {
            background: #fff8e1;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #ffc107;
            margin-bottom: 20px;
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
            border: 2px solid #3498db;
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
        
        .hint-text {
            font-size: 11px;
            color: #7f8c8d;
            margin-top: 3px;
            display: flex;
            align-items: center;
            gap: 3px;
        }
        
        .barangay-autocomplete {
            position: relative;
        }
        
        /* Zone validation display */
        .zone-validation {
            background: #e8f5e9;
            padding: 10px;
            border-radius: 6px;
            margin: 10px 0;
            border-left: 4px solid #4CAF50;
            display: none;
        }
        
        .zone-validation.valid {
            display: block;
        }
        
        .zone-validation.invalid {
            background: #ffebee;
            border-left-color: #f44336;
            display: block;
        }
        
        .checkmark {
            display: inline-block;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #27ae60;
            position: relative;
            margin-right: 5px;
        }
        
        .checkmark::after {
            content: '';
            position: absolute;
            top: 4px;
            left: 6px;
            width: 5px;
            height: 9px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        
        .warning-mark {
            display: inline-block;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #f39c12;
            color: white;
            text-align: center;
            line-height: 18px;
            font-weight: bold;
            margin-right: 5px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
            .form-group {
                width: 100%;
            }
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>Edit Machine</h1>
            <div>
                <a href="r-view_machines.php?contract_id=<?php echo $machine['contract_id']; ?>" class="btn btn-secondary" style="padding: 10px 20px; text-decoration: none;">Back to Machines</a>
            </div>
        </div>
        
        <div class="contract-header">
            <strong>Contract:</strong> <?php echo htmlspecialchars($machine['contract_number']); ?><br>
            <strong>Client:</strong> <?php echo htmlspecialchars($machine['company_name']); ?> (<?php echo $machine['classification']; ?>)<br>
            <strong>Machine #:</strong> <?php echo htmlspecialchars($machine['machine_number']); ?> | 
            <strong>Serial:</strong> <?php echo htmlspecialchars($machine['machine_serial_number']); ?>
            <?php if($machine['department']): ?>
                | <strong>Department:</strong> <?php echo htmlspecialchars($machine['department']); ?>
            <?php endif; ?>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="" id="editMachineForm">
            <!-- Machine Details -->
            <h2>Machine Details</h2>
            <div class="form-row">
                <div class="form-group">
                    <label>Machine Type</label>
                    <select name="machine_type" id="machine_type" onchange="toggleColorFields()" required>
                        <option value="MONOCHROME" <?php echo $machine['machine_type'] == 'MONOCHROME' ? 'selected' : ''; ?>>MONOCHROME</option>
                        <option value="COLOR" <?php echo $machine['machine_type'] == 'COLOR' ? 'selected' : ''; ?>>COLOR</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Machine Model</label>
                    <input type="text" name="machine_model" value="<?php echo htmlspecialchars($machine['machine_model']); ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Machine Brand</label>
                    <input type="text" name="machine_brand" value="<?php echo htmlspecialchars($machine['machine_brand']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Serial Number</label>
                    <input type="text" name="machine_serial_number" value="<?php echo htmlspecialchars($machine['machine_serial_number']); ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Machine Number</label>
                    <input type="text" name="machine_number" value="<?php echo htmlspecialchars($machine['machine_number']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Mono Meter Start</label>
                    <input type="number" name="mono_meter_start" value="<?php echo $machine['mono_meter_start']; ?>" required>
                </div>
            </div>
            
            <div id="color_fields" style="display: <?php echo $machine['machine_type'] == 'COLOR' ? 'block' : 'none'; ?>;">
                <div class="form-group">
                    <label>Color Meter Start</label>
                    <input type="number" name="color_meter_start" value="<?php echo $machine['color_meter_start']; ?>">
                </div>
            </div>
            
            <!-- Department Field -->
            <div class="department-field">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <span style="font-size: 20px;">🏢</span>
                    <span style="font-weight: bold; color: #856404;">Department/Office Assignment</span>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Department/Office</label>
                    <input type="text" name="department" value="<?php echo htmlspecialchars($machine['department'] ?? ''); ?>" 
                           placeholder="e.g., Finance Department, HR Office, Admin Office">
                    <div class="info-text">Specify the department or office where this machine is installed</div>
                </div>
            </div>
            
            <!-- Installation Address -->
            <h2>Installation Address</h2>
            <div class="form-row">
                <div class="form-group">
                    <label>Building/Unit Number</label>
                    <input type="text" name="building_number" value="<?php echo htmlspecialchars($machine['building_number']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Street Name</label>
                    <input type="text" name="street_name" value="<?php echo htmlspecialchars($machine['street_name']); ?>" required>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>City / Municipality</label>
                    <select name="city" id="city" onchange="onCityChange()" required>
                        <option value="">-- Select a city --</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?php echo htmlspecialchars($city); ?>" <?php echo $city == $machine['city'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($city); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group barangay-autocomplete">
                    <label>Barangay</label>
                    <input type="text" name="barangay" id="barangay" 
                           value="<?php echo htmlspecialchars($machine['barangay']); ?>" 
                           placeholder="Start typing to search barangay..." 
                           autocomplete="off"
                           oninput="onBarangayInput()"
                           onkeydown="handleBarangayKeydown(event)"
                           required>
                    <div id="barangaySuggestions" class="autocomplete-suggestions"></div>
                    <div id="barangayError" class="error" style="display: none; margin-top: 5px;"></div>
                    <div class="hint-text">
                        <span>🔍</span> Type at least 2 characters to see suggestions
                    </div>
                </div>
            </div>
            
            <!-- Zone Validation Status -->
            <div id="zoneValidation" class="zone-validation">
                <div style="display: flex; align-items: center;">
                    <span id="validationIcon" class="checkmark"></span>
                    <span id="validationMessage">Barangay validated</span>
                </div>
            </div>
            
            <!-- Zone Selection (Read-only - will be auto-filled) -->
            <h2>Zone Assignment</h2>
            <div class="form-row">
                <div class="form-group">
                    <label>Zone</label>
                    <select name="zone_id" id="zone_id" onchange="updateZoneDetails()" required>
                        <option value="">Select Zone</option>
                        <?php foreach ($zones as $zone): ?>
                            <option value="<?php echo $zone['id']; ?>" 
                                    data-number="<?php echo $zone['zone_number']; ?>"
                                    data-center="<?php echo htmlspecialchars($zone['area_center']); ?>"
                                    <?php echo $zone['id'] == $machine['zone_id'] ? 'selected' : ''; ?>>
                                Zone <?php echo $zone['zone_number']; ?> - <?php echo htmlspecialchars($zone['area_center']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Zone Number</label>
                    <input type="number" name="zone_number" id="zone_number" value="<?php echo $machine['zone_number']; ?>" readonly>
                </div>
            </div>
            
            <div class="form-group">
                <label>Area Center</label>
                <input type="text" name="area_center" id="area_center" value="<?php echo htmlspecialchars($machine['area_center']); ?>" readonly>
            </div>
            
            <!-- Reading Date -->
            <h2>Reading Schedule</h2>
            <div id="zoneInfo" class="zone-info <?php echo $machine['reading_date'] == $recommended_date ? '' : 'misaligned'; ?>">
                <strong>📍 Zone <?php echo $machine['zone_number']; ?> - <?php echo htmlspecialchars($machine['area_center']); ?></strong><br>
                Recommended Reading Date: <strong>Day <?php echo $recommended_date; ?></strong>
                <?php if ($machine['reading_date'] == $recommended_date): ?>
                    <span class="recommended-badge aligned">✓ ALIGNED</span>
                <?php else: ?>
                    <span class="recommended-badge misaligned">⚠️ MIS-ALIGNED</span>
                <?php endif; ?>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Reading Date (1-31)</label>
                    <input type="number" name="reading_date" id="reading_date" 
                           min="1" max="31" value="<?php echo $machine['reading_date']; ?>" 
                           onchange="checkAlignment()" required>
                    <div class="info-text">
                        Recommended: Day <?php echo $recommended_date; ?> (Zone <?php echo $machine['zone_number']; ?> + 2)
                    </div>
                </div>
                <div class="form-group">
                    <label>Comments</label>
                    <textarea name="comments" rows="3"><?php echo htmlspecialchars($machine['comments']); ?></textarea>
                </div>
            </div>
            
            <!-- Status -->
            <h2>Machine Status</h2>
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="status-select" required>
                    <option value="ACTIVE" <?php echo $machine['status'] == 'ACTIVE' ? 'selected' : ''; ?>>ACTIVE</option>
                    <option value="INACTIVE" <?php echo $machine['status'] == 'INACTIVE' ? 'selected' : ''; ?>>INACTIVE</option>
                </select>
            </div>
            
            <div style="margin-top: 30px; text-align: center;">
                <button type="submit" class="btn">Update Machine</button>
                <a href="r-view_machines.php?contract_id=<?php echo $machine['contract_id']; ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
    
    <script>
        let searchTimeout;
        let isValidBarangay = true;
        const machineId = <?php echo $machine_id; ?>; 
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleColorFields();
            checkAlignment();
            
            // Initially hide validation if we're editing (assuming it was valid when created)
            const validationDiv = document.getElementById('zoneValidation');
            if (validationDiv) {
                validationDiv.style.display = 'none';
            }
        });
        
        function toggleColorFields() {
            const machineType = document.getElementById('machine_type').value;
            const colorFields = document.getElementById('color_fields');
            if (colorFields) {
                colorFields.style.display = machineType === 'COLOR' ? 'block' : 'none';
                
                const colorInput = colorFields.querySelector('input');
                if (colorInput) {
                    colorInput.required = machineType === 'COLOR';
                }
            }
        }
        
        function onCityChange() {
            const citySelect = document.getElementById('city');
            const barangayInput = document.getElementById('barangay');
            
            // Clear suggestions
            document.getElementById('barangaySuggestions').style.display = 'none';
            
            // Reset validation since city changed
            isValidBarangay = false;
            const validationDiv = document.getElementById('zoneValidation');
            if (validationDiv) {
                validationDiv.style.display = 'none';
            }
        }
        
        function onBarangayInput() {
            const searchTerm = document.getElementById('barangay').value.trim();
            const city = document.getElementById('city').value;
            
            if (!city) {
                alert('Please select a city first');
                document.getElementById('barangay').value = '';
                return;
            }
            
            clearTimeout(searchTimeout);
            
            if (searchTerm.length >= 2) {
                searchTimeout = setTimeout(() => {
                    fetchBarangaySuggestions(searchTerm, city);
                }, 300);
            } else {
                document.getElementById('barangaySuggestions').style.display = 'none';
            }
        }
        
        function fetchBarangaySuggestions(search, city) {
            fetch(`r-edit_machine.php?id=${machineId}&get_barangays=1&city=${encodeURIComponent(city)}&search=${encodeURIComponent(search)}`)
                .then(response => response.json())
                .then(data => {
                    const suggestionsDiv = document.getElementById('barangaySuggestions');
                    
                    if (data.success && data.barangays.length > 0) {
                        let html = '';
                        data.barangays.forEach(barangay => {
                            html += `<div class="autocomplete-item" onclick="selectBarangay('${barangay.replace(/'/g, "\\'")}')">${barangay}</div>`;
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
        
        function selectBarangay(barangay) {
            document.getElementById('barangay').value = barangay;
            document.getElementById('barangaySuggestions').style.display = 'none';
            
            // Get the selected city
            const city = document.getElementById('city').value;
            
            // Automatically determine zone
            getZoneFromBarangay(barangay, city);
        }
        
        function handleBarangayKeydown(e) {
            const suggestions = document.getElementById('barangaySuggestions');
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
                selectBarangay(active.textContent);
            } else if (e.key === 'Escape') {
                suggestions.style.display = 'none';
            }
        }
        
        function validateBarangay() {
            const barangay = document.getElementById('barangay').value.trim();
            const city = document.getElementById('city').value;
            
            if (!city || !barangay) return;
            
            // Actually validate by getting the zone
            getZoneFromBarangay(barangay, city);
        }
        
        function updateZoneDetails() {
            const select = document.getElementById('zone_id');
            if (!select) return;
            
            const selected = select.options[select.selectedIndex];
            
            if (selected.value) {
                const zoneNumber = selected.dataset.number;
                const areaCenter = selected.dataset.center;
                
                document.getElementById('zone_number').value = zoneNumber;
                document.getElementById('area_center').value = areaCenter;
                
                // Update the reading date recommendation
                checkAlignment();
            }
        }
        
        function checkAlignment() {
            const readingDate = parseInt(document.getElementById('reading_date').value);
            const zoneNumber = parseInt(document.getElementById('zone_number').value);
            const recommendedDate = zoneNumber + 2;
            const zoneInfo = document.getElementById('zoneInfo');
            const areaCenter = document.getElementById('area_center').value;
            
            // Check if elements exist
            if (!zoneInfo || isNaN(zoneNumber)) return;
            
            // Clear the zoneInfo and rebuild it with the correct badge
            if (!isNaN(readingDate) && !isNaN(recommendedDate)) {
                if (readingDate === recommendedDate) {
                    zoneInfo.className = 'zone-info';
                    zoneInfo.innerHTML = `
                        <strong>📍 Zone ${zoneNumber} - ${areaCenter}</strong><br>
                        Recommended Reading Date: <strong>Day ${recommendedDate}</strong>
                        <span class="recommended-badge aligned">✓ ALIGNED</span>
                    `;
                } else {
                    zoneInfo.className = 'zone-info misaligned';
                    zoneInfo.innerHTML = `
                        <strong>📍 Zone ${zoneNumber} - ${areaCenter}</strong><br>
                        Recommended Reading Date: <strong>Day ${recommendedDate}</strong>
                        <span class="recommended-badge misaligned">⚠️ MIS-ALIGNED</span>
                    `;
                }
            }
        }

        function getZoneFromBarangay(barangay, city) {
            if (!city || !barangay) return;
            
            // Show loading state
            const zoneValidation = document.getElementById('zoneValidation');
            const validationIcon = document.getElementById('validationIcon');
            const validationMessage = document.getElementById('validationMessage');
            
            zoneValidation.className = 'zone-validation';
            validationIcon.className = '';
            validationIcon.innerHTML = '<div class="loading-spinner" style="width:18px;height:18px;border:2px solid #f3f3f3;border-top:2px solid #3498db;border-radius:50%;animation:spin 1s linear infinite;"></div>';
            validationMessage.textContent = 'Determining zone...';
            zoneValidation.style.display = 'block';
            
            const formData = new FormData();
            formData.append('barangay', barangay);
            formData.append('city', city);
            formData.append('get_zone', '1');
            
            fetch('r-get_zone_from_location.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update zone dropdown
                    const zoneSelect = document.getElementById('zone_id');
                    const zoneNumber = data.zone_number;
                    const areaCenter = data.area_center;
                    
                    // Find and select the matching zone in dropdown
                    for (let i = 0; i < zoneSelect.options.length; i++) {
                        if (zoneSelect.options[i].dataset.number == zoneNumber) {
                            zoneSelect.selectedIndex = i;
                            break;
                        }
                    }
                    
                    // Update zone number and area center
                    document.getElementById('zone_number').value = zoneNumber;
                    document.getElementById('area_center').value = areaCenter;
                    
                    // Update validation status
                    zoneValidation.className = 'zone-validation valid';
                    validationIcon.className = 'checkmark';
                    validationIcon.innerHTML = '';
                    validationMessage.textContent = 'Zone determined automatically';
                    
                    // Update reading date recommendation
                    const readingDateField = document.getElementById('reading_date');
                    const recommendedDate = parseInt(zoneNumber) + 2;
                    
                    // If reading date is empty or matches the old recommendation, update it
                    if (!readingDateField.value || readingDateField.value == parseInt(zoneNumber) + 2) {
                        readingDateField.value = recommendedDate;
                    }
                    
                    // Update alignment display
                    checkAlignment();
                    
                    isValidBarangay = true;
                } else {
                    // Show error
                    zoneValidation.className = 'zone-validation invalid';
                    validationIcon.className = 'warning-mark';
                    validationIcon.innerHTML = '!';
                    validationMessage.textContent = 'Could not determine zone for this barangay';
                    isValidBarangay = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                zoneValidation.className = 'zone-validation invalid';
                validationIcon.className = 'warning-mark';
                validationIcon.innerHTML = '!';
                validationMessage.textContent = 'Error determining zone';
                isValidBarangay = false;
            });
        }
        
        // Close suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.barangay-autocomplete')) {
                document.getElementById('barangaySuggestions').style.display = 'none';
            }
        });
    </script>
</body>
</html>