<?php
// send_due_date_notifications.php
// Run this script daily via cron job to send due date reminders and overdue notifications

require_once __DIR__ . '/db.php';
session_start();

// Function to create notification for a user
function create_notification($pdo, $user_id, $criteria_title, $message, $book_id = null, $due_date = null) {
    // Map notification titles to criteria_id
    $criteria_map = [
        'Book Due Soon' => 5,      // Book Due Soon (Info)
        'Overdue Book' => 6,       // Overdue Book (Warning)
    ];
    
    $criteria_id = $criteria_map[$criteria_title] ?? 5;
    
    // Build message with book info if provided
    if ($book_id) {
        $book_stmt = $pdo->prepare('SELECT title FROM books WHERE id = ? LIMIT 1');
        $book_stmt->execute([$book_id]);
        $book = $book_stmt->fetch();
        $book_title = $book ? $book['title'] : 'Book';
        $message = str_replace('{book}', $book_title, $message);
    }
    
    // Check if similar notification already exists for today (avoid duplicates)
    $check_stmt = $pdo->prepare('
        SELECT id FROM notifications 
        WHERE user_id = ? AND criteria_id = ? AND DATE(created_at) = CURDATE()
        LIMIT 1
    ');
    $check_stmt->execute([$user_id, $criteria_id]);
    
    if ($check_stmt->rowCount() == 0) {
        $insert_stmt = $pdo->prepare('
            INSERT INTO notifications (user_id, criteria_id, message, created_at) 
            VALUES (?, ?, ?, NOW())
        ');
        $insert_stmt->execute([$user_id, $criteria_id, $message]);
        return true;
    }
    return false;
}

echo "[" . date('Y-m-d H:i:s') . "] Starting notification check...\n";

// 1. Get all borrowed books (status_id = 2 for borrowed)
$stmt = $pdo->prepare('
    SELECT br.id AS request_id, br.user_id, br.book_id, br.due_date, 
           b.title AS book_title, u.name AS user_name, u.email, u.email_notif
    FROM borrow_requests br
    JOIN books b ON b.id = br.book_id
    JOIN users u ON u.id = br.user_id
    WHERE br.book_status_id = 2 
    AND br.due_date IS NOT NULL
');
$stmt->execute();
$borrowed_books = $stmt->fetchAll();

$today = new DateTime();
$today->setTime(0, 0, 0);

$reminders_sent = 0;
$overdue_sent = 0;

foreach ($borrowed_books as $borrow) {
    $due_date = new DateTime($borrow['due_date']);
    $due_date->setTime(0, 0, 0);
    
    $days_until_due = $today->diff($due_date)->days;
    
    // Check if due date is in the past (negative diff)
    $is_overdue = ($due_date < $today);
    
    if ($is_overdue) {
        // Calculate days overdue
        $days_overdue = $today->diff($due_date)->days;
        
        // Send daily overdue notification
        $last_sent = $borrow['last_overdue_notification_sent'];
        
        // Send if never sent or sent on a different day
        if ($last_sent !== date('Y-m-d')) {
            $overdue_message = "⚠️ OVERDUE: Your borrowed book \"{book}\" is {$days_overdue} day(s) overdue. Please return it immediately to avoid further penalties.";
            if ($days_overdue == 1) {
                $overdue_message = "⚠️ OVERDUE: Your borrowed book \"{book}\" is 1 day overdue. Please return it today to avoid penalties.";
            }
            
            create_notification($pdo, $borrow['user_id'], 'Overdue Book', $overdue_message, $borrow['book_id']);
            
            // Update last notification sent date
            $update_stmt = $pdo->prepare('
                UPDATE borrow_requests 
                SET last_overdue_notification_sent = CURDATE() 
                WHERE id = ?
            ');
            $update_stmt->execute([$borrow['request_id']]);
            
            $overdue_sent++;
            echo "  - Sent overdue notification for user {$borrow['user_id']}, book: {$borrow['book_title']} ({$days_overdue} days overdue)\n";
        }
    } 
    elseif ($days_until_due <= 3) {
        // Send reminders for 3 days, 1 day, and today
        $should_send = false;
        $reminder_message = "";
        
        if ($days_until_due == 3) {
            $should_send = true;
            $reminder_message = "📚 REMINDER: Your borrowed book \"{book}\" is due in 3 days. Please return it by " . $borrow['due_date'];
        }
        elseif ($days_until_due == 1) {
            $should_send = true;
            $reminder_message = "⏰ URGENT REMINDER: Your borrowed book \"{book}\" is due TOMORROW (" . $borrow['due_date'] . "). Please return it on time to avoid overdue penalties.";
        }
        elseif ($days_until_due == 0) {
            $should_send = true;
            $reminder_message = "📅 DUE TODAY: Your borrowed book \"{book}\" is due TODAY (" . $borrow['due_date'] . "). Please return it before the library closes.";
        }
        
        if ($should_send) {
            $sent = create_notification($pdo, $borrow['user_id'], 'Book Due Soon', $reminder_message, $borrow['book_id']);
            if ($sent) {
                $reminders_sent++;
                echo "  - Sent {$days_until_due}-day reminder for user {$borrow['user_id']}, book: {$borrow['book_title']}\n";
            }
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Completed: {$reminders_sent} reminders sent, {$overdue_sent} overdue notifications sent.\n";
?>