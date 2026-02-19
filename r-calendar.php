<?php
require_once 'config.php';

// Function to adjust date for weekends and return adjusted date
function adjustForWeekend($date, $year, $month) {
    // Create a DateTime object for the date
    $dateObj = DateTime::createFromFormat('Y-m-d', "$year-$month-$date");
    
    // Get day of week (0=Sunday, 6=Saturday)
    $dayOfWeek = (int)$dateObj->format('w');
    
    // If Saturday, move to Friday
    if ($dayOfWeek == 6) {
        $dateObj->modify('-1 day');
        return (int)$dateObj->format('j');
    }
    
    // If Sunday, move to Monday
    if ($dayOfWeek == 0) {
        $dateObj->modify('+1 day');
        return (int)$dateObj->format('j');
    }
    
    // Weekday, no adjustment needed
    return $date;
}

// Function to get original day of week
function getOriginalDayOfWeek($date, $year, $month) {
    $dateObj = DateTime::createFromFormat('Y-m-d', "$year-$month-$date");
    return (int)$dateObj->format('w');
}

// Function to get day name
function getDayName($dayOfWeek) {
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return $days[$dayOfWeek];
}

// Function to check if a date is weekend
function isWeekend($date, $year, $month) {
    $dateObj = DateTime::createFromFormat('Y-m-d', "$year-$month-$date");
    $dayOfWeek = (int)$dateObj->format('w');
    return $dayOfWeek == 0 || $dayOfWeek == 6;
}

// Get month and year from URL or use current
$month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Validate month
if ($month < 1) {
    $month = 12;
    $year--;
} elseif ($month > 12) {
    $month = 1;
    $year++;
}

// Calculate next and previous months
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Get month name
$monthName = date('F', mktime(0, 0, 0, $month, 1, $year));
$daysInMonth = date('t', mktime(0, 0, 0, $month, 1, $year));

