<?php
session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['floor_id']) || !is_numeric($_GET['floor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid floor ID']);
    exit();
}

$floor_id = intval($_GET['floor_id']);

try {
    $stmt = $pdo->prepare("
        SELECT 
            b.id as book_id, 
            b.title, 
            b.author, 
            b.copies, 
            b.copies_available, 
            b.available, 
            b.cover_image, 
            r.row as row_name,
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM borrow_requests br 
                    WHERE br.book_id = b.id AND br.book_status_id IN (1, 2)
                ) THEN 1 
                ELSE 0 
            END as has_active_borrow,
            (SELECT COUNT(*) FROM borrow_requests br 
             WHERE br.book_id = b.id AND br.book_status_id IN (1, 2)) as active_borrow_count
        FROM books b
        LEFT JOIN row r ON b.row_id = r.id
        WHERE b.floor_id = ?
        ORDER BY r.id ASC, b.title ASC
    ");
    $stmt->execute([$floor_id]);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'books' => $books]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>