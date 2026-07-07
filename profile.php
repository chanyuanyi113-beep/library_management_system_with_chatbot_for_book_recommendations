<?php
// profile.php — User Profile

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

$active_tab = 'profile';

// Include appropriate header based on user type
if ($user_type_id == 3) {
    include 'includes/header.php';
} else {
    include 'includes/librarians_header.php';
}

$success_msg = '';
$errors = [];

// Try to fetch user with membership dates
$user = null;
try {
    $stmt = $pdo->prepare('SELECT id, name, email, member_since, membership_type_id, email_notif, updated_at, membership_start_date, membership_end_date FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$logged_in_user_id]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    $errors[] = 'Database error: ' . $e->getMessage();
}

if (!$user) {
    $user = [
        'id' => $logged_in_user_id,
        'name' => 'Guest',
        'email' => '',
        'member_since' => null,
        'membership_type_id' => 1,
        'email_notif' => 1,
        'updated_at' => null,
        'membership_start_date' => null,
        'membership_end_date' => null,
    ];
}

// Get membership type details
$mem_stmt = $pdo->prepare('SELECT type FROM membership_type WHERE id = ? LIMIT 1');
$mem_stmt->execute([$user['membership_type_id']]);
$membership = $mem_stmt->fetch();
$membership_type = $membership ? $membership['type'] : 'Standard';

// Get user type name
$user_type_stmt = $pdo->prepare('SELECT type FROM user_type WHERE id = ? LIMIT 1');
$user_type_stmt->execute([$user_type_id]);
$user_type_name = $user_type_stmt->fetchColumn();
$user_type_name = $user_type_name ? ucfirst($user_type_name) : 'User';

$profile = [
    'username'          => $_SESSION['username'],
    'full_name'         => $user['name'],
    'initials'          => implode('', array_map(fn($part) => strtoupper($part[0] ?? ''), explode(' ', $user['name']))),
    'email'             => $user['email'],
    'member_since'      => $user['member_since'] ? date('F Y', strtotime($user['member_since'])) : 'Unknown',
    'currently_borrowed'=> 0,
    'total_read'        => 0,
    'fav_genres'        => [],
    'fav_categories'    => [],
    'membership_type'   => $membership_type === 'Premium' ? 'Premium Member' : 'Standard Member',
    'membership_desc'   => $membership_type === 'Premium'
                            ? 'Access to all features and extended borrowing period'
                            : 'Standard membership with basic borrowing privileges',
    'email_notifs'      => (bool) $user['email_notif'],
    'password_changed'  => $user['updated_at'] ? date('M j, Y', strtotime($user['updated_at'])) : 'N/A',
    'user_type_id'      => $user_type_id,
    'user_type_name'    => $user_type_name,
];

// Get all available book categories
$categories_stmt = $pdo->prepare('SELECT id, category FROM book_categories ORDER BY category');
$categories_stmt->execute();
$all_categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user's favorite categories from junction table
// Get user's favorite categories from junction table
$user_category_ids = [];
$is_regular_user = ($user_type_id == 3);

if ($is_regular_user) {
    // Fetch user's favorite categories from the junction table
    $fav_stmt = $pdo->prepare('
        SELECT bc.id, bc.category 
        FROM user_favorite_categories ufc
        JOIN book_categories bc ON ufc.category_id = bc.id
        WHERE ufc.user_id = ?
        ORDER BY bc.category
    ');
    $fav_stmt->execute([$logged_in_user_id]);
    $fav_categories_data = $fav_stmt->fetchAll();
    
    $profile['fav_categories'] = array_column($fav_categories_data, 'category');
    $user_category_ids = array_column($fav_categories_data, 'id');
}

// ONLY fetch borrowing stats for regular users
if ($is_regular_user) {
    // Get total borrowed and currently borrowed
    $borrow_stats = $pdo->prepare(
        'SELECT 
            COUNT(CASE WHEN book_status_id IN (1, 2) THEN 1 END) AS currently_borrowed,
            COUNT(CASE WHEN book_status_id = 3 THEN 1 END) AS total_borrowed
         FROM borrow_requests 
         WHERE user_id = ?'
    );
    $borrow_stats->execute([$logged_in_user_id]);
    $stats = $borrow_stats->fetch();

    $profile['currently_borrowed'] = (int) $stats['currently_borrowed'];
    $profile['total_read'] = (int) $stats['total_borrowed'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_profile'])) {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');

        if ($full_name === '') {
            $errors[] = 'Full name is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }

        if (empty($errors)) {
          $update = $pdo->prepare(
              'UPDATE users
              SET name = ?, email = ?, updated_at = NOW(), updated_by = ?
              WHERE id = ?'
          );
          $update->execute([$full_name, $email, $logged_in_user_id, $logged_in_user_id]);

          // Insert notification for profile updated
          $criteria_stmt = $pdo->prepare(
              'SELECT id FROM notifications_criteria 
              WHERE title_id = (SELECT id FROM notifications_title WHERE title = ?) 
              AND type_id = (SELECT id FROM notifications_type WHERE type = ?) LIMIT 1'
          );
          $criteria_stmt->execute(['Profile Updated', 'info']);
          $criteria_id = $criteria_stmt->fetchColumn();

          if ($criteria_id) {
              $notif_stmt = $pdo->prepare(
                  'INSERT INTO notifications (user_id, criteria_id, message)
                  VALUES (?, ?, ?)'
              );
              $notif_stmt->execute([
                  $logged_in_user_id,
                  $criteria_id,
                  'Your profile information has been successfully updated.'
              ]);
          }

          $success_msg = 'Profile updated successfully!';
          $profile['full_name'] = $full_name;
          $profile['email'] = $email;
      }
    }

    if (isset($_POST['change_password'])) {
        $current  = $_POST['current_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ($current === '') {
            $errors[] = 'Current password required.';
        }
        if (strlen($new_pass) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }
        if ($new_pass !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$logged_in_user_id]);
            $existing = $stmt->fetchColumn();

            if ($existing !== sha1($current)) {
                $errors[] = 'Current password is incorrect.';
            } else {
              $update = $pdo->prepare('UPDATE users SET password = ?, updated_at = NOW(), updated_by = ? WHERE id = ?');
              $update->execute([sha1($new_pass), $logged_in_user_id, $logged_in_user_id]);
              
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
                      $logged_in_user_id,
                      $criteria_id,
                      'Your password has been changed successfully. If you did not make this change, please contact the library immediately.'
                  ]);
              }
              
              $success_msg = 'Password changed successfully!';
          }
        }
    }

    // ONLY allow category updates for regular users
    if ($is_regular_user && isset($_POST['save_categories'])) {
        $selected_categories = $_POST['categories'] ?? [];
        $selected_categories = array_map('intval', $selected_categories);

        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // First, delete existing category associations for this user
            $delete_stmt = $pdo->prepare('DELETE FROM user_favorite_categories WHERE user_id = ?');
            $delete_stmt->execute([$logged_in_user_id]);
            
            // Insert new category associations
            if (!empty($selected_categories)) {
                $insert_stmt = $pdo->prepare('INSERT INTO user_favorite_categories (user_id, category_id) VALUES (?, ?)');
                foreach ($selected_categories as $category_id) {
                    $insert_stmt->execute([$logged_in_user_id, $category_id]);
                }
            }
            
            // Commit transaction
            $pdo->commit();

            // Refresh user categories for display
            $user_category_ids = $selected_categories;
            if (!empty($user_category_ids)) {
                $placeholders = implode(',', array_fill(0, count($user_category_ids), '?'));
                $categories_stmt = $pdo->prepare(
                    "SELECT category FROM book_categories WHERE id IN ($placeholders) ORDER BY category"
                );
                $categories_stmt->execute($user_category_ids);
                $profile['fav_categories'] = $categories_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            } else {
                $profile['fav_categories'] = [];
            }

            $success_msg = 'Favorite categories updated successfully!';
        } catch (PDOException $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            error_log("Error saving categories: " . $e->getMessage());
            $errors[] = 'Unable to save categories. Please try again later.';
        }
    }

    if (isset($_POST['save_email_notif'])) {
        $email_notif = isset($_POST['email_notifications']) ? 1 : 0;

        $update = $pdo->prepare('UPDATE users SET email_notif = ? WHERE id = ?');
        $update->execute([$email_notif, $logged_in_user_id]);

        // Update profile for display
        $profile['email_notifs'] = (bool) $email_notif;

        $success_msg = 'Email notification preferences updated successfully!';
    }

    // ONLY allow membership upgrade for regular users
    if ($is_regular_user && isset($_POST['upgrade_premium'])) {
        $duration = (int) ($_POST['membership_duration'] ?? 12); // Default to 12 months
        
        // Validate duration is either 6 or 12
        if (!in_array($duration, [6, 12])) {
            $duration = 12;
        }
        
        // Calculate start and end dates
        $start_date = new DateTime('now');
        $end_date = clone $start_date;
        $end_date->modify("+$duration months");
        
        $start_date_str = $start_date->format('Y-m-d H:i:s');
        $end_date_str = $end_date->format('Y-m-d');
        
        $update = $pdo->prepare('UPDATE users SET membership_type_id = 2, membership_start_date = ?, membership_end_date = ? WHERE id = ?');
        $update->execute([$start_date_str, $end_date_str, $logged_in_user_id]);
        
        // Create upgrade notification using criteria
        $criteria_stmt = $pdo->prepare(
            'SELECT id FROM notifications_criteria 
             WHERE title_id = (SELECT id FROM notifications_title WHERE title = ?) 
             AND type_id = (SELECT id FROM notifications_type WHERE type = ?) LIMIT 1'
        );
        $criteria_stmt->execute(['Membership Renewed', 'success']);
        $criteria_id = $criteria_stmt->fetchColumn();
        
        if ($criteria_id) {
            $notif_stmt = $pdo->prepare(
                'INSERT INTO notifications (user_id, criteria_id, message)
                 VALUES (?, ?, ?)'
            );
            $notif_stmt->execute([
                $logged_in_user_id,
                $criteria_id,
                'You have successfully upgraded to Premium membership! Enjoy extended borrowing periods, AI chatbot assistance, and priority support. Your membership expires on ' . $end_date_str . '.'
            ]);
        }
        
        // Reload membership info
        $mem_stmt = $pdo->prepare('SELECT type FROM membership_type WHERE id = ? LIMIT 1');
        $mem_stmt->execute([2]);
        $membership = $mem_stmt->fetch();
        $membership_type = $membership ? $membership['type'] : 'Standard';
        
        $profile['membership_type'] = 'Premium Member';
        $profile['membership_desc'] = 'Access to all features and extended borrowing period';
        $user['membership_end_date'] = $end_date_str;
        
        $success_msg = 'Successfully upgraded to Premium! Enjoy your new benefits!';
    }
}
?>

