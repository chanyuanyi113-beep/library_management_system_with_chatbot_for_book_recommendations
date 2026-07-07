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

// Handle AJAX search for users
if (isset($_GET['ajax_users'])) {
    header('Content-Type: application/json');
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    
    if (strlen($query) < 2) {
        echo json_encode([]);
        exit();
    }
    
    $stmt = $pdo->prepare("
        SELECT id, name, username, membership_type_id,
               CASE WHEN membership_type_id = 2 THEN 'Premium' ELSE 'Standard' END as membership_type
        FROM users 
        WHERE user_type_id = 3 
        AND (name LIKE ? OR username LIKE ?)
        ORDER BY name ASC
        LIMIT 10
    ");
    $stmt->execute(["%$query%", "%$query%"]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($users);
    exit();
}

// Handle AJAX search for books
if (isset($_GET['ajax_books'])) {
    header('Content-Type: application/json');
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    
    if (strlen($query) < 2) {
        echo json_encode([]);
        exit();
    }
    
    $stmt = $pdo->prepare("
        SELECT id, title, author, copies_available
        FROM books 
        WHERE available = 1 
        AND copies_available > 0
        AND (title LIKE ? OR author LIKE ?)
        ORDER BY title ASC
        LIMIT 10
    ");
    $stmt->execute(["%$query%", "%$query%"]);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($books);
    exit();
}

// Handle duplicate check AJAX
if (isset($_GET['check_duplicate']) && isset($_GET['borrow_id'])) {
    header('Content-Type: application/json');
    $borrow_id = intval($_GET['borrow_id']);
    
    // Get the borrow request details
    $stmt = $pdo->prepare("SELECT user_id, book_id FROM borrow_requests WHERE id = ?");
    $stmt->execute([$borrow_id]);
    $borrow = $stmt->fetch();
    
    if ($borrow) {
        // Check if user already has this book in requested or borrowed status
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM borrow_requests WHERE user_id = ? AND book_id = ? AND book_status_id IN (1, 2) AND id != ?");
        $check_stmt->execute([$borrow['user_id'], $borrow['book_id'], $borrow_id]);
        $has_duplicate = $check_stmt->fetchColumn() > 0;
        
        echo json_encode(['has_duplicate' => $has_duplicate]);
    } else {
        echo json_encode(['has_duplicate' => false]);
    }
    exit();
}

// Handle manual mark as borrowed (POST method)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_borrow'])) {
    $user_id = intval($_POST['user_id']);
    $book_id = intval($_POST['book_id']);
    $rent_duration = intval($_POST['rent_duration']);
    
    if ($user_id <= 0 || $book_id <= 0) {
        redirectWithAlert('Please select both user and book.', 'borrow.php');
    }
    
    try {
        // Check if book is available
        $book_stmt = $pdo->prepare("SELECT title, author, copies_available, available FROM books WHERE id = ?");
        $book_stmt->execute([$book_id]);
        $book = $book_stmt->fetch();
        
        if (!$book) {
            redirectWithAlert('Book not found!', 'borrow.php');
        }
        
        if ($book['available'] == 0) {
            redirectWithAlert('This book is currently not allowed for borrowing requests.', 'borrow.php');
        }
        
        if ($book['copies_available'] <= 0) {
            redirectWithAlert('No copies available for this book.', 'borrow.php');
        }
        
        // Check if user already has this book borrowed
        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM borrow_requests WHERE user_id = ? AND book_id = ? AND book_status_id IN (1, 2)");
        $check_stmt->execute([$user_id, $book_id]);
        if ($check_stmt->fetchColumn() > 0) {
            redirectWithAlert('User already has this book requested or borrowed!', 'borrow.php');
        }
        
        // Get user membership type to check max books limit
        $user_stmt = $pdo->prepare("SELECT username, name, membership_type_id FROM users WHERE id = ?");
        $user_stmt->execute([$user_id]);
        $user = $user_stmt->fetch();
        
        $max_books = ($user['membership_type_id'] == 2) ? 8 : 3;
        
        // Check user's active books count
        $active_stmt = $pdo->prepare("SELECT COUNT(*) FROM borrow_requests WHERE user_id = ? AND book_status_id IN (1, 2)");
        $active_stmt->execute([$user_id]);
        $active_count = $active_stmt->fetchColumn();
        
        if ($active_count >= $max_books) {
            redirectWithAlert("User has reached the maximum limit of $max_books books.", 'borrow.php');
        }
        
        // Calculate due date
        $due_date = date('Y-m-d', strtotime("+$rent_duration days"));

        $current_user_id = $_SESSION['user_id'] ?? 0;
        
        // Create borrow request with status = 2 (borrowed directly) - ADD created_by and updated_by
        $insert_stmt = $pdo->prepare("
            INSERT INTO borrow_requests (user_id, book_id, rent_duration, due_date, book_status_id, request_date, created_by, updated_by) 
            VALUES (?, ?, ?, ?, 2, NOW(), ?, ?)
        ");
        $insert_stmt->execute([$user_id, $book_id, $rent_duration, $due_date, $current_user_id, $current_user_id]);
        
        // Decrease available copies
        $update_book = $pdo->prepare("UPDATE books SET copies_available = copies_available - 1, times_borrowed = times_borrowed + 1 WHERE id = ?");
        $update_book->execute([$book_id]);
        
        // Insert notification
        $criteria_stmt = $pdo->prepare("SELECT nc.id FROM notifications_criteria nc
            JOIN notifications_title nt ON nc.title_id = nt.id
            JOIN notifications_type ntype ON nc.type_id = ntype.id
            WHERE nt.title = 'Book Borrowed' AND ntype.type = 'success' LIMIT 1");
        $criteria_stmt->execute();
        $criteria_id = $criteria_stmt->fetchColumn();
        
        if ($criteria_id) {
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, criteria_id, message) VALUES (?, ?, ?)");
            $message = 'You have been manually assigned "' . $book['title'] . '" by librarian. Due date: ' . date('d-m-Y', strtotime($due_date));
            $notif_stmt->execute([$user_id, $criteria_id, $message]);
        }
        
        redirectWithAlert("Book marked as borrowed successfully!\nUser: {$user['name']}\nBook: {$book['title']}", 'borrow.php');
        
    } catch (PDOException $e) {
        redirectWithAlert('Error: ' . $e->getMessage(), 'borrow.php');
    }
}

// Handle mark as borrowed from pending request (POST method)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_borrowed']) && isset($_POST['borrow_id'])) {
    $borrow_id = intval($_POST['borrow_id']);
    
    try {
        // Get borrow request details
        $stmt = $pdo->prepare("SELECT br.*, b.title, b.author, u.username, u.id as user_id 
                               FROM borrow_requests br 
                               JOIN books b ON br.book_id = b.id 
                               JOIN users u ON br.user_id = u.id 
                               WHERE br.id = ?");
        $stmt->execute([$borrow_id]);
        $borrow = $stmt->fetch();
        
        if ($borrow && $borrow['book_status_id'] == 1) {
            $current_user_id = $_SESSION['user_id'] ?? 0;

            // Update book status to 2 (borrowed)
            $update_stmt = $pdo->prepare("UPDATE borrow_requests SET book_status_id = 2, updated_by = ? WHERE id = ?");
            $update_stmt->execute([$current_user_id, $borrow_id]);
            
            // Only increment times_borrowed (copies_available already decreased when requested)
            $book_stmt = $pdo->prepare("UPDATE books SET times_borrowed = times_borrowed + 1 WHERE id = ?");
            $book_stmt->execute([$borrow['book_id']]);
            
            // Notify the user that the book is now borrowed
            $criteria_stmt = $pdo->prepare("SELECT nc.id FROM notifications_criteria nc
                JOIN notifications_title nt ON nc.title_id = nt.id
                JOIN notifications_type ntype ON nc.type_id = ntype.id
                WHERE nt.title = 'Book Borrowed' AND ntype.type = 'success' LIMIT 1");
            $criteria_stmt->execute();
            $criteria_id = $criteria_stmt->fetchColumn();
            
            if ($criteria_id) {
                $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, criteria_id, message) VALUES (?, ?, ?)");
                $message = 'Your request for "' . $borrow['title'] . '" has been approved and marked as borrowed. Due date: ' . date('d-m-Y', strtotime($borrow['due_date']));
                $notif_stmt->execute([$borrow['user_id'], $criteria_id, $message]);
            }
            
            redirectWithAlert("Book marked as borrowed successfully!\nUser: {$borrow['username']}\nBook: {$borrow['title']}", 'borrow.php');
        } else {
            redirectWithAlert('Borrow request not found or already processed!', 'borrow.php');
        }
    } catch (PDOException $e) {
        redirectWithAlert('Error: ' . $e->getMessage(), 'borrow.php');
    }
}

// Handle cancel request (POST method)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request']) && isset($_POST['borrow_id'])) {
    $borrow_id = intval($_POST['borrow_id']);
    
    try {
        $stmt = $pdo->prepare("SELECT br.*, b.title, b.author, u.username, u.id as user_id 
                               FROM borrow_requests br 
                               JOIN books b ON br.book_id = b.id 
                               JOIN users u ON br.user_id = u.id 
                               WHERE br.id = ? AND br.book_status_id = 1");
        $stmt->execute([$borrow_id]);
        $borrow = $stmt->fetch();
        
        if ($borrow) {
            $current_user_id = $_SESSION['user_id'] ?? 0;

            // Update book status to 4 (canceled)
            $update_stmt = $pdo->prepare("UPDATE borrow_requests SET book_status_id = 4, updated_by = ? WHERE id = ?");
            $update_stmt->execute([$current_user_id, $borrow_id]);
            
            // Return the available copy
            $book_stmt = $pdo->prepare("UPDATE books SET copies_available = copies_available + 1 WHERE id = ?");
            $book_stmt->execute([$borrow['book_id']]);
            
            // Notify the user that the request was cancelled
            $criteria_stmt = $pdo->prepare("SELECT nc.id FROM notifications_criteria nc
                JOIN notifications_title nt ON nc.title_id = nt.id
                JOIN notifications_type ntype ON nc.type_id = ntype.id
                WHERE nt.title = 'Request Cancelled' AND ntype.type = 'info' LIMIT 1");
            $criteria_stmt->execute();
            $criteria_id = $criteria_stmt->fetchColumn();
            
            if ($criteria_id) {
                $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, criteria_id, message) VALUES (?, ?, ?)");
                $message = 'Your request for "' . $borrow['title'] . '" has been cancelled by the librarian.';
                $notif_stmt->execute([$borrow['user_id'], $criteria_id, $message]);
            }
            
            redirectWithAlert("Request cancelled successfully!\nUser: {$borrow['username']}\nBook: {$borrow['title']}", 'borrow.php');
        } else {
            redirectWithAlert('Request not found or already processed!', 'borrow.php');
        }
    } catch (PDOException $e) {
        redirectWithAlert('Error: ' . $e->getMessage(), 'borrow.php');
    }
}

// Get all borrow requests with status_id = 1 (pending)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$where = "br.book_status_id = 1";
$params = [];

if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR u.username LIKE ? OR b.title LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql = "SELECT br.*, b.title, b.author, u.username, u.name, u3.username as created_by_username
        FROM borrow_requests br 
        JOIN books b ON br.book_id = b.id 
        JOIN users u ON br.user_id = u.id 
        LEFT JOIN users u3 ON u3.id = br.created_by
        WHERE $where 
        ORDER BY br.request_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$borrows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Mark Books as Borrowed</title>
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
            width: 600px;
            margin: 50px auto;
            border-radius: 12px;
            padding: 24px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
        }
        .search-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-top: 5px;
            display: none;
        }
        .search-result-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #e5e7eb;
        }
        .search-result-item:hover {
            background: #f3f4f6;
        }
        .search-result-item.selected {
            background: #e0e7ff;
        }
        .selected-info {
            background: #f3f4f6;
            padding: 10px 12px;
            border-radius: 8px;
            margin-top: 8px;
            font-size: 13px;
        }
        .duration-options {
            display: flex;
            gap: 12px;
            margin-top: 8px;
        }
        .duration-option {
            flex: 1;
        }
        .duration-option input {
            display: none;
        }
        .duration-option label {
            display: block;
            padding: 10px;
            text-align: center;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            background: white;
            margin: 0;
        }
        .duration-option input:checked + label {
            background: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }
        .duration-option.disabled label {
            background: #f3f4f6;
            color: #9ca3af;
            cursor: not-allowed;
        }
        .required:after {
            content: " *";
            color: red;
        }
    </style>
