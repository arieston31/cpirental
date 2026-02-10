<?php
require_once 'config.php';;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = strtoupper(trim($_POST['company_name']));
    $classification = $_POST['classification'];
    $contact_number = $_POST['contact_number'];
    $email = $_POST['email'];
    $status = 'ACTIVE'; // Default status
    
    $stmt = $conn->prepare("INSERT INTO zoning_clients (company_name, classification, contact_number, email, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $company_name, $classification, $contact_number, $email, $status);
    
    if ($stmt->execute()) {
        $client_id = $stmt->insert_id;
        echo json_encode(['success' => true, 'client_id' => $client_id]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Client</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; }
        .container { background: #f5f5f5; padding: 20px; border-radius: 5px; }
        h2 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #45a049; }
        .message { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .required { color: #dc3545; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Add New Client</h2>
        <form id="clientForm">
            <div class="form-group">
                <label for="company_name">Company Name <span class="required">*</span></label>
                <input type="text" id="company_name" name="company_name" required>
            </div>
            <div class="form-group">
                <label for="classification">Classification <span class="required">*</span></label>
                <select id="classification" name="classification" required>
                    <option value="">Select Classification</option>
                    <option value="GOVERNMENT">GOVERNMENT</option>
                    <option value="PRIVATE">PRIVATE</option>
                </select>
            </div>
            <div class="form-group">
                <label for="contact_number">Contact Number <span class="required">*</span></label>
                <input type="text" id="contact_number" name="contact_number" required>
            </div>
            <div class="form-group">
                <label for="email">Email <span class="required">*</span></label>
                <input type="email" id="email" name="email" required>
            </div>
            <button type="submit">Add Client</button>
        </form>
        <div id="message" class="message"></div>
    </div>
    
    <script>
    document.getElementById('clientForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        fetch('add_client.php', {
            method: 'POST',
            body: new FormData(this)
        })
        .then(response => response.json())
        .then(data => {
            const messageDiv = document.getElementById('message');
            if (data.success) {
                messageDiv.className = 'message success';
                messageDiv.textContent = 'Client added successfully! Client ID: ' + data.client_id;
                document.getElementById('clientForm').reset();
                
                // Redirect to add machine after 2 seconds
                setTimeout(() => {
                    window.location.href = 'add_machine.php?client_id=' + data.client_id;
                }, 2000);
            } else {
                messageDiv.className = 'message error';
                messageDiv.textContent = 'Error: ' + data.error;
            }
        });
    });
    </script>
</body>
</html>