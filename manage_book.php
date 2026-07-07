<?php
session_start();
require_once __DIR__ . '/db.php';  // Use PDO connection

// Check if user is logged in and is admin or librarian
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

// Helper function to validate ISBN format (must be exactly 17 characters with 4 dashes)
function validateISBN($isbn) {
    // Check if exactly 17 characters
    if (strlen($isbn) !== 17) {
        return false;
    }
    
    // Check if there are exactly 4 dashes
    if (substr_count($isbn, '-') !== 4) {
        return false;
    }
    
    // Remove dashes and check if remaining are all digits
    $clean_isbn = str_replace('-', '', $isbn);
    if (!ctype_digit($clean_isbn)) {
        return false;
    }
    
    return true;
}

// Check if ISBN already exists (excluding current book when editing)
function isbnExists($pdo, $isbn, $exclude_id = null) {
    $clean_isbn = str_replace('-', '', $isbn);
    $sql = "SELECT COUNT(*) FROM books WHERE REPLACE(isbn, '-', '') = ?";
    $params = [$clean_isbn];
    
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() > 0;
}

// Helper function to get location string from floor_id and row_id
function getLocationString($pdo, $floor_id, $row_id) {
    $floor_stmt = $pdo->prepare("SELECT floor, rack FROM floor WHERE id = ?");
    $floor_stmt->execute([$floor_id]);
    $floor_data = $floor_stmt->fetch();
    
    $row_stmt = $pdo->prepare("SELECT row FROM row WHERE id = ?");
    $row_stmt->execute([$row_id]);
    $row_data = $row_stmt->fetch();
    
    return "Floor {$floor_data['floor']}, Rack {$floor_data['rack']}, {$row_data['row']} Row";
}

// Handle delete request
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    try {
        // First check if book has any borrow requests
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM borrow_requests WHERE book_id = ?");
        $check_stmt->execute([$delete_id]);
        $borrow_count = $check_stmt->fetchColumn();
        
        if ($borrow_count > 0) {
            echo "<script>alert('Cannot delete this book because it has borrow requests!'); window.location='manage_book.php';</script>";
        } else {
            $delete_stmt = $pdo->prepare("DELETE FROM books WHERE id = ?");
            $delete_stmt->execute([$delete_id]);
            echo "<script>alert('Book deleted successfully!'); window.location='manage_book.php';</script>";
        }
    } catch (PDOException $e) {
        echo "<script>alert('Error deleting book: " . $e->getMessage() . "'); window.location='manage_book.php';</script>";
    }
    exit();
}

// Handle toggle availability request
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_book_id'])) {
    $toggle_id = intval($_POST['toggle_book_id']);
    $current_user_id = $_SESSION['user_id'] ?? null;
    try {
        $stmt = $pdo->prepare('SELECT available FROM books WHERE id = ? LIMIT 1');
        $stmt->execute([$toggle_id]);
        $book = $stmt->fetch();
        if ($book) {
            $new_available = $book['available'] ? 0 : 1;
            $update = $pdo->prepare('UPDATE books SET available = ?, updated_at = NOW(), updated_by = ? WHERE id = ?');
            $update->execute([$new_available, $current_user_id, $toggle_id]);
        }
        $action = $new_available ? 'enabled' : 'disabled';
echo "<script>alert('Book requests have been " . $action . " successfully!'); window.location='manage_book.php';</script>";
    } catch (PDOException $e) {
        echo "<script>alert('Error updating availability: " . $e->getMessage() . "'); window.location='manage_book.php';</script>";
    }
    exit();
}

