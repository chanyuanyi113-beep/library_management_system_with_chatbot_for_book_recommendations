<?php
// my_books.php — Currently Borrowed Books + Rate Returned Books

// Enable error logging but not display
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
error_reporting(E_ALL);

$active_tab = 'mybooks';

// Get rating filter from URL
$rating_filter = isset($_GET['rating_filter']) ? $_GET['rating_filter'] : 'all';

// IMPORTANT: Handle AJAX requests BEFORE including header.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating'])) {
    // Start clean for AJAX - no output buffering issues
    if (ob_get_level()) ob_clean();
    
    $borrow_id = intval($_POST['borrow_id']);
    $rating = intval($_POST['rating']);
    $book_id = intval($_POST['book_id']);
    
    // Now include header to get session and DB connection
    include 'includes/header.php';
    
    // Verify this borrow request belongs to the logged-in user AND is returned (status 3)
    $verify_stmt = $pdo->prepare("SELECT id, book_id FROM borrow_requests WHERE id = ? AND user_id = ? AND book_status_id = 3");
    $verify_stmt->execute([$borrow_id, $logged_in_user_id]);
    $borrow_data = $verify_stmt->fetch();
    
    if (!$borrow_data) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'You cannot rate this book. Invalid borrow record.']);
        exit;
    }
    
    if ($rating < 1 || $rating > 5) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid rating selected. Please choose 1-5 stars.']);
        exit;
    }
    
    // Check if user already rated this book (any borrow record)
    $check_stmt = $pdo->prepare("SELECT id, rating, borrow_requests_id FROM book_ratings WHERE user_id = ? AND book_id = ?");
    $check_stmt->execute([$logged_in_user_id, $book_id]);
    $existing_rating = $check_stmt->fetch();
    
    try {
        if ($existing_rating) {
            // Update existing rating with new borrow_requests_id and rating
            $rating_stmt = $pdo->prepare("UPDATE book_ratings SET rating = ?, borrow_requests_id = ? WHERE user_id = ? AND book_id = ?");
            $rating_stmt->execute([$rating, $borrow_id, $logged_in_user_id, $book_id]);
            $message = 'Rating updated from ' . $existing_rating['rating'] . '★ to ' . $rating . '★ based on your recent return!';
        } else {
            // Insert new rating
            $rating_stmt = $pdo->prepare("INSERT INTO book_ratings (user_id, book_id, borrow_requests_id, rating, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)");
            $rating_stmt->execute([$logged_in_user_id, $book_id, $borrow_id, $rating, $logged_in_user_id, $logged_in_user_id]);
            $message = 'Thank you for rating this book ' . $rating . '★!';
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'rating' => $rating,
            'borrow_id' => $borrow_id,
            'book_id' => $book_id
        ]);
        exit;
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// Handle cancel request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id']) && !isset($_POST['submit_rating'])) {
    // Start clean - no output buffering
    if (ob_get_level()) ob_clean();
    
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Require database connection only
    require_once __DIR__ . '/db.php';
    
    // Get logged in user ID from session
    if (!isset($_SESSION['username'])) {
        header('Location: index.php');
        exit;
    }
    
    $logged_in_username = $_SESSION['username'];
    $user_stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $user_stmt->execute([$logged_in_username]);
    $user_row = $user_stmt->fetch();
    
    if ($user_row) {
        $logged_in_user_id = $user_row['id'];
    } else {
        header('Location: index.php');
        exit;
    }
    
    $cancel_id = (int) $_POST['cancel_id'];

    try {
        $pdo->beginTransaction();

        $update = $pdo->prepare(
            'UPDATE borrow_requests
            SET book_status_id = 4, updated_by = ?
            WHERE id = ? AND user_id = ? AND book_status_id = 1'
        );
        $update->execute([$logged_in_user_id, $cancel_id, $logged_in_user_id]);

        $pdo->commit();
        
        // Get the book title for notification
        $book_stmt = $pdo->prepare('SELECT b.title FROM borrow_requests br JOIN books b ON br.book_id = b.id WHERE br.id = ?');
        $book_stmt->execute([$cancel_id]);
        $cancelled_book = $book_stmt->fetchColumn();

        // Insert notification
        $criteria_stmt = $pdo->prepare(
            'SELECT id FROM notifications_criteria 
             WHERE title_id = (SELECT id FROM notifications_title WHERE title = ?) 
             AND type_id = (SELECT id FROM notifications_type WHERE type = ?) LIMIT 1'
        );
        $criteria_stmt->execute(['Request Cancelled', 'info']);
        $criteria_id = $criteria_stmt->fetchColumn();
        
        if ($criteria_id) {
            $notif_stmt = $pdo->prepare(
                'INSERT INTO notifications (user_id, criteria_id, message)
                 VALUES (?, ?, ?)'
            );
            $notif_stmt->execute([
                $logged_in_user_id,
                $criteria_id,
                'You have cancelled your request for "' . $cancelled_book . '".'
            ]);
        }
        
        // Use JavaScript redirect instead of header to avoid "headers already sent" error
        echo '<!DOCTYPE html>
        <html>
        <head>
            <script>
                alert("Request cancelled successfully!");
                window.location.href = "my_books.php?rating_filter=' . $rating_filter . '";
            </script>
        </head>
        <body></body>
        </html>';
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo '<!DOCTYPE html>
        <html>
        <head>
            <script>
                alert("Unable to cancel the request right now.");
                window.location.href = "my_books.php?rating_filter=' . $rating_filter . '";
            </script>
        </head>
        <body></body>
        </html>';
        exit;
    }
}

