<?php
require_once 'config.php';
session_start();

$zone_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$zone_id) {
    die("Zone ID is required.");
}

// Get zone data
$zone_query = $conn->query("SELECT * FROM zoning_zone WHERE id = $zone_id");
$zone = $zone_query->fetch_assoc();

if (!$zone) {
    die("Zone not found.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $zone_number = intval($_POST['zone_number']);
    $area_center = $conn->real_escape_string($_POST['area_center']);
    $reading_date = intval($_POST['reading_date']);
    $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : 'NULL';
    $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : 'NULL';
    
    $update_sql = "UPDATE zoning_zone SET 
                    zone_number = $zone_number,
                    area_center = '$area_center',
                    reading_date = $reading_date,
                    latitude = $latitude,
                    longitude = $longitude
                    WHERE id = $zone_id";
    
    if ($conn->query($update_sql)) {
        $success = "Zone updated successfully!";
        // Refresh data
        $zone_query = $conn->query("SELECT * FROM zoning_zone WHERE id = $zone_id");
        $zone = $zone_query->fetch_assoc();
    } else {
        $error = "Error updating zone: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Zone <?php echo $zone['zone_number']; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; margin-bottom: 20px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #34495e; }
        input[type="text"], input[type="number"] {
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;
        }
        .btn {
            background: #3498db; color: white; padding: 12px 30px; border: none;
            border-radius: 5px; font-size: 16px; cursor: pointer;
        }
        .btn:hover { background: #2980b9; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .info-text { font-size: 12px; color: #7f8c8d; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Edit Zone <?php echo $zone['zone_number']; ?></h1>
        
        <?php if (isset($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Zone Number</label>
                <input type="number" name="zone_number" value="<?php echo $zone['zone_number']; ?>" required>
            </div>
            
            <div class="form-group">
                <label>Area Center</label>
                <input type="text" name="area_center" value="<?php echo htmlspecialchars($zone['area_center']); ?>" required>
            </div>
            
            <div class="form-group">
                <label>Reading Date</label>
                <input type="number" name="reading_date" value="<?php echo $zone['reading_date']; ?>" min="1" max="31" required>
                <div class="info-text">Day of month for meter reading (1-31)</div>
            </div>
            
            <div class="form-group">
                <label>Latitude</label>
                <input type="text" name="latitude" value="<?php echo $zone['latitude']; ?>" step="0.000001">
                <div class="info-text">e.g., 14.5995</div>
            </div>
            
            <div class="form-group">
                <label>Longitude</label>
                <input type="text" name="longitude" value="<?php echo $zone['longitude']; ?>" step="0.000001">
                <div class="info-text">e.g., 120.9842</div>
            </div>
            
            <button type="submit" class="btn">Update Zone</button>
            <a href="view_zones.php" style="margin-left: 10px; color: #7f8c8d;">Cancel</a>
        </form>
    </div>
</body>
</html>