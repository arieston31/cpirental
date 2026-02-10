<?php
require_once 'config.php';

// Handle delete action
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Delete machine
    $conn->query("DELETE FROM zoning_machine WHERE id = $delete_id");
    
    header("Location: view_machines.php?msg=deleted");
    exit;
}

// Handle status change
if (isset($_GET['toggle_status'])) {
    $machine_id = intval($_GET['toggle_status']);
    
    // Toggle status
    $conn->query("UPDATE zoning_machine SET status = IF(status = 'ACTIVE', 'INACTIVE', 'ACTIVE') WHERE id = $machine_id");
    header("Location: view_machines.php?msg=status_updated");
    exit;
}

// Get filter parameters
$zone_filter = $_GET['zone'] ?? '';
$date_filter = $_GET['reading_date'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query with filters
$query = "
    SELECT m.*, 
           c.company_name,
           c.classification,
           c.status as client_status,
           z.zone_number,
           z.area_center
    FROM zoning_machine m
    JOIN zoning_clients c ON m.client_id = c.id
    JOIN zoning_zone z ON m.zone_id = z.id
    WHERE c.status = 'ACTIVE'  -- Only show machines of active clients
";

if ($zone_filter) {
    $query .= " AND m.zone_id = " . intval($zone_filter);
}

if ($date_filter) {
    $query .= " AND m.reading_date = " . intval($date_filter);
}

if ($status_filter) {
    $query .= " AND m.status = '" . $conn->real_escape_string($status_filter) . "'";
} else {
    $query .= " AND m.status = 'ACTIVE'";  // Default to active only
}

$query .= " ORDER BY m.zone_id, m.reading_date, c.company_name";

$machines = $conn->query($query);

// Get zones for filter dropdown
$zones = $conn->query("SELECT * FROM zoning_zone ORDER BY zone_number");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Machines</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f0f2f5; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; color: #333; }
        .actions { margin-bottom: 20px; }
        .btn { display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px; }
        .btn:hover { background: #45a049; }
        .btn-secondary { background: #2196F3; }
        .btn-secondary:hover { background: #1976D2; }
        .btn-warning { background: #ff9800; }
        .btn-warning:hover { background: #e68900; }
        .btn-danger { background: #f44336; }
        .btn-danger:hover { background: #d32f2f; }
        .btn-home{
            padding: 12px 25px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        table { width: 100%; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; font-weight: bold; color: #333; }
        tr:hover { background: #f9f9f9; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 0.9em; }
        .badge-government { background: #e8f5e9; color: #2e7d32; }
        .badge-private { background: #e3f2fd; color: #1565c0; }
        .zone-badge { background: #e3f2fd; color: #1976d2; font-weight: bold; }
        .status-badge { 
            display: inline-block; 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 0.9em;
            font-weight: bold;
            cursor: pointer;
        }
        .status-active { 
            background: #d4edda; 
            color: #155724; 
        }
        .status-inactive { 
            background: #f8d7da; 
            color: #721c24; 
        }
        .actions-cell { white-space: nowrap; }
        .actions-cell a { margin-right: 5px; }
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .filters { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .filter-group { display: inline-block; margin-right: 20px; }
        .filter-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        .filter-group select { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .address { font-size: 0.9em; color: #666; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 10% auto; padding: 20px; width: 80%; max-width: 500px; border-radius: 8px; }
        .client-inactive { opacity: 0.6; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🖨️ View Machines</h1>
        <div class="actions">
            <a href="dashboard.php" class="btn-home">← Back to Dashboard</a>
            <a href="add_client.php" class="btn">➕ Add Client & Machine</a>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="message success">
            <?php 
            if ($_GET['msg'] == 'deleted') echo 'Machine deleted successfully!';
            if ($_GET['msg'] == 'updated') echo 'Machine updated successfully!';
            if ($_GET['msg'] == 'status_updated') echo 'Machine status updated successfully!';
            ?>
        </div>
    <?php endif; ?>

    <div class="filters">
        <form method="GET" style="display: flex; align-items: flex-end; gap: 20px; flex-wrap: wrap;">
            <div class="filter-group">
                <label for="zone">Filter by Zone:</label>
                <select id="zone" name="zone" onchange="this.form.submit()">
                    <option value="">All Zones</option>
                    <?php while($zone = $zones->fetch_assoc()): ?>
                        <option value="<?php echo $zone['id']; ?>" <?php echo ($zone_filter == $zone['id']) ? 'selected' : ''; ?>>
                            Zone <?php echo $zone['zone_number']; ?> - <?php echo $zone['area_center']; ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="reading_date">Filter by Reading Date:</label>
                <select id="reading_date" name="reading_date" onchange="this.form.submit()">
                    <option value="">All Dates</option>
                    <?php for($i = 1; $i <= 31; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo ($date_filter == $i) ? 'selected' : ''; ?>>
                            Day <?php echo $i; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="status">Filter by Status:</label>
                <select id="status" name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="ACTIVE" <?php echo ($status_filter == 'ACTIVE') ? 'selected' : ''; ?>>ACTIVE</option>
                    <option value="INACTIVE" <?php echo ($status_filter == 'INACTIVE') ? 'selected' : ''; ?>>INACTIVE</option>
                </select>
            </div>
            
            <div>
                <a href="view_machines.php" class="btn-secondary" style="padding: 8px 16px;">Clear Filters</a>
            </div>
        </form>
    </div>

    <table id="machinesTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Machine Number</th>
                <th>Client</th>
                <th>Classification</th>
                <th>Address</th>
                <th>Department</th>
                <th>Zone</th>
                <th>Reading Date</th>
                <th>Collection Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while($machine = $machines->fetch_assoc()): 
                $client_inactive = $machine['client_status'] === 'INACTIVE';
            ?>
            <tr class="<?php echo $client_inactive ? 'client-inactive' : ''; ?>">
                <td><?php echo $machine['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($machine['machine_number']); ?></strong></td>
                <td>
                    <?php echo htmlspecialchars($machine['company_name']); ?>
                    <?php if ($client_inactive): ?>
                        <br><small style="color: #f44336;">(Client Inactive)</small>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?php echo strtolower($machine['classification']); ?>">
                        <?php echo $machine['classification']; ?>
                    </span>
                </td>
                <td class="address">
                    <?php 
                    echo htmlspecialchars($machine['street_number'] . ' ' . $machine['street_name']) . '<br>';
                    echo htmlspecialchars($machine['barangay'] . ', ' . $machine['city']);
                    ?>
                </td>
                <td><?php echo htmlspecialchars($machine['department']); ?></td>
                <td>
                    <span class="zone-badge">Zone <?php echo $machine['zone_number']; ?></span><br>
                    <small><?php echo $machine['area_center']; ?></small>
                </td>
                <td><strong><?php echo $machine['reading_date']; ?>th</strong></td>
                <td><strong><?php echo $machine['collection_date']; ?>th</strong></td>
                <td>
                    <a href="view_machines.php?toggle_status=<?php echo $machine['id']; ?>" 
                       class="status-badge status-<?php echo strtolower($machine['status']); ?>"
                       onclick="return confirm('Are you sure you want to change the status of this machine?')"
                       title="Click to toggle status">
                        <?php echo $machine['status']; ?>
                    </a>
                </td>
                <td class="actions-cell">
                    <a href="edit_machine.php?id=<?php echo $machine['id']; ?>" class="btn-warning" style="padding: 5px 10px;">✏️ Edit</a>
                    <a href="#" onclick="confirmDelete(<?php echo $machine['id']; ?>, '<?php echo addslashes($machine['machine_number']); ?>')" class="btn-danger" style="padding: 5px 10px;">🗑️ Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3>Confirm Delete</h3>
            <p id="deleteMessage"></p>
            <div style="text-align: right; margin-top: 20px;">
                <button onclick="closeModal()" style="padding: 8px 16px; margin-right: 10px;">Cancel</button>
                <button id="confirmDeleteBtn" style="padding: 8px 16px; background: #f44336; color: white; border: none; border-radius: 4px;">Delete</button>
            </div>
        </div>
    </div>

    <script>
    let machineToDelete = null;
    
    function confirmDelete(id, machineNumber) {
        machineToDelete = id;
        document.getElementById('deleteMessage').textContent = `Are you sure you want to delete machine "${machineNumber}"?`;
        document.getElementById('deleteModal').style.display = 'block';
    }
    
    function closeModal() {
        document.getElementById('deleteModal').style.display = 'none';
        machineToDelete = null;
    }
    
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (machineToDelete) {
            window.location.href = `view_machines.php?delete_id=${machineToDelete}`;
        }
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('deleteModal');
        if (event.target === modal) {
            closeModal();
        }
    });
    </script>
</body>
</html>