// Normal page load - include header
include 'includes/header.php';

$return_msg = $_GET['error'] ?? '';

// Get currently borrowed books (status 1 or 2)
$stmt = $pdo->prepare(
    'SELECT br.id, br.book_id, b.title, br.request_date, br.due_date, br.book_status_id, b.author, bs.status AS status_name
     FROM borrow_requests br
     JOIN books b ON b.id = br.book_id
     JOIN book_status bs ON bs.id = br.book_status_id
     WHERE br.user_id = ? AND br.book_status_id IN (1, 2)
     ORDER BY br.due_date ASC'
);
$stmt->execute([$logged_in_user_id]);
$borrowed = $stmt->fetchAll();

// Get DISTINCT returned books (status 3) grouped by book_id
// Each book appears only once, with the most recent borrow_id and returned_date
$returned_stmt = $pdo->prepare(
    'SELECT 
        MAX(br.id) as borrow_id, 
        br.book_id, 
        b.title, 
        b.author, 
        b.cover_image, 
        MAX(br.returned_date) as returned_date,
        (SELECT rating FROM book_ratings WHERE user_id = ? AND book_id = b.id ORDER BY id DESC LIMIT 1) as user_rating
     FROM borrow_requests br
     JOIN books b ON b.id = br.book_id
     WHERE br.user_id = ? AND br.book_status_id = 3
     GROUP BY br.book_id, b.title, b.author, b.cover_image
     ORDER BY returned_date DESC'
);
$returned_stmt->execute([$logged_in_user_id, $logged_in_user_id]);
$all_returned_books = $returned_stmt->fetchAll();

// Apply rating filter
$returned_books = [];
foreach ($all_returned_books as $book) {
    if ($rating_filter === 'rated' && $book['user_rating'] !== null) {
        $returned_books[] = $book;
    } elseif ($rating_filter === 'unrated' && $book['user_rating'] === null) {
        $returned_books[] = $book;
    } elseif ($rating_filter === 'all') {
        $returned_books[] = $book;
    }
}

$today = new DateTime();

// Helper: compute status label & class
function getStatus(string $due_date, DateTime $today, string $status_name): array {
    if ($status_name === 'requested') {
        return ['label' => 'Requested', 'class' => 'requested'];
    }
    
    $due  = new DateTime($due_date);
    $diff = (int) $today->diff($due)->format('%r%a');
    if ($diff < 0) {
        return ['label' => 'Overdue by ' . abs($diff) . ' days', 'class' => 'overdue'];
    } elseif ($diff <= 3) {
        return ['label' => 'Due in ' . $diff . ' days', 'class' => 'due-soon'];
    } else {
        return ['label' => 'Active', 'class' => 'active'];
    }
}
?>

