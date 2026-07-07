<?php
session_start();
$conn = new mysqli('localhost', 'root', '', 'lms_db');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}

// Get current user's type and ID
$current_user_id = $_SESSION['user_id'] ?? 0;
$user_type_query = $conn->query("SELECT user_type_id FROM users WHERE id = $current_user_id");
$current_user_type = $user_type_query->fetch_assoc()['user_type_id'] ?? 3;
$is_admin = ($current_user_type == 1);

// Handle update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_id'])) {
    $id = intval($_POST['edit_id']);
    $name = $conn->real_escape_string($_POST['name']);
    $username = $conn->real_escape_string($_POST['username']);
    $email = $conn->real_escape_string($_POST['email']);
    $user_type_id = intval($_POST['user_type_id']);
    $current_user_id = $_SESSION['user_id'] ?? 0;
    
    $conn->query("UPDATE users SET name='$name', username='$username', email='$email', user_type_id=$user_type_id, updated_at = NOW(), updated_by = $current_user_id WHERE id=$id");

    // Insert notification for profile updated (using mysqli)
    $criteria_query = $conn->query("SELECT nc.id FROM notifications_criteria nc
        JOIN notifications_title nt ON nc.title_id = nt.id
        JOIN notifications_type ntype ON nc.type_id = ntype.id
        WHERE nt.title = 'Profile Updated' AND ntype.type = 'info' LIMIT 1");
    $criteria_row = $criteria_query->fetch_assoc();
    $criteria_id = $criteria_row ? $criteria_row['id'] : null;

    if ($criteria_id) {
        $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, criteria_id, message) VALUES (?, ?, ?)");
        $message = 'Your profile has been updated by a librarian.';
        $notif_stmt->bind_param("iis", $id, $criteria_id, $message);
        $notif_stmt->execute();
        $notif_stmt->close();
    }

    echo "<script>alert('User updated successfully!'); window.location='manage_user.php';</script>";
    exit();
}

// Handle add new user
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $errors = [];
    $name = trim($_POST['new_name']);
    $username = trim($_POST['new_username']);
    $email = trim($_POST['new_email']);
    $user_type_id = intval($_POST['new_user_type_id']);
    $password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $current_user_id = $_SESSION['user_id'] ?? 0;
    
    // Validation
    if (empty($name)) {
        $errors[] = "Name is required.";
    }
    if (empty($username)) {
        $errors[] = "Username is required.";
    }
    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    }
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    
    // Check if username or email already exists
    if (empty($errors)) {
        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        $check_stmt->bind_result($count);
        $check_stmt->fetch();
        $check_stmt->close();
        
        if ($count > 0) {
            $errors[] = "Username or email already exists.";
        }
    }
    
    if (empty($errors)) {
        $hashed_password = sha1($password);
        $membership_id = ($user_type_id == 2) ? 3 : 1;
        $stmt = $conn->prepare("INSERT INTO users (name, username, email, password, user_type_id, membership_type_id, member_since, created_at, created_by, updated_at, updated_by) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), NOW(), ?, NOW(), ?)");
        $stmt->bind_param("ssssiiii", $name, $username, $email, $hashed_password, $user_type_id, $membership_id, $current_user_id, $current_user_id);
        
        if ($stmt->execute()) {
            echo "<script>alert('User added successfully!'); window.location='manage_user.php';</script>";
        } else {
            echo "<script>alert('Error adding user: " . $conn->error . "');</script>";
        }
        $stmt->close();
    } else {
        $error_msg = implode("\\n", $errors);
        echo "<script>alert('Please fix the following errors:\\n$error_msg'); window.history.back();</script>";
    }
    exit();
}

// Handle delete
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    // Don't allow deleting own account
    if ($delete_id == $_SESSION['user_id']) {
        echo "<script>alert('You cannot delete your own account!'); window.location='manage_user.php';</script>";
        exit();
    }
    
    // Check if user has any active books (requested or borrowed)
    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM borrow_requests WHERE user_id = ? AND book_status_id IN (1, 2)");
    $check_stmt->bind_param("i", $delete_id);
    $check_stmt->execute();
    $check_stmt->bind_result($active_count);
    $check_stmt->fetch();
    $check_stmt->close();
    
    if ($active_count > 0) {
        echo "<script>alert('Cannot delete this user because they have $active_count active borrow requests. Please wait for the books to be returned first.'); window.location='manage_user.php';</script>";
        exit();
    }
    
    // Also check if user has any pending returns that haven't been rated? Optional
    $conn->query("DELETE FROM users WHERE id=$delete_id");
    echo "<script>alert('User deleted successfully!'); window.location='manage_user.php';</script>";
    exit();
}

