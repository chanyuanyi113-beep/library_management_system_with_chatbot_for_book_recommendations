<?php
session_start();
require_once __DIR__ . '/db.php';

// Check if user is admin (user_type_id = 1)
if (!isset($_SESSION['user_id']) || $_SESSION['user_type_id'] != 1) {
    echo "<script>alert('Access Denied: Only administrators can access this page.'); window.location='librarians_main.php';</script>";
    exit();
}

// Set active tab for highlighting
$active_tab = 'panel';

// Get filter period from URL
$period = isset($_GET['period']) ? $_GET['period'] : 'month';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'users';

// Get search term
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Get membership filter for users
$membership_filter = isset($_GET['membership_filter']) ? $_GET['membership_filter'] : '';

// Get user type filter for users (librarian or user)
$user_type_filter = isset($_GET['user_type_filter']) ? $_GET['user_type_filter'] : '';

// Get author filter for books
$author_filter = isset($_GET['author_filter']) ? $_GET['author_filter'] : '';

// Get category filter for books
$category_filter = isset($_GET['category_filter']) ? $_GET['category_filter'] : '';

// Define date ranges based on period
$date_ranges = [
    'month' => ['label' => 'This Month', 'sql' => "DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"],
    '6months' => ['label' => 'Last 6 Months', 'sql' => "DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)"],
    'year' => ['label' => 'This Year', 'sql' => "YEAR(created_at) = YEAR(CURDATE())"],
    'all' => ['label' => 'All Time', 'sql' => "1=1"]
];

$current_range = $date_ranges[$period];

// Get statistics based on period
// New users count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_type_id = 3 AND {$current_range['sql']}");
$stmt->execute();
$new_users = $stmt->fetchColumn();

// New books count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM books WHERE {$current_range['sql']}");
$stmt->execute();
$new_books = $stmt->fetchColumn();

// Total users (all time)
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type_id = 3");
$total_users = $stmt->fetchColumn();

// Total librarians
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type_id = 2");
$total_librarians = $stmt->fetchColumn();

// Total admins
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type_id = 1");
$total_admins = $stmt->fetchColumn();

// Users with premium membership
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE membership_type_id = 2 AND user_type_id = 3");
$premium_users = $stmt->fetchColumn();

// Users with standard membership
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE membership_type_id = 1 AND user_type_id = 3");
$standard_users = $stmt->fetchColumn();

// Total books
$stmt = $pdo->query("SELECT COUNT(*) FROM books");
$total_books = $stmt->fetchColumn();

// Active borrows
$stmt = $pdo->query("SELECT COUNT(*) FROM borrow_requests WHERE book_status_id IN (1, 2)");
$active_borrows = $stmt->fetchColumn();

// Overdue books
$stmt = $pdo->query("SELECT COUNT(*) FROM borrow_requests WHERE book_status_id = 2 AND due_date < CURDATE()");
$overdue_books = $stmt->fetchColumn();

// Build WHERE clause for users (include librarians user_type_id = 2 and users user_type_id = 3)
$user_where = "user_type_id IN (2, 3) AND {$current_range['sql']}";
if (!empty($search)) {
    $user_where .= " AND (name LIKE '%$search%' OR username LIKE '%$search%' OR email LIKE '%$search%')";
}
if (!empty($membership_filter)) {
    if ($membership_filter == 'premium') {
        $membership_id = 2;
        $user_where .= " AND membership_type_id = $membership_id";
    } elseif ($membership_filter == 'staff') {
        $membership_id = 3;
        $user_where .= " AND membership_type_id = $membership_id";
    } else {
        $membership_id = 1;
        $user_where .= " AND membership_type_id = $membership_id";
    }
}
// Add user type filter (librarian or user)
if (!empty($user_type_filter)) {
    $user_where .= " AND user_type_id = $user_type_filter";
}