<?php if ($return_msg == 'cancel_failed'): ?>
  <div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;
              padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;">
    ✗ Unable to cancel the request right now.
  </div>
<?php endif; ?>

<?php if ($return_msg && $return_msg != 'cancel_failed'): ?>
  <div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;
              padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;">
    ✓ <?= htmlspecialchars($return_msg) ?>
  </div>
<?php endif; ?>

<!-- Currently Borrowed Books Section -->
<div class="section-card">
  <div class="section-card-header">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="10"/>
      <polyline points="12 6 12 12 16 14"/>
    </svg>
    Currently Borrowed Books
  </div>

  <?php if (empty($borrowed)): ?>
    <div style="text-align:center;padding:48px 20px;color:#9ca3af;">
      <div style="font-size:40px;margin-bottom:12px;">📭</div>
      <div style="font-weight:600;color:#374151;">No borrowed books</div>
      <div style="font-size:13px;margin-top:4px;">
        Head to the <a href="main.php" style="color:#111827;text-decoration:underline;">Book Catalog</a>
        to borrow a book.
      </div>
    </div>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Book</th>
          <th>Author</th>
          <th>Borrowed Date</th>
          <th>Due Date</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($borrowed as $book):
          $status = getStatus($book['due_date'], $today, $book['status_name']);
        ?>
          <tr>
            <td style="font-weight:600;color:#111827;">
              <?= htmlspecialchars($book['title']) ?>
             </td>
            <td><?= htmlspecialchars($book['author']) ?></td>
            <td>
              <span class="date-cell">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                  <line x1="16" y1="2" x2="16" y2="6"/>
                  <line x1="8" y1="2" x2="8" y2="6"/>
                  <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <?= date('M j, Y', strtotime($book['request_date'])) ?>
              </span>
            </td>
            <td>
              <span class="date-cell">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                  <line x1="16" y1="2" x2="16" y2="6"/>
                  <line x1="8" y1="2" x2="8" y2="6"/>
                  <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <?= date('M j, Y', strtotime($book['due_date'])) ?>
              </span>
            </td>
            <td>
              <span class="status-badge <?= $status['class'] ?>">
                <?= htmlspecialchars($status['label']) ?>
              </span>
            </td>
            <td>
              <?php if ($book['book_status_id'] == 1): ?>
                <form method="POST" action="my_books.php"
                      onsubmit="return confirm('Cancel request for &quot;<?= addslashes(htmlspecialchars($book['title'])) ?>&quot;?')">
                  <input type="hidden" name="cancel_id" value="<?= $book['id'] ?>">
                  <input type="hidden" name="rating_filter" value="<?= $rating_filter ?>">
                  <button type="submit" class="btn btn-outline btn-sm">Cancel</button>
                </form>
              <?php elseif ($book['book_status_id'] == 2): ?>
                <span style="font-size:13px;color:#475569;">Return through librarian only</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- Returned Books Section - Rate Them! -->