// Search, Sort, Filter Logic
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $conn->real_escape_string($_GET['sort']) : 'created_at';
$order = isset($_GET['order']) && strtolower($_GET['order']) === 'desc' ? 'DESC' : 'DESC'; // Default DESC for date
$filter_type = isset($_GET['filter_type']) ? intval($_GET['filter_type']) : '';

if ($is_admin) {
    $where = ["users.user_type_id IN (2,3)"];  // Admin sees librarians and users
} else {
    $where = ["users.user_type_id = 3"];  // Librarians only regular users
}

// Apply filter if admin and filter_type is set
if ($is_admin && $filter_type !== '' && in_array($filter_type, [2, 3])) {
    $where[] = "users.user_type_id = $filter_type";
}

if ($search !== '') {
    $where[] = "(users.name LIKE '%$search%' OR users.username LIKE '%$search%' OR users.email LIKE '%$search%')";
}
$where_sql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$sortable = ['name', 'username', 'email', 'created_at', 'book_requesting', 'book_borrowing', 'created_by', 'updated_by'];
if (!in_array($sort, $sortable)) $sort = 'created_at';

// Set order based on sort field
if ($sort == 'created_at') {
    $order = 'DESC'; // Newest first
} elseif ($order != 'ASC' && $order != 'DESC') {
    $order = 'ASC';
}

$select = "SELECT users.*,
    (SELECT COUNT(*) FROM borrow_requests WHERE user_id=users.id AND book_status_id = 1) AS book_requesting,
    (SELECT COUNT(*) FROM borrow_requests WHERE user_id=users.id AND book_status_id = 2) AS book_borrowing,
    u1.username AS created_by_username,
    u2.username AS updated_by_username,
    user_type.type AS user_type
    FROM users 
    LEFT JOIN user_type ON users.user_type_id = user_type.id 
    LEFT JOIN users u1 ON u1.id = users.created_by
    LEFT JOIN users u2 ON u2.id = users.updated_by
    $where_sql";

if (in_array($sort, ['book_requesting','book_borrowing'])) {
    $select .= " ORDER BY $sort $order";
} elseif ($sort == 'created_at') {
    $select .= " ORDER BY users.created_at $order";
} elseif ($sort == 'created_by') {
    $select .= " ORDER BY u1.username $order";
} elseif ($sort == 'updated_by') {
    $select .= " ORDER BY u2.username $order";
} else {
    $select .= " ORDER BY users.$sort $order";
}

$users = $conn->query($select);

