<?php
require_once 'config.php';

$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$prefix = isset($_GET['prefix']) ? $_GET['prefix'] : 'G';

// Get sequence for this year and prefix
$seq_query = $conn->query("
    SELECT COUNT(*) as count 
    FROM rental_contracts 
    WHERE contract_number LIKE 'RCN-{$year}-{$prefix}%'
");
$seq_data = $seq_query->fetch_assoc();
$sequence = $seq_data['count'] + 1;

// Get overall count
$total_query = $conn->query("SELECT COUNT(*) as total FROM rental_contracts");
$total_data = $total_query->fetch_assoc();
$overall = $total_data['total'] + 1;

header('Content-Type: application/json');
echo json_encode([
    'sequence' => $sequence,
    'overall' => $overall
]);
?>