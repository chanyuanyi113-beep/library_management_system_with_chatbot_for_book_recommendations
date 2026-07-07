<?php
session_start();
require_once __DIR__ . '/db.php';

// Only administrators and librarians can access this page.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type_id']) || ($_SESSION['user_type_id'] != 1 && $_SESSION['user_type_id'] != 2)) {
    echo "<script>alert('Access Denied: Only administrators can access this page.'); window.location='librarians_main.php';</script>";
    exit();
}

$active_tab = 'library';
$success_msg = '';
$error_msg = '';

// Define filter variables
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category_filter']) ? intval($_GET['category_filter']) : '';
$language_filter = isset($_GET['language_filter']) ? intval($_GET['language_filter']) : '';

// Clear messages after page reload (using session to persist for one request)
if (!isset($_SESSION['library_success'])) {
    $_SESSION['library_success'] = '';
}
if (!isset($_SESSION['library_error'])) {
    $_SESSION['library_error'] = '';
}

// Display messages from session and clear them
if ($_SESSION['library_success']) {
    $success_msg = $_SESSION['library_success'];
    $_SESSION['library_success'] = '';
}
if ($_SESSION['library_error']) {
    $error_msg = $_SESSION['library_error'];
    $_SESSION['library_error'] = '';
}

// Display messages from session and clear them
if ($_SESSION['library_success']) {
    $success_msg = $_SESSION['library_success'];
    $_SESSION['library_success'] = '';
}
if ($_SESSION['library_error']) {
    $error_msg = $_SESSION['library_error'];
    $_SESSION['library_error'] = '';
}

// Handle add new floor
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_floor'])) {
    $new_floor = intval($_POST['new_floor']);
    
    // Check if floor already exists
    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM floor WHERE floor = ?");
    $check_stmt->execute([$new_floor]);
    $exists = $check_stmt->fetchColumn();
    
    if ($exists) {
        echo "<script>alert('Floor $new_floor already exists!'); window.location='library.php';</script>";
        exit();
    } else {
        // Get the highest rack number across all floors
        $max_rack_stmt = $pdo->query("SELECT MAX(rack) as max_rack FROM floor");
        $max_rack = $max_rack_stmt->fetchColumn();
        $start_rack = $max_rack ? $max_rack + 1 : 1;
        
        // Insert 10 racks for the new floor
        $inserted = 0;
        for ($i = 0; $i < 10; $i++) {
            $rack_num = $start_rack + $i;
            $insert_stmt = $pdo->prepare("INSERT INTO floor (floor, rack) VALUES (?, ?)");
            if ($insert_stmt->execute([$new_floor, $rack_num])) {
                $inserted++;
            }
        }
        if ($inserted > 0) {
            echo "<script>alert('Floor $new_floor added successfully with $inserted racks! (Racks $start_rack to " . ($start_rack + 9) . ")'); window.location='library.php';</script>";
        } else {
            echo "<script>alert('Failed to add floor.'); window.location='library.php';</script>";
        }
        exit();
    }
}

// Handle add new rack
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_location'])) {
    $floor = intval($_POST['floor']);
    $rack = intval($_POST['rack']);
    
    // Check if rack number already exists across ANY floor (cannot be duplicated)
    $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM floor WHERE rack = ?");
    $check_stmt->execute([$rack]);
    $rack_exists = $check_stmt->fetchColumn();
    
    if ($rack_exists) {
        echo "<script>alert('Rack number $rack already exists on another floor! Each rack number must be unique across all floors.'); window.location='library.php';</script>";
        exit();
    } else {
        // Check if floor and rack combination already exists
        $check_floor_rack = $pdo->prepare("SELECT COUNT(*) FROM floor WHERE floor = ? AND rack = ?");
        $check_floor_rack->execute([$floor, $rack]);
        $exists = $check_floor_rack->fetchColumn();
        
        if ($exists) {
            echo "<script>alert('Floor $floor, Rack $rack already exists!'); window.location='library.php';</script>";
            exit();
        } else {
            $insert_stmt = $pdo->prepare("INSERT INTO floor (floor, rack) VALUES (?, ?)");
            if ($insert_stmt->execute([$floor, $rack])) {
                echo "<script>alert('Floor $floor, Rack $rack added successfully!'); window.location='library.php';</script>";
            } else {
                echo "<script>alert('Failed to add location.'); window.location='library.php';</script>";
            }
            exit();
        }
    }
}

// Handle edit floor/rack
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_location'])) {
    $id = intval($_POST['location_id']);
    $floor = intval($_POST['floor']);
    $rack = intval($_POST['rack']);
    
    // Check if new rack number already exists on another floor (excluding current)
    $check_rack_stmt = $pdo->prepare("SELECT COUNT(*) FROM floor WHERE rack = ? AND id != ?");
    $check_rack_stmt->execute([$rack, $id]);
    $rack_exists = $check_rack_stmt->fetchColumn();
    
    if ($rack_exists) {
        echo "<script>alert('Rack number $rack already exists on another floor! Each rack number must be unique across all floors.'); window.location='library.php';</script>";
        exit();
    } else {
        // Check if new floor/rack combination already exists (excluding current)
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM floor WHERE floor = ? AND rack = ? AND id != ?");
        $check_stmt->execute([$floor, $rack, $id]);
        $exists = $check_stmt->fetchColumn();

        if ($exists) {
            echo "<script>alert('Floor $floor, Rack $rack already exists!'); window.location='library.php';</script>";
            exit();
        } else {
            $update_stmt = $pdo->prepare("UPDATE floor SET floor = ?, rack = ? WHERE id = ?");
            if ($update_stmt->execute([$floor, $rack, $id])) {
                echo "<script>alert('Rack updated successfully!'); window.location='library.php';</script>";
            } else {
                echo "<script>alert('Failed to update rack.'); window.location='library.php';</script>";
            }
            exit();
        }
    }
}

