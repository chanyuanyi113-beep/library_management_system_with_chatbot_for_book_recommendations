<?php
// Add output buffering to prevent blank pages
ob_start();

// Suppress errors that might break the redirect
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

session_start();
require_once __DIR__ . '/db.php';

// Helper function to redirect with alert message
function redirectWithAlert($message, $url) {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Convert literal \n to actual newlines for JavaScript
    $message = str_replace('\\n', "\n", $message);
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <script>
            alert(' . json_encode($message) . ');
            window.location.href = ' . json_encode($url) . ';
        </script>
    </head>
    <body></body>
    </html>';
    exit();
}

// Check if user is logged in and is admin or librarian
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type_id'] != 1 && $_SESSION['user_type_id'] != 2)) {
    redirectWithAlert('Access Denied: Only admins and librarians can access this page.', 'main.php');
}

// Handle mark as returned (POST method)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_returned']) && isset($_POST['borrow_id'])) {
    $borrow_id = intval($_POST['borrow_id']);
    
    try {
        // Get borrow request details
        $stmt = $pdo->prepare("SELECT br.*, b.title, b.author, u.username, u.name as user_name, u.id as user_id 
                               FROM borrow_requests br 
                               JOIN books b ON br.book_id = b.id 
                               JOIN users u ON br.user_id = u.id 
                               WHERE br.id = ?");
        $stmt->execute([$borrow_id]);
        $borrow = $stmt->fetch();
        
        if ($borrow && $borrow['book_status_id'] == 2) {
            // Start transaction
            $pdo->beginTransaction();

            $current_user_id = $_SESSION['user_id'] ?? 0;
            
            // Update book status to 3 (returned) and mark returned date
            $update_stmt = $pdo->prepare("UPDATE borrow_requests SET book_status_id = 3, returned_date = NOW(), updated_by = ? WHERE id = ?");
            $update_stmt->execute([$current_user_id, $borrow_id]);
            
            // Insert notification for the user that the book was returned
            $criteria_stmt = $pdo->prepare(
                'SELECT nc.id FROM notifications_criteria nc
                 JOIN notifications_title nt ON nc.title_id = nt.id
                 JOIN notifications_type ntype ON nc.type_id = ntype.id
                 WHERE nt.title = ? AND ntype.type = ? LIMIT 1'
            );
            $criteria_stmt->execute(['Book Returned', 'success']);
            $criteria_id = $criteria_stmt->fetchColumn();
            if ($criteria_id) {
                $notif_stmt = $pdo->prepare(
                    'INSERT INTO notifications (user_id, criteria_id, message)
                     VALUES (?, ?, ?)'
                );
                $notif_stmt->execute([
                    $borrow['user_id'],
                    $criteria_id,
                    'Your borrowed book "' . $borrow['title'] . '" has been returned successfully. Please rate it in "My Books" section!'
                ]);
            }
            
            $pdo->commit();
            
            redirectWithAlert("Book marked as returned successfully!\n\nUser: {$borrow['user_name']}\nBook: {$borrow['title']}", 'return.php');
        } else {
            redirectWithAlert('Borrow request not found or book already returned!', 'return.php');
        }
    } catch (PDOException $e) {
        if (isset($pdo)) $pdo->rollBack();
        redirectWithAlert('Error: ' . $e->getMessage(), 'return.php');
    }
}

// Get all borrow requests with status_id = 2 (borrowed but not yet returned)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$params = [];

$sql = "SELECT br.*, b.title, b.author, u.username, u.name as user_name, 
               u1.username as created_by_username, u2.username as updated_by_username
        FROM borrow_requests br 
        JOIN books b ON br.book_id = b.id 
        JOIN users u ON br.user_id = u.id 
        LEFT JOIN users u1 ON u1.id = br.created_by
        LEFT JOIN users u2 ON u2.id = br.updated_by
        WHERE br.book_status_id = 2";

if ($search !== '') {
    $sql .= " AND (u.name LIKE ? OR u.username LIKE ? OR b.title LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY br.request_date ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$returns = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Mark Books as Returned</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .sort-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 12px;
            margin-left: 4px;
            color: #9ca3af;
        }
        .sort-btn:hover {
            color: #4f46e5;
        }
    </style>