<div class="section-card" style="margin-top: 24px;">
  <div class="section-card-header">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
    </svg>
    Rate Returned Books
  </div>

  <!-- Rating Filter Tabs -->
  <div style="display: flex; gap: 12px; margin-bottom: 20px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px;">
    <a href="my_books.php?rating_filter=all" 
       class="filter-tab <?php echo $rating_filter === 'all' ? 'active' : ''; ?>" 
       style="padding: 6px 16px; border-radius: 20px; text-decoration: none; font-size: 13px; <?php echo $rating_filter === 'all' ? 'background: #111827; color: white;' : 'background: #f3f4f6; color: #374151;'; ?>">
      View All (<?php echo count($all_returned_books); ?>)
    </a>
    <a href="my_books.php?rating_filter=rated" 
       class="filter-tab <?php echo $rating_filter === 'rated' ? 'active' : ''; ?>" 
       style="padding: 6px 16px; border-radius: 20px; text-decoration: none; font-size: 13px; <?php echo $rating_filter === 'rated' ? 'background: #111827; color: white;' : 'background: #f3f4f6; color: #374151;'; ?>">
      Rated (<?php 
        $rated_count = 0;
        foreach ($all_returned_books as $b) { if ($b['user_rating'] !== null) $rated_count++; }
        echo $rated_count;
      ?>)
    </a>
    <a href="my_books.php?rating_filter=unrated" 
       class="filter-tab <?php echo $rating_filter === 'unrated' ? 'active' : ''; ?>" 
       style="padding: 6px 16px; border-radius: 20px; text-decoration: none; font-size: 13px; <?php echo $rating_filter === 'unrated' ? 'background: #111827; color: white;' : 'background: #f3f4f6; color: #374151;'; ?>">
      Unrated (<?php 
        $unrated_count = 0;
        foreach ($all_returned_books as $b) { if ($b['user_rating'] === null) $unrated_count++; }
        echo $unrated_count;
      ?>)
    </a>
  </div>

  <?php if (empty($returned_books)): ?>
    <div style="text-align:center;padding:48px 20px;color:#9ca3af;">
      <div style="font-size:40px;margin-bottom:12px;">
        <?php if ($rating_filter === 'rated'): ?>⭐<?php elseif ($rating_filter === 'unrated'): ?>📝<?php else: ?>📚<?php endif; ?>
      </div>
      <div style="font-weight:600;color:#374151;">
        <?php if ($rating_filter === 'rated'): ?>
          No rated books yet
        <?php elseif ($rating_filter === 'unrated'): ?>
          No unrated books - all books have been rated!
        <?php else: ?>
          No returned books yet
        <?php endif; ?>
      </div>
      <div style="font-size:13px;margin-top:4px;">
        <?php if ($rating_filter === 'unrated'): ?>
          Great job! You've rated all your returned books.
        <?php else: ?>
          Books you return will appear here for you to rate.
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div style="display: grid; gap: 16px;">
      <?php foreach ($returned_books as $book): ?>
        <div id="book-container-<?= $book['book_id'] ?>" style="border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; background: #ffffff;">
          <?php 
          $cover_path = !empty($book['cover_image']) ? $book['cover_image'] : 'cover/default.jpg';
          ?>
          <img src="<?= htmlspecialchars($cover_path) ?>" 
               style="width: 60px; height: 80px; object-fit: cover; border-radius: 8px; background: #f3f4f6;"
               onerror="this.src='cover/default.jpg'">
          <div style="flex: 1;">
            <div style="font-weight: 700; color: #111827;"><?= htmlspecialchars($book['title']) ?></div>
            <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">by <?= htmlspecialchars($book['author']) ?></div>
            <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;">
              Returned: <?= date('d M Y', strtotime($book['returned_date'])) ?>
            </div>
          </div>
          <div id="rating-area-<?= $book['book_id'] ?>">
            <?php if ($book['user_rating'] !== null): ?>
              <div style="text-align: center; background: #f3f4f6; padding: 8px 16px; border-radius: 8px;">
                <div style="color: #f59e0b; font-size: 18px; font-weight: 600;">★ <?= $book['user_rating'] ?>/5</div>
                <div style="font-size: 11px; color: #10b981; margin-top: 2px;">✓ Rated</div>
                <div style="font-size: 10px; color: #6b7280; margin-top: 2px;">
                  <a href="#" onclick="showRatingForm(<?= $book['borrow_id'] ?>, <?= $book['book_id'] ?>); return false;" style="color: #3b82f6;">Update rating</a>
                </div>
              </div>
            <?php else: ?>
              <form class="rating-form" data-borrow-id="<?= $book['borrow_id'] ?>" data-book-id="<?= $book['book_id'] ?>" method="POST" action="my_books.php" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="borrow_id" value="<?= $book['borrow_id'] ?>">
                <input type="hidden" name="book_id" value="<?= $book['book_id'] ?>">
                <input type="hidden" name="submit_rating" value="1">
                <input type="hidden" name="rating_filter" value="<?= $rating_filter ?>">
                <select name="rating" required style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; background: white; cursor: pointer;">
                  <option value="">Rate this book ★</option>
                  <option value="1">★ - Poor</option>
                  <option value="2">★★ - Fair</option>
                  <option value="3">★★★ - Good</option>
                  <option value="4">★★★★ - Very Good</option>
                  <option value="5">★★★★★ - Excellent</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm" style="white-space: nowrap; cursor: pointer;">Submit Rating</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
