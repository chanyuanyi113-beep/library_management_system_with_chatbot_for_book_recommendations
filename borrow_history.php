<?php
$active_tab = 'history';
require_once __DIR__ . '/includes/header.php';

// Fetch returned borrow requests for the logged-in user
$stmt = $pdo->prepare(
    'SELECT br.id AS borrow_id, 
            b.title, 
            b.author, 
            bc.category AS category_name,
            br.request_date, 
            br.due_date, 
            br.returned_date, 
            b.category_id
     FROM borrow_requests br
     JOIN books b ON b.id = br.book_id
     LEFT JOIN book_categories bc ON bc.id = b.category_id
     WHERE br.user_id = ? AND br.book_status_id = 3
     ORDER BY br.returned_date DESC'
);
$stmt->execute([$logged_in_user_id]);
$rows = $stmt->fetchAll();

$cats = $pdo->query('SELECT id, category FROM book_categories ORDER BY category')->fetchAll();
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;

// Calculate total records after filtering
$total_records = 0;
foreach ($rows as $r) {
    $match = true;
    if ($search_term !== '') {
        $query = strtolower($search_term);
        if (strpos(strtolower($r['title']), $query) === false && strpos(strtolower($r['author']), $query) === false) {
            $match = false;
        }
    }
    if ($category_filter > 0 && $r['category_id'] != $category_filter) {
        $match = false;
    }
    if ($match) {
        $total_records++;
    }
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <h2 style="font-size: 24px; font-weight: 700; color: #111827; margin: 0;">Borrow History</h2>
    <div style="background: #f3f4f6; padding: 6px 16px; border-radius: 20px; font-size: 14px; font-weight: 500; color: #374151;">
        📊 Total: <?php echo $total_records; ?> record(s)
    </div>
</div>

<!-- Search and Filter Bar - Auto-submit on change -->
<div style="margin-bottom: 24px;">
    <form method="GET" action="borrow_history.php" id="filterForm" style="display: flex; gap: 12px; align-items: center;">
        <input type="text" name="search" id="searchInput" placeholder="Search by Book Title or Author" value="<?php echo htmlspecialchars($search_term); ?>" style="flex: 4; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
        <select name="category" id="categorySelect" style="flex: 1; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; background: white; cursor: pointer;" onchange="this.form.submit()">
            <option value="">All Categories</option>
            <?php foreach ($cats as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $category_filter === intval($c['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['category']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <a href="borrow_history.php" class="btn btn-secondary" style="flex: 1; white-space: nowrap; text-align: center; text-decoration: none;">Reset</a>
    </form>
</div>

<!-- Results Table - Only show if there are records -->
<?php if ($total_records > 0): ?>
<div class="card">
    <table class="borrow-table" id="historyTable">
        <thead>
            <tr>
                <th style="cursor: pointer;" onclick="sortTable(0)">Title <span class="sort-btn"></span></th>
                <th style="cursor: pointer;" onclick="sortTable(1)">Author <span class="sort-btn"></span></th>
                <th style="cursor: pointer;" onclick="sortTable(2)">Category <span class="sort-btn"></span></th>
                <th style="cursor: pointer;" onclick="sortTable(3)">Borrow Date <span class="sort-btn"></span></th>
                <th style="cursor: pointer;" onclick="sortTable(4)">Due Date <span class="sort-btn"></span></th>
                <th style="cursor: pointer;" onclick="sortTable(5)">Return Date <span class="sort-btn"></span></th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($rows as $r):
                $match = true;
                if ($search_term !== '') {
                    $query = strtolower($search_term);
                    if (strpos(strtolower($r['title']), $query) === false && strpos(strtolower($r['author']), $query) === false) {
                        $match = false;
                    }
                }
                if ($category_filter > 0 && $r['category_id'] != $category_filter) {
                    $match = false;
                }
                if (!$match) {
                    continue;
                }
            ?>
                <tr>
                    <td><?= htmlspecialchars($r['title']) ?></td>
                    <td><?= htmlspecialchars($r['author']) ?></td>
                    <td><?= htmlspecialchars($r['category_name'] ?? 'Uncategorized') ?></td>
                    <td><?= htmlspecialchars(date('Y-m-d', strtotime($r['request_date']))) ?></td>
                    <td><?= $r['due_date'] ? htmlspecialchars(date('Y-m-d', strtotime($r['due_date']))) : '' ?></td>
                    <td><?= $r['returned_date'] ? htmlspecialchars(date('Y-m-d', strtotime($r['returned_date']))) : '' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<style>
    .sort-btn {
        background: none;
        border: none;
        font-size: 12px;
        margin-left: 4px;
        color: #9ca3af;
    }
</style>

<script>
// Debounced search - auto-submit after typing stops
let searchDebounceTimer;
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => {
            document.getElementById('filterForm').submit();
        }, 400);
    });
}

// Sortable table function
let currentSortColumn = -1;
let currentSortDirection = 'asc';

function sortTable(columnIndex) {
    const table = document.getElementById('historyTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    // Filter out empty rows
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
        
        // Handle date columns (Borrow Date, Due Date, Return Date) - indices 3, 4, 5
        if (columnIndex === 3 || columnIndex === 4 || columnIndex === 5) {
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

<?php else: ?>
<!-- No records message -->
<div class="card">
    <div style="text-align: center; padding: 60px 20px;">
        <p style="color: #6b7280; font-size: 16px; margin-bottom: 8px;">No returned / related book found.</p>
    </div>
</div>
<?php endif; ?>