// Handle update book copies
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_book_copies'])) {
    $book_id = intval($_POST['book_id']);
    $new_available = intval($_POST['copies_available']);
    $floor_id = isset($_POST['floor_id']) ? intval($_POST['floor_id']) : 0;
    $floor_num = isset($_POST['floor_num']) ? intval($_POST['floor_num']) : 0;
    $rack_num = isset($_POST['rack_num']) ? intval($_POST['rack_num']) : 0;
    
    // Get current book data
    $get_book = $pdo->prepare("SELECT copies FROM books WHERE id = ?");
    $get_book->execute([$book_id]);
    $total_copies = $get_book->fetchColumn();
    
    // Get actual borrowed count from borrow_requests (status 1 or 2)
    $check_active = $pdo->prepare("
        SELECT COUNT(*) FROM borrow_requests 
        WHERE book_id = ? AND book_status_id IN (1, 2)
    ");
    $check_active->execute([$book_id]);
    $active_borrow_count = $check_active->fetchColumn();
    
    // Calculate maximum allowed available copies
    $max_allowed = $total_copies - $active_borrow_count;
    
    // Validation
    $errors = [];
    
    if ($new_available < 0) {
        $errors[] = "Available copies cannot be negative.";
    }
    if ($new_available > $total_copies) {
        $errors[] = "Available copies cannot exceed total copies ($total_copies).";
    }
    if ($active_borrow_count > 0 && $new_available > $max_allowed) {
        $errors[] = "Cannot set available copies to $new_available. This book has $active_borrow_count active borrow(s).";
    }
    
    // Return JSON for AJAX response
    header('Content-Type: application/json');
    
    if (empty($errors)) {
        try {
            // Update copies_available
            $update_stmt = $pdo->prepare("UPDATE books SET copies_available = ? WHERE id = ?");
            if ($update_stmt->execute([$new_available, $book_id])) {
                // Update the 'available' flag based on copies_available
                $available_flag = ($new_available > 0) ? 1 : 0;
                $avail_stmt = $pdo->prepare("UPDATE books SET available = ? WHERE id = ?");
                $avail_stmt->execute([$available_flag, $book_id]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Available copies updated successfully! Total: $total_copies, Available: $new_available",
                    'floor_id' => $floor_id,
                    'floor_num' => $floor_num,
                    'rack_num' => $rack_num
                ]);
                exit();
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update available copies.']);
                exit();
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit();
        }
    } else {
        echo json_encode(['success' => false, 'message' => implode("\n", $errors)]);
        exit();
    }
}

// Handle delete single rack
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Check if any books are using this location
    $check_books = $pdo->prepare("SELECT COUNT(*) FROM books WHERE floor_id = ?");
    $check_books->execute([$delete_id]);
    $book_count = $check_books->fetchColumn();
    
    // Get rack info for popup message
    $rack_info = $pdo->prepare("SELECT floor, rack FROM floor WHERE id = ?");
    $rack_info->execute([$delete_id]);
    $rack = $rack_info->fetch();
    $rack_display = $rack ? "Floor {$rack['floor']}, Rack {$rack['rack']}" : "Location";
    
    if ($book_count > 0) {
        echo "<script>alert('Cannot delete this rack because $book_count book(s) are currently using it. Update the books first.'); window.location='library.php';</script>";
    } else {
        $delete_stmt = $pdo->prepare("DELETE FROM floor WHERE id = ?");
        if ($delete_stmt->execute([$delete_id])) {
            echo "<script>alert('Rack deleted successfully!'); window.location='library.php';</script>";
        } else {
            echo "<script>alert('Failed to delete rack.'); window.location='library.php';</script>";
        }
    }
    exit();
}

// Handle delete entire floor
if (isset($_GET['delete_floor'])) {
    $floor_num = intval($_GET['delete_floor']);
    
    // Check if any books exist on this floor
    $check_books = $pdo->prepare("SELECT COUNT(*) FROM books b JOIN floor f ON b.floor_id = f.id WHERE f.floor = ?");
    $check_books->execute([$floor_num]);
    $book_count = $check_books->fetchColumn();
    
    if ($book_count > 0) {
        echo "<script>alert('Cannot delete Floor $floor_num because $book_count book(s) are currently using racks on this floor. Update the books first.'); window.location='library.php';</script>";
    } else {
        // Delete all racks on this floor
        $delete_stmt = $pdo->prepare("DELETE FROM floor WHERE floor = ?");
        if ($delete_stmt->execute([$floor_num])) {
            echo "<script>alert('Floor $floor_num and all its racks deleted successfully!'); window.location='library.php';</script>";
        } else {
            echo "<script>alert('Failed to delete floor.'); window.location='library.php';</script>";
        }
    }
    exit();
}

