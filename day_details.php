<?php
require_once 'config.php';
// Function to get day name
function getDayName($dayOfWeek) {
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return $days[$dayOfWeek];
}

// Function to adjust date for weekends
function adjustForWeekend($date, $year, $month) {
    $dateObj = DateTime::createFromFormat('Y-m-d', "$year-$month-$date");
    $dayOfWeek = (int)$dateObj->format('w');
    
    if ($dayOfWeek == 6) {
        $dateObj->modify('-1 day');
        return (int)$dateObj->format('j');
    }
    
    if ($dayOfWeek == 0) {
        $dateObj->modify('+1 day');
        return (int)$dateObj->format('j');
    }
    
    return $date;
}

// Function to get ordinal suffix
function getOrdinal($number) {
    if ($number % 100 >= 11 && $number % 100 <= 13) {
        return $number . 'th';
    }
    
    switch ($number % 10) {
        case 1: return $number . 'st';
        case 2: return $number . 'nd';
        case 3: return $number . 'rd';
        default: return $number . 'th';
    }
}

// Get parameters
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$day = isset($_GET['day']) ? intval($_GET['day']) : date('j');
$type = $_GET['type'] ?? 'all'; // 'reading', 'collection', or 'all'

// Validate
if ($day < 1 || $day > 31) {
    die("Invalid day specified");
}

// Get month name
$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));
$dayOfWeek = date('w', strtotime("$year-$month-$day"));
$dayName = getDayName($dayOfWeek);
$isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);
$ordinalDay = getOrdinal($day);

// Determine which services to show based on adjusted dates
$readingServices = [];
$collectionServices = [];
$adjustedNotes = [];

// Get all active machines
$query = "
    SELECT m.*, 
           c.company_name,
           c.classification,
           c.contact_number,
           c.email,
           z.zone_number,
           z.area_center,
           z.id as zone_id
    FROM zoning_machine m
    JOIN zoning_clients c ON m.client_id = c.id
    JOIN zoning_zone z ON m.zone_id = z.id
    WHERE m.status = 'ACTIVE'
    AND c.status = 'ACTIVE'
    ORDER BY m.reading_date, m.zone_id, c.company_name
";

$machinesQuery = $conn->query($query);

while ($machine = $machinesQuery->fetch_assoc()) {
    // Check reading date
    $originalReadingDate = $machine['reading_date'];
    $adjustedReadingDate = adjustForWeekend($originalReadingDate, $year, $month);
    
    // Check collection date
    $originalCollectionDate = $machine['collection_date'];
    $adjustedCollectionDate = adjustForWeekend($originalCollectionDate, $year, $month);
    
    // If this day matches adjusted reading date
    if ($adjustedReadingDate == $day) {
        $machine['service_type'] = 'reading';
        $machine['original_date'] = $originalReadingDate;
        $machine['adjusted_date'] = $adjustedReadingDate;
        $machine['was_adjusted'] = ($originalReadingDate != $adjustedReadingDate);
        
        if ($machine['was_adjusted']) {
            $originalDayName = getDayName(date('w', strtotime("$year-$month-$originalReadingDate")));
            $machine['adjustment_note'] = "Moved from Day $originalReadingDate ($originalDayName)";
        }
        
        $readingServices[] = $machine;
    }
    
    // If this day matches adjusted collection date
    if ($adjustedCollectionDate == $day) {
        $machine['service_type'] = 'collection';
        $machine['original_date'] = $originalCollectionDate;
        $machine['adjusted_date'] = $adjustedCollectionDate;
        $machine['was_adjusted'] = ($originalCollectionDate != $adjustedCollectionDate);
        
        if ($machine['was_adjusted']) {
            $originalDayName = getDayName(date('w', strtotime("$year-$month-$originalCollectionDate")));
            $machine['adjustment_note'] = "Moved from Day $originalCollectionDate ($originalDayName)";
        }
        
        $collectionServices[] = $machine;
    }
}

// Count totals
$totalReadings = count($readingServices);
$totalCollections = count($collectionServices);
$totalServices = $totalReadings + $totalCollections;

