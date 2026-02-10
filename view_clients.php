<?php
require_once 'config.php';

// Handle delete action
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // First, delete associated machines
    $conn->query("DELETE FROM zoning_machine WHERE client_id = $delete_id");
    
    // Then delete client
    $conn->query("DELETE FROM zoning_clients WHERE id = $delete_id");
    
    header("Location: view_clients.php?msg=deleted");
    exit;
}

// Handle status change
if (isset($_GET['toggle_status'])) {
    $client_id = intval($_GET['toggle_status']);
    
    // Check if client has active machines
    $machine_check = $conn->query("SELECT COUNT(*) as active_count FROM zoning_machine WHERE client_id = $client_id AND status = 'ACTIVE'");
    $active_machines = $machine_check->fetch_assoc()['active_count'];
    
    if ($active_machines > 0) {
        header("Location: view_clients.php?error=cannot_deactivate");
        exit;
    }
    
    // Toggle status
    $conn->query("UPDATE zoning_clients SET status = IF(status = 'ACTIVE', 'INACTIVE', 'ACTIVE') WHERE id = $client_id");
    header("Location: view_clients.php?msg=status_updated");
    exit;
}

// Get filter parameter
$status_filter = $_GET['status'] ?? '';

// Build query with filters
$query = "
    SELECT c.*, 
           COUNT(m.id) as machine_count,
           SUM(CASE WHEN m.status = 'ACTIVE' THEN 1 ELSE 0 END) as active_machines
    FROM zoning_clients c
    LEFT JOIN zoning_machine m ON c.id = m.client_id
    WHERE 1=1
";

if ($status_filter) {
    $query .= " AND c.status = '" . $conn->real_escape_string($status_filter) . "'";
}

$query .= " GROUP BY c.id ORDER BY c.company_name";

$clients = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Clients</title>
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
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .search-box { margin-bottom: 20px; }
        .search-box input { padding: 8px; width: 300px; border: 1px solid #ddd; border-radius: 4px; }
        .filters { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .filter-group { display: inline-block; margin-right: 20px; }
        .filter-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        .filter-group select { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 10% auto; padding: 20px; width: 80%; max-width: 500px; border-radius: 8px; }
        .machine-count { font-size: 0.9em; color: #666; }
        .machine-count .active { color: #4CAF50; }
        .machine-count .total { color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>👥 View Clients</h1>
        <div class="actions">
            <a href="dashboard.php" class="btn-home">← Back to Dashboard</a>
            <a href="add_client.php" class="btn">➕ Add New Client</a>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="message success">
            <?php 
            if ($_GET['msg'] == 'deleted') echo 'Client deleted successfully!';
            if ($_GET['msg'] == 'updated') echo 'Client updated successfully!';
            if ($_GET['msg'] == 'status_updated') echo 'Client status updated successfully!';
            ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="message error">
            <?php 
            if ($_GET['error'] == 'cannot_deactivate') echo 'Cannot deactivate client with active machines!';
            ?>
        </div>
    <?php endif; ?>

    <div class="filters">
        <form method="GET" style="display: flex; align-items: flex-end; gap: 20px;">
            <div class="filter-group">
                <label for="status">Filter by Status:</label>
                <select id="status" name="status" onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="ACTIVE" <?php echo ($status_filter == 'ACTIVE') ? 'selected' : ''; ?>>ACTIVE</option>
                    <option value="INACTIVE" <?php echo ($status_filter == 'INACTIVE') ? 'selected' : ''; ?>>INACTIVE</option>
                </select>
            </div>
            
            <div>
                <a href="view_clients.php" class="btn-secondary" style="padding: 8px 16px;">Clear Filters</a>
            </div>
        </form>
    </div>

    <div class="search-box">
        <input type="text" id="searchInput" placeholder="Search clients by name, email, or phone..." onkeyup="searchTable()">
    </div>

    <table id="clientsTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Company Name</th>
                <th>Classification</th>
                <th>Status</th>
                <th>Contact</th>
                <th>Email</th>
                <th>Machines</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while($client = $clients->fetch_assoc()): ?>
            <tr>
                <td><?php echo $client['id']; ?></td>
                <td><strong><?php echo $client['company_name']; ?></strong></td>
                <td>
                    <span class="badge badge-<?php echo strtolower($client['classification']); ?>">
                        <?php echo $client['classification']; ?>
                    </span>
                </td>
                <td>
                    <a href="view_clients.php?toggle_status=<?php echo $client['id']; ?>" 
                       class="status-badge status-<?php echo strtolower($client['status']); ?>"
                       onclick="return confirmStatusChange(<?php echo $client['id']; ?>, '<?php echo $client['status']; ?>', <?php echo $client['active_machines']; ?>)"
                       title="Click to toggle status">
                        <?php echo $client['status']; ?>
                    </a>
                </td>
                <td><?php echo $client['contact_number']; ?></td>
                <td><?php echo $client['email']; ?></td>
                <td>
                    <div class="machine-count">
                        <span class="active"><?php echo $client['active_machines']; ?> active</span> / 
                        <span class="total"><?php echo $client['machine_count']; ?> total</span>
                    </div>
                </td>
                <td><?php echo date('M d, Y', strtotime($client['created_at'])); ?></td>
                <td class="actions-cell">
                    <a href="edit_client.php?id=<?php echo $client['id']; ?>" class="btn-warning" style="padding: 5px 10px;">✏️ Edit</a>
                    <?php if ($client['status'] === 'ACTIVE'): ?>
                        <a href="add_machine.php?client_id=<?php echo $client['id']; ?>" class="btn-secondary" style="padding: 5px 10px;">➕ Add Machine</a>
                    <?php endif; ?>
                    <a href="#" onclick="confirmDelete(<?php echo $client['id']; ?>, '<?php echo addslashes($client['company_name']); ?>')" class="btn-danger" style="padding: 5px 10px;">🗑️ Delete</a>
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
    function searchTable() {
        const input = document.getElementById('searchInput');
        const filter = input.value.toLowerCase();
        const table = document.getElementById('clientsTable');
        const tr = table.getElementsByTagName('tr');
        
        for (let i = 1; i < tr.length; i++) {
            const td = tr[i].getElementsByTagName('td');
            let found = false;
            
            for (let j = 0; j < td.length; j++) {
                if (td[j]) {
                    const txtValue = td[j].textContent || td[j].innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) {
                        found = true;
                        break;
                    }
                }
            }
            
            tr[i].style.display = found ? '' : 'none';
        }
    }
    
    function confirmStatusChange(clientId, currentStatus, activeMachines) {
        if (currentStatus === 'ACTIVE' && activeMachines > 0) {
            alert('Cannot deactivate client with active machines. Please deactivate all machines first.');
            return false;
        }
        
        const newStatus = currentStatus === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        const confirmMessage = currentStatus === 'ACTIVE' 
            ? `Are you sure you want to deactivate this client?` 
            : `Are you sure you want to activate this client?`;
        
        return confirm(confirmMessage);
    }
    
    let clientToDelete = null;
    
    function confirmDelete(id, name) {
        clientToDelete = id;
        document.getElementById('deleteMessage').textContent = `Are you sure you want to delete "${name}"? This will also delete all associated machines.`;
        document.getElementById('deleteModal').style.display = 'block';
    }
    
    function closeModal() {
        document.getElementById('deleteModal').style.display = 'none';
        clientToDelete = null;
    }
    
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (clientToDelete) {
            window.location.href = `view_clients.php?delete_id=${clientToDelete}`;
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