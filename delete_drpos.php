<?php
require_once 'config.php';
session_start();

$machine_id = isset($_GET['machine_id']) ? intval($_GET['machine_id']) : 0;
$file_to_delete = isset($_GET['file']) ? $_GET['file'] : '';

if (!$machine_id || !$file_to_delete) {
    die("Invalid request.");
}

// Get current machine files
$query = $conn->query("SELECT dr_pos_files FROM contract_machines WHERE id = $machine_id");
$machine = $query->fetch_assoc();

if ($machine) {
    $files = !empty($machine['dr_pos_files']) ? explode(',', $machine['dr_pos_files']) : [];
    $updated_files = array_diff($files, [$file_to_delete]);
    
    // Delete physical file
    if (file_exists($file_to_delete)) {
        unlink($file_to_delete);
    }
    
    // Update database
    $new_file_list = !empty($updated_files) ? implode(',', $updated_files) : NULL;
    $file_count = count($updated_files);
    
    $update_sql = "UPDATE contract_machines SET 
                   dr_pos_files = " . ($new_file_list ? "'$new_file_list'" : "NULL") . ",
                   dr_pos_file_count = $file_count 
                   WHERE id = $machine_id";
    
    if ($conn->query($update_sql)) {
        header("Location: upload_drpos.php?machine_id=$machine_id&success=deleted");
    } else {
        header("Location: upload_drpos.php?machine_id=$machine_id&error=delete_failed");
    }
} else {
    header("Location: view_machines.php");
}
exit();