// Get all floor and rack configurations
$locations = [];
$loc_stmt = $pdo->query("SELECT id, floor, rack FROM floor ORDER BY floor ASC, rack ASC");
$locations = $loc_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all unique floors
$all_floors = [];
foreach ($locations as $loc) {
    if (!in_array($loc['floor'], $all_floors)) {
        $all_floors[] = $loc['floor'];
    }
}

// Get floor groups for display
$floors = [];
foreach ($locations as $loc) {
    $floors[$loc['floor']][] = $loc;
}

// Get book counts per location with copies details (with filters applied)
$book_counts = [];
$copies_total = [];
$copies_available_total = [];
$books_in_rack = [];

// Build filtered query for books
$filtered_sql = "SELECT b.floor_id, b.id as book_id, b.title, b.author, b.copies, b.copies_available, b.available, b.cover_image, r.row as row_name
        FROM books b
        LEFT JOIN row r ON b.row_id = r.id
        LEFT JOIN book_categories bc ON bc.id = b.category_id
        LEFT JOIN book_languages bl ON bl.id = b.language_id
        WHERE 1=1";
$filter_params = [];

if ($search_term !== '') {
    $filtered_sql .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
    $filter_params[] = "%$search_term%";
    $filter_params[] = "%$search_term%";
    $filter_params[] = "%$search_term%";
}
if ($category_filter !== '' && $category_filter > 0) {
    $filtered_sql .= " AND b.category_id = ?";
    $filter_params[] = $category_filter;
}
if ($language_filter !== '' && $language_filter > 0) {
    $filtered_sql .= " AND b.language_id = ?";
    $filter_params[] = $language_filter;
}

$filtered_sql .= " ORDER BY b.floor_id, r.id ASC";
$count_stmt = $pdo->prepare($filtered_sql);
$count_stmt->execute($filter_params);

while ($row = $count_stmt->fetch()) {
    $floor_id = $row['floor_id'];
    $book_counts[$floor_id] = ($book_counts[$floor_id] ?? 0) + 1;
    $copies_total[$floor_id] = ($copies_total[$floor_id] ?? 0) + $row['copies'];
    $copies_available_total[$floor_id] = ($copies_available_total[$floor_id] ?? 0) + $row['copies_available'];
    
    if (!isset($books_in_rack[$floor_id])) {
        $books_in_rack[$floor_id] = [];
    }
    $books_in_rack[$floor_id][] = $row;
}

// Get ALL book counts per location (without filters) for delete button logic
$all_book_counts = [];
$all_copies_total = [];
$all_copies_available_total = [];