<!-- ── Profile Form ── -->
<div class="section-card">
  <div class="section-title">My Profile</div>

  <div class="profile-avatar-section">
    <div class="avatar-circle"><?= htmlspecialchars($profile['initials']) ?></div>
    <div style="margin-top: 8px;">
      <span style="background: #e5e7eb; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500;">
        <?= htmlspecialchars($profile['user_type_name']) ?>
      </span>
    </div>
  </div>

  <form method="POST" action="profile.php">
    <div class="profile-form-grid">
      <div class="form-group">
        <label class="form-label">Full Name</label>
        <input type="text" name="full_name" class="form-input"
               value="<?= htmlspecialchars($profile['full_name']) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Username</label>
        <div class="form-input-icon">
          <span class="input-icon">👤</span>
          <input type="text" class="form-input"
                value="<?= htmlspecialchars($_SESSION['username']) ?>" readonly
                style="background:#f3f4f6;cursor:not-allowed;">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <div class="form-input-icon">
          <span class="input-icon">✉</span>
          <input type="email" name="email" class="form-input"
                 value="<?= htmlspecialchars($profile['email']) ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Member Since</label>
        <div class="form-input-icon">
          <span class="input-icon">📅</span>
          <input type="text" class="form-input"
                 value="<?= htmlspecialchars($profile['member_since']) ?>" readonly
                 style="background:#f3f4f6;cursor:not-allowed;">
        </div>
      </div>
    </div>
    <div class="form-actions">
      <a href="profile.php" class="btn btn-outline btn-sm">Cancel</a>
      <button type="submit" name="save_profile" class="btn btn-primary btn-sm">
        Save Changes
      </button>
    </div>
  </form>
