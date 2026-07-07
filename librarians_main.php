<?php
session_start();
$conn = new mysqli('localhost', 'root', '', 'lms_db');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Check if user is a admin or librarian
if (!isset($_SESSION['user_type_id']) || ($_SESSION['user_type_id'] != 1 && $_SESSION['user_type_id'] != 2)) {
    echo "<script>alert('Access Denied: Only librarians can access this page.'); window.location='main.php';</script>";
    exit();
}

// Get statistics - using proper JOIN to get book information
$pending_borrows = $conn->query("SELECT COUNT(*) as count FROM borrow_requests WHERE book_status_id = 1")->fetch_assoc()['count'];
$pending_returns = $conn->query("SELECT COUNT(*) as count FROM borrow_requests WHERE book_status_id = 2")->fetch_assoc()['count'];
$total_books = $conn->query("SELECT COUNT(*) as count FROM books")->fetch_assoc()['count'];
$total_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_type_id = 3")->fetch_assoc()['count'];
$total_librarians = $conn->query("SELECT COUNT(*) as count FROM users WHERE user_type_id = 2")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Librarians Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php $active_tab = 'dashboard'; include 'includes/librarians_header.php'; ?>
    
    <div class="main-content">
        <h2 style="font-size: 24px; font-weight: 700; color: #111827; margin-bottom: 24px;">Librarians Dashboard</h2>
        
        <!-- Quick Actions -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 32px;">
            <a href="borrow.php" style="text-decoration: none;">
                <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); border-radius: 10px; padding: 24px; color: white; transition: transform 0.2s; height: 100%; display: flex; flex-direction: column;">
                    <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">Mark Books as Borrowed</h3>
                    <p style="font-size: 14px; color: rgba(255,255,255,0.8); margin-bottom: 16px; flex: 1;">Manage borrow requests and confirm books given to users</p>
                    <div style="font-size: 28px; font-weight: 700;"><?php echo $pending_borrows; ?> Pending</div>
                </div>
            </a>
            
            <a href="return.php" style="text-decoration: none;">
                <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 10px; padding: 24px; color: white; transition: transform 0.2s; height: 100%; display: flex; flex-direction: column;">
                    <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">Mark Books as Returned</h3>
                    <p style="font-size: 14px; color: rgba(255,255,255,0.8); margin-bottom: 16px; flex: 1;">Manage return requests and confirm books received from users</p>
                    <div style="font-size: 28px; font-weight: 700;"><?php echo $pending_returns; ?> Pending</div>
                </div>
            </a>
            
            <a href="manage_book.php" style="text-decoration: none;">
                <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border-radius: 10px; padding: 24px; color: white; transition: transform 0.2s; height: 100%; display: flex; flex-direction: column;">
                    <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">Manage Books</h3>
                    <p style="font-size: 14px; color: rgba(255,255,255,0.8); margin-bottom: 16px; flex: 1;">Add, edit, or delete books from the library collection</p>
                    <div style="font-size: 28px; font-weight: 700;"><?php echo $total_books; ?> Books</div>
                </div>
            </a>
            
            <a href="manage_user.php" style="text-decoration: none;">
                <div style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); border-radius: 10px; padding: 24px; color: white; transition: transform 0.2s; height: 100%; display: flex; flex-direction: column;">
                    <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">Manage Users</h3>
                    <p style="font-size: 14px; color: rgba(255,255,255,0.8); margin-bottom: 16px; flex: 1;">View and manage user accounts and information</p>
                    <div style="font-size: 28px; font-weight: 700;">
                        <?php if ($_SESSION['user_type_id'] == 1): ?>
                            <?php echo $total_users; ?> Users, <?php echo $total_librarians; ?> Librarians
                        <?php else: ?>
                            <?php echo $total_users; ?> Users
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
    </div>
</body>
</html>