$stmt = $pdo->prepare("
    SELECT id, name, username, email, created_at, 
           CASE 
               WHEN membership_type_id = 1 THEN 'Standard' 
               WHEN membership_type_id = 2 THEN 'Premium'
               WHEN membership_type_id = 3 THEN 'Staff'
               ELSE 'Standard' 
           END as membership_type,
           membership_type_id,
           user_type_id
    FROM users 
    WHERE $user_where
    ORDER BY id ASC
");
$stmt->execute();
$new_users_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique authors for filter
$authors = $pdo->query("SELECT DISTINCT author FROM books WHERE author IS NOT NULL AND author != '' ORDER BY author ASC")->fetchAll(PDO::FETCH_COLUMN);

// Get unique categories for filter
$categories = $pdo->query("SELECT DISTINCT bc.category FROM book_categories bc JOIN books b ON b.category_id = bc.id ORDER BY bc.category ASC")->fetchAll(PDO::FETCH_COLUMN);

// Build WHERE clause for books
$book_where = $current_range['sql'];
if (!empty($search)) {
    $book_where .= " AND (b.title LIKE '%$search%' OR b.author LIKE '%$search%')";
}
if (!empty($author_filter)) {
    $book_where .= " AND b.author = '$author_filter'";
}
if (!empty($category_filter)) {
    $book_where .= " AND bc.category = '$category_filter'";
}

// Get new books list based on period with search and filter
$stmt = $pdo->prepare("
    SELECT b.id, b.title, b.author, bc.category, b.created_at, b.times_borrowed
    FROM books b
    LEFT JOIN book_categories bc ON b.category_id = bc.id
    WHERE $book_where
    ORDER BY b.id ASC
");
$stmt->execute();
$new_books_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle CSV download
if (isset($_GET['download']) && $_GET['download'] == 'csv') {
    $download_period = $_GET['download_period'] ?? 'month';
    $download_type = $_GET['download_type'] ?? 'users';
    $download_range = $date_ranges[$download_period];
    
    if ($download_type == 'users') {
        $stmt = $pdo->prepare("
            SELECT id, name, username, email, created_at, 
                   CASE 
                       WHEN membership_type_id = 1 THEN 'Standard' 
                       WHEN membership_type_id = 2 THEN 'Premium'
                       WHEN membership_type_id = 3 THEN 'Staff'
                       ELSE 'Standard' 
                   END as membership_type
            FROM users 
            WHERE user_type_id IN (2, 3) AND {$download_range['sql']}
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $filename = "new_users_{$download_period}_" . date('Y-m-d') . ".csv";
        $headers = ['ID', 'Name', 'Username', 'Email', 'Added Date', 'Membership Type'];
        
    } else {
        $stmt = $pdo->prepare("
            SELECT b.id, b.title, b.author, bc.category, b.created_at, b.times_borrowed
            FROM books b
            LEFT JOIN book_categories bc ON b.category_id = bc.id
            WHERE {$download_range['sql']}
            ORDER BY b.created_at DESC
        ");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $filename = "new_books_{$download_period}_" . date('Y-m-d') . ".csv";
        $headers = ['ID', 'Title', 'Author', 'Category', 'Added Date', 'Times Borrowed'];
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Library Hub</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card-large {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            transition: box-shadow 0.2s;
        }
        
        .stat-card-large:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
        
        .stat-title {
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        
        .stat-subtitle {
            font-size: 12px;
            color: #9ca3af;
        }
        
        .period-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .period-btn {
            padding: 8px 20px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
            text-decoration: none;
            color: #374151;
            display: inline-block;
        }
        
        .period-btn:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
        }
        
        .period-btn.active {
            background: #111827;
            color: white;
            border-color: #111827;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .report-table th {
            background: #f9fafb;
            padding: 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .report-table td {
            padding: 12px;
            font-size: 13px;
            color: #374151;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .report-table tr:hover {
            background: #f9fafb;
        }
        
        .download-btn {
            background: #10b981;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .download-btn:hover {
            background: #059669;
        }
        
        .section-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 30px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .section-header h3 {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }
        
        .type-buttons {
            display: flex;
            gap: 10px;
        }
        
        .type-btn {
            padding: 6px 16px;
            border: 1px solid #e5e7eb;
            border-radius: 20px;
            background: white;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            color: #6b7280;
        }
        
        .type-btn.active {
            background: #111827;
            color: white;
            border-color: #111827;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #9ca3af;
        }
        
        .badge-premium {
            background: #fef3c7;
            color: #d97706;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-standard {
            background: #e5e7eb;
            color: #6b7280;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .search-input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            min-width: 200px;
        }
        
        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-select {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            background: white;
        }
        
        .clear-btn {
            padding: 8px 16px;
            background: #6b7280;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
        }
        
        .clear-btn:hover {
            background: #4b5563;
        }
        
        .sortable-header {
            cursor: pointer;
            user-select: none;
            transition: background 0.2s;
        }
        
        .sortable-header:hover {
            background: #e5e7eb !important;
        }
        
        .sortable-header.sort-asc::after {
            content: ' ↑';
            opacity: 1;
        }
        
        .sortable-header.sort-desc::after {
            content: ' ↓';
            opacity: 1;
        }
    </style>
</head>
<body>
    <?php include 'includes/librarians_header.php'; ?>
    
    <div class="main-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <h2 style="font-size: 24px; font-weight: 700; color: #111827;">Admin Panel</h2>
            <a href="librarians_main.php" class="btn btn-secondary" style="width: auto;">← Back to Dashboard</a>
        </div>
        
        <!-- Statistics Cards -->
        <div class="admin-stats-grid">         
            <div class="stat-card-large">
                <div class="stat-title">Premium Members</div>
                <div class="stat-number"><?php echo number_format($premium_users); ?></div>
                <div class="stat-subtitle"><?php echo number_format($standard_users); ?> Standard members</div>
            </div>
            
            <div class="stat-card-large">
                <div class="stat-title">Staff</div>
                <div class="stat-number"><?php echo number_format($total_librarians + $total_admins); ?></div>
                <div class="stat-subtitle"><?php echo $total_librarians; ?> Librarians, <?php echo $total_admins; ?> Admin</div>
            </div>

            <div class="stat-card-large">
                <div class="stat-title">Overdue Books</div>
                <div class="stat-number"><?php echo number_format($overdue_books); ?></div>
                <div class="stat-subtitle">Past due date</div>
            </div>
        </div>
        
        <!-- Period Filter -->
        <div class="period-buttons">
            <a href="?period=month&report_type=<?php echo $report_type; ?>&search=<?php echo urlencode($search); ?>&membership_filter=<?php echo $membership_filter; ?>&user_type_filter=<?php echo $user_type_filter; ?>&author_filter=<?php echo $author_filter; ?>&category_filter=<?php echo $category_filter; ?>" class="period-btn <?php echo $period == 'month' ? 'active' : ''; ?>">📅 This Month</a>
            <a href="?period=6months&report_type=<?php echo $report_type; ?>&search=<?php echo urlencode($search); ?>&membership_filter=<?php echo $membership_filter; ?>&user_type_filter=<?php echo $user_type_filter; ?>&author_filter=<?php echo $author_filter; ?>&category_filter=<?php echo $category_filter; ?>" class="period-btn <?php echo $period == '6months' ? 'active' : ''; ?>">📊 Last 6 Months</a>
            <a href="?period=year&report_type=<?php echo $report_type; ?>&search=<?php echo urlencode($search); ?>&membership_filter=<?php echo $membership_filter; ?>&user_type_filter=<?php echo $user_type_filter; ?>&author_filter=<?php echo $author_filter; ?>&category_filter=<?php echo $category_filter; ?>" class="period-btn <?php echo $period == 'year' ? 'active' : ''; ?>">📈 This Year</a>
            <a href="?period=all&report_type=<?php echo $report_type; ?>&search=<?php echo urlencode($search); ?>&membership_filter=<?php echo $membership_filter; ?>&user_type_filter=<?php echo $user_type_filter; ?>&author_filter=<?php echo $author_filter; ?>&category_filter=<?php echo $category_filter; ?>" class="period-btn <?php echo $period == 'all' ? 'active' : ''; ?>">📚 All Time</a>
        </div>
        
        <!-- Report Section -->
        <div class="section-card">
            <div class="section-header">
                <h3>📊 New <?php echo ucfirst($report_type); ?> Report - <?php echo $current_range['label']; ?></h3>
                <div style="display: flex; gap: 10px;">
                    <div class="type-buttons">
                        <a href="?period=<?php echo $period; ?>&report_type=users&search=<?php echo urlencode($search); ?>&membership_filter=<?php echo $membership_filter; ?>&user_type_filter=<?php echo $user_type_filter; ?>" class="type-btn <?php echo $report_type == 'users' ? 'active' : ''; ?>">👥 Users</a>
                        <a href="?period=<?php echo $period; ?>&report_type=books&search=<?php echo urlencode($search); ?>&author_filter=<?php echo $author_filter; ?>&category_filter=<?php echo $category_filter; ?>" class="type-btn <?php echo $report_type == 'books' ? 'active' : ''; ?>">📖 Books</a>
                    </div>
                    <a href="?download=csv&download_period=<?php echo $period; ?>&download_type=<?php echo $report_type; ?>" class="download-btn">
                        📥 Download CSV
                    </a>
                </div>
            </div>

            <div class="stats-summary" style="margin-bottom: 20px; padding: 12px; background: #f9fafb; border-radius: 8px;">
                <?php if ($report_type == 'users'): ?>
                    <strong><?php echo number_format($new_users); ?></strong> new users joined during <?php echo strtolower($current_range['label']); ?>
                <?php else: ?>
                    <strong><?php echo number_format($new_books); ?></strong> new books added during <?php echo strtolower($current_range['label']); ?>
                <?php endif; ?>
            </div>
            
            <!-- Search and Filter Bar -->
            <form method="get" class="search-bar">
                <input type="hidden" name="period" value="<?php echo $period; ?>">
                <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
                
                <input type="text" name="search" class="search-input" placeholder="🔍 Search" value="<?php echo htmlspecialchars($search); ?>">
                
                <?php if ($report_type == 'users'): ?>
                    <div class="filter-group">
                        <select name="user_type_filter" class="filter-select">
                            <option value="">All User Types</option>
                            <option value="2" <?php echo $user_type_filter == '2' ? 'selected' : ''; ?>>👑 Librarians</option>
                            <option value="3" <?php echo $user_type_filter == '3' ? 'selected' : ''; ?>>📚 Users</option>
                        </select>
        
                        <select name="membership_filter" class="filter-select">
                            <option value="">All Memberships</option>
                            <option value="standard" <?php echo $membership_filter == 'standard' ? 'selected' : ''; ?>>Standard</option>
                            <option value="premium" <?php echo $membership_filter == 'premium' ? 'selected' : ''; ?>>Premium</option>
                            <option value="staff" <?php echo $membership_filter == 'staff' ? 'selected' : ''; ?>>Staff</option>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="filter-group">
                        <select name="author_filter" class="filter-select">
                            <option value="">All Authors</option>
                            <?php foreach ($authors as $author): ?>
                                <option value="<?php echo htmlspecialchars($author); ?>" <?php echo $author_filter == $author ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($author); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="category_filter" class="filter-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $category_filter == $category ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                
                <button type="submit" class="btn btn-primary" style="width: auto; padding: 8px 16px;">Apply</button>
                <a href="?period=<?php echo $period; ?>&report_type=<?php echo $report_type; ?>" class="clear-btn">Clear</a>
            </form>
            
            <?php if ($report_type == 'users'): ?>
                <?php if (!empty($new_users_list)): ?>
                    <table class="report-table" id="userTable">
                        <thead>
                            <tr>
                                <th data-type="number">ID</th>
                                <th data-type="string">Name</th>
                                <th data-type="string">Username</th>
                                <th data-type="string">Email</th>
                                <th data-type="string">User Type</th>
                                <th data-type="date">Added Date</th>
                                <th data-type="string">Membership</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($new_users_list as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <?php if ($user['user_type_id'] == 2): ?>
                                            <span class="badge-premium">👑 Librarian</span>
                                        <?php else: ?>
                                            <span class="badge-standard">📚 User</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <?php 
                                        $membership_class = 'badge-standard';
                                        $membership_label = $user['membership_type'];
    
                                        // Check if user is staff (membership_type_id = 3)
                                        if (isset($user['user_type_id']) && $user['user_type_id'] == 2) {
                                            $membership_class = 'badge-premium';
                                            $membership_label = 'Staff';
                                        } elseif ($user['membership_type'] == 'Premium') {
                                            $membership_class = 'badge-premium';
                                            $membership_label = 'Premium';
                                        } else {
                                            $membership_label = 'Standard';
                                        }
                                        ?>
                                        <span class="<?php echo $membership_class; ?>">
                                            <?php echo $membership_label; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        No new users found during <?php echo strtolower($current_range['label']); ?>.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!empty($new_books_list)): ?>
                    <table class="report-table" id="bookTable">
                        <thead>
                            <tr>
                                <th data-type="number">ID</th>
                                <th data-type="string">Title</th>
                                <th data-type="string">Author</th>
                                <th data-type="string">Category</th>
                                <th data-type="date">Added Date</th>
                                <th data-type="number">Times Borrowed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($new_books_list as $book): ?>
                                <tr>
                                    <td><?php echo $book['id']; ?></td>
                                    <td><?php echo htmlspecialchars($book['title']); ?></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><?php echo htmlspecialchars($book['category'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($book['created_at'])); ?></td>
                                    <td><?php echo $book['times_borrowed']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        No new books added during <?php echo strtolower($current_range['label']); ?>.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>    
    </div>
    
    <script>
    // Sort table function - always starts with ascending (A-Z, 0-9, oldest first)
    function sortTable(table, column, dataType) {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        
        // Check current sort direction - default to null for first click
        let currentDirection = table.getAttribute('data-sort-dir');
        let newDirection;
        
        // First click always starts with 'asc'
        if (currentDirection === null || currentDirection === '') {
            newDirection = 'asc';
        } else {
            newDirection = currentDirection === 'asc' ? 'desc' : 'asc';
        }
        
        // Sort rows
        rows.sort((a, b) => {
            let aVal = a.cells[column].textContent.trim();
            let bVal = b.cells[column].textContent.trim();
            
            if (dataType === 'number') {
                aVal = parseInt(aVal) || 0;
                bVal = parseInt(bVal) || 0;
                return newDirection === 'asc' ? aVal - bVal : bVal - aVal;
            } else if (dataType === 'date') {
                aVal = new Date(aVal);
                bVal = new Date(bVal);
                return newDirection === 'asc' ? aVal - bVal : bVal - aVal;
            } else {
                return newDirection === 'asc' ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
            }
        });
        
        // Reorder rows
        rows.forEach(row => tbody.appendChild(row));
        
        // Update sort direction attribute
        table.setAttribute('data-sort-dir', newDirection);
        
        // Update header indicators
        const headers = table.querySelectorAll('th');
        headers.forEach((header, index) => {
            header.classList.remove('sort-asc', 'sort-desc');
            if (index === column) {
                header.classList.add(newDirection === 'asc' ? 'sort-asc' : 'sort-desc');
            }
        });
    }
    
    // Add click handlers to all sortable tables
    document.addEventListener('DOMContentLoaded', function() {
        const tables = document.querySelectorAll('.report-table');
        
        tables.forEach(table => {
            const headers = table.querySelectorAll('th');
            headers.forEach((header, index) => {
                header.classList.add('sortable-header');
                const dataType = header.getAttribute('data-type') || 'string';
                
                header.addEventListener('click', () => {
                    sortTable(table, index, dataType);
                });
            });
        });
    });
    </script>
</body>
</html>