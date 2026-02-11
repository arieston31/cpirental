<?php
require_once 'config.php';
session_start();

$contract_id = isset($_GET['contract_id']) ? intval($_GET['contract_id']) : 0;
$file_to_delete = isset($_GET['file']) ? $_GET['file'] : '';

if (!$contract_id || !$file_to_delete) {
    die("Invalid request.");
}

// Get current contract files
$query = $conn->query("SELECT contract_file FROM contracts WHERE id = $contract_id");
$contract = $query->fetch_assoc();

if ($contract) {
    $files = explode(',', $contract['contract_file']);
    $updated_files = array_diff($files, [$file_to_delete]);
    
    // Delete physical file
    if (file_exists($file_to_delete)) {
        unlink($file_to_delete);
    }
    
    // Update database
    $new_file_list = !empty($updated_files) ? implode(',', $updated_files) : NULL;
    $update_sql = "UPDATE contracts SET contract_file = " . ($new_file_list ? "'$new_file_list'" : "NULL") . " WHERE id = $contract_id";
    
    if ($conn->query($update_sql)) {
        header("Location: upload_contract_file.php?contract_id=$contract_id&success=deleted");
    } else {
        header("Location: upload_contract_file.php?contract_id=$contract_id&error=delete_failed");
    }
} else {
    header("Location: view_contracts.php");
}
exit();