<?php
$active_tab = 'history';
require_once __DIR__ . '/includes/librarians_header.php';

// Fetch all borrow requests for regular users (returned or canceled)
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';

$sql = 'SELECT br.id AS borrow_id,
            u.username,
            u.name AS user_name,
            b.title,
            b.author,
            bc.category AS category_name,
            br.request_date,
            br.due_date,
            br.returned_date,
            b.category_id,
            br.book_status_id,
            bs.status AS book_status,
            u1.username AS created_by_username,
            u2.username AS updated_by_username,
            br.created_at,
            br.updated_at
     FROM borrow_requests br
     JOIN users u ON u.id = br.user_id
     JOIN books b ON b.id = br.book_id
     LEFT JOIN book_categories bc ON bc.id = b.category_id
     LEFT JOIN book_status bs ON bs.id = br.book_status_id
     LEFT JOIN users u1 ON u1.id = br.created_by
     LEFT JOIN users u2 ON u2.id = br.updated_by
     WHERE u.user_type_id = 3
       AND br.book_status_id IN (3, 4)';

$params = [];

// Add search condition
if ($search_term !== '') {
    $sql .= ' AND (u.username LIKE ? OR u.name LIKE ? OR b.title LIKE ? OR b.author LIKE ?)';
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
    $params[] = "%$search_term%";
}

// Add category filter
if ($category_filter > 0) {
    $sql .= ' AND b.category_id = ?';
    $params[] = $category_filter;
}

// Add status filter
if ($status_filter !== '') {
    $sql .= ' AND br.book_status_id = ?';
    $params[] = $status_filter;
}

$sql .= ' ORDER BY br.returned_date DESC, br.updated_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$cats = $pdo->query('SELECT id, category FROM book_categories ORDER BY category')->fetchAll();

// Get status counts for filter display from ALL data (not filtered)
$all_sql = 'SELECT br.book_status_id FROM borrow_requests br
            JOIN users u ON u.id = br.user_id
            WHERE u.user_type_id = 3 AND br.book_status_id IN (3, 4)';
$all_stmt = $pdo->prepare($all_sql);
$all_stmt->execute();
$all_rows = $all_stmt->fetchAll();