$all_count_stmt = $pdo->query("
    SELECT b.floor_id, b.id as book_id, b.copies, b.copies_available
    FROM books b
    ORDER BY b.floor_id
");

while ($row = $all_count_stmt->fetch()) {
    $floor_id = $row['floor_id'];
    $all_book_counts[$floor_id] = ($all_book_counts[$floor_id] ?? 0) + 1;
    $all_copies_total[$floor_id] = ($all_copies_total[$floor_id] ?? 0) + $row['copies'];
    $all_copies_available_total[$floor_id] = ($all_copies_available_total[$floor_id] ?? 0) + $row['copies_available'];
}

// Also update the rack card display to show filtered book counts
$book_count_this = isset($book_counts[$loc['id']]) ? $book_counts[$loc['id']] : 0;
$total_copies_this = isset($copies_total[$loc['id']]) ? $copies_total[$loc['id']] : 0;
$available_copies_this = isset($copies_available_total[$loc['id']]) ? $copies_available_total[$loc['id']] : 0;

// Calculate statistics
$total_locations = count($locations);
$total_books_in_locations = array_sum($book_counts);
$floor_counts = [];
foreach ($all_floors as $floor) {
    $floor_counts[$floor] = isset($floors[$floor]) ? count($floors[$floor]) : 0;
}

// Get all rows for dropdown
$rows = [];
$row_stmt = $pdo->query("SELECT id, row FROM row ORDER BY id");
$rows = $row_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Library Configuration - Manage Floors & Racks</title>
    <link rel="stylesheet" href="style.css">
</head>
<style>
    .search-container {
        position: relative;
        flex: 3;
    }
    .search-clear {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        font-size: 16px;
        cursor: pointer;
        color: #9ca3af;
        display: none;
    }
    .search-clear:hover {
        color: #6b7280;
    }
    .filter-group select {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        font-size: 13px;
        background: white;
        cursor: pointer;
    }
    .clear-filters:hover {
        background: #fecaca;
    }
    .result-count {
        background: #f3f4f6;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
    }
    .live-search-status {
        font-size: 12px;
        color: #6b7280;
        margin-top: 8px;
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
</style>
<body>
    <?php 
    $active_tab = 'library';
    include 'includes/librarians_header.php'; 
    ?>
    
    <div class="main-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <h2 style="font-size: 24px; font-weight: 700; color: #111827;">Library Configuration</h2>
            <div style="display: flex; gap: 12px;">
                <a href="librarians_main.php" class="btn btn-secondary">← Back to Dashboard</a>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="location-stats">
            <div class="stat-card-simple">
                <div class="stat-number"><?php echo $total_locations; ?></div>
                <div class="stat-label">Total Racks</div>
            </div>
            <div class="stat-card-simple">
                <div class="stat-number"><?php echo $total_books_in_locations; ?></div>
                <div class="stat-label">Total Books</div>
            </div>
            <?php foreach ($all_floors as $floor): ?>
            <div class="stat-card-simple">
                <div class="stat-number"><?php echo $floor_counts[$floor]; ?></div>
                <div class="stat-label">Floor <?php echo $floor; ?> Racks</div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Search and Filter Bar -->
        <div style="margin-bottom: 24px;">
            <form method="get" action="library.php" id="searchForm" style="margin-bottom: 16px;">
                <div style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                    <div class="search-container" style="flex: 3; position: relative;">
                        <input type="text" 
                            name="search" 
                            id="searchInput" 
                            placeholder="Search by Book Title / Author / ISBN" 
                            value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                            autocomplete="off"
                            style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; padding-right: 35px;">
                        <button type="button" class="search-clear" id="clearSearchBtn" onclick="clearSearch()" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; font-size: 16px; cursor: pointer; color: #9ca3af; display: none;">&times;</button>
                    </div>
                    
                    <div class="filter-group" style="flex: 1;">
                        <select name="category_filter" id="categoryFilter" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; background: white; cursor: pointer;">
                            <option value="">All Categories</option>
                            <?php
                            // Get all categories for filter
                            $cat_filter_stmt = $pdo->query("SELECT id, category FROM book_categories ORDER BY category ASC");
                            $filter_categories = $cat_filter_stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($filter_categories as $cat):
                                $selected = (isset($_GET['category_filter']) && $_GET['category_filter'] == $cat['id']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($cat['category']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group" style="flex: 1;">
                        <select name="language_filter" id="languageFilter" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; background: white; cursor: pointer;">
                            <option value="">All Languages</option>
                            <?php
                            // Get all languages for filter
                            $lang_filter_stmt = $pdo->query("SELECT id, language FROM book_languages ORDER BY language ASC");
                            $filter_languages = $lang_filter_stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($filter_languages as $lang):
                                $selected = (isset($_GET['language_filter']) && $_GET['language_filter'] == $lang['id']) ? 'selected' : '';
                            ?>
                                <option value="<?php echo $lang['id']; ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($lang['language']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <a href="library.php" class="btn btn-secondary" style="padding: 10px 20px; text-decoration: none; display: inline-block;">Reset All</a>
                    </div>
                </div>
            </form>

            <!-- Active Filters Display -->
            <div class="live-search-status" id="searchStatus" style="font-size: 12px; color: #6b7280; margin-top: 8px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <?php              
                // Get total books count based on filters
                $filter_sql = "SELECT COUNT(DISTINCT b.id) FROM books b LEFT JOIN book_categories bc ON bc.id = b.category_id LEFT JOIN book_languages bl ON bl.id = b.language_id WHERE 1=1";
                $filter_params = [];
                
                if ($search_term !== '') {
                    $filter_sql .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
                    $filter_params[] = "%$search_term%";
                    $filter_params[] = "%$search_term%";
                    $filter_params[] = "%$search_term%";
                }
                if ($category_filter !== '' && $category_filter > 0) {
                    $filter_sql .= " AND b.category_id = ?";
                    $filter_params[] = $category_filter;
                }
                if ($language_filter !== '' && $language_filter > 0) {
                    $filter_sql .= " AND b.language_id = ?";
                    $filter_params[] = $language_filter;
                }
                
                $count_stmt = $pdo->prepare($filter_sql);
                $count_stmt->execute($filter_params);
                $filtered_count = $count_stmt->fetchColumn();
                ?>
                
                <?php if ($search_term !== '' || $category_filter !== '' || $language_filter !== ''): ?>
                    <span class="result-count" style="background: #f3f4f6; padding: 4px 12px; border-radius: 20px; font-size: 12px;">🔍 Found <?php echo $filtered_count; ?> book(s)</span>
                    <?php if ($search_term !== ''): ?>
                        <span class="result-count" style="background: #f3f4f6; padding: 4px 12px; border-radius: 20px; font-size: 12px;">🔎 Search: "<strong><?php echo htmlspecialchars($search_term); ?></strong>"</span>
                    <?php endif; ?>
                    <?php if ($category_filter !== ''): 
                        $cat_name_stmt = $pdo->prepare("SELECT category FROM book_categories WHERE id = ?");
                        $cat_name_stmt->execute([$category_filter]);
                        $cat_name = $cat_name_stmt->fetchColumn();
                    ?>
                        <span class="result-count" style="background: #f3f4f6; padding: 4px 12px; border-radius: 20px; font-size: 12px;">📚 Category: <strong><?php echo htmlspecialchars($cat_name); ?></strong></span>
                    <?php endif; ?>
                    <?php if ($language_filter !== ''):
                        $lang_name_stmt = $pdo->prepare("SELECT language FROM book_languages WHERE id = ?");
                        $lang_name_stmt->execute([$language_filter]);
                        $lang_name = $lang_name_stmt->fetchColumn();
                    ?>
                        <span class="result-count" style="background: #f3f4f6; padding: 4px 12px; border-radius: 20px; font-size: 12px;">🌐 Language: <strong><?php echo htmlspecialchars($lang_name); ?></strong></span>
                    <?php endif; ?>
                    <button type="button" class="clear-filters" onclick="clearAllFilters()" style="display: inline-flex; align-items: center; gap: 4px; background: #fee2e2; color: #dc2626; border: none; font-size: 12px; padding: 4px 12px; border-radius: 20px; cursor: pointer;">✖ Clear All Filters</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Floor Configuration Sections -->
        <?php foreach ($all_floors as $floor_num): ?>
        <?php if (isset($floors[$floor_num]) && count($floors[$floor_num]) > 0): 
            // Check if this floor has any racks with books that match the filters
            $floor_has_matching_books = false;
            foreach ($floors[$floor_num] as $loc) {
                $book_count_this = isset($book_counts[$loc['id']]) ? $book_counts[$loc['id']] : 0;
                if ($book_count_this > 0) {
                    $floor_has_matching_books = true;
                    break;
                }
            }
            
            // Only show the floor if there are matching books OR no filters are applied
            $has_filters = ($search_term !== '' || $category_filter !== '' || $language_filter !== '');
            if (!$has_filters || ($has_filters && $floor_has_matching_books)):
        ?>
        <div class="floor-section">
            <div class="floor-header-with-delete">
                <h3 class="floor-title">📍 Floor <?php echo $floor_num; ?></h3>
                <?php if ($_SESSION['user_type_id'] == 1): ?>
                    <?php 
                    // Check if floor has any books at all (not just matching)
                    $floor_has_any_books = false;
                    foreach ($floors[$floor_num] as $loc) {
                        $book_count_all = isset($all_book_counts[$loc['id']]) ? $all_book_counts[$loc['id']] : 0;
                        if ($book_count_all > 0) {
                            $floor_has_any_books = true;
                            break;
                        }
                    }
                    ?>
                    <?php if (!$floor_has_any_books): ?>
                        <a href="library.php?delete_floor=<?php echo $floor_num; ?>" 
                        class="btn-delete-floor" 
                        onclick="return confirm('Are you sure you want to delete Floor <?php echo $floor_num; ?> and all its racks? This action cannot be undone.');">
                            🗑️ Delete Floor
                        </a>
                    <?php else: ?>
                        <button class="btn-delete-floor" disabled title="Cannot delete: Some racks have books">
                            🗑️ Delete Floor
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="location-container">
                <div class="racks-grid">
                    <?php foreach ($floors[$floor_num] as $loc): 
                        $book_count_this = isset($book_counts[$loc['id']]) ? $book_counts[$loc['id']] : 0;
                        $total_copies_this = isset($copies_total[$loc['id']]) ? $copies_total[$loc['id']] : 0;
                        $available_copies_this = isset($copies_available_total[$loc['id']]) ? $copies_available_total[$loc['id']] : 0;
                        
                        // Only show rack if it has books matching filters OR no filters are applied
                        $has_filters = ($search_term !== '' || $category_filter !== '' || $language_filter !== '');
                        if (!$has_filters || ($has_filters && $book_count_this > 0)):
                    ?>
                        <div class="rack-card" onclick="showBooksInRackModal(<?php echo $loc['id']; ?>, <?php echo $loc['floor']; ?>, <?php echo $loc['rack']; ?>)">
                            <div class="rack-number">Rack <?php echo $loc['rack']; ?></div>
                            <div class="rack-location">Floor <?php echo $loc['floor']; ?></div>
                            <div class="book-count">📚 <?php echo $book_count_this; ?> book(s)</div>
                            <div class="copies-info">
                                <span class="total-copies">📖 Total: <?php echo $total_copies_this; ?></span>
                                <span class="available-copies">✅ Available: <?php echo $available_copies_this; ?></span>
                            </div>
                            <div class="rack-actions">
                                <?php if ($_SESSION['user_type_id'] == 1): ?>
                                    <button class="btn-edit-rack" onclick="event.stopPropagation(); openEditModal(<?php echo $loc['id']; ?>, <?php echo $loc['floor']; ?>, <?php echo $loc['rack']; ?>)">Edit</button>
                                    <?php 
                                    // Check if rack has any books at all (not just matching)
                                    $rack_has_any_books = false;
                                    foreach ($all_book_counts as $rack_id => $count) {
                                        if ($rack_id == $loc['id'] && $count > 0) {
                                            $rack_has_any_books = true;
                                            break;
                                        }
                                    }
                                    ?>
                                    <?php if (!$rack_has_any_books): ?>
                                        <a href="library.php?delete_id=<?php echo $loc['id']; ?>" class="btn-delete-rack" onclick="event.stopPropagation(); return confirm('Are you sure you want to delete Rack <?php echo $loc['rack']; ?>? This action cannot be undone.');">Delete</a>
                                    <?php else: ?>
                                        <button class="btn-delete-rack" disabled style="opacity: 0.5; cursor: not-allowed;" title="Cannot delete: <?php echo $book_count_this; ?> book(s) use this location">Delete</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; endif; ?>
        <?php endforeach; ?>

        <!-- Show message when no racks match filters -->
        <?php if ($search_term !== '' || $category_filter !== '' || $language_filter !== ''): 
            $total_matching_racks = 0;
            foreach ($all_floors as $floor_num) {
                if (isset($floors[$floor_num])) {
                    foreach ($floors[$floor_num] as $loc) {
                        if (isset($book_counts[$loc['id']]) && $book_counts[$loc['id']] > 0) {
                            $total_matching_racks++;
                        }
                    }
                }
            }
            if ($total_matching_racks == 0):
        ?>
            <div style="text-align: center; padding: 60px 20px; background: #f9fafb; border-radius: 12px; margin-top: 24px;">
                <p style="color: #6b7280; font-size: 16px;">📖 No books found matching your filters.</p>
                <p style="color: #9ca3af; font-size: 13px; margin-top: 8px;">Try adjusting your search or <a href="library.php" style="color: #4f46e5;">reset all filters</a>.</p>
            </div>
        <?php endif; endif; ?>

        <!-- Admin-only Forms -->
        <?php if ($_SESSION['user_type_id'] == 1): ?>
            <!-- Add New Floor Form -->
            <div class="add-location-form">
                <h4>🏢 Add New Floor</h4>
                <form method="POST" onsubmit="return confirm('Adding a new floor will create 10 new racks. Continue?');">
                    <input type="hidden" name="add_floor" value="1">
                    <div class="form-row-inline">
                        <div class="form-group-inline">
                            <label>Floor Number</label>
                            <input type="number" name="new_floor" min="1" max="10" required placeholder="Enter floor number">
                        </div>
                        <div class="form-group-inline">
                            <button type="submit" class="btn btn-primary" style="width: auto; padding: 10px 24px;">Add Floor</button>
                        </div>
                    </div>
                    <p style="font-size: 11px; color: #6b7280; margin-top: 8px;">
                        <strong>Note:</strong> Adding a new floor will create 10 racks with unique rack numbers.
                    </p>
                </form>
            </div>

            <!-- Add New Rack Form -->
            <div class="add-location-form">
                <h4>➕ Add New Rack</h4>
                <form method="POST">
                    <input type="hidden" name="add_location" value="1">
                    <div class="form-row-inline">
                        <div class="form-group-inline">
                            <label>Floor Number</label>
                            <select name="floor" required>
                                <?php foreach ($all_floors as $floor_num): ?>
                                    <option value="<?php echo $floor_num; ?>">Floor <?php echo $floor_num; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group-inline">
                            <label>Rack Number</label>
                            <input type="number" name="rack" min="1" max="999" required placeholder="Enter rack number">
                        </div>
                        <div class="form-group-inline">
                            <button type="submit" class="btn btn-primary" style="width: auto; padding: 10px 24px;">Add Rack</button>
                        </div>
                    </div>
                    <p style="font-size: 11px; color: #6b7280; margin-top: 8px;">
                        <strong>Note:</strong> Rack numbers must be <strong>unique across all floors</strong>. You cannot use the same rack number on different floors.
                    </p>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal for Editing Rack -->
    <div id="editRackModal" class="modal" style="display: none;">
        <div class="modal-content modal-small">
            <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 20px;">Edit Rack Location</h3>
            <form method="POST">
                <input type="hidden" name="edit_location" value="1">
                <input type="hidden" name="location_id" id="edit_location_id">
                <div style="margin-bottom: 16px;">
                    <label class="required" style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Floor Number</label>
                    <select name="floor" id="edit_floor" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                        <?php foreach ($all_floors as $floor_num): ?>
                            <option value="<?php echo $floor_num; ?>">Floor <?php echo $floor_num; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-bottom: 20px;">
                    <label class="required" style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Rack Number</label>
                    <input type="number" name="rack" id="edit_rack" required min="1" max="999" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                </div>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal for Books in Rack -->
    <div id="booksInRackModal" class="modal" style="display: none;">
        <div class="modal-content modal-books">
            <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 20px;" id="modalRackTitle">Books in Rack</h3>
            <div id="booksListContainer" class="books-list">
                <p style="text-align: center; padding: 20px; color: #6b7280;">Loading books...</p>
            </div>
            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="closeBooksModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Modal for Editing Book Copies -->
    <div id="editBookModal" class="modal" style="display: none;">
        <div class="modal-content modal-small">
            <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 20px;" id="editBookTitle">Edit Book Copies</h3>
            <form method="POST" id="editBookForm">
                <input type="hidden" name="update_book_copies" value="1">
                <input type="hidden" name="book_id" id="edit_book_id">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Total Copies</label>
                    <input type="number" name="copies" id="edit_total_copies" readonly disabled style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f3f4f6; color: #6b7280; cursor: not-allowed;">
                    <p style="font-size: 11px; color: #6b7280; margin-top: 4px;">Total copies cannot be changed here. Use Manage Book page.</p>
                </div>
                <div style="margin-bottom: 20px;">
                    <label class="required" style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px;">Available Copies</label>
                    <input type="number" name="copies_available" id="edit_available_copies" min="0" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <p style="font-size: 11px; color: #6b7280; margin-top: 4px;">Available copies cannot exceed total copies</p>
                </div>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeEditBookModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Auto-hide messages after 5 seconds
        setTimeout(function() {
            var successMsg = document.getElementById('successMsg');
            var errorMsg = document.getElementById('errorMsg');
            if (successMsg) {
                successMsg.style.transition = 'opacity 0.5s';
                successMsg.style.opacity = '0';
                setTimeout(function() {
                    if (successMsg) successMsg.style.display = 'none';
                }, 500);
            }
            if (errorMsg) {
                errorMsg.style.transition = 'opacity 0.5s';
                errorMsg.style.opacity = '0';
                setTimeout(function() {
                    if (errorMsg) errorMsg.style.display = 'none';
                }, 500);
            }
        }, 5000);

        function openEditModal(id, floor, rack) {
            document.getElementById('edit_location_id').value = id;
            document.getElementById('edit_floor').value = floor;
            document.getElementById('edit_rack').value = rack;
            document.getElementById('editRackModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editRackModal').style.display = 'none';
        }

        function closeBooksModal() {
            document.getElementById('booksInRackModal').style.display = 'none';
        }

        function showBooksInRackModal(floorId, floorNum, rackNum) {
            const modal = document.getElementById('booksInRackModal');
            const title = document.getElementById('modalRackTitle');
            const container = document.getElementById('booksListContainer');
            
            title.innerHTML = `Books in Floor ${floorNum}, Rack ${rackNum}`;
            container.innerHTML = '<p style="text-align: center; padding: 20px; color: #6b7280;">Loading books...</p>';
            modal.style.display = 'flex';
            
            // Fetch books via AJAX
            fetch(`get_books_by_rack.php?floor_id=${floorId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.books.length > 0) {
                        let html = '';
                        data.books.forEach(book => {
                            // Determine row class and display text
                            let rowClass = '';
                            let rowDisplay = '';
                            if (book.row_name == 'Top') {
                                rowClass = 'row-top';
                                rowDisplay = 'Top Row';
                            } else if (book.row_name == 'Middle') {
                                rowClass = 'row-middle';
                                rowDisplay = 'Middle Row';
                            } else if (book.row_name == 'Bottom') {
                                rowClass = 'row-bottom';
                                rowDisplay = 'Bottom Row';
                            } else {
                                rowClass = '';
                                rowDisplay = book.row_name || 'Unknown';
                            }
                            
                            // Warning for active borrows
                            let warningHtml = '';
                            if (book.has_active_borrow) {
                                warningHtml = `<span style="color: #f59e0b; font-size: 11px; margin-left: 8px;">⚠️ ${book.active_borrow_count} active borrow(s)</span>`;
                            }
                            
                            // Escape special characters for JavaScript
                            let safeTitle = escapeHtml(book.title);
                            let safeAuthor = escapeHtml(book.author);
                            
                            html += `
                                <div class="book-item">
                                    <img src="${book.cover_image || 'cover/default.jpg'}" alt="${safeTitle}" class="book-item-cover" onerror="this.src='cover/default.jpg'">
                                    <div class="book-item-info">
                                        <div class="book-item-title">
                                            ${safeTitle}
                                            <span class="row-badge ${rowClass}">${rowDisplay}</span>
                                            ${warningHtml}
                                        </div>
                                        <div class="book-item-author">by ${safeAuthor}</div>
                                        <div class="book-item-details">
                                            <span>📖 Total: ${book.copies}</span>
                                            <span>✅ Available: ${book.copies_available}</span>
                                            <button class="edit-book-btn" onclick="openEditBookModalFromList(${book.book_id}, ${book.copies}, ${book.copies_available}, '${safeTitle.replace(/'/g, "\\'")}', ${book.has_active_borrow}, ${book.active_borrow_count}, ${floorId}, ${floorNum}, ${rackNum})">Edit Copies</button>
                                        </div>
                                    </div>
                                </div>
                            `;
                        });
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<p style="text-align: center; padding: 40px; color: #9ca3af;">📖 No books found in this rack.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = '<p style="text-align: center; padding: 20px; color: #ef4444;">Failed to load books. Please try again.</p>';
                });
        }

        function openEditBookModalFromList(bookId, totalCopies, availableCopies, bookTitle, hasActiveBorrow, activeBorrowCount, floorId, floorNum, rackNum) {
            console.log('Opening modal from list:', bookId, totalCopies, availableCopies, bookTitle, hasActiveBorrow, activeBorrowCount, floorId, floorNum, rackNum);
            document.getElementById('edit_book_id').value = bookId;
            document.getElementById('edit_total_copies').value = totalCopies;
            
            // Add floor info to the form
            let floorIdInput = document.getElementById('edit_floor_id');
            let floorNumInput = document.getElementById('edit_floor_num');
            let rackNumInput = document.getElementById('edit_rack_num');
            
            if (!floorIdInput) {
                // Create hidden inputs if they don't exist
                const form = document.getElementById('editBookForm');
                floorIdInput = document.createElement('input');
                floorIdInput.type = 'hidden';
                floorIdInput.name = 'floor_id';
                floorIdInput.id = 'edit_floor_id';
                floorNumInput = document.createElement('input');
                floorNumInput.type = 'hidden';
                floorNumInput.name = 'floor_num';
                floorNumInput.id = 'edit_floor_num';
                rackNumInput = document.createElement('input');
                rackNumInput.type = 'hidden';
                rackNumInput.name = 'rack_num';
                rackNumInput.id = 'edit_rack_num';
                form.appendChild(floorIdInput);
                form.appendChild(floorNumInput);
                form.appendChild(rackNumInput);
            }
            
            floorIdInput.value = floorId;
            floorNumInput.value = floorNum;
            rackNumInput.value = rackNum;
            
            // Calculate maximum allowed available copies
            const maxAllowed = totalCopies - activeBorrowCount;
            
            // Set the available copies input
            const availableInput = document.getElementById('edit_available_copies');
            availableInput.value = availableCopies;
            availableInput.max = maxAllowed;
            availableInput.min = 0;
            
            let warningMessage = '';
            
            if (hasActiveBorrow && activeBorrowCount > 0) {
                warningMessage = `<p style="color: #f59e0b; font-size: 11px; margin-top: 4px; margin-bottom: 0; padding: 8px 12px; background: #fef3c7; border-left: 3px solid #f59e0b; border-radius: 4px;">⚠️ This book has ${activeBorrowCount} active borrow(s).</p>`;
            } else {
                warningMessage = `<p style="color: #10b981; font-size: 11px; margin-top: 4px; margin-bottom: 0; padding: 8px 12px; background: #d1fae5; border-left: 3px solid #10b981; border-radius: 4px;">✅ No active borrows.</p>`;
            }
            
            document.getElementById('editBookTitle').innerHTML = 'Edit Available Copies - ' + bookTitle;
            
            // Add message to modal with spacing
            const warningContainer = document.getElementById('edit_available_warning');
            if (warningContainer) {
                warningContainer.innerHTML = warningMessage;
                warningContainer.style.marginBottom = '20px';
                warningContainer.style.marginTop = '8px';
            } else {
                const availableDiv = document.querySelector('#editBookModal .modal-content div:has(input#edit_available_copies)');
                const warningDiv = document.createElement('div');
                warningDiv.id = 'edit_available_warning';
                warningDiv.style.marginBottom = '20px';
                warningDiv.style.marginTop = '8px';
                warningDiv.innerHTML = warningMessage;
                if (availableDiv && availableDiv.nextSibling) {
                    availableDiv.parentNode.insertBefore(warningDiv, availableDiv.nextSibling);
                } else if (availableDiv) {
                    availableDiv.parentNode.appendChild(warningDiv);
                }
            }
            
            document.getElementById('editBookModal').style.display = 'flex';
        }

        function closeEditBookModal() {
            document.getElementById('editBookModal').style.display = 'none';
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            var editModal = document.getElementById('editRackModal');
            var booksModal = document.getElementById('booksInRackModal');
            var editBookModal = document.getElementById('editBookModal');
            if (event.target == editModal) {
                editModal.style.display = 'none';
            }
            if (event.target == booksModal) {
                booksModal.style.display = 'none';
            }
            if (event.target == editBookModal) {
                editBookModal.style.display = 'none';
            }
        }

        // Get the edit book form
        const editBookForm = document.getElementById('editBookForm');
        if (editBookForm) {
            editBookForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn ? submitBtn.textContent : 'Save Changes';
                
                if (submitBtn) {
                    submitBtn.textContent = 'Saving...';
                    submitBtn.disabled = true;
                }
                
                fetch('library.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showPopupMessage(data.message, 'success');
                        // Close the edit modal
                        closeEditBookModal();
                        // Refresh the books in rack modal if we have floor info
                        if (data.floor_id && data.floor_num && data.rack_num) {
                            // Small delay to let modal close
                            setTimeout(function() {
                                showBooksInRackModal(data.floor_id, data.floor_num, data.rack_num);
                            }, 300);
                        }
                    } else {
                        showPopupMessage(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showPopupMessage('An error occurred. Please try again.', 'error');
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.textContent = originalText;
                        submitBtn.disabled = false;
                    }
                });
            });
        }

        function showPopupMessage(message, type) {
            // Remove existing popup
            const existingPopup = document.querySelector('.popup-message');
            if (existingPopup) existingPopup.remove();
            
            const popup = document.createElement('div');
            popup.className = 'popup-message';
            popup.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: #1a1a1a;
                color: white;
                padding: 16px 24px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                z-index: 10000;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                text-align: center;
                min-width: 300px;
                max-width: 500px;
                animation: popupFadeInOut 2s ease;
                border-left: 4px solid ${type === 'error' ? '#ef4444' : '#10b981'};
            `;
            popup.textContent = message;
            document.body.appendChild(popup);
            
            setTimeout(function() {
                if (popup) popup.remove();
            }, 2000);
        }

        // Add CSS animation for popup if not exists
        if (!document.querySelector('#popup-styles')) {
            const style = document.createElement('style');
            style.id = 'popup-styles';
            style.textContent = `
                @keyframes popupFadeInOut {
                    0% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
                    15% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                    85% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                    100% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
                }
            `;
            document.head.appendChild(style);
        }

            // Auto-submit for filters and search
    let searchDebounceTimer;
    const searchInput = document.getElementById('searchInput');
    const categoryFilter = document.getElementById('categoryFilter');
    const languageFilter = document.getElementById('languageFilter');
    const clearBtn = document.getElementById('clearSearchBtn');

    function submitSearchForm() {
        const searchForm = document.getElementById('searchForm');
        if (searchForm) {
            searchForm.submit();
        }
    }

    // Auto-submit on filter changes
    if (categoryFilter) {
        categoryFilter.addEventListener('change', function() {
            submitSearchForm();
        });
    }
    
    if (languageFilter) {
        languageFilter.addEventListener('change', function() {
            submitSearchForm();
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            if (clearBtn) {
                clearBtn.style.display = this.value.length > 0 ? 'block' : 'none';
            }
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                submitSearchForm();
            }, 400);
        });
    }

    window.clearSearch = function() {
        if (searchInput) {
            searchInput.value = '';
            if (clearBtn) clearBtn.style.display = 'none';
            submitSearchForm();
        }
    };
    
    window.clearAllFilters = function() {
        window.location.href = 'library.php';
    };

    if (searchInput && clearBtn) {
        clearBtn.style.display = searchInput.value.length > 0 ? 'block' : 'none';
    }
    </script>
</body>
</html>