</head>
<body>
    <?php 
    $active_tab = 'dashboard';
    include 'includes/librarians_header.php'; 
    ?>
    
    <div class="main-content">
        <div class="section-header">
            <h2 class="page-title">Mark Books as Returned</h2>
            <div class="flex-gap">
                <a href="librarians_main.php" class="btn btn-secondary">← Back To Dashboard</a>
                <a href="borrow.php" class="btn btn-secondary">Go To Borrow</a>
            </div>
        </div>

        <!-- Search Bar -->
        <div style="margin-bottom: 24px;">
            <form method="get" id="searchForm" style="display: flex; gap: 12px; align-items: center;">
                <input type="text" name="search" id="searchInput" placeholder="Search by Name, Username or Book Title" value="<?php echo htmlspecialchars($search); ?>" style="flex: 4; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
                <button type="submit" class="btn btn-primary" style="flex: 1; white-space: nowrap;">Search</button>
                <a href="return.php" class="btn btn-secondary" style="flex: 1; white-space: nowrap; text-align: center;">Reset</a>
            </form>
        </div>

        <!-- Return Requests Table -->
        <div class="card">
            <?php if (count($returns) > 0): ?>
                <table class="borrow-table" id="returnTable">
                    <thead>
                        <tr>
                            <th style="cursor: pointer;" onclick="sortTable(0)">Name <span class="sort-btn"></span></th>
                            <th style="cursor: pointer;" onclick="sortTable(1)">Username <span class="sort-btn"></span></th>
                            <th style="cursor: pointer;" onclick="sortTable(2)">Book Title <span class="sort-btn"></span></th>
                            <th style="cursor: pointer;" onclick="sortTable(3)">Author <span class="sort-btn"></span></th>
                            <th style="cursor: pointer;" onclick="sortTable(4)">Borrow Date <span class="sort-btn"></span></th>
                            <th style="cursor: pointer;" onclick="sortTable(5)">Duration <span class="sort-btn"></span></th>
                            <th style="cursor: pointer;" onclick="sortTable(6)">Due Date <span class="sort-btn"></span></th>
                            <th style="cursor: pointer;" onclick="sortTable(7)">Days Remaining <span class="sort-btn"></span></th>
                            <th style="cursor: pointer;" onclick="sortTable(8)">Created By <span class="sort-btn"></span></th>
                            <th style="cursor: pointer;" onclick="sortTable(9)">Updated By <span class="sort-btn"></span></th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($returns as $return): 
                            $due_date = new DateTime($return['due_date']);
                            $today = new DateTime();
                            
                            $today->setTime(0, 0, 0);
                            $due_date->setTime(0, 0, 0);
                            
                            $interval = $today->diff($due_date);
                            $days_remaining = $interval->days;
                            
                            if ($today > $due_date) {
                                $days_remaining = -$days_remaining;
                                $is_overdue = true;
                            } else {
                                $is_overdue = false;
                                if ($today < $due_date) {
                                    $days_remaining = $interval->days;
                                }
                                if ($today == $due_date) {
                                    $days_remaining = 0;
                                }
                            }
                            
                            // Format days remaining display
                            if ($is_overdue) {
                                $days_remaining_display = '<span style="color: #ef4444; font-weight: 600;">' . abs($days_remaining) . ' days overdue</span>';
                            } elseif ($days_remaining == 0) {
                                $days_remaining_display = '<span style="color: #f59e0b; font-weight: 600;">Today</span>';
                            } else {
                                $days_remaining_display = '<span style="color: #10b981; font-weight: 600;">' . $days_remaining . ' days left</span>';
                            }
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($return['user_name']); ?></td>
                                <td><?php echo htmlspecialchars($return['username']); ?></td>
                                <td><?php echo htmlspecialchars($return['title']); ?></td>
                                <td><?php echo htmlspecialchars($return['author']); ?></td>
                                <td><?php echo date('d-m-Y', strtotime($return['request_date'])); ?></td>
                                <td><?php echo $return['rent_duration']; ?> days</td>
                                <td><?php echo date('d-m-Y', strtotime($return['due_date'])); ?></td>
                                <td><?php echo $days_remaining_display; ?></td>
                                <td><?php echo htmlspecialchars($return['created_by_username'] ?? 'System'); ?></td>
                                <td><?php echo htmlspecialchars($return['updated_by_username'] ?? 'System'); ?></td>
                                <td><span style="display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; background: #dbeafe; color: #1e40af;">Borrowed</span></td>
                                <td>
                                    <div style="display: flex; gap: 8px;">
                                        <form method="POST" action="return.php" style="display: inline-block; margin: 0;"
                                              onsubmit="return confirm('Mark this book as returned?\n\nUser: <?php echo addslashes($return['user_name']); ?>\nBook: <?php echo addslashes($return['title']); ?>');">
                                            <input type="hidden" name="mark_returned" value="1">
                                            <input type="hidden" name="borrow_id" value="<?php echo $return['id']; ?>">
                                            <button type="submit" class="btn btn-success btn-sm">
                                                Mark Returned
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty-state">No related books being borrowed!</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Debounced search
        let searchDebounceTimer;
        const searchInput = document.getElementById('searchInput');
        const searchForm = document.getElementById('searchForm');
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchDebounceTimer);
                searchDebounceTimer = setTimeout(() => {
                    searchForm.submit();
                }, 400);
            });
        }
        
        // Sortable table function
        let currentSortColumn = -1;
        let currentSortDirection = 'asc';
        
        function sortTable(columnIndex) {
            const table = document.getElementById('returnTable');
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Filter out any empty rows
            const dataRows = rows.filter(row => row.cells.length > 1);
            
            if (dataRows.length === 0) return;
            
            // Toggle sort direction if same column, otherwise reset to asc
            if (currentSortColumn === columnIndex) {
                currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortDirection = 'asc';
                currentSortColumn = columnIndex;
            }
            
            // Sort the rows
            dataRows.sort((a, b) => {
                let aValue = a.cells[columnIndex].textContent.trim().toLowerCase();
                let bValue = b.cells[columnIndex].textContent.trim().toLowerCase();
                
                // Handle date columns (Borrow Date, Due Date) - indices 4, 6
                if (columnIndex === 4 || columnIndex === 6) {
                    aValue = aValue === '-' ? '' : aValue;
                    bValue = bValue === '-' ? '' : bValue;
                }
                
                // Handle duration (extract number) - index 5
                if (columnIndex === 5) {
                    aValue = parseInt(aValue) || 0;
                    bValue = parseInt(bValue) || 0;
                }
                
                // Handle days remaining (extract number and handle overdue/Today) - index 7
                if (columnIndex === 7) {
                    const aMatch = aValue.match(/(\d+)/);
                    const bMatch = bValue.match(/(\d+)/);
                    
                    if (aValue.includes('overdue')) {
                        aValue = aMatch ? -parseInt(aMatch[1]) : -999;
                    } else if (aValue.includes('today')) {
                        aValue = 0;
                    } else {
                        aValue = aMatch ? parseInt(aMatch[1]) : 0;
                    }
                    
                    if (bValue.includes('overdue')) {
                        bValue = bMatch ? -parseInt(bMatch[1]) : -999;
                    } else if (bValue.includes('today')) {
                        bValue = 0;
                    } else {
                        bValue = bMatch ? parseInt(bMatch[1]) : 0;
                    }
                }
                
                if (aValue < bValue) return currentSortDirection === 'asc' ? -1 : 1;
                if (aValue > bValue) return currentSortDirection === 'asc' ? 1 : -1;
                return 0;
            });
            
            // Reorder the rows in the DOM
            dataRows.forEach(row => tbody.appendChild(row));
            
            // Update sort indicators
            updateSortIndicators(columnIndex);
        }
        
        function updateSortIndicators(activeColumn) {
            const headers = document.querySelectorAll('#returnTable thead th');
            headers.forEach((header, index) => {
                const sortBtn = header.querySelector('.sort-btn');
                if (sortBtn) {
                    if (index === activeColumn) {
                        sortBtn.innerHTML = currentSortDirection === 'asc' ? '↑' : '↓';
                    } else {
                        sortBtn.innerHTML = '';
                    }
                }
            });
        }
    </script>
</body>
</html>