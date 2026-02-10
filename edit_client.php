<?php
require_once 'config.php';

$client_id = intval($_GET['id'] ?? 0);

// Get client data
$stmt = $conn->prepare("SELECT * FROM zoning_clients WHERE id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$result = $stmt->get_result();
$client = $result->fetch_assoc();

if (!$client) {
    die("Client not found!");
}

// Check if client has active machines
$machine_check = $conn->query("SELECT COUNT(*) as active_count FROM zoning_machine WHERE client_id = $client_id AND status = 'ACTIVE'");
$active_machines = $machine_check->fetch_assoc()['active_count'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = strtoupper(trim($_POST['company_name']));
    $classification = $_POST['classification'];
    $contact_number = $_POST['contact_number'];
    $email = $_POST['email'];
    $status = $_POST['status'];
    
    // Prevent making client inactive if they have active machines
    if ($status === 'INACTIVE' && $active_machines > 0) {
        $error = "Cannot deactivate client with active machines. Please deactivate all machines first.";
    } else {
        $stmt = $conn->prepare("UPDATE zoning_clients SET company_name = ?, classification = ?, contact_number = ?, email = ?, status = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $company_name, $classification, $contact_number, $email, $status, $client_id);
        
        if ($stmt->execute()) {
            header("Location: view_clients.php?msg=updated");
            exit;
        } else {
            $error = $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Client</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f0f2f5; }
        .container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; color: #333; border-bottom: 2px solid #ff9800; padding-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
        button { background: #ff9800; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #e68900; }
        .btn-secondary { background: #2196F3; margin-right: 10px; }
        .btn-secondary:hover { background: #1976D2; }
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .form-actions { display: flex; gap: 10px; margin-top: 20px; }
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
        <h2>✏️ Edit Client: <?php echo htmlspecialchars($client['company_name']); ?></h2>
        
        <?php if ($active_machines > 0): ?>
            <div class="message warning">
                ⚠️ This client has <?php echo $active_machines; ?> active machine(s). 
                Client cannot be deactivated until all machines are deactivated.
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="message error">Error: <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="company_name">Company Name *</label>
                <input type="text" id="company_name" name="company_name" value="<?php echo htmlspecialchars($client['company_name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="classification">Classification *</label>
                <select id="classification" name="classification" required>
                    <option value="GOVERNMENT" <?php echo $client['classification'] == 'GOVERNMENT' ? 'selected' : ''; ?>>GOVERNMENT</option>
                    <option value="PRIVATE" <?php echo $client['classification'] == 'PRIVATE' ? 'selected' : ''; ?>>PRIVATE</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="contact_number">Contact Number *</label>
                <input type="text" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($client['contact_number']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($client['email']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="status">Status *</label>
                <select id="status" name="status" required <?php echo ($active_machines > 0 && $client['status'] == 'ACTIVE') ? 'disabled' : ''; ?>>
                    <option value="ACTIVE" <?php echo $client['status'] == 'ACTIVE' ? 'selected' : ''; ?>>ACTIVE</option>
                    <option value="INACTIVE" <?php echo $client['status'] == 'INACTIVE' ? 'selected' : ''; ?> <?php echo $active_machines > 0 ? 'disabled' : ''; ?>>INACTIVE</option>
                </select>
                <?php if ($active_machines > 0 && $client['status'] == 'ACTIVE'): ?>
                    <small style="color: #f44336;">Cannot change to INACTIVE while client has active machines</small>
                <?php endif; ?>
            </div>
            
            <div class="form-actions">
                <a href="view_clients.php" class="btn-secondary" style="padding: 12px 24px; text-decoration: none; display: inline-block;">← Cancel</a>
                <button type="submit">💾 Save Changes</button>
            </div>
        </form>
    </div>
</body>
</html>