// Handle update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_id'])) {
    $errors = [];
    $id = intval($_POST['update_id']);
    $title = trim($_POST['title']);
    $author = trim($_POST['author']);
    $isbn = trim($_POST['isbn']);
    $copies = intval($_POST['copies']);
    $copies_available = intval($_POST['copies_available']);
    $category_input = $_POST['category'];
    $description = trim($_POST['description']);
    $publish_date = $_POST['publish_date'];
    $language_input = $_POST['language'];
    $floor_id = intval($_POST['floor_id']);
    $row_id = intval($_POST['row_id']);
    $cover_image_path = $_POST['existing_cover_image'];

    $current_user_id = $_SESSION['user_id'] ?? null;
    
    // Get active borrow count for validation
    $active_borrow_stmt = $pdo->prepare("SELECT COUNT(*) FROM borrow_requests WHERE book_id = ? AND book_status_id IN (1, 2)");
    $active_borrow_stmt->execute([$id]);
    $active_borrow_count = $active_borrow_stmt->fetchColumn();
    
    // Validation
    if (empty($title)) {
        $errors[] = "Title is required.";
    }
    if (empty($author)) {
        $errors[] = "Author is required.";
    }
    if (!empty($isbn) && !validateISBN($isbn)) {
        $errors[] = "ISBN must be 17 characters total including dashes.";
    }
    if (!empty($isbn) && isbnExists($pdo, $isbn, $id)) {
        $errors[] = "ISBN already exists in the database.";
    }
    if ($copies < 1) {
        $errors[] = "Total copies must be at least 1.";
    }
    // Check if total copies is less than active borrow count
    if ($active_borrow_count > 0 && $copies < $active_borrow_count) {
        $errors[] = "Total copies cannot be less than active borrow count ($active_borrow_count).";
    }
    if ($copies_available < 0 || $copies_available > $copies) {
        $errors[] = "Available copies must be between 0 and total copies.";
    }
    // Check if available copies exceeds total minus borrowed
    $max_allowed_available = $copies - $active_borrow_count;
    if ($active_borrow_count > 0 && $copies_available > $max_allowed_available) {
        $errors[] = "Available copies cannot exceed $max_allowed_available when there are $active_borrow_count active borrow(s).";
    }
    if (empty($publish_date)) {
        $errors[] = "Publish date is required.";
    }
    
    // Handle category
    $category_id = null;
    if ($category_input === "Add New" && !empty($_POST['new_category'])) {
        $new_category = trim($_POST['new_category']);
        $check_cat = $pdo->prepare("SELECT id FROM book_categories WHERE category = ?");
        $check_cat->execute([$new_category]);
        $cat = $check_cat->fetch();
        if ($cat) {
            $category_id = $cat['id'];
        } else {
            $insert_cat = $pdo->prepare("INSERT INTO book_categories (category) VALUES (?)");
            $insert_cat->execute([$new_category]);
            $category_id = $pdo->lastInsertId();
        }
    } else {
        $category_id = intval($category_input);
        if ($category_id <= 0) {
            $errors[] = "Please select a category.";
        }
    }
    
    // Handle language
    $language_id = null;
    if ($language_input === "Add New" && !empty($_POST['new_language'])) {
        $new_language = trim($_POST['new_language']);
        $check_lang = $pdo->prepare("SELECT id FROM book_languages WHERE language = ?");
        $check_lang->execute([$new_language]);
        $lang = $check_lang->fetch();
        if ($lang) {
            $language_id = $lang['id'];
        } else {
            $insert_lang = $pdo->prepare("INSERT INTO book_languages (language) VALUES (?)");
            $insert_lang->execute([$new_language]);
            $language_id = $pdo->lastInsertId();
        }
    } else {
        $language_id = intval($language_input);
        if ($language_id <= 0) {
            $errors[] = "Please select a language.";
        }
    }

    // Handle cover image upload
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = "cover/image/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_ext = pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION);
        $file_name = uniqid("book_", true) . "." . $file_ext;
        $cover_image_path = $upload_dir . $file_name;
        move_uploaded_file($_FILES['cover_image']['tmp_name'], $cover_image_path);
    }

    if (empty($errors)) {
        try {
            $sql = "UPDATE books SET 
                title = ?, author = ?, isbn = ?, copies = ?, copies_available = ?,
                category_id = ?, description = ?, publish_date = ?, language_id = ?, 
                cover_image = ?, floor_id = ?, row_id = ?,
                updated_at = NOW(), updated_by = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$title, $author, $isbn, $copies, $copies_available,
                        $category_id, $description, $publish_date, $language_id, 
                        $cover_image_path, $floor_id, $row_id, $current_user_id, $id]);
            echo "<script>alert('Book updated successfully!'); window.location='manage_book.php';</script>";
        } catch (PDOException $e) {
            echo "<script>alert('Error updating book: " . $e->getMessage() . "'); window.location='manage_book.php';</script>";
        }
    } else {
        $error_msg = implode("\\n", $errors);
        echo "<script>alert('Please fix the following errors:\\n$error_msg'); window.history.back();</script>";
    }
    exit();
}