</div>

<!-- Show reading stats -->
<?php if ($is_regular_user): ?>
<div class="reading-stats-grid">
  <div class="stat-card">
    <div class="stat-card-header">
      <span class="stat-label">Currently Borrowed</span>
      <svg class="stat-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2">
        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
      </svg>
    </div>
    <div class="stat-value"><?= $profile['currently_borrowed'] ?></div>
    <div class="stat-sub">Active loans</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-header">
      <span class="stat-label">Total Books Borrowed</span>
      <svg class="stat-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
      </svg>
    </div>
    <div class="stat-value"><?= $profile['total_read'] ?></div>
    <div class="stat-sub">All time</div>
  </div>
</div>
<?php endif; ?>

<!-- ── Reading Preferences ── -->
<?php if ($is_regular_user): ?>
<div class="section-card">
  <div class="section-title">Reading Preferences</div>

  <div class="settings-row">
    <div>
      <div class="settings-label">Favourite Categories</div>
      <div class="settings-sub">
        <?php if (!empty($profile['fav_categories'])): ?>
          <?= htmlspecialchars(implode(', ', $profile['fav_categories'])) ?>
        <?php else: ?>
          No categories selected
        <?php endif; ?>
      </div>
    </div>
    <button type="button" class="btn btn-outline btn-sm"
            onclick="document.getElementById('categoriesModal').style.display='flex'">
      Choose Categories
    </button>
  </div>

  <div class="membership-row" style="margin-top:20px;">
    <div>
      <div class="membership-label">Membership Type</div>
      <div style="font-size:13px;color:#374151;margin-top:4px;font-weight:500;">
        <?= htmlspecialchars($profile['membership_type']) ?>
      </div>
      <div class="membership-sub"><?= htmlspecialchars($profile['membership_desc']) ?></div>
      <?php if ($membership_type === 'Premium' && $user['membership_end_date']): ?>
        <div style="font-size:12px;color:#ea580c;margin-top:8px;padding-top:8px;border-top:1px solid #fee2e2;">
          <strong>Expires:</strong> <?= date('F j, Y', strtotime($user['membership_end_date'])) ?>
        </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
      <span class="badge-active">Active</span>
      <!-- Membership Actions -->
      <div style="display:flex;gap:8px;">
        <?php if ($membership_type === 'Standard'): ?>
          <button type="button" class="btn btn-primary btn-sm"
                  onclick="document.getElementById('upgradeModal').style.display='flex'">
            Upgrade to Premium
          </button>
        <?php endif; ?>
        
        <?php if ($membership_type === 'Premium'): ?>
          <button type="button" class="btn btn-outline btn-sm"
                  onclick="document.getElementById('featuresModal').style.display='flex'">
            Premium Features
          </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Account Settings ── -->
