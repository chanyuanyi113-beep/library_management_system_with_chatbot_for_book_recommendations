<?php
// notifications.php — Notifications

session_start();
require_once __DIR__ . '/db.php';

// Check if user is logged in
if (!isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

// Fetch user_type_id to determine which header to use
$stmt = $pdo->prepare('SELECT user_type_id FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$_SESSION['username']]);
$user_row = $stmt->fetch();
$user_type_id = $user_row ? $user_row['user_type_id'] : 3;

// Get logged in user ID
$user_stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$user_stmt->execute([$_SESSION['username']]);
$user = $user_stmt->fetch();
$logged_in_user_id = $user['id'] ?? null;

$active_tab = 'notifications';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_all_read'])) {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$logged_in_user_id]);
        $_SESSION['success_message'] = 'All notifications marked as read.';
    }
    elseif (isset($_POST['delete_all'])) {
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE user_id = ?');
        $stmt->execute([$logged_in_user_id]);
        $_SESSION['success_message'] = 'All notifications deleted.';
    }
    elseif (isset($_POST['mark_read_id'])) {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([(int)$_POST['mark_read_id'], $logged_in_user_id]);
    }
    elseif (isset($_POST['delete_id'])) {
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
        $stmt->execute([(int)$_POST['delete_id'], $logged_in_user_id]);
    }
    
    // Redirect to avoid form resubmission
    header('Location: notifications.php');
    exit;
}

// Include appropriate header based on user type
if ($user_type_id == 1 || $user_type_id == 2) {
    // Admin or Librarian
    include 'includes/librarians_header.php';
} else {
    // Regular user
    include 'includes/header.php';
}

// Fetch all notifications for the user
$stmt = $pdo->prepare(
    'SELECT n.id, nc.id AS criteria_id, nt.title, ntype.type, n.message, n.is_read, n.created_at
     FROM notifications n
     JOIN notifications_criteria nc ON nc.id = n.criteria_id
     JOIN notifications_title nt ON nt.id = nc.title_id
     JOIN notifications_type ntype ON ntype.id = nc.type_id
     WHERE n.user_id = ?
     ORDER BY n.created_at DESC'
);
$stmt->execute([$logged_in_user_id]);
$notifications = $stmt->fetchAll();

// Format time ago for each notification
$notifications = array_map(function ($n) {
    // Set timezone to GMT+8 (Malaysia/Singapore/Philippines time)
    $timezone = new DateTimeZone('Asia/Kuala_Lumpur');
    $created = new DateTime($n['created_at'], $timezone);
    $now = new DateTime('now', $timezone);
    $interval = $now->diff($created);
    $days = (int)$interval->days;
    
    if ($days == 0) {
        $hours = (int)$interval->h;
        if ($hours == 0) {
            $minutes = (int)$interval->i;
            if ($minutes == 0) {
                $n['time'] = 'Just now';
            } else {
                $n['time'] = $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
            }
        } else {
            $n['time'] = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        }
    } else if ($days == 1) {
        $n['time'] = 'Yesterday';
    } else if ($days < 7) {
        $n['time'] = $days . ' days ago';
    } else if ($days < 30) {
        $weeks = floor($days / 7);
        $n['time'] = $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    } else {
        $n['time'] = $created->format('M d, Y');
    }
    return $n;
}, $notifications);

// Calculate stats
$total = count($notifications);
$unread = count(array_filter($notifications, fn($n) => !$n['is_read']));

// Display success message if exists
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert-success" style="margin: 20px 24px 0 24px; padding: 12px 16px; border-radius: 8px;">' 
         . htmlspecialchars($_SESSION['success_message']) . '</div>';
    unset($_SESSION['success_message']);
}
?>

<div class="main-content">
    <!-- Stats Cards -->
    <div class="notif-stats-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 24px;">
        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-label">Total Notifications</span>
                <svg class="stat-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
            </div>
            <div class="stat-value"><?= $total ?></div>
            <div class="stat-sub">All time</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-label">Unread</span>
                <svg class="stat-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
            </div>
            <div class="stat-value"><?= $unread ?></div>
            <div class="stat-sub">Requires attention</div>
        </div>
    </div>

    <!-- Notifications Section -->
    <div class="section-card">
        <div class="notifications-header">
            <div class="notifications-title">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                Notifications
                <?php if ($unread > 0): ?>
                    <span class="new-count-badge"><?= $unread ?> new</span>
                <?php endif; ?>
            </div>
            <div class="notifications-actions">
                <?php if ($total > 0): ?>
                    <form method="POST" action="notifications.php" style="display:inline;">
                        <button type="submit" name="mark_all_read" class="btn-outline btn-sm">Mark All as Read</button>
                    </form>
                    <form method="POST" action="notifications.php" style="display:inline;"
                          onsubmit="return confirm('Delete all notifications? This action cannot be undone.')">
                        <button type="submit" name="delete_all" class="icon-btn" title="Delete all">🗑</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notification Items -->
        <?php if (empty($notifications)): ?>
            <div style="text-align:center;padding:48px 20px;color:#9ca3af;">
                <div style="font-size:40px;margin-bottom:12px;">🔔</div>
                <div style="font-weight:600;color:#374151;">No notifications</div>
                <div style="font-size:13px;margin-top:4px;">You're all caught up!</div>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
                <div class="notif-item <?= $notif['is_read'] ? 'read' : '' ?>">
                    <div class="notif-icon-wrap <?= htmlspecialchars($notif['type']) ?>">
                        <?php
                        $icon = '🔔';
                        switch ($notif['type']) {
                            case 'warning': $icon = '⚠️'; break;
                            case 'success': $icon = '✅'; break;
                            case 'info': $icon = 'ℹ️'; break;
                            default: $icon = '📘';
                        }
                        echo $icon;
                        ?>
                    </div>
                    <div class="notif-body">
                        <h4><?= htmlspecialchars($notif['title']) ?></h4>
                        <p><?= nl2br(htmlspecialchars($notif['message'])) ?></p>
                        <div class="notif-footer">
                            <span class="notif-time">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2" style="vertical-align:middle;">
                                    <circle cx="12" cy="12" r="10"/>
                                    <polyline points="12 6 12 12 16 14"/>
                                </svg>
                                <?= htmlspecialchars($notif['time']) ?>
                            </span>
                            <span class="notif-type-tag <?= htmlspecialchars($notif['type']) ?>">
                                <?= htmlspecialchars($notif['type']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="notif-controls">
                        <?php if (!$notif['is_read']): ?>
                            <span class="unread-dot"></span>
                            <form method="POST" action="notifications.php">
                                <input type="hidden" name="mark_read_id" value="<?= $notif['id'] ?>">
                                <button type="submit" class="mark-read-btn">Mark Read</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" action="notifications.php"
                              onsubmit="return confirm('Delete this notification?')">
                            <input type="hidden" name="delete_id" value="<?= $notif['id'] ?>">
                            <button type="submit" class="icon-btn" title="Delete">🗑</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>