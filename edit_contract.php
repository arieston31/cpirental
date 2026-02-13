<?php
require_once 'config.php';
session_start();

$contract_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$contract_id) {
    die("Contract ID is required.");
}

// Get contract data
$contract_query = $conn->query("
    SELECT c.*, cl.classification, cl.company_name 
    FROM contracts c
    JOIN clients cl ON c.client_id = cl.id
    WHERE c.id = $contract_id
");
$contract = $contract_query->fetch_assoc();

if (!$contract) {
    die("Contract not found.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $contract_start = !empty($_POST['contract_start']) ? "'" . $conn->real_escape_string($_POST['contract_start']) . "'" : 'NULL';
    $contract_end = !empty($_POST['contract_end']) ? "'" . $conn->real_escape_string($_POST['contract_end']) . "'" : 'NULL';
    $mono_rate = floatval($_POST['mono_rate']);
    $color_rate = isset($_POST['color_rate']) && !empty($_POST['color_rate']) ? floatval($_POST['color_rate']) : 'NULL';
    $excess_monorate = floatval($_POST['excess_monorate']);
    $excess_colorrate = isset($_POST['excess_colorrate']) && !empty($_POST['excess_colorrate']) ? floatval($_POST['excess_colorrate']) : 'NULL';
    $mincopies_mono = intval($_POST['mincopies_mono']);
    $mincopies_color = isset($_POST['mincopies_color']) && !empty($_POST['mincopies_color']) ? intval($_POST['mincopies_color']) : 'NULL';
    $spoilage = floatval($_POST['spoilage']);
    $minimum_monthly_charge = !empty($_POST['minimum_monthly_charge']) ? floatval($_POST['minimum_monthly_charge']) : 'NULL';
    $collection_processing_period = intval($_POST['collection_processing_period']);
    $collection_date = !empty($_POST['collection_date']) ? intval($_POST['collection_date']) : 'NULL';
    $vatable = $_POST['vatable'];
    $status = $_POST['status'];
    
    $update_sql = "UPDATE contracts SET 
                    contract_start = $contract_start,
                    contract_end = $contract_end,
                    mono_rate = '$mono_rate',
                    color_rate = $color_rate,
                    excess_monorate = '$excess_monorate',
                    excess_colorrate = $excess_colorrate,
                    mincopies_mono = '$mincopies_mono',
                    mincopies_color = $mincopies_color,
                    spoilage = '$spoilage',
                    minimum_monthly_charge = $minimum_monthly_charge,
                    collection_processing_period = '$collection_processing_period',
                    collection_date = $collection_date,
                    vatable = '$vatable',
                    status = '$status',
                    updated_at = NOW()
                    WHERE id = $contract_id";
    
    if ($conn->query($update_sql)) {
        $success = "Contract updated successfully!";
        
        // Refresh contract data
        $contract_query = $conn->query("
            SELECT c.*, cl.classification, cl.company_name 
            FROM contracts c
            JOIN clients cl ON c.client_id = cl.id
            WHERE c.id = $contract_id
        ");
        $contract = $contract_query->fetch_assoc();
    } else {
        $error = "Error updating contract: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Contract - <?php echo htmlspecialchars($contract['contract_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; margin-bottom: 10px; }
        h2 { color: #34495e; font-size: 16px; margin-bottom: 30px; border-bottom: 1px solid #bdc3c7; padding-bottom: 10px; }
        .contract-header {
            background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 30px;
            border-left: 4px solid #3498db;
        }
        .form-group { margin-bottom: 20px; }
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #34495e; }
        input[type="text"], input[type="number"], input[type="date"], select, textarea {
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 14px;
        }
        input:focus, select:focus { border-color: #3498db; outline: none; box-shadow: 0 0 5px rgba(52,152,219,0.3); }
        input[readonly] { background: #f8f9fa; color: #7f8c8d; }
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
        .status-select {
            padding: 10px; border-radius: 5px; font-weight: bold;
        }
        .date-range {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #3498db;
        }
        .minimum-charge {
            background: #fff8e1;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #f39c12;
        }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1>Edit Contract</h1>
            <div>
                <a href="view_contracts.php" class="btn btn-secondary" style="padding: 10px 20px; text-decoration: none;">Back to Contracts</a>
                <a href="view_machines.php?contract_id=<?php echo $contract_id; ?>" class="btn" style="background: #27ae60; padding: 10px 20px; text-decoration: none;">View Machines</a>
            </div>
        </div>
        
        <div class="contract-header">
            <strong>Contract Number:</strong> <?php echo htmlspecialchars($contract['contract_number']); ?><br>
            <strong>Client:</strong> <?php echo htmlspecialchars($contract['company_name']); ?> (<?php echo $contract['classification']; ?>)<br>
            <strong>Contract Type:</strong> <?php echo $contract['type_of_contract']; ?><br>
            <strong>Created:</strong> <?php echo date('M d, Y h:i A', strtotime($contract['datecreated'])); ?>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <!-- Contract Period -->
            <h2>📅 Contract Period</h2>
            <div class="date-range">
                <div class="form-row">
                    <div class="form-group">
                        <label>Contract Start Date</label>
                        <input type="date" name="contract_start" id="contract_start" 
                               value="<?php echo $contract['contract_start']; ?>" required
                               onchange="calculateDuration()">
                    </div>
                    <div class="form-group">
                        <label>Contract End Date</label>
                        <input type="date" name="contract_end" id="contract_end" 
                               value="<?php echo $contract['contract_end']; ?>" required
                               onchange="calculateDuration()">
                    </div>
                </div>
                <div id="durationDisplay" style="margin-top: 10px;"></div>
            </div>
            
            <!-- Rates Section -->
            <h2>💰 Rates & Pricing</h2>
            <div class="form-row">
                <div class="form-group">
                    <label>Mono Rate (₱)</label>
                    <input type="number" step="0.01" name="mono_rate" value="<?php echo $contract['mono_rate']; ?>" required>
                </div>
                <div class="form-group">
                    <label>Excess Mono Rate (₱)</label>
                    <input type="number" step="0.01" name="excess_monorate" value="<?php echo $contract['excess_monorate']; ?>" required>
                </div>
            </div>
            
            <?php if ($contract['has_colored_machines'] == 'YES'): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label>Color Rate (₱)</label>
                        <input type="number" step="0.01" name="color_rate" value="<?php echo $contract['color_rate']; ?>">
                    </div>
                    <div class="form-group">
                        <label>Excess Color Rate (₱)</label>
                        <input type="number" step="0.01" name="excess_colorrate" value="<?php echo $contract['excess_colorrate']; ?>">
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Minimum Copies (Mono)</label>
                    <input type="number" name="mincopies_mono" value="<?php echo $contract['mincopies_mono']; ?>" required>
                </div>
                <?php if ($contract['has_colored_machines'] == 'YES'): ?>
                    <div class="form-group">
                        <label>Minimum Copies (Color)</label>
                        <input type="number" name="mincopies_color" value="<?php echo $contract['mincopies_color']; ?>">
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Minimum Monthly Charge - NEW FIELD -->
            <div class="form-row">
                <div class="form-group">
                    <label>Spoilage (%)</label>
                    <input type="number" step="0.01" name="spoilage" value="<?php echo $contract['spoilage']; ?>" required>
                </div>
                <div class="form-group">
                    <label>Minimum Monthly Charge (₱)</label>
                    <input type="number" step="0.01" name="minimum_monthly_charge" 
                           value="<?php echo $contract['minimum_monthly_charge']; ?>" 
                           placeholder="0.00" 
                           style="border-color: <?php echo !empty($contract['minimum_monthly_charge']) ? '#f39c12' : '#ddd'; ?>;">
                    <div class="info-text">Optional minimum monthly billing amount</div>
                </div>
                <div class="form-group">
                    <label>Vatable</label>
                    <select name="vatable" required>
                        <option value="YES" <?php echo $contract['vatable'] == 'YES' ? 'selected' : ''; ?>>YES</option>
                        <option value="NO" <?php echo $contract['vatable'] == 'NO' ? 'selected' : ''; ?>>NO</option>
                    </select>
                </div>
            </div>
            
            <!-- Collection Section -->
            <h2 style="margin-top: 30px;">📊 Collection Settings</h2>
            <div class="form-row">
                <div class="form-group">
                    <label>Collection Processing Period (Days)</label>
                    <input type="number" name="collection_processing_period" value="<?php echo $contract['collection_processing_period']; ?>" required>
                </div>
                <div class="form-group">
                    <label>Collection Date (Day of Month)</label>
                    <input type="number" name="collection_date" min="1" max="31" value="<?php echo $contract['collection_date']; ?>" <?php echo $contract['classification'] == 'PRIVATE' ? 'readonly' : ''; ?>>
                    <div class="info-text">
                        <?php if ($contract['classification'] == 'PRIVATE'): ?>
                            Auto-calculated based on highest reading date + processing period
                        <?php else: ?>
                            Enter day of month (1-31)
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Status -->
            <h2 style="margin-top: 30px;">⚡ Contract Status</h2>
            <div class="form-group">
                <label>Status</label>
                <select name="status" class="status-select" required>
                    <option value="ACTIVE" <?php echo $contract['status'] == 'ACTIVE' ? 'selected' : ''; ?>>ACTIVE</option>
                    <option value="INACTIVE" <?php echo $contract['status'] == 'INACTIVE' ? 'selected' : ''; ?>>INACTIVE</option>
                    <option value="SUSPENDED" <?php echo $contract['status'] == 'SUSPENDED' ? 'selected' : ''; ?>>SUSPENDED</option>
                </select>
            </div>
            
            <div style="margin-top: 30px; text-align: center;">
                <button type="submit" class="btn">Update Contract</button>
                <a href="view_contracts.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
    
    <script>
        function calculateDuration() {
            const startDate = document.getElementById('contract_start').value;
            const endDate = document.getElementById('contract_end').value;
            const durationDisplay = document.getElementById('durationDisplay');
            
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                
                if (end < start) {
                    durationDisplay.innerHTML = '<span style="color: #e74c3c;">⚠️ End date cannot be before start date</span>';
                } else {
                    const diffTime = Math.abs(end - start);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    const diffMonths = Math.floor(diffDays / 30);
                    const remainingDays = diffDays % 30;
                    
                    let durationText = '';
                    if (diffMonths > 0) {
                        durationText += `${diffMonths} month${diffMonths > 1 ? 's' : ''}`;
                    }
                    if (remainingDays > 0) {
                        durationText += `${diffMonths > 0 ? ' and ' : ''}${remainingDays} day${remainingDays > 1 ? 's' : ''}`;
                    }
                    
                    durationDisplay.innerHTML = `<span style="color: #27ae60; font-weight: 600;">✓ Contract duration: ${durationText}</span>`;
                }
            }
        }
        
        // Calculate duration on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateDuration();
        });
    </script>
</body>
</html>