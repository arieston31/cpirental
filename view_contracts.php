<?php
require_once 'config.php';
session_start();

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$classification = isset($_GET['classification']) ? $_GET['classification'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build query with filters
$query = "SELECT c.*, cl.company_name, cl.classification as client_classification, 
                 (SELECT COUNT(*) FROM contract_machines WHERE contract_id = c.id) as machine_count
          FROM contracts c
          JOIN clients cl ON c.client_id = cl.id
          WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (c.contract_number LIKE ? OR cl.company_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if (!empty($status)) {
    $query .= " AND c.status = ?";
    $params[] = $status;
    $types .= "s";
}

if (!empty($classification)) {
    $query .= " AND cl.classification = ?";
    $params[] = $classification;
    $types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND DATE(c.datecreated) >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND DATE(c.datecreated) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$query .= " ORDER BY c.datecreated DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$contracts = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Contracts</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; margin-bottom: 10px; }
        .btn {
            display: inline-block; padding: 10px 20px; background: #3498db; color: white;
            text-decoration: none; border-radius: 5px; transition: background 0.3s;
            border: none; cursor: pointer; font-size: 14px;
        }
        .btn:hover { background: #2980b9; }
        .btn-success { background: #27ae60; }
        .btn-success:hover { background: #229954; }
        .btn-warning { background: #f39c12; }
        .btn-warning:hover { background: #e67e22; }
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }
        
        .filter-section {
            background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;
        }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-weight: bold; margin-bottom: 5px; color: #34495e; }
        .filter-group input, .filter-group select {
            padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;
        }
        
        .table-responsive { overflow-x: auto; }
        table {
            width: 100%; background: white; border-radius: 10px; overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-collapse: collapse;
        }
        th {
            background: #34495e; color: white; padding: 15px; text-align: left; font-weight: 600;
        }
        td {
            padding: 12px 15px; border-bottom: 1px solid #ecf0f1; vertical-align: middle;
        }
        tr:hover { background: #f8f9fa; }
        
        .status-badge {
            display: inline-block; padding: 5px 10px; border-radius: 20px;
            font-size: 12px; font-weight: bold; text-transform: uppercase;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .status-suspended { background: #fff3cd; color: #856404; }
        
        .contract-number {
            font-family: monospace; font-weight: bold; color: #2980b9;
        }
        .action-buttons { display: flex; gap: 5px; }
        .action-btn {
            padding: 5px 10px; border-radius: 3px; text-decoration: none;
            color: white; font-size: 12px;
        }
        .pagination {
            margin-top: 20px; display: flex; justify-content: center; gap: 10px;
        }
        .pagination a {
            padding: 8px 12px; background: white; border: 1px solid #ddd;
            text-decoration: none; color: #3498db; border-radius: 4px;
        }
        .pagination a.active { background: #3498db; color: white; border-color: #3498db; }
        .summary-cards {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px; margin-bottom: 20px;
        }
        .summary-card {
            background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .summary-card h3 { color: #7f8c8d; font-size: 14px; margin-bottom: 5px; }
        .summary-card .number { font-size: 28px; font-weight: bold; color: #2c3e50; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h1>Contracts Management</h1>
                <div>
                    <a href="add_contracts.php" class="btn btn-success">+ New Contract</a>
                    <a href="index.php" class="btn">Dashboard</a>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <?php
        $total_contracts = $conn->query("SELECT COUNT(*) as count FROM contracts")->fetch_assoc()['count'];
        $active_contracts = $conn->query("SELECT COUNT(*) as count FROM contracts WHERE status = 'ACTIVE'")->fetch_assoc()['count'];
        $total_machines = $conn->query("SELECT COUNT(*) as count FROM contract_machines")->fetch_assoc()['count'];
        ?>
        <div class="summary-cards">
            <div class="summary-card">
                <h3>Total Contracts</h3>
                <div class="number"><?php echo $total_contracts; ?></div>
            </div>
            <div class="summary-card">
                <h3>Active Contracts</h3>
                <div class="number"><?php echo $active_contracts; ?></div>
            </div>
            <div class="summary-card">
                <h3>Total Machines</h3>
                <div class="number"><?php echo $total_machines; ?></div>
            </div>
            <div class="summary-card">
                <h3>Avg Machines/Contract</h3>
                <div class="number"><?php echo $total_contracts > 0 ? round($total_machines / $total_contracts, 1) : 0; ?></div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" style="display: contents;">
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Contract # or Company" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="ACTIVE" <?php echo $status == 'ACTIVE' ? 'selected' : ''; ?>>ACTIVE</option>
                        <option value="INACTIVE" <?php echo $status == 'INACTIVE' ? 'selected' : ''; ?>>INACTIVE</option>
                        <option value="SUSPENDED" <?php echo $status == 'SUSPENDED' ? 'selected' : ''; ?>>SUSPENDED</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Client Type</label>
                    <select name="classification">
                        <option value="">All Types</option>
                        <option value="GOVERNMENT" <?php echo $classification == 'GOVERNMENT' ? 'selected' : ''; ?>>GOVERNMENT</option>
                        <option value="PRIVATE" <?php echo $classification == 'PRIVATE' ? 'selected' : ''; ?>>PRIVATE</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                </div>
                <div class="filter-group" style="display: flex; flex-direction: row; align-items: flex-end; gap: 10px;">
                    <button type="submit" class="btn">Apply Filters</button>
                    <a href="view_contracts.php" class="btn" style="background: #95a5a6;">Clear</a>
                </div>
            </form>
        </div>

        <!-- Contracts Table -->
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Contract #</th>
                        <th>Client</th>
                        <th>Contract Period</th> 
                        <th>Type</th>
                        <th>Contract Type</th>
                        <th>Machines</th>
                        <th>Rates</th>
                        <th>Collection</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($contracts->num_rows > 0): ?>
                        <?php while($contract = $contracts->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="contract-number"><?php echo htmlspecialchars($contract['contract_number']); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($contract['company_name']); ?></strong><br>
                                    <small style="color: <?php echo $contract['client_classification'] == 'GOVERNMENT' ? '#27ae60' : '#3498db'; ?>;">
                                        <?php echo $contract['client_classification']; ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if ($contract['contract_start'] && $contract['contract_end']): ?>
                                        <span style="font-size: 12px; display: block;">
                                            <strong>From:</strong> <?php echo date('M d, Y', strtotime($contract['contract_start'])); ?>
                                        </span>
                                        <span style="font-size: 12px; display: block;">
                                            <strong>To:</strong> <?php echo date('M d, Y', strtotime($contract['contract_end'])); ?>
                                        </span>
                                        <?php 
                                        $start = new DateTime($contract['contract_start']);
                                        $end = new DateTime($contract['contract_end']);
                                        $interval = $start->diff($end);
                                        $days_left = $interval->days;
                                        $today = new DateTime();
                                        if ($today < $end) {
                                            $remaining = $today->diff($end);
                                            echo '<span style="font-size: 11px; color: #27ae60;">' . $remaining->days . ' days left</span>';
                                        } else {
                                            echo '<span style="font-size: 11px; color: #e74c3c;">Expired</span>';
                                        }
                                        ?>
                                    <?php else: ?>
                                        <span style="color: #7f8c8d;">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-weight: bold; color: <?php echo $contract['type_of_contract'] == 'UMBRELLA' ? '#27ae60' : '#e67e22'; ?>;">
                                        <?php echo $contract['type_of_contract']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($contract['has_colored_machines'] == 'YES'): ?>
                                        <span style="color: #e67e22;">🎨 Colored</span>
                                    <?php else: ?>
                                        <span style="color: #7f8c8d;">⚫ Mono Only</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <a href="view_machines.php?contract_id=<?php echo $contract['id']; ?>" style="text-decoration: none;">
                                        <span style="background: #3498db; color: white; padding: 5px 10px; border-radius: 20px; font-weight: bold;">
                                            <?php echo $contract['machine_count']; ?> Machines
                                        </span>
                                    </a>
                                </td>
                                <td>
                                    <small>
                                        Mono: ₱<?php echo number_format($contract['mono_rate'], 2); ?><br>
                                        <?php if ($contract['has_colored_machines'] == 'YES'): ?>
                                            Color: ₱<?php echo number_format($contract['color_rate'], 2); ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <small>
                                        Period: <?php echo $contract['collection_processing_period']; ?> days<br>
                                        <?php if ($contract['collection_date']): ?>
                                            Day: <?php echo $contract['collection_date']; ?>
                                        <?php else: ?>
                                            <span style="color: #7f8c8d;">TBD</span>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($contract['status']); ?>">
                                        <?php echo $contract['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <small>
                                        <?php echo date('M d, Y', strtotime($contract['datecreated'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="edit_contract.php?id=<?php echo $contract['id']; ?>" class="action-btn" style="background: #f39c12;">Edit</a>
                                        <a href="view_machines.php?contract_id=<?php echo $contract['id']; ?>" class="action-btn" style="background: #3498db;">Machines</a>
                                        <?php if ($contract['status'] == 'ACTIVE'): ?>
                                            <a href="#" onclick="updateStatus(<?php echo $contract['id']; ?>, 'INACTIVE')" class="action-btn" style="background: #95a5a6;">Deactivate</a>
                                        <?php else: ?>
                                            <a href="#" onclick="updateStatus(<?php echo $contract['id']; ?>, 'ACTIVE')" class="action-btn" style="background: #27ae60;">Activate</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px;">
                                <h3 style="color: #7f8c8d; margin-bottom: 10px;">No Contracts Found</h3>
                                <p>Click "New Contract" to create your first contract.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    function updateStatus(contractId, newStatus) {
        if (confirm(`Are you sure you want to ${newStatus === 'ACTIVE' ? 'activate' : 'deactivate'} this contract?`)) {
            fetch('update_contract_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: contractId, status: newStatus })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to update status: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
            });
        }
    }
    </script>
</body>
</html>