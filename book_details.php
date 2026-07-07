<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 
require_once __DIR__ . '/db.php';
 
$logged_in_username = $_SESSION['username'] ?? 'Stephen';
$stmt = $pdo->prepare('SELECT id, membership_type_id FROM users WHERE username = ? LIMIT 1');
$stmt->execute([$logged_in_username]);
$user_row = $stmt->fetch();
$logged_in_user_id = $user_row ? $user_row['id'] : 2;
$membership_type_id = $user_row ? $user_row['membership_type_id'] : 1;
$is_premium = $membership_type_id == 2;
 
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
  die("Invalid book ID.");
}
 
$id = (int) $_GET['id'];
$stmt = $pdo->prepare(
    'SELECT b.*, bc.category, bl.language, f.floor, f.rack, r.row
     FROM books b
     LEFT JOIN book_categories bc ON bc.id = b.category_id
     LEFT JOIN book_languages bl ON bl.id = b.language_id
     LEFT JOIN floor f ON f.id = b.floor_id
     LEFT JOIN row r ON r.id = b.row_id
     WHERE b.id = ?'
);
$stmt->execute([$id]);
$book = $stmt->fetch();
 
if (!$book) {
  die("Book not found.");
}

// Build location string from floor and row data
$location_display = '';
if ($book['floor'] && $book['rack'] && $book['row']) {
    $location_display = "Floor {$book['floor']}, Rack {$book['rack']}, {$book['row']} Row";
} elseif ($book['floor'] && $book['rack']) {
    $location_display = "Floor {$book['floor']}, Rack {$book['rack']}";
} else {
    $location_display = 'Location not specified';
}
 
