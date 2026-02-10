<?php
require_once 'config.php'; // Database connection

$message = '';
$message_type = ''; // 'success' or 'error'
$duplicate_warning = '';
$existing_client = null;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is an AJAX duplicate check request
    if (isset($_POST['check_duplicate']) && $_POST['check_duplicate'] === 'true') {
        $company_name = trim($_POST['company_name'] ?? '');
        if (strlen($company_name) >= 5) {
            $check_sql = "SELECT * FROM clients WHERE company_name LIKE ? LIMIT 1";
            $check_stmt = $conn->prepare($check_sql);
            $search_term = "%" . $company_name . "%";
            $check_stmt->bind_param("s", $search_term);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $existing_client = $check_result->fetch_assoc();
                echo json_encode([
                    'duplicate' => true,
                    'client' => $existing_client,
                    'message' => '⚠️ A client with similar name already exists.'
                ]);
            } else {
                echo json_encode(['duplicate' => false]);
            }
            $check_stmt->close();
            exit();
        }
        echo json_encode(['duplicate' => false]);
        exit();
    }
    
    // Regular form submission
    $classification = trim($_POST['classification'] ?? '');
    $company_name = trim($_POST['company_name'] ?? '');
    $main_signatory = trim($_POST['main_signatory'] ?? '');
    $signatory_position = trim($_POST['signatory_position'] ?? '');
    $main_number = trim($_POST['main_number'] ?? '');
    $main_address = trim($_POST['main_address'] ?? '');
    $tin_number = trim($_POST['tin_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    // Validate required fields
    $errors = [];
    
    if (empty($classification)) {
        $errors[] = "Classification is required.";
    }
    
    if (empty($company_name)) {
        $errors[] = "Company name is required.";
    }
    
    if (empty($main_signatory)) {
        $errors[] = "Main signatory is required.";
    }
    
    if (empty($main_number)) {
        $errors[] = "Main contact number is required.";
    }
    
    if (empty($main_address)) {
        $errors[] = "Main address is required.";
    }
    
    // Final duplicate check before inserting
    if (strlen($company_name) >= 5) {
        $check_sql = "SELECT * FROM clients WHERE company_name LIKE ? LIMIT 1";
        $check_stmt = $conn->prepare($check_sql);
        $search_term = "%" . $company_name . "%";
        $check_stmt->bind_param("s", $search_term);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $existing_client = $check_result->fetch_assoc();
            $duplicate_warning = "⚠️ Warning: A client with a similar name already exists in the system.";
        }
        $check_stmt->close();
    }
    
    // If no errors, insert into database
    if (empty($errors)) {
        // Prepare SQL statement with hidden fields
        $sql = "INSERT INTO clients (
                    classification, company_name, main_signatory, signatory_position, 
                    main_number, main_address, tin_number, email, 
                    status, created_at, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', NOW(), NULL)";
        
        $stmt = $conn->prepare($sql);
        
        // Bind parameters (only 8 parameters now since status and created_by are in SQL)
        $stmt->bind_param("ssssssss", 
            $classification, $company_name, $main_signatory, 
            $signatory_position, $main_number, $main_address, $tin_number, $email
        );
        
        // Execute query
        if ($stmt->execute()) {
            $message = "Client added successfully!";
            $message_type = "success";
            
            // Clear form fields for new entry
            $classification = $company_name = $main_signatory = $signatory_position = '';
            $main_number = $main_address = $tin_number = $email = '';
            $duplicate_warning = '';
        } else {
            $message = "Error adding client: " . $conn->error;
            $message_type = "error";
        }
        
        $stmt->close();
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Client - Rental System</title>
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
        }
        
        .dashboard-btn {
            background-color: #2c3e50;
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
        
        .dashboard-btn:hover {
            background-color: #1a252f;
        }
        
        .dashboard-btn::before {
            content: "🏠";
            margin-right: 8px;
        }
        
        .container {
            max-width: 900px;
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
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .required::after {
            content: " *";
            color: #e74c3c;
        }
        
        select, input[type="text"], input[type="tel"], input[type="email"], textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border 0.3s;
        }
        
        select:focus, input:focus, textarea:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .btn {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 14px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background-color 0.3s;
            display: inline-block;
            width: 100%;
            margin-top: 10px;
        }
        
        .btn:hover {
            background-color: #2980b9;
        }
        
        .message {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .duplicate-section {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            animation: slideDown 0.4s ease;
        }
        
        .duplicate-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .duplicate-title {
            color: #856404;
            font-weight: 600;
            font-size: 18px;
            display: flex;
            align-items: center;
        }
        
        .duplicate-title::before {
            content: "⚠️";
            margin-right: 10px;
            font-size: 20px;
        }
        
        .hide-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: background-color 0.3s;
        }
        
        .hide-btn:hover {
            background-color: #545b62;
        }
        
        .client-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
            background-color: #fff;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }
        
        .detail-item {
            margin-bottom: 8px;
        }
        
        .detail-label {
            font-weight: 600;
            color: #495057;
            font-size: 14px;
            margin-bottom: 3px;
        }
        
        .detail-value {
            color: #212529;
            padding: 5px 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
            border-left: 3px solid #3498db;
        }
        
        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        
        @keyframes slideDown {
            from { 
                opacity: 0; 
                transform: translateY(-20px); 
                max-height: 0;
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
                max-height: 500px;
            }
        }
        
        @keyframes slideUp {
            from { 
                opacity: 1; 
                transform: translateY(0); 
                max-height: 500px;
            }
            to { 
                opacity: 0; 
                transform: translateY(-20px); 
                max-height: 0;
                margin: 0;
                padding: 0;
            }
        }
        
        .hide {
            animation: slideUp 0.3s ease forwards;
            overflow: hidden;
        }
        
        @media (max-width: 600px) {
            .form-row {
                flex-direction: column;
                gap: 20px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .client-details-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Add New Client</h1>
        <a href="dashboard.php" class="dashboard-btn">Dashboard</a>
    </div>
    
    <div class="container">
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="clientForm">
            <!-- Classification -->
            <div class="form-group">
                <label for="classification" class="required">Classification</label>
                <select id="classification" name="classification" required>
                    <option value="">-- Select Classification --</option>
                    <option value="GOVERNMENT" <?php echo ($classification ?? '') == 'GOVERNMENT' ? 'selected' : ''; ?>>GOVERNMENT</option>
                    <option value="PRIVATE" <?php echo ($classification ?? '') == 'PRIVATE' ? 'selected' : ''; ?>>PRIVATE</option>
                </select>
            </div>
            
            <!-- Company Name with Dynamic Duplicate Check -->
            <div class="form-group">
                <label for="company_name" class="required">Company Name</label>
                <input type="text" id="company_name" name="company_name" 
                       value="<?php echo htmlspecialchars($company_name ?? ''); ?>" 
                       required maxlength="100">
                <div id="duplicateContainer"></div>
            </div>
            
            <!-- Signatory Details -->
            <div class="form-row">
                <div class="form-group">
                    <label for="main_signatory" class="required">Main Signatory</label>
                    <input type="text" id="main_signatory" name="main_signatory" 
                           value="<?php echo htmlspecialchars($main_signatory ?? ''); ?>" 
                           required maxlength="50">
                </div>
                
                <div class="form-group">
                    <label for="signatory_position">Signatory Position (Optional)</label>
                    <input type="text" id="signatory_position" name="signatory_position" 
                           value="<?php echo htmlspecialchars($signatory_position ?? ''); ?>" 
                           maxlength="50">
                </div>
            </div>
            
            <!-- Contact Details -->
            <div class="form-row">
                <div class="form-group">
                    <label for="main_number" class="required">Main Contact Number</label>
                    <input type="tel" id="main_number" name="main_number" 
                           value="<?php echo htmlspecialchars($main_number ?? ''); ?>" 
                           required maxlength="20">
                </div>
                
                <div class="form-group">
                    <label for="tin_number">TIN Number (Optional)</label>
                    <input type="text" id="tin_number" name="tin_number" 
                           value="<?php echo htmlspecialchars($tin_number ?? ''); ?>" 
                           maxlength="20">
                </div>
            </div>
            
            <!-- Address -->
            <div class="form-group">
                <label for="main_address" class="required">Main Address</label>
                <textarea id="main_address" name="main_address" 
                          required maxlength="255"><?php echo htmlspecialchars($main_address ?? ''); ?></textarea>
            </div>
            
            <!-- Email -->
            <div class="form-group">
                <label for="email">Email Address (Optional)</label>
                <input type="email" id="email" name="email" 
                       value="<?php echo htmlspecialchars($email ?? ''); ?>" 
                       maxlength="100">
            </div>

            
            
            <button type="submit" class="btn">Add Client</button>
        </form>
    </div>
    
    <script>
        // Dynamic duplicate checking as user types (minimum 5 letters)
        let duplicateCheckTimeout;
        
        document.getElementById('company_name').addEventListener('input', function(e) {
            const companyName = e.target.value.trim();
            const duplicateContainer = document.getElementById('duplicateContainer');
            
            // Clear previous timeout
            clearTimeout(duplicateCheckTimeout);
            
            // Clear container if input is too short (less than 5 letters)
            if (companyName.length < 5) {
                duplicateContainer.innerHTML = '';
                return;
            }
            
            // Set timeout to check after user stops typing for 500ms
            duplicateCheckTimeout = setTimeout(() => {
                checkDuplicate(companyName);
            }, 500);
        });
        
        function checkDuplicate(companyName) {
            // Create a FormData object to send the data
            const formData = new FormData();
            formData.append('check_duplicate', 'true');
            formData.append('company_name', companyName);
            
            // Send AJAX request to check for duplicates
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const duplicateContainer = document.getElementById('duplicateContainer');
                
                if (data.duplicate && data.client) {
                    // Display detailed duplicate information
                    const duplicateHtml = `
                        <div class="duplicate-section" id="duplicateSection">
                            <div class="duplicate-header">
                                <div class="duplicate-title">⚠️ It has the same company name!</div>
                                <button type="button" class="hide-btn" onclick="hideDuplicate()">Hide</button>
                            </div>
                            <div style="color: #856404; margin-bottom: 10px;">
                                This company name already exists in the database. Here are the details:
                            </div>
                            <div class="client-details-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Company Name</div>
                                    <div class="detail-value">${escapeHtml(data.client.company_name)}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Classification</div>
                                    <div class="detail-value">${escapeHtml(data.client.classification)}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Main Signatory</div>
                                    <div class="detail-value">${escapeHtml(data.client.main_signatory)}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Signatory Position</div>
                                    <div class="detail-value">${escapeHtml(data.client.signatory_position || 'Not specified')}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Contact Number</div>
                                    <div class="detail-value">${escapeHtml(data.client.main_number)}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">TIN Number</div>
                                    <div class="detail-value">${escapeHtml(data.client.tin_number || 'Not specified')}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Email</div>
                                    <div class="detail-value">${escapeHtml(data.client.email || 'Not specified')}</div>
                                </div>
                                <div class="detail-item" style="grid-column: 1 / -1;">
                                    <div class="detail-label">Address</div>
                                    <div class="detail-value">${escapeHtml(data.client.main_address)}</div>
                                </div>
                            </div>
                            <div style="margin-top: 15px; font-style: italic; color: #6c757d; font-size: 14px;">
                                You can still proceed to add this as a new client.
                            </div>
                        </div>
                    `;
                    
                    duplicateContainer.innerHTML = duplicateHtml;
                } else {
                    // Clear duplicate section if no duplicate found
                    duplicateContainer.innerHTML = '';
                }
            })
            .catch(error => {
                console.error('Error checking duplicate:', error);
            });
        }
        
        function hideDuplicate() {
            const duplicateSection = document.getElementById('duplicateSection');
            if (duplicateSection) {
                duplicateSection.classList.add('hide');
                // Remove element after animation completes
                setTimeout(() => {
                    duplicateSection.remove();
                }, 300);
            }
        }
        
        // Helper function to escape HTML (prevent XSS)
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Form validation
        document.getElementById('clientForm').addEventListener('submit', function(e) {
            let isValid = true;
            const requiredFields = document.querySelectorAll('select[required], input[required], textarea[required]');
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.style.borderColor = '#e74c3c';
                    
                    if (field.tagName === 'SELECT') {
                        field.style.backgroundColor = '#fff8f8';
                    }
                } else {
                    field.style.borderColor = '#ddd';
                    if (field.tagName === 'SELECT') {
                        field.style.backgroundColor = 'white';
                    }
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields marked with *.');
            }
        });
        
        // Clear error styling when user interacts with fields
        const formFields = document.querySelectorAll('select, input, textarea');
        formFields.forEach(field => {
            field.addEventListener('input', function() {
                this.style.borderColor = '#ddd';
                if (this.tagName === 'SELECT') {
                    this.style.backgroundColor = 'white';
                }
            });
            
            field.addEventListener('change', function() {
                this.style.borderColor = '#ddd';
                if (this.tagName === 'SELECT') {
                    this.style.backgroundColor = 'white';
                }
            });
        });
        
        // Auto-format TIN number (optional)
        document.getElementById('tin_number')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 0) {
                value = value.match(/.{1,3}/g).join('-');
            }
            e.target.value = value;
        });
    </script>
</body>
</html>