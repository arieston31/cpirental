<?php
require_once 'config.php';

// Get statistics (active only)
$stats = [
    'total_clients' => $conn->query("SELECT COUNT(*) as count FROM zoning_clients WHERE status = 'ACTIVE'")->fetch_assoc()['count'],
    'total_machines' => $conn->query("SELECT COUNT(*) as count FROM zoning_machine WHERE status = 'ACTIVE'")->fetch_assoc()['count'],
    'government_clients' => $conn->query("SELECT COUNT(*) as count FROM zoning_clients WHERE classification = 'GOVERNMENT' AND status = 'ACTIVE'")->fetch_assoc()['count'],
    'private_clients' => $conn->query("SELECT COUNT(*) as count FROM zoning_clients WHERE classification = 'PRIVATE' AND status = 'ACTIVE'")->fetch_assoc()['count']
];

// Get zone distribution (active machines only)
$zone_stats = $conn->query("
    SELECT z.zone_number, z.area_center, COUNT(m.id) as machine_count 
    FROM zoning_zone z 
    LEFT JOIN zoning_machine m ON z.id = m.zone_id AND m.status = 'ACTIVE'
    GROUP BY z.id 
    ORDER BY z.zone_number
");

// Get reading schedule (active machines only, grouped by zone and reading date)
$schedule = $conn->query("
    SELECT z.zone_number, m.reading_date, COUNT(m.id) as machine_count 
    FROM zoning_machine m 
    JOIN zoning_zone z ON m.zone_id = z.id 
    WHERE m.status = 'ACTIVE' 
    GROUP BY z.zone_number, m.reading_date 
    ORDER BY z.zone_number, m.reading_date
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zoning System Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f0f2f5; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { margin: 0; color: #333; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-card h3 { margin-top: 0; color: #666; }
        .stat-number { font-size: 2.5em; font-weight: bold; color: #2196F3; }
        .tables { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; }
        table { width: 100%; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; font-weight: bold; color: #333; }
        tr:hover { background: #f9f9f9; }
        .actions { margin-top: 30px; display: flex; gap: 10px; }
        .btn { display: inline-block; padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 4px; }
        .btn:hover { background: #45a049; }
        .btn-secondary { background: #2196F3; }
        .btn-secondary:hover { background: #1976D2; }
        .zone-badge { 
            display: inline-block; 
            padding: 4px 8px; 
            border-radius: 4px; 
            background: #e3f2fd; 
            color: #1976d2; 
            font-weight: bold; 
        }
        .status-badge { 
            display: inline-block; 
            padding: 4px 8px; 
            border-radius: 4px; 
            font-size: 0.8em;
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
        .schedule-count {
            font-weight: bold;
            color: #2196F3;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Zoning System Dashboard</h1>
        <p>Manage copier machine reading schedules efficiently</p>
    </div>
    
    <div class="stats">
        <div class="stat-card">
            <h3>Active Clients</h3>
            <div class="stat-number"><?php echo $stats['total_clients']; ?></div>
            <small>Total registered clients</small>
        </div>
        <div class="stat-card">
            <h3>Active Machines</h3>
            <div class="stat-number"><?php echo $stats['total_machines']; ?></div>
            <small>Total installed machines</small>
        </div>
        <div class="stat-card">
            <h3>Government Clients</h3>
            <div class="stat-number"><?php echo $stats['government_clients']; ?></div>
            <small>Active government clients</small>
        </div>
        <div class="stat-card">
            <h3>Private Clients</h3>
            <div class="stat-number"><?php echo $stats['private_clients']; ?></div>
            <small>Active private clients</small>
        </div>
    </div>
    
    <div class="tables">
        <div>
            <h2>Zone Distribution</h2>
            <table>
                <thead>
                    <tr>
                        <th>Zone</th>
                        <th>Area/Center</th>
                        <th>Active Machines</th>
                        <th>Fixed Reading Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($zone = $zone_stats->fetch_assoc()): 
                        $reading_date = $zone['zone_number'] + 2; // Zone 1 = 3rd, Zone 2 = 4th, etc.
                    ?>
                    <tr>
                        <td><span class="zone-badge">Zone <?php echo $zone['zone_number']; ?></span></td>
                        <td><?php echo $zone['area_center']; ?></td>
                        <td><?php echo $zone['machine_count']; ?> machines</td>
                        <td><strong>Day <?php echo $reading_date; ?></strong></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <div>
            <h2>Reading Schedule</h2>
            <table>
                <thead>
                    <tr>
                        <th>Zone</th>
                        <th>Reading Date</th>
                        <th>Scheduled Machines</th>
                        <th>Fixed Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $schedule->fetch_assoc()): 
                        $expected_date = $row['zone_number'] + 2;
                        $is_correct = $row['reading_date'] == $expected_date;
                    ?>
                    <tr>
                        <td><span class="zone-badge">Zone <?php echo $row['zone_number']; ?></span></td>
                        <td><?php echo $row['reading_date']; ?>th</td>
                        <td>
                            <span class="schedule-count"><?php echo $row['machine_count']; ?></span> machines
                        </td>
                        <td>
                            <?php if ($is_correct): ?>
                                <span style="color: #4CAF50;">✓ Correct</span>
                            <?php else: ?>
                                <span style="color: #f44336;">
                                    Should be: <?php echo $expected_date; ?>th
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <div class="actions">
        <a href="add_client.php" class="btn">➕ Add New Client</a>
        <a href="view_clients.php" class="btn btn-secondary">👥 View All Clients</a>
        <a href="view_machines.php" class="btn btn-secondary">🖨️ View All Machines</a>
        <a href="calendar.php" class="btn btn-secondary">�� View Calendar</a>
    </div>
</body>
</html>