</head>
<body>
    <?php $active_tab = 'dashboard'; include 'includes/librarians_header.php'; ?>
    
    <div class="main-content">
        <div class="section-header">
            <h2 class="page-title">Mark Books as Borrowed</h2>
            <div class="flex-gap">
                <button onclick="openManualBorrowModal()" class="btn btn-primary">Manual Borrow</button>
                <a href="librarians_main.php" class="btn btn-secondary">← Back To Dashboard</a>
                <a href="return.php" class="btn btn-secondary">Go To Return</a>
            </div>
        </div>

        <!-- Pending Requests Section -->
        <div class="pending-section">           
            <!-- Search Bar -->
            <div style="margin-bottom: 24px;">
                <form method="get" style="display: flex; gap: 12px; align-items: center;">
                    <input type="text" name="search" placeholder="Search by Name, Username or Book Title" value="<?php echo htmlspecialchars($search); ?>" style="flex: 4; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
                    <button type="submit" class="btn btn-primary" style="flex: 1; white-space: nowrap;">Search</button>
                    <a href="borrow.php" class="btn btn-secondary" style="flex: 1; white-space: nowrap; text-align: center;">Reset</a>
                </form>
            </div>

            <!-- Borrow Requests Table -->
            <div class="card">
                <?php if (count($borrows) > 0): ?>
                    <table class="borrow-table">
                        <thead>
                            <tr>
                                <th style="cursor: pointer;" onclick="sortTable(0)">Name <span class="sort-btn"></span></th>
                                <th style="cursor: pointer;" onclick="sortTable(1)">Username <span class="sort-btn"></span></th>
                                <th style="cursor: pointer;" onclick="sortTable(2)">Book Title <span class="sort-btn"></span></th>
                                <th style="cursor: pointer;" onclick="sortTable(3)">Author <span class="sort-btn"></span></th>
                                <th style="cursor: pointer;" onclick="sortTable(4)">Borrow Date <span class="sort-btn"></span></th>
                                <th style="cursor: pointer;" onclick="sortTable(5)">Duration <span class="sort-btn"></span></th>
                                <th style="cursor: pointer;" onclick="sortTable(6)">Due Date <span class="sort-btn"></span></th>
                                <th style="cursor: pointer;" onclick="sortTable(7)">Created By <span class="sort-btn"></span></th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($borrows as $borrow): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($borrow['name']); ?></td>
                                    <td><?php echo htmlspecialchars($borrow['username']); ?></td>
                                    <td><?php echo htmlspecialchars($borrow['title']); ?></td>
                                    <td><?php echo htmlspecialchars($borrow['author']); ?></td>
                                    <td><?php echo date('d-m-Y', strtotime($borrow['request_date'])); ?></td>
                                    <td><?php echo $borrow['rent_duration']; ?> days</td>
                                    <td><?php echo date('d-m-Y', strtotime($borrow['due_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($borrow['created_by_username'] ?? 'System'); ?></td>
                                    <td><span class="status-pill status-pill--pending">Pending</span></td>
                                    <td>
                                        <div style="display: flex; gap: 8px;">
                                            <form method="POST" action="borrow.php" style="display: inline-block; margin: 0;" 
                                                data-borrow-id="<?php echo $borrow['id']; ?>"
                                                data-user-name="<?php echo addslashes($borrow['name']); ?>"
                                                data-book-title="<?php echo addslashes($borrow['title']); ?>">
                                                <input type="hidden" name="mark_borrowed" value="1">
                                                <input type="hidden" name="borrow_id" value="<?php echo $borrow['id']; ?>">
                                                <button type="button" class="btn btn-success btn-sm mark-borrowed-btn">
                                                    Mark Borrowed
                                                </button>
                                            </form>
                                            <form method="POST" action="borrow.php" style="display: inline-block; margin: 0;" 
                                                onsubmit="return confirm('Are you sure you want to cancel this request?\n\nUser: <?php echo addslashes($borrow['name']); ?>\nBook: <?php echo addslashes($borrow['title']); ?>');">
                                                <input type="hidden" name="cancel_request" value="1">
                                                <input type="hidden" name="borrow_id" value="<?php echo $borrow['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">
                                                    Cancel Request
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="empty-state">No pending borrow requests found.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Manual Borrow Modal -->
    <div id="manualBorrowModal" class="modal">
        <div class="modal-content">
            <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 20px;">📖 Manually Mark Book as Borrowed</h3>
            <form method="POST" id="manualBorrowForm">
                <input type="hidden" name="manual_borrow" value="1">
                <input type="hidden" name="user_id" id="selected_user_id">
                <input type="hidden" name="book_id" id="selected_book_id">
                
                <!-- User Search -->
                <div class="form-group">
                    <label class="required">Search User</label>
                    <input type="text" id="userSearch" placeholder="Search By Name or Username" autocomplete="off">
                    <div id="userResults" class="search-results"></div>
                    <div id="selectedUserInfo" class="selected-info" style="display: none;"></div>
                </div>
                
                <!-- Book Search -->
                <div class="form-group">
                    <label class="required">Search Book</label>
                    <input type="text" id="bookSearch" placeholder="Search By Book Title or Author" autocomplete="off">
                    <div id="bookResults" class="search-results"></div>
                    <div id="selectedBookInfo" class="selected-info" style="display: none;"></div>
                </div>
                
                <!-- Borrow Duration -->
                <div class="form-group">
                    <label class="required">Borrow Duration</label>
                    <div class="duration-options" id="durationOptions">
                        <div class="duration-option">
                            <input type="radio" name="rent_duration" value="7" id="dur7">
                            <label for="dur7">7 Days</label>
                        </div>
                        <div class="duration-option">
                            <input type="radio" name="rent_duration" value="14" id="dur14">
                            <label for="dur14">14 Days</label>
                        </div>
                        <div class="duration-option">
                            <input type="radio" name="rent_duration" value="21" id="dur21">
                            <label for="dur21">21 Days</label>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="submit" class="btn btn-primary">Mark as Borrowed</button>
                    <button type="button" class="btn btn-secondary" onclick="closeManualBorrowModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let selectedUser = null;
        let selectedBook = null;
        let userSearchTimeout, bookSearchTimeout;
        
        // User search with debounce
        const userSearchInput = document.getElementById('userSearch');
        const userResultsDiv = document.getElementById('userResults');
        
        if (userSearchInput) {
            userSearchInput.addEventListener('input', function() {
                clearTimeout(userSearchTimeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    userResultsDiv.style.display = 'none';
                    return;
                }
                
                userSearchTimeout = setTimeout(() => {
                    fetch(`borrow.php?ajax_users=1&q=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.length > 0) {
                                userResultsDiv.innerHTML = data.map(user => 
                                    `<div class="search-result-item" onclick="selectUser(${user.id}, '${user.name.replace(/'/g, "\\'")}', '${user.username.replace(/'/g, "\\'")}', ${user.membership_type_id})">
                                        <strong>${escapeHtml(user.name)}</strong> (${escapeHtml(user.username)}) - ${user.membership_type}
                                    </div>`
                                ).join('');
                                userResultsDiv.style.display = 'block';
                            } else {
                                userResultsDiv.innerHTML = '<div class="search-result-item">No users found</div>';
                                userResultsDiv.style.display = 'block';
                            }
                        });
                }, 300);
            });
        }
        
        // Book search with debounce
        const bookSearchInput = document.getElementById('bookSearch');
        const bookResultsDiv = document.getElementById('bookResults');
        
        if (bookSearchInput) {
            bookSearchInput.addEventListener('input', function() {
                clearTimeout(bookSearchTimeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    bookResultsDiv.style.display = 'none';
                    return;
                }
                
                bookSearchTimeout = setTimeout(() => {
                    fetch(`borrow.php?ajax_books=1&q=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.length > 0) {
                                bookResultsDiv.innerHTML = data.map(book => 
                                    `<div class="search-result-item" onclick="selectBook(${book.id}, '${book.title.replace(/'/g, "\\'")}', '${book.author.replace(/'/g, "\\'")}')">
                                        <strong>${escapeHtml(book.title)}</strong> by ${escapeHtml(book.author)} (${book.copies_available} available)
                                    </div>`
                                ).join('');
                                bookResultsDiv.style.display = 'block';
                            } else {
                                bookResultsDiv.innerHTML = '<div class="search-result-item">No books found</div>';
                                bookResultsDiv.style.display = 'block';
                            }
                        });
                }, 300);
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Close results when clicking outside
        document.addEventListener('click', function(e) {
            if (userSearchInput && !userSearchInput.contains(e.target) && userResultsDiv && !userResultsDiv.contains(e.target)) {
                userResultsDiv.style.display = 'none';
            }
            if (bookSearchInput && !bookSearchInput.contains(e.target) && bookResultsDiv && !bookResultsDiv.contains(e.target)) {
                bookResultsDiv.style.display = 'none';
            }
        });
        
        function selectUser(id, name, username, membershipTypeId) {
            selectedUser = { id, name, username, membershipTypeId };
            document.getElementById('selected_user_id').value = id;
            document.getElementById('selectedUserInfo').innerHTML = `✅ Selected: <strong>${escapeHtml(name)}</strong> (${escapeHtml(username)})`;
            document.getElementById('selectedUserInfo').style.display = 'block';
            userSearchInput.value = name;
            userResultsDiv.style.display = 'none';
            
            // Update duration options based on membership
            updateDurationOptions(membershipTypeId);
        }
        
        function selectBook(id, title, author) {
            selectedBook = { id, title, author };
            document.getElementById('selected_book_id').value = id;
            document.getElementById('selectedBookInfo').innerHTML = `✅ Selected: <strong>${escapeHtml(title)}</strong> by ${escapeHtml(author)}`;
            document.getElementById('selectedBookInfo').style.display = 'block';
            bookSearchInput.value = title;
            bookResultsDiv.style.display = 'none';
        }
        
        function updateDurationOptions(membershipTypeId) {
            const isPremium = (membershipTypeId == 2);
            const dur21Option = document.querySelector('.duration-option input[value="21"]').closest('.duration-option');
            
            if (dur21Option) {
                if (!isPremium) {
                    dur21Option.classList.add('disabled');
                    const radio21 = document.getElementById('dur21');
                    if (radio21 && radio21.checked) {
                        radio21.checked = false;
                    }
                    const label = dur21Option.querySelector('label');
                    if (label) {
                        label.style.opacity = '0.5';
                        label.style.cursor = 'not-allowed';
                    }
                    if (radio21) radio21.disabled = true;
                } else {
                    dur21Option.classList.remove('disabled');
                    const radio21 = document.getElementById('dur21');
                    if (radio21) {
                        radio21.disabled = false;
                    }
                    const label = dur21Option.querySelector('label');
                    if (label) {
                        label.style.opacity = '1';
                        label.style.cursor = 'pointer';
                    }
                }
            }
        }
        
        function openManualBorrowModal() {
            // Reset form
            selectedUser = null;
            selectedBook = null;
            document.getElementById('selected_user_id').value = '';
            document.getElementById('selected_book_id').value = '';
            document.getElementById('userSearch').value = '';
            document.getElementById('bookSearch').value = '';
            document.getElementById('selectedUserInfo').style.display = 'none';
            document.getElementById('selectedBookInfo').style.display = 'none';
            document.getElementById('dur7').checked = false;
            document.getElementById('dur14').checked = false;
            document.getElementById('dur21').checked = false;
            
            // Reset duration options
            const dur21Option = document.querySelector('.duration-option input[value="21"]').closest('.duration-option');
            if (dur21Option) {
                dur21Option.classList.remove('disabled');
                const radio21 = document.getElementById('dur21');
                if (radio21) {
                    radio21.disabled = false;
                }
                const label = dur21Option.querySelector('label');
                if (label) {
                    label.style.opacity = '1';
                    label.style.cursor = 'pointer';
                }
            }
            
            document.getElementById('manualBorrowModal').style.display = 'block';
        }
        
        function closeManualBorrowModal() {
            document.getElementById('manualBorrowModal').style.display = 'none';
        }
        
        // Handle mark borrowed button clicks with confirmation popup
        document.body.addEventListener('click', async function(e) {
            const button = e.target.closest('.mark-borrowed-btn');
            if (!button) return;
            
            e.preventDefault();
            const form = button.closest('form');
            if (!form) return;
            
            const borrowId = form.dataset.borrowId;
            const userName = form.dataset.userName;
            const bookTitle = form.dataset.bookTitle;
            
            // First check for duplicate via AJAX
            try {
                const response = await fetch(`borrow.php?check_duplicate=1&borrow_id=${borrowId}`);
                const data = await response.json();
                
                if (data.has_duplicate) {
                    alert(`⚠️ User "${userName}" already has this book "${bookTitle}" requested or borrowed!\n\nPlease check the user's current books before proceeding.`);
                    return false;
                }
                
                // Show confirmation popup
                const confirmed = confirm(`Mark this book as borrowed?\n\nUser: ${userName}\nBook: ${bookTitle}`);
                if (confirmed) {
                    form.submit();
                }
            } catch (error) {
                // If AJAX fails, still show confirmation
                const confirmed = confirm(`Mark this book as borrowed?\n\nUser: ${userName}\nBook: ${bookTitle}`);
                if (confirmed) {
                    form.submit();
                }
            }
        });
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            var modal = document.getElementById('manualBorrowModal');
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
        
        // Form validation for manual borrow
        const manualBorrowForm = document.getElementById('manualBorrowForm');
        if (manualBorrowForm) {
            manualBorrowForm.addEventListener('submit', function(e) {
                if (!selectedUser || !selectedBook) {
                    alert('Please select both a user and a book.');
                    e.preventDefault();
                    return false;
                }
                
                const duration = document.querySelector('input[name="rent_duration"]:checked');
                if (!duration) {
                    alert('Please select a borrow duration.');
                    e.preventDefault();
                    return false;
                }
                
                return confirm(`Confirm manual borrow?\n\nUser: ${selectedUser.name}\nBook: ${selectedBook.title}\nDuration: ${duration.value} days`);
            });
        }

        // Sortable table function
        let currentSortColumn = -1;
        let currentSortDirection = 'asc';

        function sortTable(columnIndex) {
            const table = document.querySelector('.borrow-table');
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
                
                // Handle date columns
                if (columnIndex === 4 || columnIndex === 6) { // Borrow Date or Due Date
                    aValue = aValue === '-' ? '' : aValue;
                    bValue = bValue === '-' ? '' : bValue;
                }
                
                // Handle duration (extract number)
                if (columnIndex === 5) {
                    aValue = parseInt(aValue) || 0;
                    bValue = parseInt(bValue) || 0;
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
            const headers = document.querySelectorAll('.borrow-table thead th');
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