<?php
require_once 'config.php';

$machine_id = intval($_GET['id'] ?? 0);

// Get machine data with client info
$stmt = $conn->prepare("
    SELECT m.*, c.classification, c.company_name, c.id as client_id, c.status as client_status
    FROM zoning_machine m
    JOIN zoning_clients c ON m.client_id = c.id
    WHERE m.id = ?
");
$stmt->bind_param("i", $machine_id);
$stmt->execute();
$result = $stmt->get_result();
$machine = $result->fetch_assoc();

if (!$machine) {
    die("Machine not found!");
}

// Check if client is active
if ($machine['client_status'] === 'INACTIVE') {
    die("Cannot edit machine of inactive client!");
}

// Function to get current zone info for display
function getCurrentZoneInfo($zone_id, $conn) {
    if (!$zone_id) {
        return ['zone_number' => 'Not assigned', 'area_center' => 'Please enter address'];
    }
    
    $stmt = $conn->prepare("SELECT zone_number, area_center FROM zoning_zone WHERE id = ?");
    if (!$stmt) {
        return ['zone_number' => 'Error', 'area_center' => 'Database error'];
    }
    
    $stmt->bind_param("i", $zone_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row;
    }
    
    return ['zone_number' => 'Unknown', 'area_center' => 'Zone not found'];
}

// Get current zone info
$current_zone_info = getCurrentZoneInfo($machine['zone_id'] ?? 0, $conn);

// Function to get zone-based fixed reading date
function getZoneReadingDate($zone_number) {
    // Zone 1 = 3rd, Zone 2 = 4th, Zone 3 = 5th, ... Zone 12 = 14th
    // Formula: reading_date = zone_number + 2
    return $zone_number + 2;
}

// Function to get zone from location (copied from get_zone_from_location.php but simplified)
function getZoneFromLocation($barangay, $city, $conn) {
    $barangay = trim($barangay);
    $city = trim($city);
    
    if (empty($city)) {
        return getZoneFromCityFallback($city, $conn);
    }
    
    // First, check our local cache
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
                'source' => 'database_cache'
            ];
        }
    }
    
    // Try city-only matching as fallback
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get POST data
    $street_number = $_POST['street_number'] ?? '';
    $street_name = $_POST['street_name'] ?? '';
    $barangay = $_POST['barangay'] ?? '';
    $city = $_POST['city'] ?? '';
    $department = $_POST['department'] ?? '';
    $processing_period = $_POST['processing_period'] ?? 10;
    $status = $_POST['status'] ?? 'ACTIVE';
    
    // For PRIVATE clients, get new zone and reading date
    $new_zone_id = $machine['zone_id'];
    $new_reading_date = $machine['reading_date'];
    $new_collection_date = $machine['collection_date'];
    
    if ($machine['classification'] == 'PRIVATE') {
        // Get new zone based on updated address
        $zone_data = getZoneFromLocation($barangay, $city, $conn);
        
        if ($zone_data && isset($zone_data['zone_id'])) {
            $new_zone_id = $zone_data['zone_id'];
            $new_zone_number = $zone_data['zone_number'];
            
            // Get zone-based reading date
            $new_reading_date = getZoneReadingDate($new_zone_number);
            
            // Calculate collection date
            $new_collection_date = $new_reading_date + $processing_period;
            if ($new_collection_date > 31) {
                $new_collection_date -= 31;
            }
        }
    } else {
        // GOVERNMENT clients - manual dates
        $new_reading_date = $_POST['reading_date'] ?? $machine['reading_date'];
        $new_collection_date = $_POST['collection_date'] ?? $machine['collection_date'];
    }
    
    // Update machine record
    $stmt = $conn->prepare("
        UPDATE zoning_machine 
        SET street_number = ?, 
            street_name = ?, 
            barangay = ?, 
            city = ?, 
            department = ?, 
            processing_period = ?,
            reading_date = ?,
            collection_date = ?,
            zone_id = ?,
            status = ?
        WHERE id = ?
    ");
    $stmt->bind_param(
        "sssssiiiiisi",
        $street_number,
        $street_name,
        $barangay,
        $city,
        $department,
        $processing_period,
        $new_reading_date,
        $new_collection_date,
        $new_zone_id,
        $status,
        $machine_id
    );
    
    if ($stmt->execute()) {
        header("Location: view_machines.php?msg=updated");
        exit;
    } else {
        $error = $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Machine</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; background: #f0f2f5; }
        .container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; color: #333; border-bottom: 2px solid #ff9800; padding-bottom: 10px; }
        .client-info { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .client-info p { margin: 5px 0; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
        .readonly-field { background: #f5f5f5; color: #666; }
        button { background: #ff9800; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #e68900; }
        .btn-secondary { background: #2196F3; margin-right: 10px; }
        .btn-secondary:hover { background: #1976D2; }
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .form-actions { display: flex; gap: 10px; margin-top: 20px; }
        .inline-fields { display: flex; gap: 10px; }
        .inline-fields > div { flex: 1; }
        .zone-display { background: #e3f2fd; padding: 10px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #2196F3; }
        .schedule-display { background: #fff3cd; padding: 10px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .info-text { color: #666; font-size: 0.9em; }
        .loading { color: #666; font-style: italic; }
        .required { color: #dc3545; }
        .status-badge { 
            display: inline-block; 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 0.9em;
            font-weight: bold;
        }
        .status-active { 
            background: #d4edda; 
            color: #155724; 
        }
        .status-inactive { 
            background: #f8d7da; 
            color: #721c24; 
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>✏️ Edit Machine</h2>
        
        <div class="client-info">
            <p><strong>Client:</strong> <?php echo htmlspecialchars($machine['company_name']); ?></p>
            <p><strong>Classification:</strong> 
                <span style="color: <?php echo $machine['classification'] === 'GOVERNMENT' ? '#2e7d32' : '#1565c0'; ?>; font-weight: bold;">
                    <?php echo $machine['classification']; ?>
                </span>
            </p>
            <p><strong>Client Status:</strong> 
                <span class="status-badge status-<?php echo strtolower($machine['client_status']); ?>">
                    <?php echo $machine['client_status']; ?>
                </span>
            </p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="message error">Error: <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" id="editForm">
            <!-- Current Zone and Schedule Display -->
            <div class="zone-display">
                <strong>📍 Current Zone Assignment:</strong> 
                <div id="zoneInfo">
                    Zone <?php echo $current_zone_info['zone_number']; ?> - <?php echo $current_zone_info['area_center']; ?>
                    <small>(Will update when address changes)</small>
                </div>
            </div>
            
            <?php if ($machine['classification'] == 'PRIVATE'): ?>
                <div class="schedule-display">
                    <strong>📅 Current Schedule:</strong>
                    <div id="scheduleInfo">
                        Reading Date: Day <?php echo $machine['reading_date']; ?> (Fixed for Zone <?php echo $current_zone_info['zone_number']; ?>)<br>
                        Processing Period: <?php echo $machine['processing_period']; ?> days<br>
                        Collection Date: Day <?php echo $machine['collection_date']; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Address Fields (Can be changed) -->
            <div class="form-group">
                <label>Installation Address <span class="required">*</span></label>
                <div class="inline-fields">
                    <div>
                        <label>Street Number <span class="required">*</span></label>
                        <input type="text" name="street_number" id="street_number" 
                               value="<?php echo htmlspecialchars($machine['street_number']); ?>" required
                               onblur="updateZoneAndSchedule()">
                    </div>
                    <div>
                        <label>Street Name <span class="required">*</span></label>
                        <input type="text" name="street_name" id="street_name" 
                               value="<?php echo htmlspecialchars($machine['street_name']); ?>" required
                               onblur="updateZoneAndSchedule()">
                    </div>
                </div>
                <div class="inline-fields" style="margin-top: 10px;">
                    <div>
                        <label>Barangay <span class="required">*</span></label>
                        <input type="text" name="barangay" id="barangay" required
                               value="<?php echo htmlspecialchars($machine['barangay']); ?>"
                               onblur="updateZoneAndSchedule()">
                        <small class="info-text">Important for accurate zone assignment</small>
                    </div>
                    <div>
                        <label>City <span class="required">*</span></label>
                        <input type="text" name="city" id="city" required
                               value="<?php echo htmlspecialchars($machine['city']); ?>"
                               onblur="updateZoneAndSchedule()">
                        <small class="info-text">Zone is determined by geographical location</small>
                    </div>
                </div>
            </div>
            
            <!-- Machine Number (Cannot be changed) -->
            <div class="form-group">
                <label for="machine_number">Machine Number <span class="required">*</span></label>
                <input type="text" id="machine_number" class="readonly-field" 
                       value="<?php echo htmlspecialchars($machine['machine_number']); ?>" readonly>
                <small class="info-text">Machine number cannot be changed</small>
            </div>
            
            <!-- Department (Can be changed) -->
            <div class="form-group">
                <label for="department">Department</label>
                <input type="text" id="department" name="department" 
                       value="<?php echo htmlspecialchars($machine['department']); ?>">
            </div>
            
            <!-- Processing Period (Can be changed) -->
            <div class="form-group">
                <label for="processing_period">Processing Period (days) <span class="required">*</span></label>
                <input type="number" id="processing_period" name="processing_period" 
                       min="1" max="31" value="<?php echo $machine['processing_period']; ?>" required
                       onchange="updateCollectionDate()">
                <small class="info-text">Collection date = Reading date + Processing period</small>
            </div>
            
            <!-- Status Field -->
            <div class="form-group">
                <label for="status">Status <span class="required">*</span></label>
                <select id="status" name="status" required>
                    <option value="ACTIVE" <?php echo $machine['status'] == 'ACTIVE' ? 'selected' : ''; ?>>ACTIVE</option>
                    <option value="INACTIVE" <?php echo $machine['status'] == 'INACTIVE' ? 'selected' : ''; ?>>INACTIVE</option>
                </select>
            </div>
            
            <?php if ($machine['classification'] == 'GOVERNMENT'): ?>
                <!-- Government clients enter dates manually -->
                <div class="form-group">
                    <label for="reading_date">Reading Date (1-31) <span class="required">*</span></label>
                    <input type="number" id="reading_date" name="reading_date" 
                           min="1" max="31" value="<?php echo $machine['reading_date']; ?>" required
                           onchange="calculateGovernmentCollectionDate()">
                </div>
                
                <div class="form-group">
                    <label for="collection_date">Collection Date</label>
                    <input type="number" id="collection_date" name="collection_date" 
                           min="1" max="31" value="<?php echo $machine['collection_date']; ?>">
                </div>
            <?php else: ?>
                <!-- Hidden fields for private clients (will be populated by JavaScript) -->
                <input type="hidden" id="assigned_reading_date" name="reading_date" value="<?php echo $machine['reading_date']; ?>">
                <input type="hidden" id="assigned_collection_date" name="collection_date" value="<?php echo $machine['collection_date']; ?>">
            <?php endif; ?>
            
            <div class="form-actions">
                <a href="view_machines.php" class="btn-secondary" style="padding: 12px 24px; text-decoration: none; display: inline-block;">← Cancel</a>
                <button type="submit">💾 Save Changes</button>
            </div>
        </form>
    </div>
    
    <script>
    // Function to get zone-based reading date
    function getZoneReadingDate(zoneNumber) {
        return zoneNumber + 2; // Zone 1 = 3rd, Zone 2 = 4th, etc.
    }

    // Function to update zone and schedule based on address
    async function updateZoneAndSchedule() {
        const barangay = document.getElementById('barangay').value;
        const city = document.getElementById('city').value;
        const classification = '<?php echo $machine['classification']; ?>';
        
        console.log('updateZoneAndSchedule called:', { barangay, city, classification });
        
        // Don't call API if city or barangay is empty
        if (!city.trim() || !barangay.trim()) {
            console.log('City or barangay is empty, skipping API call');
            return;
        }
        
        // Show loading message
        const zoneInfo = document.getElementById('zoneInfo');
        if (zoneInfo) {
            zoneInfo.innerHTML = '<span class="loading">Calculating new zone...</span>';
        }
        
        try {
            // Call API to get zone based on barangay + city
            const formData = new FormData();
            formData.append('barangay', barangay);
            formData.append('city', city);
            formData.append('client_id', <?php echo $machine['client_id']; ?>);
            
            const response = await fetch('get_zone_from_location.php', {
                method: 'POST',
                body: formData
            });
            
            console.log('API Response status:', response.status);
            
            const data = await response.json();
            console.log('API Response data:', data);
            
            if (data.success) {
                if (zoneInfo) {
                    zoneInfo.innerHTML = `
                        <strong>Zone ${data.zone_number}</strong> - ${data.area_center}<br>
                        <small>New assignment via: ${data.source}${data.distance_km ? ' • Distance: ' + data.distance_km + ' km' : ''}</small>
                    `;
                }
                
                // For PRIVATE clients, get new reading date based on zone
                if (classification === 'PRIVATE' && data.zone_number) {
                    const readingDate = getZoneReadingDate(data.zone_number);
                    const processingPeriod = parseInt(document.getElementById('processing_period').value) || <?php echo $machine['processing_period']; ?>;
                    let collectionDate = readingDate + processingPeriod;
                    if (collectionDate > 31) {
                        collectionDate -= 31;
                    }
                    
                    const scheduleInfo = document.getElementById('scheduleInfo');
                    if (scheduleInfo) {
                        scheduleInfo.innerHTML = `
                            <div>📅 <strong>New Reading Date:</strong> Day ${readingDate} (Fixed for Zone ${data.zone_number})</div>
                            <div>⏱️ <strong>Processing Period:</strong> ${processingPeriod} days (Editable)</div>
                            <div>📦 <strong>New Collection Date:</strong> Day ${collectionDate} (Auto-calculated)</div>
                        `;
                    }
                    
                    // Set hidden fields for form submission
                    document.getElementById('assigned_reading_date').value = readingDate;
                    document.getElementById('assigned_collection_date').value = collectionDate;
                }
            } else {
                if (zoneInfo) {
                    zoneInfo.innerHTML = `Zone assignment failed: ${data.error || 'Unknown error'}`;
                }
                console.error('API Error:', data.error);
            }
        } catch (error) {
            console.error('Error:', error);
            if (zoneInfo) {
                zoneInfo.innerHTML = 'Error calculating zone: ' + error.message;
            }
        }
    }

    // Function to update collection date for PRIVATE clients
    function updateCollectionDate() {
        const classification = '<?php echo $machine['classification']; ?>';
        
        if (classification === 'PRIVATE') {
            updateZoneAndSchedule();
        }
    }

    // Function for GOVERNMENT clients to calculate collection date
    function calculateGovernmentCollectionDate() {
        const readingDate = parseInt(document.getElementById('reading_date').value) || 0;
        const processingPeriod = parseInt(document.getElementById('processing_period').value) || 10;
        let collectionDate = readingDate + processingPeriod;
        
        if (collectionDate > 31) {
            collectionDate -= 31;
        }
        
        document.getElementById('collection_date').value = collectionDate;
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded, initializing edit form');
        
        <?php if ($machine['classification'] == 'GOVERNMENT'): ?>
        calculateGovernmentCollectionDate();
        <?php else: ?>
        // Only call updateZoneAndSchedule if we have address data
        const barangay = document.getElementById('barangay').value;
        const city = document.getElementById('city').value;
        
        if (barangay.trim() && city.trim()) {
            console.log('Initializing with existing address');
            // Don't call API on page load, just show current data
        } else {
            console.log('No address data yet, waiting for user input');
        }
        <?php endif; ?>
        
        // Add event listeners for address fields
        const addressFields = ['barangay', 'city', 'street_number', 'street_name'];
        addressFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('blur', function() {
                    console.log(`${fieldId} changed, updating zone...`);
                    updateZoneAndSchedule();
                });
            }
        });
    });
    </script>
</body>
</html>