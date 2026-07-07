<?php
// main.php — Book Catalog (Main Page)
 
$active_tab = 'catalog';
include 'includes/header.php';

// Function to get book rating
function getBookRating($pdo, $book_id) {
    $stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(rating) as rating_count FROM book_ratings WHERE book_id = ?");
    $stmt->execute([$book_id]);
    $rating = $stmt->fetch();
    return $rating;
}
 
$search   = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$sort     = trim($_GET['sort'] ?? 'name');
 
$sql = 'SELECT b.id, b.title, b.author, b.cover_image AS cover,
               bc.category, b.description, b.copies, b.copies_available, b.available
        FROM books b
        LEFT JOIN book_categories bc ON bc.id = b.category_id';
$params = [];
 
if ($search !== '') {
    $sql .= ' WHERE (b.title LIKE ? OR b.author LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
 
if ($category !== '' && $category !== 'All Categories') {
    if (empty($params)) {
        $sql .= ' WHERE bc.category = ?';
    } else {
        $sql .= ' AND bc.category = ?';
    }
    $params[] = $category;
}

if ($sort === 'date') {
    $sql .= ' ORDER BY b.created_at DESC';
} else {
    $sql .= ' ORDER BY b.title';
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();
$filtered = $books;
 
$genres = ['All Categories'];
$category_stmt = $pdo->query('SELECT DISTINCT COALESCE(bc.category, "Uncategorized")
                               FROM books b
                               LEFT JOIN book_categories bc ON bc.id = b.category_id
                               ORDER BY 1');
foreach ($category_stmt->fetchAll(PDO::FETCH_COLUMN) as $genre) {
    $genres[] = $genre;
}
 
// Get list of book_ids currently borrowed by the user
$borrowed_books = [];
$borrowed_count = 0;
$has_overdue = false;
$max_borrow_limit = 3;  // Default for normal members

if ($logged_in_user_id) {
    // Get user membership type to determine max books
    $user_stmt = $pdo->prepare('SELECT membership_type_id, user_type_id FROM users WHERE id = ? LIMIT 1');
    $user_stmt->execute([$logged_in_user_id]);
    $user_data = $user_stmt->fetch();
    
    if ($user_data && $user_data['membership_type_id'] == 2) {
        $max_borrow_limit = 8;
    }
    
    // Get currently borrowed books (requested or borrowed)
    $stmt = $pdo->prepare('SELECT book_id FROM borrow_requests WHERE user_id = ? AND book_status_id IN (1, 2)');
    $stmt->execute([$logged_in_user_id]);
    $borrowed_books = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $borrowed_count = count($borrowed_books);
    
    // Check for overdue books (borrowed status with due_date < today)
    $overdue_stmt = $pdo->prepare('
        SELECT COUNT(*) FROM borrow_requests 
        WHERE user_id = ? 
        AND book_status_id = 2 
        AND due_date < CURDATE()
    ');
    $overdue_stmt->execute([$logged_in_user_id]);
    $has_overdue = $overdue_stmt->fetchColumn() > 0;
}

// Calculate total books after filtering
$total_books_filtered = count($filtered);
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <h2 style="font-size: 24px; font-weight: 700; color: #111827; margin: 0;">Book Catalog</h2>
</div>

<!-- Overdue Warning Banner -->
<?php if ($has_overdue && $logged_in_user_id): ?>
<div class="overdue-warning" style="background-color: #fee2e2; border-left: 4px solid #dc2626; padding: 12px 20px; margin-bottom: 20px; border-radius: 8px;">
    <strong style="color: #991b1b;">⚠️ Overdue Books Alert</strong>
    <p style="margin: 5px 0 0 0; color: #7f1d1d;">You have overdue books. Please return them as soon as possible to borrow new books.</p>
</div>
<?php endif; ?>

<!-- Search and Filter Bar - Same style as librarians_main.php -->
<div style="margin-bottom: 24px;">
    <form method="GET" action="main.php" style="display: flex; gap: 12px; align-items: center;">
        <input type="text" name="search" placeholder="Search by Book Title or Author" value="<?= htmlspecialchars($search) ?>" style="flex: 4; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px;">
        <select name="category" style="flex: 1; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; background: white; cursor: pointer;" onchange="this.form.submit()">
            <?php foreach ($genres as $g): ?>
                <option value="<?= htmlspecialchars($g) ?>" <?= $category === $g ? 'selected' : '' ?>>
                    <?= htmlspecialchars($g) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="sort" style="flex: 1; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; background: white; cursor: pointer;" onchange="this.form.submit()">
            <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Sort by Name</option>
            <option value="date" <?= $sort === 'date' ? 'selected' : '' ?>>Sort by Date Added</option>
        </select>
        <button type="submit" class="btn btn-primary" style="flex: 1; white-space: nowrap;">Search</button>
        <a href="main.php" class="btn btn-secondary" style="flex: 1; white-space: nowrap; text-align: center; text-decoration: none;">Reset</a>
    </form>
</div>

<!-- Book Grid -->
<?php if (!empty($filtered)): ?>
<div class="book-grid">
  <?php foreach ($filtered as $book): 
    $rating_data = getBookRating($pdo, $book['id']);
  ?>
    <div class="book-card">
      <img src="<?= htmlspecialchars($book['cover']) ?>"
           alt="<?= htmlspecialchars($book['title']) ?>"
           class="book-cover" loading="lazy">
      <div class="book-info">
        <div class="book-title"><?= htmlspecialchars($book['title']) ?></div>
        <div class="book-author"><?= htmlspecialchars($book['author']) ?></div>
        <div class="book-meta">
          <?php if ($book['copies_available'] > 0): ?>
            <span class="availability-badge available"><?= $book['copies_available'] ?> available</span>
          <?php else: ?>
            <span class="availability-badge unavailable">Not available</span>
          <?php endif; ?>
        </div>
        
        <!-- Rating Display -->
        <?php if ($rating_data['avg_rating']): ?>
        <div class="book-rating" style="display: flex; align-items: center; gap: 6px; margin: 4px 0;">
          <span style="color: #f59e0b; font-size: 12px;">★</span>
          <span style="font-size: 12px; font-weight: 600;"><?= number_format($rating_data['avg_rating'], 1); ?></span>
          <span style="font-size: 11px; color: #9ca3af;">(<?= $rating_data['rating_count']; ?> reviews)</span>
        </div>
        <?php else: ?>
        <div class="book-rating" style="display: flex; align-items: center; gap: 6px; margin: 4px 0;">
          <span style="color: #d1d5db; font-size: 12px;">★</span>
          <span style="font-size: 11px; color: #9ca3af;">No ratings yet</span>
        </div>
        <?php endif; ?>
        
        <span class="book-genre-tag"><?= htmlspecialchars($book['category'] ?? 'Uncategorized') ?></span>
        <p class="book-description"><?= htmlspecialchars($book['description']) ?></p>
 
        <?php if ($has_overdue): ?>
            <!-- User has overdue books - cannot borrow -->
            <button class="btn btn-disabled" onclick="alert('You have overdue books. Please return them before borrowing new books.'); return false;">
                Overdue Books - Cannot Borrow
            </button>
        <?php elseif ($book['copies_available'] > 0 && $book['available'] && !in_array($book['id'], $borrowed_books)): ?>
            <?php if ($borrowed_count < $max_borrow_limit): ?>
                <a href="book_details.php?id=<?= $book['id'] ?>" class="btn btn-primary">View More</a>
            <?php else: ?>
                <button class="btn btn-disabled" onclick="alert('You have reached the maximum of ' + <?= $max_borrow_limit ?> + ' books. Please return some books first.'); return false;">
                    Max Books Reached
                </button>
            <?php endif; ?>
        <?php elseif (in_array($book['id'], $borrowed_books)): ?>
            <button class="btn btn-disabled" onclick="alert('You already have this book borrowed.'); return false;">
                Already Borrowed
            </button>
        <?php elseif ($book['copies_available'] > 0 && !$book['available']): ?>
            <button class="btn btn-disabled" disabled>Request Disabled</button>
        <?php else: ?>
            <button class="btn btn-disabled" disabled>Unavailable</button>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<!-- No records message -->
<div class="card">
    <div style="text-align: center; padding: 60px 20px;">
        <p style="color: #6b7280; font-size: 16px; margin-bottom: 8px;">No books found.</p>
        <p style="color: #9ca3af; font-size: 13px;">Try adjusting your search or filter.</p>
    </div>
</div>
<?php endif; ?>
 
<?php include 'includes/footer.php'; ?>