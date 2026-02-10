<?php
require_once 'config.php'; // Database connection

// Pagination settings
$limit = 20; // Number of clients per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$classification_filter = isset($_GET['classification']) ? $_GET['classification'] : '';

// Build WHERE clause for search
$where_clauses = [];
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_clauses[] = "(company_name LIKE ? OR main_signatory LIKE ? OR main_number LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= 'sss';
}

if (!empty($classification_filter) && in_array($classification_filter, ['GOVERNMENT', 'PRIVATE'])) {
    $where_clauses[] = "classification = ?";
    $params[] = $classification_filter;
    $param_types .= 's';
}

if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(' AND ', $where_clauses);
} else {
    $where_sql = "";
}

// Get total number of clients for pagination
$count_sql = "SELECT COUNT(*) as total FROM clients $where_sql";
$count_stmt = $conn->prepare($count_sql);

if (!empty($params)) {
    $count_stmt->bind_param($param_types, ...$params);
}

$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_clients = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_clients / $limit);

// Get clients with pagination
$sql = "SELECT id, classification, company_name, main_signatory, signatory_position, 
               main_number, main_address, tin_number, email, status, created_at 
        FROM clients 
        $where_sql 
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?";

// Add limit and offset to params
$params[] = $limit;
$params[] = $offset;
$param_types .= 'ii';

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$clients = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Clients - Rental System</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .header-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background-color: #2980b9;
        }
        
        .btn-dashboard::before {
            content: "🏠";
            margin-right: 8px;
        }
        
        .btn-add::before {
            content: "➕";
            margin-right: 8px;
        }
        
        .btn-edit {
            background-color: #f39c12;
            padding: 6px 12px;
            font-size: 13px;
        }
        
        .btn-edit:hover {
            background-color: #d68910;
        }
        
        .btn-edit::before {
            content: "✏️";
            margin-right: 5px;
        }
        
        .container {
            max-width: auto;
            margin: 0 auto;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            padding: 30px;
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #3498db;
        }
        
        /* Search and Filter Styles */
        .search-filter {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .search-box {
            flex: 1;
            min-width: 300px;
        }
        
        .search-box input,
        .filter-box select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
        }
        
        .filter-box {
            min-width: 200px;
        }
        
        .search-btn {
            background-color: #2c3e50;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .search-btn:hover {
            background-color: #1a252f;
        }
        
        /* Table Styles */
        .clients-table-container {
            overflow-x: auto;
            margin-bottom: 30px;
        }
        
        .clients-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }
        
        .clients-table th {
            background-color: #2c3e50;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        
        .clients-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }
        
        .clients-table tr:hover {
            background-color: #f8f9fa;
        }
        
        .clients-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .status-active {
            background-color: #d4edda;
            color: #155724;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .classification-gov {
            background-color: #cce5ff;
            color: #004085;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .classification-pvt {
            background-color: #d4edda;
            color: #155724;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .email-cell {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .address-cell {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .actions-cell {
            white-space: nowrap;
        }
        
        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #3498db;
            background-color: white;
        }
        
        .pagination a:hover {
            background-color: #f8f9fa;
        }
        
        .pagination .current {
            background-color: #3498db;
            color: white;
            border-color: #3498db;
        }
        
        .pagination .disabled {
            color: #6c757d;
            cursor: not-allowed;
        }
        
        /* Stats Info */
        .stats-info {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 6px;
            flex-wrap: wrap;
        }
        
        .stat-item {
            display: flex;
            flex-direction: column;
        }
        
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .header-buttons {
                justify-content: center;
            }
            
            .search-filter {
                flex-direction: column;
            }
            
            .search-box {
                min-width: 100%;
            }
            
            .filter-box {
                min-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Client Management</h1>
            <div class="header-buttons">
                <a href="dashboard.php" class="btn btn-dashboard">Dashboard</a>
                <a href="add_client.php" class="btn btn-add">Add New Client</a>
            </div>
        </div>
        
        <!-- Stats Information -->
        <div class="stats-info">
            <div class="stat-item">
                <span class="stat-label">Total Clients</span>
                <span class="stat-value"><?php echo $total_clients; ?></span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Current Page</span>
                <span class="stat-value"><?php echo $page; ?> / <?php echo $total_pages; ?></span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Showing</span>
                <span class="stat-value"><?php echo count($clients); ?> clients</span>
            </div>
        </div>
        
        <!-- Search and Filter -->
        <form method="GET" action="" class="search-filter">
            <div class="search-box">
                <input type="text" 
                       name="search" 
                       placeholder="Search by company name, signatory, or phone..." 
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-box">
                <select name="classification">
                    <option value="">All Classifications</option>
                    <option value="GOVERNMENT" <?php echo $classification_filter == 'GOVERNMENT' ? 'selected' : ''; ?>>Government</option>
                    <option value="PRIVATE" <?php echo $classification_filter == 'PRIVATE' ? 'selected' : ''; ?>>Private</option>
                </select>
            </div>
            <button type="submit" class="search-btn">Search</button>
            <?php if (!empty($search) || !empty($classification_filter)): ?>
                <a href="view_clients.php" class="btn" style="background-color: #6c757d;">Clear Filters</a>
            <?php endif; ?>
        </form>
        
        <!-- Clients Table -->
        <div class="clients-table-container">
            <?php if (!empty($clients)): ?>
                <table class="clients-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Company Name</th>
                            <th>Classification</th>
                            <th>Main Signatory</th>
                            <th>Position</th>
                            <th>Contact Number</th>
                            <th>Email</th>
                            <th>TIN Number</th>
                            <th>Status</th>
                            <th>Created Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $client): ?>
                            <tr>
                                <td>#<?php echo $client['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($client['company_name']); ?></strong>
                                    <div class="address-cell" title="<?php echo htmlspecialchars($client['main_address']); ?>">
                                        <?php echo htmlspecialchars(substr($client['main_address'], 0, 50)); ?>
                                        <?php echo strlen($client['main_address']) > 50 ? '...' : ''; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="classification-<?php echo strtolower(substr($client['classification'], 0, 3)); ?>">
                                        <?php echo htmlspecialchars($client['classification']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($client['main_signatory']); ?></td>
                                <td><?php echo htmlspecialchars($client['signatory_position'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($client['main_number']); ?></td>
                                <td class="email-cell" title="<?php echo htmlspecialchars($client['email'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($client['email'] ?? 'N/A'); ?>
                                </td>
                                <td><?php echo htmlspecialchars($client['tin_number'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="status-<?php echo strtolower($client['status']); ?>">
                                        <?php echo htmlspecialchars($client['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($client['created_at'])); ?></td>
                                <td class="actions-cell">
                                    <a href="edit_client.php?id=<?php echo $client['id']; ?>" class="btn btn-edit">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <h3>No Clients Found</h3>
                    <p><?php echo !empty($search) ? 'Try a different search term or ' : ''; ?>
                       <a href="add_client.php">add your first client</a> to get started.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <!-- First Page -->
                <?php if ($page > 1): ?>
                    <a href="?page=1<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($classification_filter) ? '&classification=' . urlencode($classification_filter) : ''; ?>">« First</a>
                <?php else: ?>
                    <span class="disabled">« First</span>
                <?php endif; ?>
                
                <!-- Previous Page -->
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($classification_filter) ? '&classification=' . urlencode($classification_filter) : ''; ?>">‹ Prev</a>
                <?php else: ?>
                    <span class="disabled">‹ Prev</span>
                <?php endif; ?>
                
                <!-- Page Numbers -->
                <?php 
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++): 
                ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($classification_filter) ? '&classification=' . urlencode($classification_filter) : ''; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <!-- Next Page -->
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($classification_filter) ? '&classification=' . urlencode($classification_filter) : ''; ?>">Next ›</a>
                <?php else: ?>
                    <span class="disabled">Next ›</span>
                <?php endif; ?>
                
                <!-- Last Page -->
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $total_pages; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($classification_filter) ? '&classification=' . urlencode($classification_filter) : ''; ?>">Last »</a>
                <?php else: ?>
                    <span class="disabled">Last »</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Confirm before deleting (if you add delete functionality later)
        function confirmDelete(clientId, companyName) {
            if (confirm(`Are you sure you want to delete "${companyName}"? This action cannot be undone.`)) {
                window.location.href = `delete_client.php?id=${clientId}`;
            }
        }
        
        // Quick search on Enter key
        document.querySelector('input[name="search"]').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.form.submit();
            }
        });
        
        // Auto-focus search box if there's a search term
        window.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput.value) {
                searchInput.select();
            }
        });
    </script>
</body>
</html>