<div class="section-card">
  <div class="section-title">Account Settings</div>

  <!-- Email Notifications -->
  <div class="settings-row">
    <div>
      <div class="settings-label">Email Notifications</div>
      <div class="settings-sub">Receive updates about due dates and new books</div>
    </div>
    <button class="btn btn-outline btn-sm"
            onclick="document.getElementById('emailModal').style.display='flex'">
      Configure
    </button>
  </div>

  <!-- Change Password -->
  <div class="settings-row">
    <div>
      <div class="settings-label">Password</div>
      <div class="settings-sub">Last changed <?= htmlspecialchars($profile['password_changed']) ?></div>
    </div>
    <button class="btn btn-primary btn-sm"
            onclick="document.getElementById('pwModal').style.display='flex'">
      Change Password
    </button>
  </div>
</div>

<!-- ── Change Password Modal ── -->
<div id="pwModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);
     z-index:200;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:28px;width:380px;
              box-shadow:0 10px 40px rgba(0,0,0,.2);">
    <h3 style="font-size:15px;margin-bottom:20px;">Change Password</h3>
    <form method="POST" action="profile.php">
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">Current Password</label>
        <input type="password" name="current_password" class="form-input"
               placeholder="Enter current password" required>
      </div>
      <div class="form-group" style="margin-bottom:12px;">
        <label class="form-label">New Password</label>
        <input type="password" name="new_password" class="form-input"
               placeholder="At least 8 characters" required>
      </div>
      <div class="form-group" style="margin-bottom:20px;">
        <label class="form-label">Confirm New Password</label>
        <input type="password" name="confirm_password" class="form-input"
               placeholder="Repeat new password" required>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-outline btn-sm"
                onclick="document.getElementById('pwModal').style.display='none'">
          Cancel
        </button>
        <button type="submit" name="change_password" class="btn btn-primary btn-sm">
          Update Password
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Upgrade to Premium Modal ── -->
<?php if ($is_regular_user): ?>
<div id="upgradeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);
     z-index:200;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:28px;width:400px;
              box-shadow:0 10px 40px rgba(0,0,0,.2);">
    <h3 style="font-size:16px;margin-bottom:16px;font-weight:600;">Upgrade to Premium</h3>
    <div style="margin-bottom:20px;">
      <p style="font-size:13px;color:#374151;margin-bottom:12px;">Unlock exclusive features and benefits:</p>
      <ul style="list-style:none;padding:0;margin:0;">
        <li style="font-size:13px;color:#374151;margin-bottom:8px;">✓ Borrow up to 8 books at the same time</li>
        <li style="font-size:13px;color:#374151;margin-bottom:8px;">✓ Extended borrowing period (21 days)</li>
        <li style="font-size:13px;color:#374151;margin-bottom:8px;">✓ AI Chatbot assistance for book recommendations</li>
      </ul>
    </div>
    <form method="POST" action="profile.php">
      <div class="form-group" style="margin-bottom:16px;">
        <label class="form-label">Select Membership Duration:</label>
        <div style="display:flex;gap:12px;margin-top:8px;">
          <label style="display:flex;align-items:center;gap:8px;flex:1;font-size:13px;cursor:pointer;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
            <input type="radio" name="membership_duration" value="6" style="width:16px;height:16px;cursor:pointer;">
            6 Months
          </label>
          <label style="display:flex;align-items:center;gap:8px;flex:1;font-size:13px;cursor:pointer;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
            <input type="radio" name="membership_duration" value="12" checked style="width:16px;height:16px;cursor:pointer;">
            12 Months
          </label>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-outline btn-sm"
                onclick="document.getElementById('upgradeModal').style.display='none'">
          Cancel
        </button>
        <button type="submit" name="upgrade_premium" class="btn btn-primary btn-sm">
          Confirm Upgrade
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Premium Features Modal ── -->
<div id="featuresModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);
     z-index:200;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:28px;width:400px;
              box-shadow:0 10px 40px rgba(0,0,0,.2);">
    <h3 style="font-size:16px;margin-bottom:16px;font-weight:600;">Premium Member Features</h3>
    <div style="margin-bottom:20px;">
      <h4 style="font-size:14px;font-weight:600;color:#1f2937;margin-bottom:12px;">Your Premium Benefits:</h4>
      <ul style="list-style:none;padding:0;margin:0;">
        <li style="font-size:13px;color:#374151;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;">
          <strong style="color:#1f2937;">📚 Extended Space</strong><br>Borrow up to 8 books at the same time.
        </li>
        <li style="font-size:13px;color:#374151;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;">
          <strong style="color:#1f2937;">⏰ Extended Borrowing</strong><br>Borrow books for 21 days.
        </li>
        <li style="font-size:13px;color:#374151;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;">
          <strong style="color:#1f2937;">🤖 AI Chatbot Service</strong><br>Get personalized book recommendations from our AI chatbot assistant.
        </li>
      </ul>
    </div>
    <div class="form-actions">
      <button type="button" class="btn btn-primary btn-sm"
              onclick="document.getElementById('featuresModal').style.display='none'">
        Close
      </button>
    </div>
  </div>
