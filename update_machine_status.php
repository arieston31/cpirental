<?php
require_once 'config.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? intval($data['id']) : 0;
$status = isset($data['status']) ? $conn->real_escape_string($data['status']) : '';

if (!$id || !$status) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

$conn->begin_transaction();

try {
    // Update machine status
    $sql = "UPDATE contract_machines SET status = '$status', updated_at = NOW() WHERE id = $id";
    $conn->query($sql);
    
    // Get contract_id and client classification
    $machine_query = $conn->query("
        SELECT cm.contract_id, cl.classification 
        FROM contract_machines cm
        JOIN contracts c ON cm.contract_id = c.id
        JOIN clients cl ON c.client_id = cl.id
        WHERE cm.id = $id
    ");
    $machine = $machine_query->fetch_assoc();
    
    // If PRIVATE client, recalculate collection date
    if ($machine['classification'] == 'PRIVATE') {
        // Get highest reading date from active machines
        $highest_query = $conn->query("
            SELECT MAX(reading_date) as max_date 
            FROM contract_machines 
            WHERE contract_id = {$machine['contract_id']} AND status = 'ACTIVE'
        ");
        $highest = $highest_query->fetch_assoc();
        $highest_reading_date = $highest['max_date'] ?: 0;
        
        // Get contract processing period
        $contract_query = $conn->query("
            SELECT collection_processing_period 
            FROM contracts 
            WHERE id = {$machine['contract_id']}
        ");
        $contract = $contract_query->fetch_assoc();
        $processing_period = $contract['collection_processing_period'];
        
        // Calculate collection date
        $collection_date = $highest_reading_date + $processing_period;
        if ($collection_date > 31) {
            $collection_date -= 31;
        }
        
        // Update contract
        $conn->query("
            UPDATE contracts 
            SET collection_date = $collection_date 
            WHERE id = {$machine['contract_id']}
        ");
    }
    
    $conn->commit();
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>