// Get counts for filter display (admin only)
$total_librarians = 0;
$total_users = 0;
if ($is_admin) {
    $lib_count = $conn->query("SELECT COUNT(*) FROM users WHERE user_type_id = 2");
    $total_librarians = $lib_count->fetch_row()[0];
    $user_count = $conn->query("SELECT COUNT(*) FROM users WHERE user_type_id = 3");
    $total_users = $user_count->fetch_row()[0];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Users</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .user-table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }
        .user-table th {
            background: #f3f4f6;
            border-bottom: 1px solid #e5e7eb;
            padding: 12px 16px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            cursor: pointer;
        }
        .user-table th:hover {
            background: #e5e7eb;
        }
        .user-table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 12px 16px;
            font-size: 13px;
            color: #374151;
        }
        .user-table tbody tr:hover {
            background: #f9fafb;
        }
        .user-table tr:last-child td {
            border-bottom: none;
        }
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
            width: 500px;
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
        .filter-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .filter-badge.active {
            background: #1976d2;
            color: white;
        }
        .filter-badge.inactive {
            background: #f3f4f6;
            color: #6b7280;
            text-decoration: none;
        }
        .sort-indicator {
            font-size: 10px;
            margin-left: 4px;
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
    </style>
</head>
<body>
    <?php include 'includes/librarians_header.php'; ?>
    
    <div class="main-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <h2 style="font-size: 24px; font-weight: 700; color: #111827;">Manage Users</h2>
            <div style="display: flex; gap: 12px;">
                <a href="librarians_main.php" class="btn btn-secondary">← Back To Dashboard</a>
                <button type="button" onclick="document.getElementById('addUserModal').style.display='block'" class="btn btn-primary">+ Add New User</button>
            </div>
        </div>

        <!-- Search & Filter Bar -->
        <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
            <form method="get" id="filterForm" style="display: flex; gap: 12px; width: 100%;">
                <input type="text" name="search" id="searchInput" placeholder="Search Name / Username / Email" value="<?php echo htmlspecialchars($search); ?>" style="flex: 3; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
                
                <!-- Hidden input to preserve filter type -->
                <input type="hidden" name="filter_type" value="<?php echo htmlspecialchars($filter_type); ?>">

                <!-- Sort Dropdown -->
                <select name="sort" id="sortSelect" style="flex: 1; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
                    <option value="created_at" <?php echo $sort == 'created_at' ? 'selected' : ''; ?>>Sort by Date Added</option>
                    <option value="name" <?php echo $sort == 'name' ? 'selected' : ''; ?>>Sort by Name</option>
                    <option value="username" <?php echo $sort == 'username' ? 'selected' : ''; ?>>Sort by Username</option>
                    <option value="email" <?php echo $sort == 'email' ? 'selected' : ''; ?>>Sort by Email</option>
                </select>
                
                <button type="submit" class="btn btn-primary" style="flex: 1; white-space: nowrap;">Search</button>
                <a href="manage_user.php" class="btn btn-secondary" style="flex: 1; white-space: nowrap; text-align: center;">Reset</a>
            </form>
        </div>

        <!-- Filter Buttons (Admin only) -->
        <?php if ($is_admin): ?>
        <div style="display: flex; gap: 12px; margin-bottom: 20px;">
            <a href="manage_user.php?<?php echo http_build_query(array_merge($_GET, ['filter_type' => ''])) ?>" 
               class="filter-badge <?php echo $filter_type === '' ? 'active' : 'inactive'; ?>"
               style="text-decoration: none;">
                All Users (<?php echo $total_librarians + $total_users; ?>)
            </a>
            <a href="manage_user.php?<?php echo http_build_query(array_merge($_GET, ['filter_type' => 2])) ?>" 
               class="filter-badge <?php echo $filter_type == 2 ? 'active' : 'inactive'; ?>"
               style="text-decoration: none;">
                👑 Librarians (<?php echo $total_librarians; ?>)
            </a>
            <a href="manage_user.php?<?php echo http_build_query(array_merge($_GET, ['filter_type' => 3])) ?>" 
               class="filter-badge <?php echo $filter_type == 3 ? 'active' : 'inactive'; ?>"
               style="text-decoration: none;">
                📚 Users (<?php echo $total_users; ?>)
            </a>
        </div>
        <?php endif; ?>

        <!-- Users Table -->
        <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden;">
            <?php if ($users && $users->num_rows > 0): ?>
                <table class="user-table">
                    <thead>
                        <tr>
                            <th onclick="sortTable('name')">Name <?php echo $sort == 'name' ? ($order == 'ASC' ? '↑' : '↓') : ''; ?></th>
                            <th onclick="sortTable('username')">Username <?php echo $sort == 'username' ? ($order == 'ASC' ? '↑' : '↓') : ''; ?></th>
                            <th onclick="sortTable('email')">Email <?php echo $sort == 'email' ? ($order == 'ASC' ? '↑' : '↓') : ''; ?></th>
                            <th>User Type</th>
                            <th onclick="sortTable('created_at')">Date Added <?php echo $sort == 'created_at' ? '↓' : ''; ?></th>
                            <th onclick="sortTable('book_requesting')">Books Requesting <?php echo $sort == 'book_requesting' ? ($order == 'ASC' ? '↑' : '↓') : ''; ?></th>
                            <th onclick="sortTable('book_borrowing')">Books Borrowing <?php echo $sort == 'book_borrowing' ? ($order == 'ASC' ? '↑' : '↓') : ''; ?></th>
                            <th onclick="sortTable('created_by')">Created By <?php echo $sort == 'created_by' ? ($order == 'ASC' ? '↑' : '↓') : ''; ?></th>
                            <th onclick="sortTable('updated_by')">Updated By <?php echo $sort == 'updated_by' ? ($order == 'ASC' ? '↑' : '↓') : ''; ?></th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($user = $users->fetch_assoc()): ?>
                            <?php if (isset($_GET['edit_id']) && $_GET['edit_id'] == $user['id']): ?>
                                <tr>
                                    <form method="POST" style="display: contents;">
                                        <input type="hidden" name="edit_id" value="<?php echo $user['id']; ?>">
                                        <td><input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;"></td>
                                        <td><input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;"></td>
                                        <td><input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;"></td>
                                        <td>
                                            <?php if ($is_admin): ?>
                                                <select name="user_type_id" required style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px;">
                                                    <option value="2" <?php echo $user['user_type_id'] == 2 ? 'selected' : ''; ?>>Librarian</option>
                                                    <option value="3" <?php echo $user['user_type_id'] == 3 ? 'selected' : ''; ?>>User</option>
                                                </select>
                                            <?php else: ?>
                                                <input type="text" value="<?php echo $user['user_type']; ?>" disabled style="width: 100%; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 13px; background: #f3f4f6;">
                                                <input type="hidden" name="user_type_id" value="<?php echo $user['user_type_id']; ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                        <td><?php echo $user['book_requesting']; ?></td>
                                        <td><?php echo $user['book_borrowing']; ?></td>
                                        <td><?php echo htmlspecialchars($user['created_by_username'] ?? 'System'); ?></td>
                                        <td><?php echo htmlspecialchars($user['updated_by_username'] ?? 'System'); ?></td>
                                        <td>
                                            <button type="submit" style="background: none; border: none; color: #1976d2; cursor: pointer; text-decoration: underline; font-size: 13px; font-weight: 600; padding: 0; margin-right: 8px;">Save</button>
                                            <a href="manage_user.php" style="color: #1976d2; text-decoration: underline; font-size: 13px; font-weight: 600;">Cancel</a>
                                        </td>
                                    </form>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo htmlspecialchars($user['user_type']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                    <td><?php echo $user['book_requesting']; ?></td>
                                    <td><?php echo $user['book_borrowing']; ?></td>
                                    <td><?php echo htmlspecialchars($user['created_by_username'] ?? 'System'); ?></td>
                                    <td><?php echo htmlspecialchars($user['updated_by_username'] ?? 'System'); ?></td>
                                    <td>
                                        <a href="manage_user.php?edit_id=<?php echo $user['id']; ?>&<?php echo http_build_query($_GET); ?>" style="color: #1976d2; text-decoration: underline; font-size: 13px; font-weight: 600; margin-right: 8px;">Edit</a>
                                        <form method="get" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                            <input type="hidden" name="delete_id" value="<?php echo $user['id']; ?>">
                                            <?php foreach ($_GET as $key => $value): ?>
                                                <?php if ($key != 'delete_id'): ?>
                                                    <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            <button type="submit" style="background: none; border: none; color: #ef4444; cursor: pointer; text-decoration: underline; font-size: 13px; font-weight: 600; padding: 0;">Delete</button>
                                        </form>
                                     </td>
                                </tr>
                            <?php endif; ?>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: #6b7280; text-align: center; padding: 40px;">No users found.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add User Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content">
            <h3 style="font-size: 18px; font-weight: 600; margin-bottom: 20px;">Add New User</h3>
            <form method="POST" onsubmit="return validateForm()">
                <input type="hidden" name="add_user" value="1">
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label class="required">Full Name</label>
                        <input type="text" name="new_name" id="new_name" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
                    </div>
                    <div>
                        <label class="required">Username</label>
                        <input type="text" name="new_username" id="new_username" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
                    </div>
                    <div>
                        <label class="required">Email Address</label>
                        <input type="email" name="new_email" id="new_email" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
                    </div>
                    <div>
                        <label class="required">User Type</label>
                        <?php if ($is_admin): ?>
                            <select name="new_user_type_id" id="new_user_type_id" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
                                <option value="2">Librarian</option>
                                <option value="3">User</option>
                            </select>
                        <?php else: ?>
                            <input type="text" value="User" disabled style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; background: #f3f4f6;">
                            <input type="hidden" name="new_user_type_id" value="3">
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="required">Password</label>
                        <input type="password" name="new_password" id="new_password" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
                        <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">Minimum 8 characters</div>
                    </div>
                    <div>
                        <label class="required">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" required style="width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
                    </div>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="submit" class="btn btn-primary">Add User</button>
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('addUserModal').style.display='none'">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Auto-submit for search (debounced)
        let searchDebounceTimer;
        const searchInput = document.getElementById('searchInput');
        const sortSelect = document.getElementById('sortSelect');
        const filterForm = document.getElementById('filterForm');
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchDebounceTimer);
                searchDebounceTimer = setTimeout(() => {
                    filterForm.submit();
                }, 400);
            });
        }
        
        if (sortSelect) {
            sortSelect.addEventListener('change', function() {
                filterForm.submit();
            });
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            var modal = document.getElementById('addUserModal');
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
        
        function validateForm() {
            var password = document.getElementById('new_password').value;
            var confirm = document.getElementById('confirm_password').value;
            
            if (password.length < 8) {
                alert('Password must be at least 8 characters long.');
                return false;
            }
            
            if (password !== confirm) {
                alert('Passwords do not match.');
                return false;
            }
            
            return true;
        }
        
        function sortTable(column) {
            var currentSort = '<?php echo $sort; ?>';
            var currentOrder = '<?php echo $order; ?>';
            var newOrder = 'ASC';
            
            if (currentSort === column) {
                newOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
            }
            
            // Preserve existing parameters
            var urlParams = new URLSearchParams(window.location.search);
            urlParams.set('sort', column);
            urlParams.set('order', newOrder);
            
            window.location.href = 'manage_user.php?' + urlParams.toString();
        }
    </script>
</body>
</html>