// Get status counts for filter display from FILTERED data only
$returned_count = 0;
$canceled_count = 0;
foreach ($rows as $r) {
    if ($r['book_status_id'] == 3) {
        $returned_count++;
    } elseif ($r['book_status_id'] == 4) {
        $canceled_count++;
    }
}
$total_records = count($rows);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Book History</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .status-returned {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            background: #10b981;
            color: white;
        }
        .status-canceled {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            background: #ef4444;
            color: white;
        }
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
    <div class="main-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <h2 style="font-size: 24px; font-weight: 700; color: #111827; margin: 0;">Books Requests History</h2>
            <div style="background: #f3f4f6; padding: 6px 16px; border-radius: 20px; font-size: 14px; font-weight: 500; color: #374151;">
                📊 Total: <?php echo $total_records; ?> record(s)
            </div>
        </div>

        <!-- Search and Filter Bar -->
        <div style="margin-bottom: 24px;">
            <form method="GET" action="book_history.php" id="filterForm" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <input type="text" name="search" id="searchInput" placeholder="Search by Username, Name, Book Title or Author" value="<?php echo htmlspecialchars($search_term); ?>" style="flex: 3; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
                
                <select name="category" id="categorySelect" style="flex: 1; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; background: white; cursor: pointer;">
                    <option value="">All Categories</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $category_filter === intval($c['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['category']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <!-- Status Filter Dropdown - Moved between Category and Reset -->
                <select name="status" id="statusSelect" style="flex: 1; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; background: white; cursor: pointer;">
                    <option value="">All Status</option>
                    <option value="3" <?php echo $status_filter == 3 ? 'selected' : ''; ?>>Returned</option>
                    <option value="4" <?php echo $status_filter == 4 ? 'selected' : ''; ?>>Canceled</option>
                </select>
                
                <a href="book_history.php" class="btn btn-secondary" style="flex: 1; white-space: nowrap; text-align: center; text-decoration: none;">Reset</a>
            </form>
        </div>

        <!-- Results Table -->
        <div class="card">
            <table class="borrow-table" id="historyTable">
                <thead>
                    <tr>
                        <th style="cursor: pointer;" onclick="sortTable(0)">Username <span class="sort-btn"></span></th>
                        <th style="cursor: pointer;" onclick="sortTable(1)">Name <span class="sort-btn"></span></th>
                        <th style="cursor: pointer;" onclick="sortTable(2)">Book Title <span class="sort-btn"></span></th>
                        <th style="cursor: pointer;" onclick="sortTable(3)">Author <span class="sort-btn"></span></th>
                        <th style="cursor: pointer;" onclick="sortTable(4)">Category <span class="sort-btn"></span></th>
                        <th style="cursor: pointer;" onclick="sortTable(5)">Borrow Date <span class="sort-btn"></span></th>
                        <th style="cursor: pointer;" onclick="sortTable(6)">Due Date <span class="sort-btn"></span></th>
                        <th style="cursor: pointer;" onclick="sortTable(7)">Return Date <span class="sort-btn"></span></th>
                        <th style="cursor: pointer;" onclick="sortTable(8)">Created By <span class="sort-btn"></span></th>
                        <th style="cursor: pointer;" onclick="sortTable(9)">Updated By <span class="sort-btn"></span></th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($rows) > 0): ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['username']) ?></td>
                                <td><?= htmlspecialchars($r['user_name']) ?></td>
                                <td><?= htmlspecialchars($r['title']) ?></td>
                                <td><?= htmlspecialchars($r['author']) ?></td>
                                <td><?= htmlspecialchars($r['category_name'] ?? 'Uncategorized') ?></td>
                                <td><?= htmlspecialchars(date('Y-m-d', strtotime($r['request_date']))) ?></td>
                                <td><?= $r['due_date'] ? htmlspecialchars(date('Y-m-d', strtotime($r['due_date']))) : '' ?></td>
                                <td>
                                    <?php if ($r['returned_date']): ?>
                                        <?= htmlspecialchars(date('Y-m-d', strtotime($r['returned_date']))) ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($r['created_by_username'] ?? 'System') ?></td>
                                <td><?= htmlspecialchars($r['updated_by_username'] ?? 'System') ?></td>
                                <td>
                                    <?php if ($r['book_status_id'] == 3): ?>
                                        <span class="status-returned">Returned</span>
                                    <?php elseif ($r['book_status_id'] == 4): ?>
                                        <span class="status-canceled">Canceled</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($r['book_status'] ?? 'Unknown') ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 40px; color: #9ca3af;">
                                No records found matching your criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Debounced search - auto-submit after typing stops
        let searchDebounceTimer;
        const searchInput = document.getElementById('searchInput');
        const categorySelect = document.getElementById('categorySelect');
        const statusSelect = document.getElementById('statusSelect');
        const filterForm = document.getElementById('filterForm');

        function submitForm() {
            filterForm.submit();
        }

        // Auto-submit on search input (debounced)
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchDebounceTimer);
                searchDebounceTimer = setTimeout(() => {
                    submitForm();
                }, 400);
            });
        }

        // Auto-submit on category change
        if (categorySelect) {
            categorySelect.addEventListener('change', function() {
                submitForm();
            });
        }

        // Auto-submit on status change
        if (statusSelect) {
            statusSelect.addEventListener('change', function() {
                submitForm();
            });
        }

        // Sortable table function
        let currentSortColumn = -1;
        let currentSortDirection = 'asc';

        function sortTable(columnIndex) {
            const table = document.getElementById('historyTable');
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Filter out the "no records" row if it exists
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
                
                // Handle date columns (Borrow Date, Due Date, Return Date) - indices 5, 6, 7
                if (columnIndex === 5 || columnIndex === 6 || columnIndex === 7) {
                    aValue = aValue === '-' ? '' : aValue;
                    bValue = bValue === '-' ? '' : bValue;
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
            const headers = document.querySelectorAll('#historyTable thead th');
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