// Determine page title based on type filter
if ($type == 'reading') {
    $pageTitle = "Reading Services - $monthName $day, $year";
    $servicesToShow = $readingServices;
} elseif ($type == 'collection') {
    $pageTitle = "Collection Services - $monthName $day, $year";
    $servicesToShow = $collectionServices;
} else {
    $pageTitle = "All Services - $monthName $day, $year";
    $servicesToShow = array_merge($readingServices, $collectionServices);
    // Sort by service type then zone
    usort($servicesToShow, function($a, $b) {
        if ($a['service_type'] == $b['service_type']) {
            return $a['zone_number'] <=> $b['zone_number'];
        }
        return strcmp($a['service_type'], $b['service_type']);
    });
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f7fa;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .header h1 {
            margin: 0;
            font-size: 2.5em;
        }
        
        .header p {
            margin: 10px 0 0;
            font-size: 1.2em;
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-card h3 {
            margin: 0 0 10px;
            color: #666;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            color: #667eea;
            margin: 10px 0;
        }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 10px 20px;
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            color: #495057;
            text-decoration: none;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .filter-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        
        .filter-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .services-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .services-container h2 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        
        .service-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .service-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: bold;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        
        .service-table td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        
        .service-table tr:hover {
            background: #f8f9fa;
        }
        
        .service-type {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .type-reading {
            background: #e8f5e9;
            color: #2E7D32;
        }
        
        .type-collection {
            background: #e3f2fd;
            color: #1976D2;
        }
        
        .zone-badge {
            display: inline-block;
            padding: 5px 12px;
            background: #e3f2fd;
            color: #1976d2;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9em;
        }
        
        .client-name {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .client-details {
            font-size: 0.9em;
            color: #666;
            margin: 5px 0;
        }
        
        .machine-details {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin-top: 10px;
            border-left: 3px solid #667eea;
        }
        
        .machine-number {
            font-weight: bold;
            color: #333;
        }
        
        .address {
            color: #666;
            font-size: 0.9em;
            margin: 5px 0;
        }
        
        .adjustment-note {
            background: #fff3cd;
            color: #856404;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 10px;
            border-left: 4px solid #ffc107;
            font-size: 0.9em;
        }
        
        .no-services {
            text-align: center;
            padding: 50px 20px;
            color: #666;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px dashed #dee2e6;
        }
        
        .actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #4CAF50 0%, #2E7D32 100%);
        }
        
        .day-header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-left: 5px solid #667eea;
        }
        
        .day-header h2 {
            margin: 0;
            color: #333;
        }
        
        .day-header p {
            margin: 10px 0 0;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .service-table {
                display: block;
                overflow-x: auto;
            }
            
            .filter-buttons {
                flex-direction: column;
            }
            
            .filter-btn {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📅 Service Details</h1>
            <p><?php echo $pageTitle; ?></p>
        </div>
        
        <div class="day-header">
            <h2><?php echo $dayName; ?>, <?php echo $monthName . ' ' . $ordinalDay . ', ' . $year; ?></h2>
            <p>
                <?php if ($isWeekend): ?>
                    <span style="color: #FF9800; font-weight: bold;">⚠️ Weekend Day - Services shown are adjusted from weekend dates</span>
                <?php else: ?>
                    <span style="color: #4CAF50;">✓ Weekday</span>
                <?php endif; ?>
            </p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Total Services</h3>
                <div class="stat-number"><?php echo $totalServices; ?></div>
                <p>All services on this day</p>
            </div>
            
            <div class="stat-card">
                <h3>Reading Services</h3>
                <div class="stat-number"><?php echo $totalReadings; ?></div>
                <p>Machine reading services</p>
            </div>
            
            <div class="stat-card">
                <h3>Collection Services</h3>
                <div class="stat-number"><?php echo $totalCollections; ?></div>
                <p>Collection services</p>
            </div>
            
            <div class="stat-card">
                <h3>Day of Month</h3>
                <div class="stat-number"><?php echo $day; ?></div>
                <p><?php echo $ordinalDay; ?> day of <?php echo $monthName; ?></p>
            </div>
        </div>
        
        <div class="filters">
            <h3>Filter Services:</h3>
            <div class="filter-buttons">
                <a href="day_details.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>&day=<?php echo $day; ?>&type=all" 
                   class="filter-btn <?php echo $type == 'all' ? 'active' : ''; ?>">
                   All Services (<?php echo $totalServices; ?>)
                </a>
                <a href="day_details.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>&day=<?php echo $day; ?>&type=reading" 
                   class="filter-btn <?php echo $type == 'reading' ? 'active' : ''; ?>">
                   📅 Readings (<?php echo $totalReadings; ?>)
                </a>
                <a href="day_details.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>&day=<?php echo $day; ?>&type=collection" 
                   class="filter-btn <?php echo $type == 'collection' ? 'active' : ''; ?>">
                   📦 Collections (<?php echo $totalCollections; ?>)
                </a>
            </div>
        </div>
        
        <div class="services-container">
            <h2>
                <?php if ($type == 'reading'): ?>
                    📅 Reading Services
                <?php elseif ($type == 'collection'): ?>
                    📦 Collection Services
                <?php else: ?>
                    📋 All Services
                <?php endif; ?>
            </h2>
            
            <?php if (empty($servicesToShow)): ?>
                <div class="no-services">
                    <h3>No services scheduled for this day</h3>
                    <p>There are no <?php echo $type == 'all' ? '' : $type; ?> services scheduled for <?php echo $monthName . ' ' . $ordinalDay . ', ' . $year; ?>.</p>
                    <p><a href="calendar.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn">← Back to Calendar</a></p>
                </div>
            <?php else: ?>
                <table class="service-table">
                    <thead>
                        <tr>
                            <?php if ($type == 'all'): ?>
                                <th width="100">Type</th>
                            <?php endif; ?>
                            <th width="80">Zone</th>
                            <th>Client & Machine Details</th>
                            <th width="150">Schedule Info</th>
                            <th width="100">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($servicesToShow as $service): ?>
                        <tr>
                            <?php if ($type == 'all'): ?>
                                <td>
                                    <span class="service-type type-<?php echo $service['service_type']; ?>">
                                        <?php echo $service['service_type']; ?>
                                    </span>
                                </td>
                            <?php endif; ?>
                            <td>
                                <div class="zone-badge">Zone <?php echo $service['zone_number']; ?></div>
                                <div style="font-size: 0.8em; color: #666; margin-top: 5px;">
                                    <?php echo $service['area_center']; ?>
                                </div>
                            </td>
                            <td>
                                <div class="client-name"><?php echo htmlspecialchars($service['company_name']); ?></div>
                                <div class="client-details">
                                    <strong>Classification:</strong> 
                                    <span style="color: <?php echo $service['classification'] == 'GOVERNMENT' ? '#2e7d32' : '#1565c0'; ?>;">
                                        <?php echo $service['classification']; ?>
                                    </span>
                                </div>
                                <div class="client-details">
                                    <strong>Contact:</strong> <?php echo htmlspecialchars($service['contact_number']); ?> | 
                                    <strong>Email:</strong> <?php echo htmlspecialchars($service['email']); ?>
                                </div>
                                
                                <div class="machine-details">
                                    <div class="machine-number">Machine #<?php echo htmlspecialchars($service['machine_number']); ?></div>
                                    <div class="address">
                                        <strong>Address:</strong> 
                                        <?php echo htmlspecialchars($service['street_number'] . ' ' . $service['street_name']); ?><br>
                                        <?php echo htmlspecialchars($service['barangay'] . ', ' . $service['city']); ?>
                                    </div>
                                    <?php if (!empty($service['department'])): ?>
                                        <div class="client-details">
                                            <strong>Department:</strong> <?php echo htmlspecialchars($service['department']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($service['was_adjusted']): ?>
                                    <div class="adjustment-note">
                                        ⚠️ <strong>Adjusted Service:</strong> <?php echo $service['adjustment_note']; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size: 0.9em;">
                                    <?php if ($service['service_type'] == 'reading'): ?>
                                        <strong>Reading Date:</strong><br>
                                        Original: Day <?php echo $service['original_date']; ?><br>
                                        <?php if ($service['was_adjusted']): ?>
                                            <span style="color: #FF9800; font-weight: bold;">
                                                Adjusted: Day <?php echo $service['adjusted_date']; ?>
                                            </span>
                                        <?php endif; ?>
                                        <br><br>
                                        <strong>Collection Date:</strong><br>
                                        Day <?php echo $service['collection_date']; ?>
                                    <?php else: ?>
                                        <strong>Collection Date:</strong><br>
                                        Original: Day <?php echo $service['original_date']; ?><br>
                                        <?php if ($service['was_adjusted']): ?>
                                            <span style="color: #FF9800; font-weight: bold;">
                                                Adjusted: Day <?php echo $service['adjusted_date']; ?>
                                            </span>
                                        <?php endif; ?>
                                        <br><br>
                                        <strong>Reading Date:</strong><br>
                                        Day <?php echo $service['reading_date']; ?>
                                    <?php endif; ?>
                                    <br><br>
                                    <strong>Processing:</strong> <?php echo $service['processing_period']; ?> days
                                </div>
                            </td>
                            <td>
                                <a href="edit_machine.php?id=<?php echo $service['id']; ?>" 
                                   class="filter-btn" 
                                   style="display: block; margin-bottom: 5px; text-align: center;">
                                   ✏️ Edit
                                </a>
                                <a href="view_machines.php" 
                                   class="filter-btn" 
                                   style="display: block; margin-bottom: 5px; text-align: center;">
                                   👁️ View All
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <div class="actions">
            <a href="calendar.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn">← Back to Calendar</a>
            <a href="dashboard.php" class="btn btn-secondary">📊 Dashboard</a>
        </div>
    </div>
    
    <script>
    // Auto-scroll to top when page loads
    document.addEventListener('DOMContentLoaded', function() {
        window.scrollTo(0, 0);
    });
    </script>
</body>
</html>