// Handle add new book
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_book'])) {
    $errors = [];
    $title = trim($_POST['title']);
    $author = trim($_POST['author']);
    $isbn = trim($_POST['isbn']);
    $copies = intval($_POST['copies']);
    $copies_available = $copies; // Initially all copies are available
    $category_input = $_POST['category'];
    $description = trim($_POST['description']);
    $publish_date = $_POST['publish_date'];
    $language_input = $_POST['language'];
    $floor_id = intval($_POST['floor_id']);
    $row_id = intval($_POST['row_id']);

    $current_user_id = $_SESSION['user_id'] ?? null;
    
    // Validation - all fields required
    if (empty($title)) {
        $errors[] = "Title is required.";
    }
    if (empty($author)) {
        $errors[] = "Author is required.";
    }
    if (empty($isbn)) {
        $errors[] = "ISBN is required.";
    } elseif (!validateISBN($isbn)) {
        $errors[] = "ISBN must be 17 characters total including dashes";
    } elseif (isbnExists($pdo, $isbn)) {
        $errors[] = "ISBN already exists in the database.";
    }
    if ($copies < 1) {
        $errors[] = "Total copies must be at least 1.";
    }
    if (empty($publish_date)) {
        $errors[] = "Publish date is required.";
    }
    if (empty($description)) {
        $errors[] = "Description is required.";
    }
    
    // Handle category
    $category_id = null;
    if ($category_input === "Add New" && !empty($_POST['new_category'])) {
        $new_category = trim($_POST['new_category']);
        $check_cat = $pdo->prepare("SELECT id FROM book_categories WHERE category = ?");
        $check_cat->execute([$new_category]);
        $cat = $check_cat->fetch();
        if ($cat) {
            $category_id = $cat['id'];
        } else {
            $insert_cat = $pdo->prepare("INSERT INTO book_categories (category) VALUES (?)");
            $insert_cat->execute([$new_category]);
            $category_id = $pdo->lastInsertId();
        }
    } else {
        $category_id = intval($category_input);
        if ($category_id <= 0) {
            $errors[] = "Please select a category.";
        }
    }
    
    // Handle language
    $language_id = null;
    if ($language_input === "Add New" && !empty($_POST['new_language'])) {
        $new_language = trim($_POST['new_language']);
        $check_lang = $pdo->prepare("SELECT id FROM book_languages WHERE language = ?");
        $check_lang->execute([$new_language]);
        $lang = $check_lang->fetch();
        if ($lang) {
            $language_id = $lang['id'];
        } else {
            $insert_lang = $pdo->prepare("INSERT INTO book_languages (language) VALUES (?)");
            $insert_lang->execute([$new_language]);
            $language_id = $pdo->lastInsertId();
        }
    } else {
        $language_id = intval($language_input);
        if ($language_id <= 0) {
            $errors[] = "Please select a language.";
        }
    }

    // Handle cover image upload - REQUIRED
    $cover_image_path = null;
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = "cover/image/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_ext = pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION);
        $file_name = uniqid("book_", true) . "." . $file_ext;
        $cover_image_path = $upload_dir . $file_name;
        move_uploaded_file($_FILES['cover_image']['tmp_name'], $cover_image_path);
    } else {
        $errors[] = "Cover image is required.";
    }

    if (empty($errors)) {
        try {
            $sql = "INSERT INTO books (title, author, isbn, copies, copies_available, available, times_borrowed, 
                    category_id, description, publish_date, language_id, cover_image, floor_id, row_id,
                    created_at, created_by, updated_at, updated_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$title, $author, $isbn, $copies, $copies_available, 1, 0,
                        $category_id, $description, $publish_date, $language_id, 
                        $cover_image_path, $floor_id, $row_id, $current_user_id, $current_user_id]);
            echo "<script>alert('Book added successfully!'); window.location='manage_book.php';</script>";
        } catch (PDOException $e) {
            echo "<script>alert('Error adding book: " . $e->getMessage() . "'); window.location='manage_book.php';</script>";
        }
    } else {
        $error_msg = implode("\\n", $errors);
        echo "<script>alert('Please fix the following errors:\\n$error_msg'); window.history.back();</script>";
    }
    exit();
}

// ========== SEARCH AND SORT LOGIC ==========
// Initialize variables with default values
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'title';
$category_filter = isset($_GET['category_filter']) ? intval($_GET['category_filter']) : '';
$language_filter = isset($_GET['language_filter']) ? intval($_GET['language_filter']) : '';
$request_status = isset($_GET['request_status']) ? $_GET['request_status'] : '';

// Define valid sort options
$sort_options = ['title' => 'Name', 'created_at' => 'Date Added', 'book_location' => 'Location'];
if (!array_key_exists($sort, $sort_options)) {
    $sort = 'title';
}

// Build the SQL query
$sql = "SELECT b.*, bc.category as category_name, bl.language as language_name 
        FROM books b
        LEFT JOIN book_categories bc ON bc.id = b.category_id
        LEFT JOIN book_languages bl ON bl.id = b.language_id";

$params = [];
$where_conditions = [];