document.body.addEventListener('submit', function(e) {
    const form = e.target.closest('.rating-form');
    if (!form) return;
    
    e.preventDefault();
    
    const borrowId = form.dataset.borrowId;
    const bookId = form.dataset.bookId;
    const formData = new FormData(form);
    
    if (!formData.has('submit_rating')) {
        formData.append('submit_rating', '1');
    }
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn ? submitBtn.textContent : 'Submit';
    if (submitBtn) {
        submitBtn.textContent = 'Submitting...';
        submitBtn.disabled = true;
    }
    
    fetch('my_books.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(async function(response) {
        if (!response.ok) {
            throw new Error('HTTP error ' + response.status);
        }
        
        const text = await response.text();
        console.log('RAW RESPONSE TEXT:', text);
        
        try {
            const result = JSON.parse(text);
            return result;
        } catch (e) {
            console.error('JSON parse error:', e);
            console.error('Full response:', text);
            throw new Error('Invalid JSON response. Check console for details.');
        }
    })
    .then(function(result) {
        console.log('Result:', result);
        
        if (result.success) {
            // Get current rating filter from URL or hidden input
            let ratingFilter = '<?php echo $rating_filter; ?>';
            
            // If this was an unrated book being rated, and we're in 'unrated' filter,
            // remove it from the display
            const bookContainer = document.getElementById('book-container-' + result.book_id);
            if (ratingFilter === 'unrated') {
                if (bookContainer) {
                    bookContainer.remove();
                    // Check if there are no more books in unrated section
                    const remainingBooks = document.querySelectorAll('#rating-area-' + result.book_id);
                    if (remainingBooks.length === 0) {
                        location.reload();
                    }
                }
            } else {
                // Update the rating area
                const ratingArea = document.getElementById('rating-area-' + result.book_id);
                if (ratingArea) {
                    ratingArea.innerHTML = `
                        <div style="text-align: center; background: #f3f4f6; padding: 8px 16px; border-radius: 8px;">
                            <div style="color: #f59e0b; font-size: 18px; font-weight: 600;">★ ${result.rating}/5</div>
                            <div style="font-size: 11px; color: #10b981; margin-top: 2px;">✓ Rated</div>
                            <div style="font-size: 10px; color: #6b7280; margin-top: 2px;">
                                <a href="#" onclick="showRatingForm(${result.borrow_id}, ${result.book_id}); return false;" style="color: #3b82f6;">Update rating</a>
                            </div>
                        </div>
                    `;
                }
            }
            alert(result.message);
        } else {
            alert(result.message || 'Rating failed!');
        }
    })
    .catch(function(error) {
        console.error('Error:', error);
        alert('Something went wrong: ' + error.message);
    })
    .finally(function() {
        if (submitBtn) {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    });
});

function showRatingForm(borrowId, bookId) {
    const ratingArea = document.getElementById('rating-area-' + bookId);
    if (!ratingArea) return;
    
    ratingArea.innerHTML = `
        <form class="rating-form" data-borrow-id="${borrowId}" data-book-id="${bookId}" method="POST" action="my_books.php" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="borrow_id" value="${borrowId}">
            <input type="hidden" name="book_id" value="${bookId}">
            <input type="hidden" name="submit_rating" value="1">
            <input type="hidden" name="rating_filter" value="<?php echo $rating_filter; ?>">
            <select name="rating" required style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; background: white; cursor: pointer;">
                <option value="">Rate this book ★</option>
                <option value="1">★ - Poor</option>
                <option value="2">★★ - Fair</option>
                <option value="3">★★★ - Good</option>
                <option value="4">★★★★ - Very Good</option>
                <option value="5">★★★★★ - Excellent</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm" style="white-space: nowrap; cursor: pointer;">Submit Rating</button>
        </form>
    `;
}
</script>

<?php include 'includes/footer.php'; ?>