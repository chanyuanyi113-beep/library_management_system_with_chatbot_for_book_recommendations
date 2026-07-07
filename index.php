<?php
session_start();
require_once __DIR__ . '/db.php';

if (isset($_SESSION['username'])) {
    header('Location: main.php');
    exit;
}

$tab = $_GET['tab'] ?? 'login';
$errors = [];
$success = '';

function h($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function old($field, $default = '') {
    return isset($_POST[$field]) ? h(trim($_POST[$field])) : h($default);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';
    $tab = $action === 'register' ? 'register' : ($action === 'reset' ? 'reset' : 'login');

    if ($action === 'login') {
        $identity = trim($_POST['identity'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($identity === '' || $password === '') {
            $errors[] = 'Please enter your username/email and password.';
        } else {
            $stmt = $pdo->prepare('SELECT id, username, user_type_id, password FROM users WHERE username = ? OR email = ? LIMIT 1');
            $stmt->execute([$identity, $identity]);
            $user = $stmt->fetch();

            if ($user && hash_equals($user['password'], sha1($password))) {
                // Update last login timestamp
                $update_login = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
                $update_login->execute([$user['id']]);
                
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_type_id'] = $user['user_type_id'];
                
                // Redirect based on user type
                if ($user['user_type_id'] == 1) {
                    // Admin
                    header('Location: librarians_main.php');
                } elseif ($user['user_type_id'] == 2) {
                    // Librarian
                    header('Location: librarians_main.php');
                } else {
                    // Regular user
                    header('Location: main.php');
                }
                exit;
            }

            $errors[] = 'Invalid username/email or password.';
        }
    }

    if ($action === 'register') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($name === '' || $username === '' || $email === '' || $password === '' || $confirm === '') {
            $errors[] = 'All fields are required for registration.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR email = ?');
            $stmt->execute([$username, $email]);
            $count = (int) $stmt->fetchColumn();

            if ($count > 0) {
                $errors[] = 'Username or email is already registered.';
            } else {
                try {
                    // Insert new user with proper audit columns
                    $stmt = $pdo->prepare('INSERT INTO users (name, username, email, password, user_type_id, membership_type_id, user_preferences, member_since, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                    $stmt->execute([$name, $username, $email, sha1($password), 3, 1, json_encode([]), date('Y-m-d')]);
                    
                    // Get the new user ID
                    $new_user_id = $pdo->lastInsertId();
                    
                    // Update created_by and updated_by with the user's own ID
                    $update_audit = $pdo->prepare('UPDATE users SET created_by = ?, updated_by = ? WHERE id = ?');
                    $update_audit->execute([$new_user_id, $new_user_id, $new_user_id]);
                    
                } catch (PDOException $e) {
                    // Column doesn't exist yet, insert without user_preferences
                    try {
                        $stmt = $pdo->prepare('INSERT INTO users (name, username, email, password, user_type_id, membership_type_id, member_since, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
                        $stmt->execute([$name, $username, $email, sha1($password), 3, 1, date('Y-m-d')]);
                        
                        // Get the new user ID
                        $new_user_id = $pdo->lastInsertId();
                        
                        // Update created_by and updated_by with the user's own ID
                        $update_audit = $pdo->prepare('UPDATE users SET created_by = ?, updated_by = ? WHERE id = ?');
                        $update_audit->execute([$new_user_id, $new_user_id, $new_user_id]);
                        
                    } catch (PDOException $e2) {
                        // Fallback without member_since if column doesn't exist
                        $stmt = $pdo->prepare('INSERT INTO users (name, username, email, password, user_type_id, membership_type_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())');
                        $stmt->execute([$name, $username, $email, sha1($password), 3, 1]);
                        
                        // Get the new user ID
                        $new_user_id = $pdo->lastInsertId();
                        
                        // Update created_by and updated_by with the user's own ID
                        $update_audit = $pdo->prepare('UPDATE users SET created_by = ?, updated_by = ? WHERE id = ?');
                        $update_audit->execute([$new_user_id, $new_user_id, $new_user_id]);
                    }
                }

                // Show popup and redirect to login page
                echo '<script>alert("Registration successful! Please log in with your credentials."); window.location.href = "index.php?tab=login";</script>';
                exit;
            }
        }
    }

    if ($action === 'reset') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($username === '' || $email === '' || $password === '' || $confirm === '') {
            $errors[] = 'All fields are required to reset your password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND email = ? LIMIT 1');
            $stmt->execute([$username, $email]);
            $user = $stmt->fetch();

            if (!$user) {
                $errors[] = 'No account matches that username and email.';
            } else {
                // Update password and audit columns
                $update = $pdo->prepare('UPDATE users SET password = ?, updated_at = NOW(), updated_by = ? WHERE id = ?');
                $update->execute([sha1($password), $user['id'], $user['id']]);

                // Insert notification for password updated
                $criteria_stmt = $pdo->prepare(
                    'SELECT id FROM notifications_criteria 
                    WHERE title_id = (SELECT id FROM notifications_title WHERE title = ?) 
                    AND type_id = (SELECT id FROM notifications_type WHERE type = ?) LIMIT 1'
                );
                $criteria_stmt->execute(['Password Updated', 'info']);
                $criteria_id = $criteria_stmt->fetchColumn();

                if ($criteria_id) {
                    $notif_stmt = $pdo->prepare(
                        'INSERT INTO notifications (user_id, criteria_id, message)
                        VALUES (?, ?, ?)'
                    );
                    $notif_stmt->execute([
                        $user['id'],
                        $criteria_id,
                        'Your password has been successfully reset. If you did not perform this action, please contact the library immediately.'
                    ]);
                }

                // Show popup and redirect to login page
                echo '<script>alert("Password reset successful! Please log in with your new password."); window.location.href = "index.php?tab=login";</script>';
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <div class="left-section">
        <div class="hero-copy">
            <h1 class="hero-heading">Welcome to the Library</h1>
            <p class="hero-text">Login or register to access the book catalogue and borrow books you like.</p>
        </div>
    </div>

    <div class="auth-container">
        <div class="auth-tabs">
            <button type="button" class="tab-button<?= $tab === 'login' ? ' active' : '' ?>" data-tab="login">Login</button>
            <button type="button" class="tab-button<?= $tab === 'register' ? ' active' : '' ?>" data-tab="register">Register</button>
            <button type="button" class="tab-button<?= $tab === 'reset' ? ' active' : '' ?>" data-tab="reset">Reset Password</button>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <strong>Please fix the following:</strong>
                <ul class="alert-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success">
                <?= h($success) ?>
            </div>
        <?php endif; ?>

        <div class="auth-section<?= $tab === 'login' ? ' active' : '' ?>" id="login">
            <h2>Login</h2>
            <form method="POST" action="index.php?tab=login">
                <input type="hidden" name="action" value="login">
                <label for="identity">Username or Email</label>
                <input id="identity" name="identity" type="text" placeholder="Your username or Email" value="<?= old('identity') ?>">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" placeholder="Your password">
                <button type="submit">Login</button>
            </form>
        </div>

        <div class="auth-section<?= $tab === 'register' ? ' active' : '' ?>" id="register">
            <h2>Register</h2>
            <form method="POST" action="index.php?tab=register">
                <input type="hidden" name="action" value="register">
                <label for="name">Full Name</label>
                <input id="name" name="name" type="text" placeholder="Your full name" value="<?= old('name') ?>">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" placeholder="Choose a username" value="<?= old('username') ?>">
                <label for="email">Email Address</label>
                <input id="email" name="email" type="email" placeholder="name@example.com" value="<?= old('email') ?>">
                <label for="register-password">Password</label>
                <input id="register-password" name="password" type="password" placeholder="Create a password (min. 8 characters)">
                <label for="confirm_password">Confirm Password</label>
                <input id="confirm_password" name="confirm_password" type="password" placeholder="Repeat your password">
                <button type="submit">Register</button>
            </form>
        </div>

        <div class="auth-section<?= $tab === 'reset' ? ' active' : '' ?>" id="reset">
            <h2>Reset Password</h2>
            <form method="POST" action="index.php?tab=reset">
                <input type="hidden" name="action" value="reset">
                <label for="reset-username">Username</label>
                <input id="reset-username" name="username" type="text" placeholder="Your username" value="<?= old('username') ?>">
                <label for="reset-email">Email Address</label>
                <input id="reset-email" name="email" type="email" placeholder="Your email" value="<?= old('email') ?>">
                <label for="reset-password">New Password</label>
                <input id="reset-password" name="password" type="password" placeholder="New password (min. 8 characters)">
                <label for="reset-confirm-password">Confirm Password</label>
                <input id="reset-confirm-password" name="confirm_password" type="password" placeholder="Repeat new password">
                <button type="submit">Update Password</button>
            </form>
        </div>
    </div>
</div>

<script>
    const tabButtons = document.querySelectorAll('.tab-button');
    const sections = document.querySelectorAll('.auth-section');

    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const target = button.getAttribute('data-tab');

            tabButtons.forEach(btn => btn.classList.toggle('active', btn === button));
            sections.forEach(section => section.classList.toggle('active', section.id === target));
            history.replaceState(null, '', 'index.php?tab=' + target);
        });
    });
</script>
</body>
</html>