</div>

<!-- ── Categories Modal ── -->
<div id="categoriesModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);
     z-index:200;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:28px;width:400px;
              box-shadow:0 10px 40px rgba(0,0,0,.2);">
    <h3 style="font-size:15px;margin-bottom:16px;">Choose Your Favourite Categories</h3>
    <form method="POST" action="profile.php">
      <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:20px;max-height:300px;overflow-y:auto;">
        <?php foreach ($all_categories as $cat): ?>
          <label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;">
            <input type="checkbox" name="categories[]" value="<?= $cat['id'] ?>"
                   <?php if (in_array($cat['id'], $user_category_ids)): ?>checked<?php endif; ?>
                   style="width:16px;height:16px;cursor:pointer;">
            <?= htmlspecialchars($cat['category']) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-outline btn-sm"
                onclick="document.getElementById('categoriesModal').style.display='none'">
          Cancel
        </button>
        <button type="submit" name="save_categories" class="btn btn-primary btn-sm">
          Save Categories
        </button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Email Notifications Modal (Visible to all) ── -->
<div id="emailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);
     z-index:200;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:28px;width:360px;
              box-shadow:0 10px 40px rgba(0,0,0,.2);">
    <h3 style="font-size:15px;margin-bottom:16px;">Email Notification Settings</h3>
    <form method="POST" action="profile.php">
      <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:20px;">
        <label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;">
          <input type="checkbox" name="email_notifications" value="1"
                 <?php if ($profile['email_notifs']): ?>checked<?php endif; ?>
                 style="width:16px;height:16px;cursor:pointer;">
          Email Notifications
        </label>
        <div style="font-size:12px; color:#6b7280; margin-top: 8px; padding-top: 8px; border-top: 1px solid #e5e7eb;">
          <strong>You will receive notifications for:</strong><br>
          • Book Borrowed<br>
          • Book Due Soon<br>
          • Book Returned<br>
          • Membership Updates
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-outline btn-sm"
                onclick="document.getElementById('emailModal').style.display='none'">
          Cancel
        </button>
        <button type="submit" name="save_email_notif" class="btn btn-primary btn-sm">
          Save Settings
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// Display success message as popup
<?php if ($success_msg): ?>
    alert("<?= htmlspecialchars($success_msg, ENT_QUOTES) ?>");
<?php endif; ?>

// Display error messages as popup
<?php if (!empty($errors)): ?>
    <?php foreach ($errors as $e): ?>
        alert("<?= htmlspecialchars($e, ENT_QUOTES) ?>");
    <?php endforeach; ?>
<?php endif; ?>
</script>

</body>
</html>