$borrow_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rent_duration'])) {
    $duration = (int) $_POST['rent_duration'];
    
    // Validate duration based on membership type
    $valid_durations = $is_premium ? [7, 14, 21] : [7, 14];
    if (!in_array($duration, $valid_durations)) {
        $borrow_msg = $is_premium ? 'Invalid duration.' : 'Normal members can borrow for 7 or 14 days only. Upgrade to Premium for 21 days.';
    } elseif ((int) $book['available'] === 0) {
        $borrow_msg = 'This book is currently not allowed for borrowing requests.';
    } elseif ((int) $book['copies_available'] <= 0) {
        $borrow_msg = 'This book is currently not available.';
    } else {
        // Check if user already has max active books based on membership
        $max_books = $is_premium ? 10 : 5;
        $count_stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM borrow_requests WHERE user_id = ? AND book_status_id IN (1, 2)'
        );
        $count_stmt->execute([$logged_in_user_id]);
        $active_count = (int) $count_stmt->fetchColumn();
        
        if ($active_count >= $max_books) {
            // Show popup and redirect
            $max_msg = $is_premium ? 10 : 5;
            echo '<script>alert("You have reached the maximum limit of ' . $max_msg . ' books. Please return or cancel some books before borrowing more."); window.location.href = "main.php";</script>';
            exit;
        }
        
        try {
            $pdo->beginTransaction();
 
            $due_date = (new DateTime())->modify("+$duration days")->format('Y-m-d');
            $insert = $pdo->prepare(
                'INSERT INTO borrow_requests
                    (user_id, book_id, rent_duration, due_date, book_status_id, created_by, updated_by)
                VALUES (?, ?, ?, ?, 1, ?, ?)'
            );
            $insert->execute([
                $logged_in_user_id,
                $id,
                $duration,
                $due_date,
                $logged_in_user_id,
                $logged_in_user_id
            ]);
 
            $update = $pdo->prepare(
                'UPDATE books SET copies_available = copies_available - 1 WHERE id = ?'
            );
            $update->execute([$id]);
 
            $pdo->commit();

            // Insert notification using criteria
            $criteria_stmt = $pdo->prepare(
                'SELECT id FROM notifications_criteria 
                 WHERE title_id = (SELECT id FROM notifications_title WHERE title = ?) 
                 AND type_id = (SELECT id FROM notifications_type WHERE type = ?) LIMIT 1'
            );
            $criteria_stmt->execute(['Book Requested', 'success']);
            $criteria_id = $criteria_stmt->fetchColumn();
            
            if ($criteria_id) {
                $notif_stmt = $pdo->prepare(
                    'INSERT INTO notifications (user_id, criteria_id, message)
                     VALUES (?, ?, ?)'
                );
                $notif_stmt->execute([
                    $logged_in_user_id,
                    $criteria_id,
                    'You have successfully requested "' . $book['title'] . '".'
                ]);
            }

            // Show popup and redirect
            echo '<script>alert("Book requested successfully!"); window.location.href = "my_books.php";</script>';
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $borrow_msg = 'Unable to borrow the book at this time.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Book Details</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="book-detail-container">
    <div class="book-detail-header">
      <h1>Book Details</h1>
    </div>
    <?php if ($borrow_msg): ?>
      <div class="borrow-msg">✓ <?= htmlspecialchars($borrow_msg) ?></div>
    <?php endif; ?>
    <img class="book-detail-cover" src="<?php echo htmlspecialchars($book['cover_image']); ?>" alt="Book Cover">
    <div class="book-detail-title"><?php echo htmlspecialchars($book['title']); ?></div>
    <div class="book-detail-author">by <?php echo htmlspecialchars($book['author']); ?></div>
    <div class="book-detail-list">
      <p><strong>ISBN:</strong> <?php echo htmlspecialchars($book['isbn']); ?></p>
      <p><strong>Category:</strong> <?php echo htmlspecialchars($book['category'] ?? 'Uncategorized'); ?></p>
      <p><strong>Language:</strong> <?php echo htmlspecialchars($book['language'] ?? 'Unknown'); ?></p>
      <p><strong>Published Date:</strong> <?php echo htmlspecialchars($book['publish_date']); ?></p>
      <?php if (!empty($book['description'])): ?>
          <p><strong>Description:</strong> <?php echo nl2br(htmlspecialchars($book['description'])); ?></p>
      <?php endif; ?>
      <p><strong>Copies Available:</strong> <span id="copyCount"><?php echo (int)$book['copies_available']; ?></span></p>
      <p><strong>Book Location:</strong> <?php echo htmlspecialchars($location_display); ?></p>
    </div>
    <?php if ((int)$book['copies_available'] > 0 && (int)$book['available'] === 1): ?>
      <form class="borrow-form" action="book_details.php?id=<?php echo $id; ?>" method="POST">
        <div class="duration-label">Select Borrow Duration:</div>
        <div class="duration-options">
          <input id="dur7" class="duration-radio" type="radio" name="rent_duration" value="7" required>
          <label for="dur7" class="duration-option-label">7 Days</label>
          <input id="dur14" class="duration-radio" type="radio" name="rent_duration" value="14">
          <label for="dur14" class="duration-option-label">14 Days</label>
          <?php if ($is_premium): ?>
            <input id="dur21" class="duration-radio" type="radio" name="rent_duration" value="21">
            <label for="dur21" class="duration-option-label">21 Days</label>
          <?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary">Borrow Book</button>
      </form>
    <?php elseif ((int)$book['copies_available'] > 0 && (int)$book['available'] === 0): ?>
      <div style="color:#f59e0b;font-weight:600;">This book is currently not allowed for borrowing requests.</div>
    <?php else: ?>
      <div style="color:#ef4444;font-weight:600;">This book is currently not available.</div>
    <?php endif; ?>
    <div class="book-detail-actions">
      <a href="main.php" class="btn btn-outline">← Back to Catalog</a>
    </div>
  </div>
 
  <script>
    function checkCopies() {
      const copies = parseInt(document.getElementById("copyCount").textContent);
      if (copies <= 0) {
        alert("❌ This book is currently not available.");
        return false;
      }
      return true;
    }
  </script>
</body>
</html>