// Add search condition
if ($search !== '') {
    $where_conditions[] = "(b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Add category filter
if ($category_filter !== '' && $category_filter > 0) {
    $where_conditions[] = "b.category_id = ?";
    $params[] = $category_filter;
}

// Add language filter
if ($language_filter !== '' && $language_filter > 0) {
    $where_conditions[] = "b.language_id = ?";
    $params[] = $language_filter;
}

// Add request status filter (available column)
if ($request_status !== '') {
    $where_conditions[] = "b.available = ?";
    $params[] = ($request_status == 'allow') ? 1 : 0;
}

// Combine conditions
if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

// Add sorting
if ($sort == 'book_location') {
    // Sort by floor and row using JOINs
    $sql .= " LEFT JOIN floor f ON b.floor_id = f.id
              LEFT JOIN row r ON b.row_id = r.id
              ORDER BY f.floor ASC, f.rack ASC, 
              CASE r.row WHEN 'Top' THEN 1 WHEN 'Middle' THEN 2 WHEN 'Bottom' THEN 3 ELSE 4 END";
} else {
    $order = ($sort == 'created_at') ? 'DESC' : 'ASC';
    $sql .= " ORDER BY b.$sort $order";
}

// Execute the query
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();

// Get all categories for dropdown
$categories = [];
$cat_stmt = $pdo->query("SELECT id, category FROM book_categories ORDER BY category ASC");
$categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all languages
$languages = [];
$lang_stmt = $pdo->query("SELECT id, language FROM book_languages ORDER BY language ASC");
$languages = $lang_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all floor options (id, floor, rack)
$floor_options = [];
$floor_stmt = $pdo->query("SELECT id, floor, rack FROM floor ORDER BY floor ASC, rack ASC");
$floor_options = $floor_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all rows
$rows = [];
$row_stmt = $pdo->query("SELECT id, row FROM row ORDER BY id");
$rows = $row_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Books</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        .modal-content {
            background: white;
            width: 650px;
            margin: 50px auto;
            border-radius: 12px;
            padding: 24px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .required:after {
            content: " *";
            color: red;
        }
        .search-container {
            position: relative;
            flex: 3;
        }
        .search-container input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            padding-right: 35px;
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
        .live-search-status {
            font-size: 12px;
            color: #6b7280;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .result-count {
            background: #f3f4f6;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .book-card {
            animation: fadeIn 0.2s ease-out;
        }
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #4f46e5;
            color: white;
        }
        .btn-primary:hover {
            background: #4338ca;
        }
        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #e5e7eb;
        }
        .btn-secondary:hover {
            background: #e5e7eb;
        }
        .filter-group {
            flex: 1;
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
        .filter-group label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .filter-active {
            background: #4f46e5 !important;
            color: white !important;
        }
        .clear-filters {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fee2e2;
            color: #dc2626;
            border: none;
            font-size: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .clear-filters:hover {
            background: #fecaca;
        }
    </style>
</head>
<body>
    <?php 
    $active_tab = 'dashboard';
    include 'includes/librarians_header.php'; 
    ?>
    
    <div class="main-content">
        <div style="display: flex; justify-content: space-between; align-items: center; <?php echo isset($_GET['edit_id']) ? 'margin-bottom: 0px;' : 'margin-bottom: 24px;'; ?>">
            <h2 style="font-size: 24px; font-weight: 700; color: #111827;">Manage Books</h2>
            <div style="display: flex; gap: 12px;">
                <a href="librarians_main.php" class="btn btn-secondary">← Back To Dashboard</a>
                <button onclick="openAddBookModal()" class="btn btn-primary">+ Add New Book</button>
            </div>
        </div>

        <!-- Search and Filter Bar -->
        <?php if (!isset($_GET['edit_id'])): ?>
        <form method="get" action="manage_book.php" id="searchForm" style="margin-bottom: 16px;">
            <div style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
                <div class="search-container">
                    <input type="text" 
                        name="search" 
                        id="searchInput" 
                        placeholder="Search by Book Title / Author / ISBN" 
                        value="<?php echo htmlspecialchars($search); ?>"
                        autocomplete="off">
                    <button type="button" class="search-clear" id="clearSearchBtn" onclick="clearSearch()">&times;</button>
                </div>
                
                <div class="filter-group">
                    <select name="category_filter" id="categoryFilter">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($category_filter == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <select name="language_filter" id="languageFilter">
                        <option value="">All Languages</option>
                        <?php foreach ($languages as $lang): ?>
                            <option value="<?php echo $lang['id']; ?>" <?php echo ($language_filter == $lang['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lang['language']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <select name="request_status" id="requestStatusFilter">
                        <option value="">All Requests</option>
                        <option value="allow" <?php if ($request_status == 'allow') echo 'selected'; ?>>Allowed Request</option>
                        <option value="disable" <?php if ($request_status == 'disable') echo 'selected'; ?>>Disabled Request</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <select name="sort" id="sortSelect">
                        <option value="title" <?php if ($sort == 'title') echo 'selected'; ?>>Sort By Name</option>
                        <option value="created_at" <?php if ($sort == 'created_at') echo 'selected'; ?>>Sort By Date Added</option>
                        <option value="book_location" <?php if ($sort == 'book_location') echo 'selected'; ?>>Sort By Location</option>
                    </select>
                </div>
                
                <div>
                    <a href="manage_book.php" class="btn btn-secondary" style="padding: 10px 20px; text-decoration: none;">Reset</a>
                </div>
            </div>
        </form>

        <!-- Active Filters Display -->
        <div class="live-search-status" id="searchStatus">
            <?php if ($search !== '' || $category_filter !== '' || $language_filter !== ''): ?>
                <span class="result-count">🔍 Found <?php echo count($books); ?> result(s)</span>
                <?php if ($search !== ''): ?>
                    <span class="result-count">🔎 Search: "<strong><?php echo htmlspecialchars($search); ?></strong>"</span>
                <?php endif; ?>
                <?php if ($category_filter !== ''): 
                    $cat_name = '';
                    foreach ($categories as $cat) {
                        if ($cat['id'] == $category_filter) {
                            $cat_name = $cat['category'];
                            break;
                        }
                    }
                ?>
                    <span class="result-count">📚 Category: <strong><?php echo htmlspecialchars($cat_name); ?></strong></span>
                <?php endif; ?>
                <?php if ($language_filter !== ''):
                    $lang_name = '';
                    foreach ($languages as $lang) {
                        if ($lang['id'] == $language_filter) {
                            $lang_name = $lang['language'];
                            break;
                        }
                    }
                ?>
                    <span class="result-count">🌐 Language: <strong><?php echo htmlspecialchars($lang_name); ?></strong></span>
                <?php endif; ?>
                <?php if ($request_status !== ''): ?>
                    <span class="result-count">🔘 Request Status: <strong><?php echo $request_status == 'allow' ? 'Allowed Request' : 'Disabled Request'; ?></strong></span>
                <?php endif; ?>
                <?php if ($search !== '' || $category_filter !== '' || $language_filter !== ''): ?>
                    <button type="button" class="clear-filters" onclick="clearAllFilters()">✖ Clear All Filters</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Books List -->
        <?php if (!isset($_GET['edit_id']) && count($books) > 0): ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px; margin-top: 20px;">
                <?php foreach ($books as $row): ?>
                    <div class="book-card" style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; display: flex; flex-direction: column;">
                        <div style="display: flex; gap: 16px; padding: 16px;">
                            <img src="<?php echo htmlspecialchars($row['cover_image']); ?>" alt="Cover" style="width: 80px; height: 120px; object-fit: cover; border-radius: 6px;">
                            <div style="flex: 1;">
                                <h4 style="font-size: 14px; font-weight: 600; color: #111827; margin-bottom: 8px;"><?php echo htmlspecialchars($row['title']); ?></h4>
                                <p style="font-size: 12px; color: #6b7280; margin-bottom: 4px;"><strong>Author:</strong> <?php echo htmlspecialchars($row['author']); ?></p>
                                <p style="font-size: 12px; color: #6b7280; margin-bottom: 4px;"><strong>ISBN:</strong> <?php echo htmlspecialchars($row['isbn']); ?></p>
                                <p style="font-size: 12px; color: #6b7280; margin-bottom: 4px;"><strong>Copies:</strong> <?php echo $row['copies']; ?> (<?php echo $row['copies_available']; ?> available)</p>
                                <p style="font-size: 12px; color: #6b7280; margin-bottom: 4px;"><strong>Category:</strong> <?php echo htmlspecialchars($row['category_name']); ?></p>
                                <p style="font-size: 12px; color: #6b7280;"><strong>Language:</strong> <?php echo htmlspecialchars($row['language_name']); ?></p>
                                <?php 
                                $location_display = '';
                                if ($row['floor_id'] && $row['row_id']) {
                                    $floor_stmt = $pdo->prepare("SELECT floor, rack FROM floor WHERE id = ?");
                                    $floor_stmt->execute([$row['floor_id']]);
                                    $floor_data = $floor_stmt->fetch();
                                    $row_stmt = $pdo->prepare("SELECT row FROM row WHERE id = ?");
                                    $row_stmt->execute([$row['row_id']]);
                                    $row_data = $row_stmt->fetch();
                                    $location_display = "Floor {$floor_data['floor']}, Rack {$floor_data['rack']}, {$row_data['row']} Row";
                                }
                                ?>
                                <p style="font-size: 12px; color: #6b7280;"><strong>Location:</strong> <?php echo htmlspecialchars($location_display); ?></p>
                            </div>
                        </div>
                        <div style="padding: 12px 16px; border-top: 1px solid #e5e7eb; display: flex; gap: 8px;">
                            <a href="manage_book.php?edit_id=<?php echo $row['id']; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>&category_filter=<?php echo urlencode($category_filter); ?>&language_filter=<?php echo urlencode($language_filter); ?>" class="btn btn-primary" style="flex: 1; text-align: center;">Edit</a>
                            <a href="manage_book.php?delete_id=<?php echo $row['id']; ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>&category_filter=<?php echo urlencode($category_filter); ?>&language_filter=<?php echo urlencode($language_filter); ?>" class="btn btn-secondary" style="flex: 1; text-align: center;" onclick="return confirm('Are you sure you want to delete this book?');">Delete</a>
                        </div>
                        <div style="padding: 12px 16px; border-top: 1px solid #e5e7eb; display: flex; gap: 8px;">
                            <form method="POST" action="manage_book.php" style="flex: 1;" onsubmit="return confirmToggle('<?php echo addslashes($row['title']); ?>', <?php echo $row['available']; ?>);">
                                <input type="hidden" name="toggle_book_id" value="<?php echo $row['id']; ?>">
                                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                                <input type="hidden" name="category_filter" value="<?php echo htmlspecialchars($category_filter); ?>">
                                <input type="hidden" name="language_filter" value="<?php echo htmlspecialchars($language_filter); ?>">
                                <button type="submit" class="btn <?php echo $row['available'] ? 'btn-secondary' : 'btn-primary'; ?>" style="width: 100%;">
                                    <?php echo $row['available'] ? 'Disable Request' : 'Allow Request'; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif (!isset($_GET['edit_id'])): ?>
            <div style="text-align: center; padding: 60px 20px; background: #f9fafb; border-radius: 12px; margin-top: 24px;">
                <p style="color: #6b7280; font-size: 16px;">📖 No books found matching your criteria.</p>
                <p style="color: #9ca3af; font-size: 13px; margin-top: 8px;">Try adjusting your filters or <a href="manage_book.php" style="color: #4f46e5;">reset all filters</a>.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add Book Modal -->
    <div id="addBookModal" class="modal">
        <div class="modal-content">
            <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 20px;">Add New Book</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="add_book" value="1">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label class="required">Title *</label>
                        <input type="text" name="title" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    <div>
                        <label class="required">Author *</label>
                        <input type="text" name="author" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    <div>
                        <label class="required">ISBN *</label>
                        <input type="text" name="isbn" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    <div>
                        <label class="required">Total Copies *</label>
                        <input type="number" name="copies" min="1" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    <div>
                        <label class="required">Category *</label>
                        <select name="category" id="modal-category-select" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category']); ?></option>
                            <?php endforeach; ?>
                            <option value="Add New">Add New...</option>
                        </select>
                    </div>
                    <div id="modal-new-category-span" style="display: none;">
                        <label>New Category</label>
                        <input type="text" name="new_category" placeholder="Enter new category" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    <div>
                        <label class="required">Publish Date *</label>
                        <input type="date" name="publish_date" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    <div>
                        <label class="required">Language *</label>
                        <select name="language" id="modal-language-select" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                            <option value="">Select Language</option>
                            <?php foreach ($languages as $lang): ?>
                                <option value="<?php echo $lang['id']; ?>"><?php echo htmlspecialchars($lang['language']); ?></option>
                            <?php endforeach; ?>
                            <option value="Add New">Add New...</option>
                        </select>
                    </div>
                    <div id="modal-new-language-span" style="display: none;">
                        <label>New Language</label>
                        <input type="text" name="new_language" placeholder="Enter new language" style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="required">Book Location *</label>
                        <div style="display: flex; gap: 12px;">
                            <select id="modal-floor_id" name="floor_id" required style="flex: 2; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;" onchange="enableModalRowSelect()">
                                <option value="">Select Floor & Rack</option>
                                <?php foreach ($floor_options as $floor_opt): ?>
                                    <option value="<?php echo $floor_opt['id']; ?>">Floor <?php echo $floor_opt['floor']; ?>, Rack <?php echo $floor_opt['rack']; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="modal-row_id" name="row_id" required style="flex: 1; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;" disabled>
                                <option value="">Select Row</option>
                                <?php foreach ($rows as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo $r['row']; ?> Row</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="required">Description *</label>
                        <textarea name="description" rows="3" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"></textarea>
                    </div>
                    <div style="grid-column: span 2;">
                        <label class="required">Cover Image *</label>
                        <input type="file" name="cover_image" accept="image/*" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;">
                    </div>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="submit" class="btn btn-primary">Add Book</button>
                    <button type="button" class="btn btn-secondary" onclick="closeAddBookModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Form Section -->
    <?php if (isset($_GET['edit_id'])): 
        $edit_id = intval($_GET['edit_id']);
        $edit_stmt = $pdo->prepare("
            SELECT b.*, bc.category as category_name, bl.language as language_name,
                u1.username as created_by_username, u2.username as updated_by_username
            FROM books b 
            LEFT JOIN book_categories bc ON bc.id = b.category_id 
            LEFT JOIN book_languages bl ON bl.id = b.language_id
            LEFT JOIN users u1 ON u1.id = b.created_by
            LEFT JOIN users u2 ON u2.id = b.updated_by
            WHERE b.id = ?
        ");
        $edit_stmt->execute([$edit_id]);
        $book_edit = $edit_stmt->fetch();
        
        // Get active borrow count for this book
        $active_borrow_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM borrow_requests 
            WHERE book_id = ? AND book_status_id IN (1, 2)
        ");
        $active_borrow_stmt->execute([$edit_id]);
        $active_borrow_count = $active_borrow_stmt->fetchColumn();
        
        // Calculate min total copies and max available
        $min_total_copies = $active_borrow_count;
        $current_total_copies = $book_edit['copies'];
        $current_available = $book_edit['copies_available'];
        $max_allowed_available = $current_total_copies - $active_borrow_count;
        
        if ($book_edit):
    ?>
    <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 24px; margin-top: 0px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">
                Edit Book — <?php echo htmlspecialchars($book_edit['title']); ?> 
                <?php if (!empty($book_edit['author'])): ?>
                    by <?php echo htmlspecialchars($book_edit['author']); ?>
                <?php endif; ?>
            </h3>
            <a href="manage_book.php?search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>&category_filter=<?php echo urlencode($category_filter); ?>&language_filter=<?php echo urlencode($language_filter); ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 13px; text-decoration: none; width: 25%; text-align: center;">← Back To Manage Books</a>
        </div>
        
        <?php if ($active_borrow_count > 0): ?>
        <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; margin-bottom: 20px; border-radius: 6px;">
            <p style="color: #92400e; font-size: 13px; margin: 0;">
                ⚠️ This book has <strong><?php echo $active_borrow_count; ?> active borrow(s)</strong>. 
                Total copies cannot be less than <strong><?php echo $min_total_copies; ?></strong>.
                Available copies cannot exceed <strong><?php echo $max_allowed_available; ?></strong>.
            </p>
        </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" id="editBookForm">
            <input type="hidden" name="update_id" value="<?php echo $book_edit['id']; ?>">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                                <div style="grid-column: 1 / -1; background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin: 10px 0;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 12px;">Book Created and Last Updated</label>
                    <div style="display: flex; gap: 24px; flex-wrap: wrap; padding-top: 4px;">
                        <div>
                            <span style="font-size: 14px; color: #6b7280;">Created By:</span>
                            <strong style="font-size: 14px; color: #111827; margin-left: 6px;">
                                <?php echo htmlspecialchars($book_edit['created_by_username'] ?? 'System'); ?>
                            </strong>
                        </div>
                        <div>
                            <span style="font-size: 14px; color: #6b7280;">Created At:</span>
                            <strong style="font-size: 14px; color: #111827; margin-left: 6px;">
                                <?php echo $book_edit['created_at'] ? htmlspecialchars(date('Y-m-d H:i:s', strtotime($book_edit['created_at']))) : 'N/A'; ?>
                            </strong>
                        </div>
                        <div>
                            <span style="font-size: 14px; color: #6b7280;">Last Updated By:</span>
                            <strong style="font-size: 14px; color: #111827; margin-left: 6px;">
                                <?php echo htmlspecialchars($book_edit['updated_by_username'] ?? 'System'); ?>
                            </strong>
                        </div>
                        <div>
                            <span style="font-size: 14px; color: #6b7280;">Last Updated At:</span>
                            <strong style="font-size: 14px; color: #111827; margin-left: 6px;">
                                <?php echo $book_edit['updated_at'] ? htmlspecialchars(date('Y-m-d H:i:s', strtotime($book_edit['updated_at']))) : 'N/A'; ?>
                            </strong>
                        </div>
                    </div>
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;" class="required">Title</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($book_edit['title']); ?>" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;" class="required">Author</label>
                    <input type="text" name="author" value="<?php echo htmlspecialchars($book_edit['author']); ?>" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">ISBN</label>
                    <input type="text" name="isbn" value="<?php echo htmlspecialchars($book_edit['isbn']); ?>" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;" class="required">Total Copies</label>
                    <input type="number" name="copies" id="edit_total_copies" value="<?php echo $book_edit['copies']; ?>" 
                           min="<?php echo $min_total_copies; ?>" required 
                           style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <?php if ($active_borrow_count > 0): ?>
                    <p style="font-size: 11px; color: #f59e0b; margin-top: 4px;">Min: <?php echo $min_total_copies; ?> (due to <?php echo $active_borrow_count; ?> active borrow(s))</p>
                    <?php endif; ?>
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;" class="required">Available Copies</label>
                    <input type="number" name="copies_available" id="edit_available_copies" 
                           value="<?php echo $book_edit['copies_available']; ?>" 
                           min="0" max="<?php echo $book_edit['copies']; ?>" required 
                           style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                    <?php if ($active_borrow_count > 0): ?>
                    <p style="font-size: 11px; color: #f59e0b; margin-top: 4px;">Max: <?php echo $max_allowed_available; ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;" class="required">Category</label>
                    <select name="category" id="category-select" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($cat['id'] == $book_edit['category_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['category']); ?></option>
                        <?php endforeach; ?>
                        <option value="Add New">Add New Category...</option>
                    </select>
                </div>
                <div id="new-category-span" style="display: none;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">New Category</label>
                    <input type="text" name="new_category" placeholder="Enter new category" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;" class="required">Publish Date</label>
                    <input type="date" name="publish_date" value="<?php echo $book_edit['publish_date']; ?>" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;" class="required">Language</label>
                    <select name="language" id="language-select" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                        <option value="">Select Language</option>
                        <?php foreach ($languages as $lang): ?>
                            <option value="<?php echo $lang['id']; ?>" <?php echo ($lang['id'] == $book_edit['language_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($lang['language']); ?></option>
                        <?php endforeach; ?>
                        <option value="Add New">Add New Language...</option>
                    </select>
                </div>
                <div id="new-language-span" style="display: none;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">New Language</label>
                    <input type="text" name="new_language" placeholder="Enter new language" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                </div>
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;" class="required">Book Location</label>
                    <div style="display: flex; gap: 12px;">
                        <select id="floor_id" name="floor_id" required style="flex: 2; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;" onchange="enableRowSelect()">
                            <option value="">Select Floor & Rack</option>
                            <?php foreach ($floor_options as $floor_opt): ?>
                                <option value="<?php echo $floor_opt['id']; ?>">Floor <?php echo $floor_opt['floor']; ?>, Rack <?php echo $floor_opt['rack']; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="row_id" name="row_id" required style="flex: 1; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;" disabled>
                            <option value="">Select Row</option>
                            <?php foreach ($rows as $r): ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo $r['row']; ?> Row</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="grid-column: 1 / -1;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Description</label>
                    <textarea name="description" rows="3" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;"><?php echo htmlspecialchars($book_edit['description']); ?></textarea>
                </div>
                <div style="grid-column: 1 / -1;">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Current Cover Image</label>
                    <img src="<?php echo htmlspecialchars($book_edit['cover_image']); ?>" alt="Cover" style="width: 80px; height: 120px; object-fit: cover; border-radius: 6px; margin-bottom: 12px;">
                    <input type="hidden" name="existing_cover_image" value="<?php echo htmlspecialchars($book_edit['cover_image']); ?>">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">New Cover Image (Optional)</label>
                    <input type="file" name="cover_image" accept="image/*" style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px;">
                </div>
            </div>
            <div style="display: flex; gap: 12px; margin-top: 24px;">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="manage_book.php?search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>&category_filter=<?php echo urlencode($category_filter); ?>&language_filter=<?php echo urlencode($language_filter); ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <script>
    // Add real-time validation for total and available copies
    document.addEventListener('DOMContentLoaded', function() {
        const totalCopiesInput = document.getElementById('edit_total_copies');
        const availableCopiesInput = document.getElementById('edit_available_copies');
        const activeBorrowCount = <?php echo $active_borrow_count; ?>;
        const minTotal = <?php echo $min_total_copies; ?>;
        
        if (totalCopiesInput) {
            totalCopiesInput.addEventListener('change', function() {
                const newTotal = parseInt(this.value);
                const currentAvailable = parseInt(availableCopiesInput.value);
                const maxAvailable = newTotal - activeBorrowCount;
                
                // Update available copies max
                availableCopiesInput.max = newTotal;
                
                // If available copies exceeds new max, adjust it
                if (currentAvailable > maxAvailable) {
                    availableCopiesInput.value = maxAvailable;
                }
                
                // Update the hint text
                const hint = availableCopiesInput.parentNode.querySelector('.max-hint');
                if (hint && activeBorrowCount > 0) {
                    hint.innerHTML = 'Max: ' + maxAvailable;
                }
            });
        }
    });
    </script>
<?php endif; endif; ?>

    <script>
        // ========== AUTO-SUBMIT FOR FILTERS AND SEARCH ==========
        let searchDebounceTimer;
        const searchInput = document.getElementById('searchInput');
        const sortSelect = document.getElementById('sortSelect');
        const categoryFilter = document.getElementById('categoryFilter');
        const languageFilter = document.getElementById('languageFilter');
        const searchForm = document.getElementById('searchForm');
        const clearBtn = document.getElementById('clearSearchBtn');

        function submitSearchForm() {
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

        const requestStatusFilter = document.getElementById('requestStatusFilter');
        if (requestStatusFilter) {
            requestStatusFilter.addEventListener('change', function() {
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
            window.location.href = 'manage_book.php';
        };

        if (sortSelect) {
            sortSelect.addEventListener('change', function() {
                submitSearchForm();
            });
        }

        if (searchInput && clearBtn) {
            clearBtn.style.display = searchInput.value.length > 0 ? 'block' : 'none';
        }

        // Location dropdown handlers
        function enableRowSelect() {
            const floorSelect = document.getElementById('floor_id');
            const rowSelect = document.getElementById('row_id');
            if (floorSelect && floorSelect.value !== '') {
                rowSelect.disabled = false;
            } else {
                rowSelect.disabled = true;
            }
        }

        function enableModalRowSelect() {
            const floorSelect = document.getElementById('modal-floor_id');
            const rowSelect = document.getElementById('modal-row_id');
            if (floorSelect && floorSelect.value !== '') {
                rowSelect.disabled = false;
            } else {
                rowSelect.disabled = true;
            }
        }

        function showNewCategoryInput(select, spanId, inputId) {
            var span = document.getElementById(spanId);
            if (select.value === "Add New") {
                span.style.display = "block";
                if (inputId && document.getElementById(inputId)) {
                    document.getElementById(inputId).required = true;
                }
            } else {
                span.style.display = "none";
                if (inputId && document.getElementById(inputId)) {
                    document.getElementById(inputId).required = false;
                }
            }
        }
        
        function showNewLanguageInput(select, spanId, inputId) {
            var span = document.getElementById(spanId);
            if (select.value === "Add New") {
                span.style.display = "block";
                if (inputId && document.getElementById(inputId)) {
                    document.getElementById(inputId).required = true;
                }
            } else {
                span.style.display = "none";
                if (inputId && document.getElementById(inputId)) {
                    document.getElementById(inputId).required = false;
                }
            }
        }

        function confirmToggle(bookTitle, currentStatus) {
            var action = currentStatus === 1 ? 'disable' : 'allow';
            var message = 'Are you sure you want to ' + action + ' requests for "' + bookTitle + '"?';
            return confirm(message);
        }

        // Modal functions
        function openAddBookModal() {
            resetModalLocation();
            document.getElementById('addBookModal').style.display = 'block';
        }

        function closeAddBookModal() {
            document.getElementById('addBookModal').style.display = 'none';
        }

        function resetModalLocation() {
            const modalFloor = document.getElementById('modal-floor_id');
            const modalRow = document.getElementById('modal-row_id');
            
            if (modalFloor) modalFloor.value = '';
            if (modalRow) {
                modalRow.disabled = true;
                modalRow.value = '';
            }
        }

        // Category select handlers
        var editSelect = document.getElementById('category-select');
        if (editSelect) {
            editSelect.addEventListener('change', function() {
                showNewCategoryInput(this, 'new-category-span', 'new_category');
            });
        }
        
        var editLanguageSelect = document.getElementById('language-select');
        if (editLanguageSelect) {
            editLanguageSelect.addEventListener('change', function() {
                showNewLanguageInput(this, 'new-language-span', 'new_language');
            });
        }
        
        var modalSelect = document.getElementById('modal-category-select');
        if (modalSelect) {
            modalSelect.addEventListener('change', function() {
                showNewCategoryInput(this, 'modal-new-category-span', 'new_category');
            });
        }
        
        var modalLanguageSelect = document.getElementById('modal-language-select');
        if (modalLanguageSelect) {
            modalLanguageSelect.addEventListener('change', function() {
                showNewLanguageInput(this, 'modal-new-language-span', 'new_language');
            });
        }

        // Set existing location values when editing
        <?php if (isset($book_edit)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const floorSelect = document.getElementById('floor_id');
            const rowSelect = document.getElementById('row_id');
            
            if (floorSelect) {
                floorSelect.value = <?php echo $book_edit['floor_id']; ?>;
                if (floorSelect.value !== '') {
                    rowSelect.disabled = false;
                }
            }
            if (rowSelect) {
                rowSelect.value = <?php echo $book_edit['row_id']; ?>;
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>