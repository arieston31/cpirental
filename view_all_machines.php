<?php
require_once 'config.php';

$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$zone = isset($_GET['zone']) ? intval($_GET['zone']) : 0;

$query = "SELECT cm.*, c.contract_number, cl.company_name, cl.classification 
          FROM contract_machines cm
          JOIN contracts c ON cm.contract_id = c.id
          JOIN clients cl ON c.client_id = cl.id
          WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (cm.machine_number LIKE ? OR cm.machine_serial_number LIKE ? OR cl.company_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($status)) {
    $query .= " AND cm.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($zone > 0) {
    $query .= " AND cm.zone_number = ?";
    $params[] = $zone;
    $types .= "i";
}

$query .= " ORDER BY cm.datecreated DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$machines = $stmt->get_result();

// Get zones for filter
$zones = $conn->query("SELECT * FROM zoning_zone ORDER BY zone_number");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Machines</title>
    <style>
        /* Copy styles from view_machines.php */
        body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .machine-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
        .machine-card { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .machine-header { background: #34495e; color: white; padding: 15px; }
        .machine-body { padding: 20px; }
        .filter-section { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>All Machines</h1>
            <a href="dashboard.php" class="btn">← Back to Dashboard</a>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET">
                <input type="text" name="search" placeholder="Search machines..." value="<?php echo htmlspecialchars($search); ?>">
                <select name="status">
                    <option value="">All Status</option>
                    <option value="ACTIVE" <?php echo $status == 'ACTIVE' ? 'selected' : ''; ?>>ACTIVE</option>
                    <option value="INACTIVE" <?php echo $status == 'INACTIVE' ? 'selected' : ''; ?>>INACTIVE</option>
                </select>
                <select name="zone">
                    <option value="">All Zones</option>
                    <?php while($z = $zones->fetch_assoc()): ?>
                        <option value="<?php echo $z['zone_number']; ?>" <?php echo $zone == $z['zone_number'] ? 'selected' : ''; ?>>
                            Zone <?php echo $z['zone_number']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <button type="submit">Filter</button>
                <a href="view_all_machines.php">Clear</a>
            </form>
        </div>
        
        <!-- Machines Grid -->
        <div class="machine-grid">
            <?php while($machine = $machines->fetch_assoc()): ?>
                <div class="machine-card">
                    <div class="machine-header">
                        <strong><?php echo htmlspecialchars($machine['machine_number']); ?></strong>
                        <span style="float: right;"><?php echo $machine['machine_type']; ?></span>
                    </div>
                    <div class="machine-body">
                        <p><strong>Client:</strong> <?php echo htmlspecialchars($machine['company_name']); ?></p>
                        <?php if ($machine['department']): ?>
                            <p style="margin: 5px 0;">
                                <strong>Department:</strong> 
                                <span style="background: #fff8e1; padding: 2px 8px; border-radius: 15px; color: #856404;">
                                    <?php echo htmlspecialchars($machine['department']); ?>
                                </span>
                            </p>
                        <?php endif; ?>
                        <p><strong>Contract:</strong> <?php echo htmlspecialchars($machine['contract_number']); ?></p>
                        <p><strong>Serial:</strong> <?php echo htmlspecialchars($machine['machine_serial_number']); ?></p>
                        <p><strong>Location:</strong> <?php echo htmlspecialchars($machine['barangay'] . ', ' . $machine['city']); ?></p>
                        <p><strong>Zone:</strong> <?php echo $machine['zone_number']; ?> - <?php echo htmlspecialchars($machine['area_center']); ?></p>
                        <p><strong>Reading Date:</strong> Day <?php echo $machine['reading_date']; ?></p>
                        <a href="edit_machine.php?id=<?php echo $machine['id']; ?>">Edit</a>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
</body>
</html>