// Get all active machines with their reading dates for the selected month
$machinesQuery = $conn->query("
    SELECT 
        cm.*,
        c.contract_number,
        cl.company_name,
        cl.classification,
        cl.main_number as contact_number,
        cl.email,
        z.zone_number,
        z.area_center,
        z.reading_date as zone_reading_date,
        c.collection_processing_period
    FROM rental_contract_machines cm
    JOIN rental_contracts c ON cm.contract_id = c.id
    JOIN rental_clients cl ON cm.client_id = cl.id
    JOIN rental_zoning_zones z ON cm.zone_id = z.id
    WHERE cm.status = 'ACTIVE'
    AND c.status = 'ACTIVE'
    AND cl.status = 'ACTIVE'
    ORDER BY cm.reading_date, z.zone_number
");

$machines = [];
$readingCounts = array_fill(1, $daysInMonth, 0);
$collectionCounts = array_fill(1, $daysInMonth, 0);
$zoneReadingCounts = [];
$adjustmentNotes = [];

while ($machine = $machinesQuery->fetch_assoc()) {
    $machines[] = $machine;
    
    // Get reading date
    $originalReadingDate = $machine['reading_date'];
    $originalReadingDayOfWeek = getOriginalDayOfWeek($originalReadingDate, $year, $month);
    
    // Adjust reading date for weekends
    $adjustedReadingDate = adjustForWeekend($originalReadingDate, $year, $month);
    
    // Only count if adjusted date is within current month
    if ($adjustedReadingDate >= 1 && $adjustedReadingDate <= $daysInMonth) {
        $readingCounts[$adjustedReadingDate]++;
        
        // Store adjustment note if date was moved
        if ($originalReadingDate != $adjustedReadingDate) {
            $originalDayName = getDayName($originalReadingDayOfWeek);
            
            if (!isset($adjustmentNotes[$adjustedReadingDate])) {
                $adjustmentNotes[$adjustedReadingDate] = [];
            }
            
            $adjustmentNotes[$adjustedReadingDate][] = [
                'type' => 'reading',
                'original_date' => $originalReadingDate,
                'original_day' => $originalDayName,
                'reason' => ($originalReadingDayOfWeek == 6) ? 'Saturday → Friday' : 'Sunday → Monday',
                'machine_number' => $machine['machine_number'],
                'company_name' => $machine['company_name']
            ];
        }
    }
    
    // Calculate collection date based on reading date + processing period
    $processing_period = $machine['collection_processing_period'];
    $originalCollectionDate = $originalReadingDate + $processing_period;
    if ($originalCollectionDate > 31) {
        $originalCollectionDate -= 31;
    }
    
    $originalCollectionDayOfWeek = getOriginalDayOfWeek($originalCollectionDate, $year, $month);
    
    // Adjust collection date for weekends
    $adjustedCollectionDate = adjustForWeekend($originalCollectionDate, $year, $month);
    
    // Only count if adjusted date is within current month
    if ($adjustedCollectionDate >= 1 && $adjustedCollectionDate <= $daysInMonth) {
        $collectionCounts[$adjustedCollectionDate]++;
        
        // Store adjustment note if date was moved
        if ($originalCollectionDate != $adjustedCollectionDate) {
            $originalDayName = getDayName($originalCollectionDayOfWeek);
            
            if (!isset($adjustmentNotes[$adjustedCollectionDate])) {
                $adjustmentNotes[$adjustedCollectionDate] = [];
            }
            
            $adjustmentNotes[$adjustedCollectionDate][] = [
                'type' => 'collection',
                'original_date' => $originalCollectionDate,
                'original_day' => $originalDayName,
                'reason' => ($originalCollectionDayOfWeek == 6) ? 'Saturday → Friday' : 'Sunday → Monday',
                'machine_number' => $machine['machine_number'],
                'company_name' => $machine['company_name']
            ];
        }
    }
    
    // Count by zone (use adjusted reading date)
    $zoneId = $machine['zone_id'];
    if (!isset($zoneReadingCounts[$zoneId])) {
        $zoneReadingCounts[$zoneId] = [
            'zone_number' => $machine['zone_number'],
            'area_center' => $machine['area_center'],
            'reading_date' => $machine['zone_reading_date'],
            'count' => 0,
            'original_reading_date' => $originalReadingDate,
            'adjusted_reading_date' => $adjustedReadingDate,
            'was_adjusted' => ($originalReadingDate != $adjustedReadingDate)
        ];
    }
    $zoneReadingCounts[$zoneId]['count']++;
}

// Calculate statistics for selected month
$totalMachines = count($machines);
$totalReadings = array_sum($readingCounts);
$totalCollections = array_sum($collectionCounts);

// Count adjusted services
$adjustedReadings = 0;
$adjustedCollections = 0;
foreach ($adjustmentNotes as $day => $notes) {
    foreach ($notes as $note) {
        if ($note['type'] == 'reading') $adjustedReadings++;
        if ($note['type'] == 'collection') $adjustedCollections++;
    }
}

// Get alignment statistics
$alignment_stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN reading_date_remarks = 'aligned reading date' THEN 1 ELSE 0 END) as aligned,
        SUM(CASE WHEN reading_date_remarks = 'mis-aligned reading date' THEN 1 ELSE 0 END) as misaligned
    FROM rental_contract_machines
    WHERE status = 'ACTIVE'
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Calendar - <?php echo $monthName . ' ' . $year; ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background: #f5f7fa;
        }
        
        .container {
            max-width: auto;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-content h1 {
            margin: 0;
            font-size: 2.5em;
        }
        
        .header-content p {
            margin: 10px 0 0;
            font-size: 1.2em;
            opacity: 0.9;
        }
        
        .year-nav {
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
        }
        
        .year-nav a {
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .year-nav a:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-card h3 {
            margin: 0 0 10px;
            color: #666;
            font-size: 1em;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-number {
            font-size: 3em;
            font-weight: bold;
            color: #667eea;
            margin: 10px 0;
        }
        
        .calendar-container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .month-year {
            font-size: 2em;
            font-weight: bold;
            color: #333;
        }
        
        .month-navigation {
            display: flex;
            gap: 10px;
        }
        
        .nav-btn {
            padding: 10px 20px;
            background: white;
            border: 2px solid #667eea;
            color: #667eea;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        .nav-btn:hover {
            background: #667eea;
            color: white;
            text-decoration: none;
        }
        
        .legend {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }
        
        .reading-color { background: linear-gradient(135deg, #4CAF50, #2E7D32); }
        .collection-color { background: linear-gradient(135deg, #2196F3, #1976D2); }
        .weekend-color { background: linear-gradient(135deg, #FF9800, #F57C00); }
        .adjusted-color { background: linear-gradient(135deg, #9C27B0, #7B1FA2); }
        .aligned-color { background: linear-gradient(135deg, #27ae60, #229954); }
        .misaligned-color { background: linear-gradient(135deg, #e67e22, #d35400); }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 10px;
        }
        
        .day-header {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            font-weight: bold;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .day-cell {
            min-height: 120px;
            padding: 15px;
            border: 2px solid #f0f0f0;
            border-radius: 10px;
            background: white;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .day-cell:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        
        .day-number {
            font-size: 1.5em;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .weekend {
            background: #fff8e1;
            border-color: #ffecb3;
        }
        
        .today {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-color: #2196F3;
        }
        
        .today .day-number {
            color: #1976D2;
        }
        
        .event-count {
            margin: 8px 0;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.9em;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .reading-count {
            background: #e8f5e9;
            color: #2E7D32;
            border-left: 4px solid #4CAF50;
        }
        
        .collection-count {
            background: #e3f2fd;
            color: #1976D2;
            border-left: 4px solid #2196F3;
        }
        
        .adjusted-count {
            background: #f3e5f5;
            color: #7B1FA2;
            border-left: 4px solid #9C27B0;
        }
        
        .count-number {
            font-size: 1.2em;
            font-weight: bold;
        }
        
        .empty-cell {
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
        }
        
        .zone-breakdown {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .zone-breakdown h2 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        
        .zone-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .zone-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            border-left: 5px solid #667eea;
        }
        
        .zone-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .zone-number {
            font-size: 1.5em;
            font-weight: bold;
            color: #667eea;
        }
        
        .zone-count {
            background: #667eea;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
        }
        
        .zone-area {
            color: #666;
            font-size: 0.9em;
            margin-bottom: 10px;
        }
        
        .reading-date-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: bold;
        }
        
        .aligned {
            background: #d4edda;
            color: #155724;
        }
        
        .misaligned {
            background: #fff3cd;
            color: #856404;
        }
        
        .weekend-note {
            background: #fff8e1;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            border-left: 4px solid #FF9800;
            color: #5d4037;
        }
        
        .weekend-note strong {
            color: #e65100;
        }
        
        .adjusted-note {
            font-size: 0.8em;
            color: #9C27B0;
            background: #f3e5f5;
            padding: 3px 6px;
            border-radius: 4px;
            margin-top: 5px;
            border-left: 2px solid #9C27B0;
        }
        
        .adjusted-badge {
            display: inline-block;
            background: #9C27B0;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7em;
            margin-left: 5px;
            vertical-align: super;
        }
        
        .no-services {
            color: #999;
            font-style: italic;
            text-align: center;
            padding: 20px 0;
        }
        
        .actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            padding: 12px 25px;
            background: linear-gradient(135deg, #667eea, #764ba2);
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
            background: linear-gradient(135deg, #4CAF50, #2E7D32);
        }
        
        .btn-info {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }
        
        .machine-count-tooltip {
            font-size: 0.8em;
            color: #666;
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            
            .calendar-grid {
                grid-template-columns: repeat(1, 1fr);
            }
            
            .day-header {
                display: none;
            }
            
            .zone-grid {
                grid-template-columns: repeat(1, 1fr);
            }
            
            .stats-grid {
                grid-template-columns: repeat(1, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <h1>📅 Service Calendar</h1>
                <p>Reading and Collection Schedule for <?php echo $monthName . ' ' . $year; ?></p>
            </div>
            <div class="year-nav">
                <a href="r-calendar.php?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>">← <?php echo date('M', mktime(0, 0, 0, $prevMonth, 1, $prevYear)); ?></a>
                <a href="r-calendar.php">Current Month</a>
                <a href="r-calendar.php?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>"><?php echo date('M', mktime(0, 0, 0, $nextMonth, 1, $nextYear)); ?> →</a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Active Machines</h3>
                <div class="stat-number"><?php echo $totalMachines; ?></div>
                <p>Total active machines</p>
            </div>
            
            <div class="stat-card">
                <h3>This Month's Readings</h3>
                <div class="stat-number"><?php echo $totalReadings; ?></div>
                <p>Readings in <?php echo $monthName; ?></p>
                <?php if ($adjustedReadings > 0): ?>
                    <small style="color: #9C27B0;">(<?php echo $adjustedReadings; ?> adjusted for weekends)</small>
                <?php endif; ?>
            </div>
            
            <div class="stat-card">
                <h3>This Month's Collections</h3>
                <div class="stat-number"><?php echo $totalCollections; ?></div>
                <p>Collections in <?php echo $monthName; ?></p>
                <?php if ($adjustedCollections > 0): ?>
                    <small style="color: #9C27B0;">(<?php echo $adjustedCollections; ?> adjusted for weekends)</small>
                <?php endif; ?>
            </div>
            
            <div class="stat-card">
                <h3>Alignment Rate</h3>
                <div class="stat-number">
                    <?php echo $alignment_stats['total'] > 0 ? round(($alignment_stats['aligned'] / $alignment_stats['total']) * 100, 1) : 0; ?>%
                </div>
                <p>
                    <span style="color: #27ae60;">✓ <?php echo $alignment_stats['aligned']; ?> Aligned</span> | 
                    <span style="color: #e67e22;">⚠️ <?php echo $alignment_stats['misaligned']; ?> Misaligned</span>
                </p>
            </div>
        </div>
        
        <div class="calendar-container">
            <div class="calendar-header">
                <div class="month-year"><?php echo $monthName . ' ' . $year; ?></div>
                <div class="month-navigation">
                    <a href="r-calendar.php?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="nav-btn">← <?php echo date('M', mktime(0, 0, 0, $prevMonth, 1, $prevYear)); ?></a>
                    <a href="r-calendar.php" class="nav-btn">Current Month</a>
                    <a href="r-calendar.php?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="nav-btn"><?php echo date('M', mktime(0, 0, 0, $nextMonth, 1, $nextYear)); ?> →</a>
                </div>
            </div>
            
            <div class="legend">
                <div class="legend-item">
                    <div class="legend-color reading-color"></div>
                    <span>Reading Days</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color collection-color"></div>
                    <span>Collection Days</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color adjusted-color"></div>
                    <span>Adjusted from Weekend</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color weekend-color"></div>
                    <span>Weekend (No Original Services)</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color aligned-color"></div>
                    <span>Aligned Reading Dates</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color misaligned-color"></div>
                    <span>Misaligned Reading Dates</span>
                </div>
            </div>
            
            <div class="weekend-note">
                <strong>Weekend Adjustment Rule:</strong> 
                Saturday services move to Friday. Sunday services move to Monday. 
                Adjusted services are shown on the weekday with a purple indicator.
            </div>
            
            <div class="calendar-grid">
                <!-- Day headers -->
                <div class="day-header">Sunday</div>
                <div class="day-header">Monday</div>
                <div class="day-header">Tuesday</div>
                <div class="day-header">Wednesday</div>
                <div class="day-header">Thursday</div>
                <div class="day-header">Friday</div>
                <div class="day-header">Saturday</div>
                
                <!-- Empty cells for days before the 1st -->
                <?php 
                $firstDayOfMonth = date('w', strtotime("$year-$month-01"));
                for ($i = 0; $i < $firstDayOfMonth; $i++): ?>
                    <div class="day-cell empty-cell"></div>
                <?php endfor; ?>
                
                <!-- Day cells -->
                <?php for ($day = 1; $day <= $daysInMonth; $day++): 
                    $isWeekend = isWeekend($day, $year, $month);
                    $isToday = ($day == date('j') && $month == date('n') && $year == date('Y'));
                    $dayOfWeek = date('w', strtotime("$year-$month-$day"));
                    $dayName = getDayName($dayOfWeek);
                    
                    // Get counts for this day
                    $readingCount = $readingCounts[$day] ?? 0;
                    $collectionCount = $collectionCounts[$day] ?? 0;
                    
                    // Check if this day has adjusted services
                    $hasAdjustedServices = isset($adjustmentNotes[$day]);
                    $adjustedNotes = $hasAdjustedServices ? $adjustmentNotes[$day] : [];
                    
                    // Check if there are any services on this day
                    $hasServices = ($readingCount > 0 || $collectionCount > 0);
                ?>
                    <div class="day-cell <?php echo $isWeekend ? 'weekend' : ''; ?> <?php echo $isToday ? 'today' : ''; ?>">
                        <div class="day-number">
                            <span>
                                <?php if ($hasServices): ?>
                                    <a href="r-day_details.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>&day=<?php echo $day; ?>" 
                                       style="color: inherit; text-decoration: none;">
                                        <?php echo $day; ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo $day; ?>
                                <?php endif; ?>
                            </span>
                            <span style="font-size: 0.8em; color: #666;"><?php echo substr($dayName, 0, 3); ?></span>
                            <?php if ($isWeekend): ?>
                                <span class="weekend-indicator" title="Weekend"></span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($readingCount > 0): ?>
                            <div class="event-count <?php echo $hasAdjustedServices && array_filter($adjustedNotes, function($n) { return $n['type'] == 'reading'; }) ? 'adjusted-count' : 'reading-count'; ?>">
                                <span>
                                    <?php if ($hasServices): ?>
                                        <a href="r-day_details.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>&day=<?php echo $day; ?>&type=reading" 
                                           style="color: inherit; text-decoration: none;">
                                            📅 Readings 
                                        </a>
                                    <?php else: ?>
                                        📅 Readings
                                    <?php endif; ?>
                                    <?php if ($hasAdjustedServices && array_filter($adjustedNotes, function($n) { return $n['type'] == 'reading'; })): ?>
                                        <span class="adjusted-badge">Adjusted</span>
                                    <?php endif; ?>
                                </span>
                                <span class="count-number">
                                    <?php if ($hasServices): ?>
                                        <a href="r-day_details.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>&day=<?php echo $day; ?>&type=reading" 
                                           style="color: inherit; text-decoration: none;">
                                            <?php echo $readingCount; ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo $readingCount; ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($collectionCount > 0): ?>
                            <div class="event-count <?php echo $hasAdjustedServices && array_filter($adjustedNotes, function($n) { return $n['type'] == 'collection'; }) ? 'adjusted-count' : 'collection-count'; ?>">
                                <span>
                                    <?php if ($hasServices): ?>
                                        <a href="r-day_details.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>&day=<?php echo $day; ?>&type=collection" 
                                           style="color: inherit; text-decoration: none;">
                                            📦 Collections 
                                        </a>
                                    <?php else: ?>
                                        📦 Collections
                                    <?php endif; ?>
                                    <?php if ($hasAdjustedServices && array_filter($adjustedNotes, function($n) { return $n['type'] == 'collection'; })): ?>
                                        <span class="adjusted-badge">Adjusted</span>
                                    <?php endif; ?>
                                </span>
                                <span class="count-number">
                                    <?php if ($hasServices): ?>
                                        <a href="r-day_details.php?year=<?php echo $year; ?>&month=<?php echo $month; ?>&day=<?php echo $day; ?>&type=collection" 
                                           style="color: inherit; text-decoration: none;">
                                            <?php echo $collectionCount; ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo $collectionCount; ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($hasAdjustedServices): ?>
                            <?php foreach (array_slice($adjustedNotes, 0, 1) as $note): ?>
                                <div class="adjusted-note">
                                    <?php echo ucfirst($note['type']); ?>: Day <?php echo $note['original_date']; ?> → Today<br>
                                    <small><?php echo $note['reason']; ?></small>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($adjustedNotes) > 1): ?>
                                <div class="adjusted-note" style="background: #e9ecef; color: #495057;">
                                    +<?php echo count($adjustedNotes) - 1; ?> more adjusted services
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($readingCount == 0 && $collectionCount == 0): ?>
                            <div class="no-services">
                                <?php if ($isWeekend): ?>
                                    Weekend
                                <?php else: ?>
                                    No services
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        
        <?php if (!empty($zoneReadingCounts)): ?>
        <div class="zone-breakdown">
            <h2>📍 Zone Breakdown for <?php echo $monthName; ?></h2>
            <div class="zone-grid">
                <?php foreach ($zoneReadingCounts as $zoneId => $zoneData): ?>
                    <div class="zone-item">
                        <div class="zone-header">
                            <div class="zone-number">Zone <?php echo $zoneData['zone_number']; ?></div>
                            <div class="zone-count"><?php echo $zoneData['count']; ?> machines</div>
                        </div>
                        <div class="zone-area"><?php echo htmlspecialchars($zoneData['area_center']); ?></div>
                        <div style="margin-top: 10px;">
                            <span class="reading-date-badge <?php echo $zoneData['was_adjusted'] ? 'misaligned' : 'aligned'; ?>">
                                📅 Reading: Day <?php echo $zoneData['reading_date']; ?>
                            </span>
                        </div>
                        <div style="font-size: 0.9em; color: #666; margin-top: 10px;">
                            <?php if ($zoneData['was_adjusted']): ?>
                                <div style="color: #9C27B0;">
                                    <strong>Adjusted:</strong> Day <?php echo $zoneData['original_reading_date']; ?> → Day <?php echo $zoneData['adjusted_reading_date']; ?>
                                </div>
                            <?php endif; ?>
                            <div style="margin-top: 5px;">
                                <strong>Formula:</strong> Zone + 2 = Day <?php echo $zoneData['reading_date']; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="actions">
            <a href="r-dashboard.php" class="btn">← Back to Dashboard</a>
            <a href="r-view_contracts.php" class="btn btn-secondary">📋 View Contracts</a>
            <a href="r-view_zones.php" class="btn btn-info">🗺️ Zone Map</a>
        </div>
    </div>
    
    <script>
    // Highlight current day and scroll to it
    document.addEventListener('DOMContentLoaded', function() {
        const todayCells = document.querySelectorAll('.today');
        if (todayCells.length > 0) {
            todayCells[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    });
    </script>
</body>
</html>