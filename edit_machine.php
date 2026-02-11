<?php
require_once 'config.php';
session_start();

$machine_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$machine_id) {
    die("Machine ID is required.");
}

// Get machine data with contract and client info
$machine_query = $conn->query("
    SELECT cm.*, c.contract_number, c.type_of_contract, c.has_colored_machines,
           cl.classification, cl.company_name
    FROM contract_machines cm
    JOIN contracts c ON cm.contract_id = c.id
    JOIN clients cl ON cm.client_id = cl.id
    WHERE cm.id = $machine_id
");
$machine = $machine_query->fetch_assoc();

if (!$machine) {
    die("Machine not found.");
}

// Get all zones for dropdown
$zones_query = $conn->query("SELECT * FROM zoning_zone ORDER BY zone_number");
$zones = [];
while ($zone = $zones_query->fetch_assoc()) {
    $zones[] = $zone;
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
    
    $update_sql = "UPDATE contract_machines SET 
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
            FROM contract_machines cm
            JOIN contracts c ON cm.contract_id = c.id
            JOIN clients cl ON cm.client_id = cl.id
            WHERE cm.id = $machine_id
        ");
        $machine = $machine_query->fetch_assoc();
        
        // Update contract collection date if client is PRIVATE
        if ($machine['classification'] == 'PRIVATE') {
            // Get highest reading date from all machines in this contract
            $highest_date_query = $conn->query("
                SELECT MAX(reading_date) as max_date 
                FROM contract_machines 
                WHERE contract_id = {$machine['contract_id']} AND status = 'ACTIVE'
            ");
            $highest = $highest_date_query->fetch_assoc();
            $highest_reading_date = $highest['max_date'];
            
            // Get contract processing period
            $contract_query = $conn->query("
                SELECT collection_processing_period 
                FROM contracts 
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
                UPDATE contracts 
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Machine - <?php echo htmlspecialchars($machine['machine_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; margin-bottom: 10px; }
        h2 { color: #34495e; font-size: 18px; margin: 25px 0 15px; border-bottom: 1px solid #bdc3c7; padding-bottom: 8px; }
        .contract-header {
            background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 30px;
            border-left: 4px solid #3498db;
        }
        .form-row { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
        .form-group { flex: 1 1 calc(50% - 20px); min-width: 250px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #34495e; }
        input[type="text"], input[type="number"], select, textarea {
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;
        }
        input:focus, select:focus, textarea:focus { 
            border-color: #3498db; outline: none; box-shadow: 0 0 5px rgba(52,152,219,0.3);
        }
        .btn {
            background: #3498db; color: white; padding: 12px 30px; border: none;
            border-radius: 5px; font-size: 16px; cursor: pointer; transition: background 0.3s;
        }
        .btn:hover { background: #2980b9; }
        .btn-secondary {
            background: #95a5a6; margin-left: 10px;
        }
        .btn-secondary:hover { background: #7f8c8d; }
        .success {
            background: #d4edda; color: #155724; padding: 15px; border-radius: 5px;
            margin-bottom: 20px; border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px;
            margin-bottom: 20px; border: 1px solid #f5c6cb;
        }
        .info-text { font-size: 12px; color: #7f8c8d; margin-top: 5px; }
        .zone-info {
            background: #e8f5e9; padding: 15px; border-radius: 8px; margin: 20px 0;
            border-left: 4px solid #4CAF50;
        }
        .zone-info.misaligned {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
        }
        .recommended-badge {
            display: inline-block; padding: 5px 10px; border-radius: 20px;
            font-size: 12px; font-weight: bold; margin-left: 10px;
        }
        .recommended-badge.aligned { background: #d4edda; color: #155724; }
        .recommended-badge.misaligned { background: #fff3cd; color: #856404; }
        .status-select {
            padding: 10px; border-radius: 5px; font-weight: bold;
        }
        .department-field {
            background: #fff8e1;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #ffc107;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>Edit Machine</h1>
            <div>
                <a href="view_machines.php?contract_id=<?php echo $machine['contract_id']; ?>" class="btn btn-secondary" style="padding: 10px 20px; text-decoration: none;">Back to Machines</a>
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
        
        <form method="POST" action="">
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
                    <label>Barangay</label>
                    <input type="text" name="barangay" id="barangay" value="<?php echo htmlspecialchars($machine['barangay']); ?>" required>
                </div>
                <div class="form-group">
                    <label>City</label>
                    <input type="text" name="city" id="city" value="<?php echo htmlspecialchars($machine['city']); ?>" required>
                </div>
            </div>
            
            <!-- Zone Selection -->
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
            <div id="zone_info" class="zone-info <?php echo $machine['reading_date'] == $recommended_date ? '' : 'misaligned'; ?>">
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
                <a href="view_machines.php?contract_id=<?php echo $machine['contract_id']; ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
    
    <script>
        function toggleColorFields() {
            const machineType = document.getElementById('machine_type').value;
            const colorFields = document.getElementById('color_fields');
            colorFields.style.display = machineType === 'COLOR' ? 'block' : 'none';
            
            const colorInput = colorFields.querySelector('input');
            if (colorInput) {
                colorInput.required = machineType === 'COLOR';
            }
        }
        
        function updateZoneDetails() {
            const select = document.getElementById('zone_id');
            const selected = select.options[select.selectedIndex];
            
            if (selected.value) {
                const zoneNumber = selected.dataset.number;
                const areaCenter = selected.dataset.center;
                
                document.getElementById('zone_number').value = zoneNumber;
                document.getElementById('area_center').value = areaCenter;
                
                const recommendedDate = parseInt(zoneNumber) + 2;
                const zoneInfo = document.getElementById('zone_info');
                zoneInfo.innerHTML = `
                    <strong>📍 Zone ${zoneNumber} - ${areaCenter}</strong><br>
                    Recommended Reading Date: <strong>Day ${recommendedDate}</strong>
                    <span id="alignment_badge" class="recommended-badge"></span>
                `;
                
                checkAlignment();
            }
        }
        
        function checkAlignment() {
            const readingDate = parseInt(document.getElementById('reading_date').value);
            const zoneNumber = parseInt(document.getElementById('zone_number').value);
            const recommendedDate = zoneNumber + 2;
            const zoneInfo = document.getElementById('zone_info');
            const alignmentBadge = document.getElementById('alignment_badge');
            
            if (!isNaN(readingDate) && !isNaN(recommendedDate)) {
                if (readingDate === recommendedDate) {
                    zoneInfo.className = 'zone-info';
                    alignmentBadge.className = 'recommended-badge aligned';
                    alignmentBadge.innerHTML = '✓ ALIGNED';
                } else {
                    zoneInfo.className = 'zone-info misaligned';
                    alignmentBadge.className = 'recommended-badge misaligned';
                    alignmentBadge.innerHTML = '⚠️ MIS-ALIGNED';
                }
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            toggleColorFields();
            checkAlignment();
        });
    </script>
</body>
</html>