<?php
// chatbot_api.php - Pure database recommendations with dynamic genres
session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$userMessage = trim($_POST['message'] ?? '');
$getGenres = isset($_POST['get_genres']) && $_POST['get_genres'] == '1';

// Get all genres from database (only those with available books)
function getAllGenres($pdo) {
    $stmt = $pdo->prepare("
        SELECT DISTINCT bc.id, bc.category 
        FROM book_categories bc
        INNER JOIN books b ON b.category_id = bc.id
        WHERE b.copies_available > 0 AND b.available = 1
        ORDER BY bc.category ASC
    ");
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no genres found with available books, return all categories as fallback
    if (empty($result)) {
        $stmt = $pdo->prepare("
            SELECT id, category 
            FROM book_categories 
            ORDER BY category ASC
        ");
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $result;
}

// Get ALL genres from database (including those without books)
function getAllGenresIncludingEmpty($pdo) {
    $stmt = $pdo->prepare("
        SELECT id, category 
        FROM book_categories 
        ORDER BY category ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle genre list request (only return genres with books)
if ($getGenres) {
    $genres = getAllGenres($pdo);
    echo json_encode(['genres' => $genres]);
    exit;
}

if (empty($userMessage)) {
    echo json_encode(['reply' => 'Please ask me something about books! 📚', 'showBubbles' => true]);
    exit;
}

$messageLower = strtolower($userMessage);

// Get books by category
function getBooksByCategory($pdo, $category, $limit = 5) {
    $stmt = $pdo->prepare("
        SELECT b.title, b.author, bc.category, b.copies_available, b.times_borrowed,
               f.floor, f.rack, r.row
        FROM books b
        LEFT JOIN book_categories bc ON b.category_id = bc.id
        LEFT JOIN floor f ON b.floor_id = f.id
        LEFT JOIN row r ON b.row_id = r.id
        WHERE LOWER(bc.category) = LOWER(?) AND b.copies_available > 0 AND b.available = 1
        ORDER BY b.times_borrowed DESC, b.title ASC
        LIMIT ?
    ");
    $stmt->execute([$category, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if genre has any available books
function hasBooksInGenre($pdo, $genre) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM books b
        LEFT JOIN book_categories bc ON b.category_id = bc.id
        WHERE LOWER(bc.category) = LOWER(?) AND b.copies_available > 0 AND b.available = 1
    ");
    $stmt->execute([$genre]);
    return $stmt->fetchColumn() > 0;
}

// Search books by keyword
function searchBooks($pdo, $keyword, $limit = 5) {
    $stmt = $pdo->prepare("
        SELECT b.title, b.author, bc.category, b.copies_available, 
               f.floor, f.rack, r.row
        FROM books b
        LEFT JOIN book_categories bc ON b.category_id = bc.id
        LEFT JOIN floor f ON b.floor_id = f.id
        LEFT JOIN row r ON b.row_id = r.id
        WHERE (LOWER(b.title) LIKE ? OR LOWER(b.author) LIKE ? OR LOWER(bc.category) LIKE ?)
        AND b.copies_available > 0
        LIMIT ?
    ");
    $searchTerm = "%$keyword%";
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get popular books
function getPopularBooks($pdo, $limit = 5) {
    $stmt = $pdo->prepare("
        SELECT b.title, b.author, bc.category, b.times_borrowed, b.copies_available,
               f.floor, f.rack, r.row
        FROM books b
        LEFT JOIN book_categories bc ON b.category_id = bc.id
        LEFT JOIN floor f ON b.floor_id = f.id
        LEFT JOIN row r ON b.row_id = r.id
        WHERE b.copies_available > 0 AND b.available = 1
        ORDER BY b.times_borrowed DESC, b.title ASC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get highly rated books
function getHighlyRatedBooks($pdo, $limit = 5) {
    $stmt = $pdo->prepare("
        SELECT b.id, b.title, b.author, bc.category, b.copies_available, b.cover_image,
               AVG(br.rating) as avg_rating, COUNT(br.id) as rating_count,
               f.floor, f.rack, r.row
        FROM books b
        LEFT JOIN book_categories bc ON b.category_id = bc.id
        LEFT JOIN book_ratings br ON b.id = br.book_id
        LEFT JOIN floor f ON b.floor_id = f.id
        LEFT JOIN row r ON b.row_id = r.id
        WHERE b.copies_available > 0 AND b.available = 1
        GROUP BY b.id
        HAVING avg_rating >= 4
        ORDER BY avg_rating DESC, rating_count DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check if any books are rated
function hasRatedBooks($pdo) {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT br.book_id) as rated_count
        FROM book_ratings br
        JOIN books b ON b.id = br.book_id
        WHERE b.copies_available > 0 AND b.available = 1
    ");
    $stmt->execute();
    return $stmt->fetchColumn() > 0;
}

// Get all available genres for display (with book count)
function getGenreListWithCount($pdo) {
    $stmt = $pdo->prepare("
        SELECT bc.category, COUNT(b.id) as book_count
        FROM book_categories bc
        LEFT JOIN books b ON b.category_id = bc.id AND b.copies_available > 0 AND b.available = 1
        GROUP BY bc.id, bc.category
        HAVING book_count > 0
        ORDER BY bc.category ASC
    ");
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no genres with books, return all categories with count 0
    if (empty($result)) {
        $stmt = $pdo->prepare("
            SELECT category, 0 as book_count
            FROM book_categories 
            ORDER BY category ASC
        ");
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $result;
}

// Get personalized recommendations based on user's favorite categories
function getPersonalizedRecommendations($pdo, $userId) {
    // Get user's favorite categories
    $fav_stmt = $pdo->prepare('
        SELECT bc.id, bc.category 
        FROM user_favorite_categories ufc
        JOIN book_categories bc ON ufc.category_id = bc.id
        WHERE ufc.user_id = ?
        ORDER BY bc.category
    ');
    $fav_stmt->execute([$userId]);
    $favoriteCategories = $fav_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($favoriteCategories)) {
        return null;
    }
    
    $categoryCount = count($favoriteCategories);
    
    // Determine how many books per category
    $booksPerCategory = ($categoryCount == 1) ? 5 : 3;
    
    $recommendations = [];
    
    foreach ($favoriteCategories as $cat) {
        $stmt = $pdo->prepare("
            SELECT b.title, b.author, bc.category, b.copies_available, b.times_borrowed,
                f.floor, f.rack, r.row
            FROM books b
            LEFT JOIN book_categories bc ON b.category_id = bc.id
            LEFT JOIN floor f ON b.floor_id = f.id
            LEFT JOIN row r ON b.row_id = r.id
            WHERE LOWER(bc.category) = LOWER(?) 
            AND b.copies_available > 0 
            AND b.available = 1
            ORDER BY b.times_borrowed DESC, b.title ASC
            LIMIT ?
        ");
        $stmt->execute([$cat['category'], $booksPerCategory]);
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $recommendations[$cat['category']] = $books;
    }
    
    return [
        'categories' => $favoriteCategories,
        'books_per_category' => $booksPerCategory,
        'recommendations' => $recommendations
    ];
}

$reply = '';
$showBubbles = true;

// Check for specific intents first
$isAskingForHowTo = (strpos($messageLower, 'how to borrow') !== false || strpos($messageLower, 'step') !== false);
$isAskingForGenres = (strpos($messageLower, 'genre') !== false || strpos($messageLower, 'category') !== false || strpos($messageLower, 'categories') !== false);
$isAskingForPopular = (strpos($messageLower, 'popular') !== false || strpos($messageLower, 'trend') !== false || strpos($messageLower, 'famous') !== false ||
                       strpos($messageLower, 'hot') !== false);
$isAskingForRated = (strpos($messageLower, 'rated') !== false || strpos($messageLower, 'good') !== false || strpos($messageLower, 'great') !== false ||
                     strpos($messageLower, 'best') !== false || strpos($messageLower, 'top') !== false);
$isAskingForRecommend = (strpos($messageLower, 'recommend') !== false || strpos($messageLower, 'suggest') !== false);
$isAskingForSearch = (strpos($messageLower, 'search') !== false || strpos($messageLower, 'find') !== false || strpos($messageLower, 'have') !== false || 
                      strpos($messageLower, 'want') !== false || strpos($messageLower, 'any') !== false || strpos($messageLower, 'give') !== false);
$isAskingForNew = (strpos($messageLower, 'new') !== false || strpos($messageLower, 'late') !== false || strpos($messageLower, 'recent') !== false);

// Check for genre in message
if (!$isAskingForHowTo && !$isAskingForGenres && !$isAskingForPopular && !$isAskingForRated && !$isAskingForSearch && !$isAskingForNew) {
    $genresList = getAllGenresIncludingEmpty($pdo);
    $foundGenre = null;
    
    // Sort genres by length (longest first) to prioritize "science fiction" over "fiction"
    usort($genresList, function($a, $b) {
        return strlen($b['category']) - strlen($a['category']);
    });
    
    foreach ($genresList as $genre) {
        $genreLower = strtolower($genre['category']);
        if (strpos($messageLower, $genreLower) !== false) {
            $foundGenre = $genre['category'];
            break;
        }
    }
    
    if ($foundGenre) {
        // User wants books from a specific genre
        $hasBooks = hasBooksInGenre($pdo, $foundGenre);
        
        if (!$hasBooks) {
            $reply = "😔 **Sorry, no books available in {$foundGenre} right now.**\n\n" .
                     "Please check back later or try a different genre!";
        } else {
            $books = getBooksByCategory($pdo, $foundGenre, 5);
            if (!empty($books)) {
                $reply = "📚 **{$foundGenre} books available in our library:**\n\n";
                foreach ($books as $book) {
                    $reply .= "✨ **{$book['title']}** by {$book['author']}\n";
                    // Build location string
                    $location = '';
                    if ($book['floor'] && $book['rack'] && $book['row']) {
                        $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}, {$book['row']} Row\n";
                    } elseif ($book['floor'] && $book['rack']) {
                        $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}\n";
                    }
                    $reply .= $location;
                    $reply .= "   <button class=\"chat-go-btn\" data-book=\"" . htmlspecialchars($book['title']) . "\">🔍 Go to Book</button>\n\n";
                }
            } else {
                $reply = "😔 **No {$foundGenre} books available right now.**\n\n" .
                         "Please check back later or try a different genre!";
            }
        }
    }
}

// If no reply yet, continue with other intents
if (empty($reply)) {
    if ($isAskingForHowTo) {
        $reply = "📚 **How to borrow a book:**\n\n" .
                 "1. Search for the book in our catalog\n" .
                 "2. Click on the book to view details\n" .
                 "3. Choose your borrow duration\n" .
                 "4. Click the 'Borrow Book' button\n" .
                 "5. Take the book to front desk and wait for librarian approval\n\n" .
                 "Need help? Ask a librarian at the front desk!";
    }
    elseif ($isAskingForGenres) {
        $genres = getGenreListWithCount($pdo);
        
        if (empty($genres)) {
            $reply = "😔 I couldn't find any genres with available books at the moment. Please check back later!";
        } else {
            // Build text list of genres
            $genreList = "";
            
            foreach ($genres as $genreData) {
                $genre = $genreData['category'];
                $bookCount = $genreData['book_count'];
                $genreList .= "• **{$genre}** ({$bookCount} books available)\n";
            }
            
            $reply = "🎭 **Here are the genres available in our library:**\n\n" .
                     $genreList .
                     "\nJust say 'recommend [genre] books' to get started! Example: 'recommend fantasy books'";
        }
    }
    elseif ($isAskingForPopular) {
        $books = getPopularBooks($pdo);
        if (!empty($books)) {
            $reply = "⭐ **Trending books in the library:** \n\n";
            foreach ($books as $book) {
                $reply .= "✨ **{$book['title']}** by {$book['author']}\n";
                // Build location string
                $location = '';
                if ($book['floor'] && $book['rack'] && $book['row']) {
                    $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}, {$book['row']} Row\n";
                } elseif ($book['floor'] && $book['rack']) {
                    $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}\n";
                }
                $reply .= $location;
                $reply .= "   <button class=\"chat-go-btn\" data-book=\"" . htmlspecialchars($book['title']) . "\">🔍 Go to Book</button>\n\n";
            }
            $reply .= "Want recommendations for a specific genre? Just say 'recommend fantasy books' or try a different genre!";
        } else {
            $reply = "😔 I couldn't find any books available right now. Please check back later! 📚";
        }
    }
    elseif ($isAskingForRated) {
        $hasRated = hasRatedBooks($pdo);
        
        if (!$hasRated) {
            $reply = "⭐ **No books have been rated yet!**\n\n" .
                     "Be the first to rate books you've borrowed! After returning a book, " .
                     "go to 'My Books' section and rate the books you've read.\n\n" .
                     "Your ratings help other readers find great books!";
        } else {
            $books = getHighlyRatedBooks($pdo, 5);
            if (!empty($books)) {
                $reply = "⭐ **Highly Rated Books**\n\n";
                foreach ($books as $book) {
                    $avgRating = round($book['avg_rating'], 1);
                    $stars = '';
                    $fullStars = floor($avgRating);
                    $halfStar = ($avgRating - $fullStars) >= 0.5;
                    for ($i = 1; $i <= 5; $i++) {
                        if ($i <= $fullStars) {
                            $stars .= '★';
                        } elseif ($i == $fullStars + 1 && $halfStar) {
                            $stars .= '½';
                        } else {
                            $stars .= '☆';
                        }
                    }
                    $reply .= "✨ **{$book['title']}** by {$book['author']}\n";
                    $reply .= "   ⭐ {$stars} ({$avgRating}/5 from {$book['rating_count']} ratings)\n";
                    // Build location string
                    $location = '';
                    if ($book['floor'] && $book['rack'] && $book['row']) {
                        $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}, {$book['row']} Row\n";
                    } elseif ($book['floor'] && $book['rack']) {
                        $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}\n";
                    }
                    $reply .= $location;
                    $reply .= "   <button class=\"chat-go-btn\" data-book=\"" . htmlspecialchars($book['title']) . "\">🔍 Go to Book</button>\n\n";
                }
                $reply .= "Want more recommendations? Try 'what's popular' or 'recommend fantasy books'!";
            } else {
                $reply = "⭐ **No highly rated books found at the moment.**\n\n" .
                         "Check back later when more readers have rated books!";
            }
        }
    }
    elseif ($isAskingForRecommend) {
        // Check if user mentioned a specific genre
        $genresList = getAllGenresIncludingEmpty($pdo);
        $foundGenre = null;
        
        // Sort genres by length (longest first) to prioritize "science fiction" over "fiction"
        usort($genresList, function($a, $b) {
            return strlen($b['category']) - strlen($a['category']);
        });
        
        foreach ($genresList as $genre) {
            $genreLower = strtolower($genre['category']);
            if (strpos($messageLower, $genreLower) !== false) {
                $foundGenre = $genre['category'];
                break;
            }
        }
        
        if ($foundGenre) {
            // User asked for a specific genre - show books from that genre
            $hasBooks = hasBooksInGenre($pdo, $foundGenre);
            
            if (!$hasBooks) {
                $reply = "😔 **Sorry, no books available in {$foundGenre} right now.**\n\n" .
                         "We don't have any {$foundGenre} books available in our library at the moment. " .
                         "Would you like me to recommend a different genre?\n\n" .
                         "Try saying 'recommend fantasy books' or 'recommend mystery books' for other options!";
            } else {
                $books = getBooksByCategory($pdo, $foundGenre);
                if (!empty($books)) {
                    $reply = "📚 **{$foundGenre} books available in our library:**\n\n";
                    foreach ($books as $book) {
                        $reply .= "✨ **{$book['title']}** by {$book['author']}\n";
                        // Build location string
                        $location = '';
                        if ($book['floor'] && $book['rack'] && $book['row']) {
                            $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}, {$book['row']} Row\n";
                        } elseif ($book['floor'] && $book['rack']) {
                            $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}\n";
                        }
                        $reply .= $location;
                        $reply .= "   <button class=\"chat-go-btn\" data-book=\"" . htmlspecialchars($book['title']) . "\">🔍 Go to Book</button>\n\n";
                    }
                    $reply .= "Would you like more details about any of these books?";
                } else {
                    $reply = "😔 **No {$foundGenre} books available right now.**\n\n" .
                             "We don't have any {$foundGenre} books in our collection at the moment. " .
                             "Please check back later or try a different genre! 📚";
                }
            }
        } else {
            // No specific genre mentioned - check user's favorite categories
            $userId = $_SESSION['user_id'] ?? null;
            
            if ($userId) {
                $personalized = getPersonalizedRecommendations($pdo, $userId);
                
                if ($personalized) {
                    // User has favorite categories - show personalized recommendations
                    $categoryCount = count($personalized['categories']);
                    $reply = "🎯 **Here are your personalized recommendations** based on your favorite " . 
                             ($categoryCount == 1 ? "genre" : "genres") . "!\n\n";
                    
                    foreach ($personalized['recommendations'] as $genre => $books) {
                        if (!empty($books)) {
                            $reply .= "📖 **{$genre}**\n";
                            foreach ($books as $book) {
                                $reply .= "✨ **{$book['title']}** by {$book['author']}\n";
                                // Build location string
                                $location = '';
                                if ($book['floor'] && $book['rack'] && $book['row']) {
                                    $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}, {$book['row']} Row\n";
                                } elseif ($book['floor'] && $book['rack']) {
                                    $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}\n";
                                }
                                $reply .= $location;
                                $reply .= "   <button class=\"chat-go-btn\" data-book=\"" . htmlspecialchars($book['title']) . "\">🔍 Go to Book</button>\n\n";
                            }
                        }
                    }
                    $reply .= "💡 Want a specific genre? Try saying 'recommend fantasy books'!";
                } else {
                    // User has NO favorite categories - show HIGHLY RATED BOOKS instead of popular
                    $hasRated = hasRatedBooks($pdo);
                    
                    if ($hasRated) {
                        $highlyRatedBooks = getHighlyRatedBooks($pdo, 5);
                        if (!empty($highlyRatedBooks)) {
                            $reply = "⭐ **You haven't selected any favorite categories yet!**\n\n" .
                                     "Here are our **Highly Rated Books** to get you started:\n\n";
                            foreach ($highlyRatedBooks as $book) {
                                $avgRating = round($book['avg_rating'], 1);
                                $stars = '';
                                $fullStars = floor($avgRating);
                                $halfStar = ($avgRating - $fullStars) >= 0.5;
                                for ($i = 1; $i <= 5; $i++) {
                                    if ($i <= $fullStars) {
                                        $stars .= '★';
                                    } elseif ($i == $fullStars + 1 && $halfStar) {
                                        $stars .= '½';
                                    } else {
                                        $stars .= '☆';
                                    }
                                }
                                $reply .= "✨ **{$book['title']}** by {$book['author']}\n";
                                $reply .= "   ⭐ {$stars} ({$avgRating}/5 from {$book['rating_count']} ratings)\n";
                                // Build location string
                                $location = '';
                                if ($book['floor'] && $book['rack'] && $book['row']) {
                                    $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}, {$book['row']} Row\n";
                                } elseif ($book['floor'] && $book['rack']) {
                                    $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}\n";
                                }
                                $reply .= $location;
                                $reply .= "   <button class=\"chat-go-btn\" data-book=\"" . htmlspecialchars($book['title']) . "\">🔍 Go to Book</button>\n\n";
                            }
                            $reply .= "\n💡 **Tip:** Go to your **Profile** → **Reading Preferences** → **Choose Categories**\n" .
                                     "Select your favorite book genres, then I'll recommend books just for you!\n" .
                                     "<button class=\"chat-go-btn\" onclick=\"window.location.href='profile.php'\"> Go to Profile</button>";
                        } else {
                            $reply = "⭐ **No highly rated books found at the moment.**\n\n" .
                                     "Be the first to rate books you've borrowed!\n\n" .
                                     "💡 **Tip:** Go to your **Profile** → **Reading Preferences** → **Choose Categories**\n" .
                                     "Select your favorite book genres, then I'll recommend books just for you!\n" .
                                     "<button class=\"chat-go-btn\" onclick=\"window.location.href='profile.php'\">Go to Profile</button>";
                        }
                    } else {
                        $reply = "⭐ **No books have been rated yet!**\n\n" .
                                 "Be the first to rate books you've borrowed! After returning a book, " .
                                 "go to 'My Books' section and rate the books you've read.\n\n" .
                                 "💡 **Tip:** Go to your **Profile** → **Reading Preferences** → **Choose Categories**\n" .
                                 "Select your favorite book genres, then I'll recommend books just for you!\n" .
                                 "<button class=\"chat-go-btn\" onclick=\"window.location.href='profile.php'\">Go to Profile</button>";
                    }
                }
            } else {
                // User not logged in
                $reply = "👋 **Welcome!**\n\n" .
                         "I can help you find great books to read!\n\n" .
                         "Try:\n" .
                         "• 'recommend fantasy books'\n" .
                         "• 'what's popular'\n" .
                         "• 'highly rated'\n" .
                         "• 'genres'\n\n" .
                         "Log in to get personalized recommendations based on your favorite genres! 📚";
            }
        }
    }
        elseif ($isAskingForSearch) {
        // Remove punctuation from the message first
        $cleanMessage = preg_replace('/[^\w\s]/u', '', $userMessage);
        
        // FIRST: Check if this is actually a genre request (e.g., "search for horror books")
        $genresList = getAllGenresIncludingEmpty($pdo);
        $isGenreRequest = false;
        $foundGenre = null;
        
        foreach ($genresList as $genre) {
            $genreLower = strtolower($genre['category']);
            if (strpos($messageLower, $genreLower) !== false) {
                $isGenreRequest = true;
                $foundGenre = $genre['category'];
                break;
            }
        }
        
        if ($isGenreRequest) {
            // Handle as genre recommendation instead of search
            $hasBooks = hasBooksInGenre($pdo, $foundGenre);
            if (!$hasBooks) {
                $reply = "😔 **Sorry, no books available in {$foundGenre} right now.**\n\n" .
                        "Please check back later or try a different genre!";
            } else {
                $books = getBooksByCategory($pdo, $foundGenre, 5);
                if (!empty($books)) {
                    $reply = "📚 **{$foundGenre} books available in our library:**\n\n";
                    foreach ($books as $book) {
                        $reply .= "✨ **{$book['title']}** by {$book['author']}\n";
                        $location = '';
                        if ($book['floor'] && $book['rack'] && $book['row']) {
                            $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}, {$book['row']} Row\n";
                        } elseif ($book['floor'] && $book['rack']) {
                            $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}\n";
                        }
                        $reply .= $location;
                        $reply .= "   <button class=\"chat-go-btn\" data-book=\"" . htmlspecialchars($book['title']) . "\">🔍 Go to Book</button>\n\n";
                    }
                } else {
                    $reply = "😔 **No {$foundGenre} books available right now.**\n\n" .
                            "Please check back later or try a different genre!";
                }
            }
        } else {
            // Extract search term
            $searchTerm = '';
            
            // ========== PATTERN 1: "searching for X" or "search for X" ==========
            if (preg_match('/search(?:ing)? for (.+)/i', $userMessage, $matches)) {
                $searchTerm = trim($matches[1]);
                error_log("Pattern: 'search(ing) for' -> " . $searchTerm);
            }
            // ========== PATTERN 2: "finding for X" ==========
            elseif (preg_match('/find(?:ing)? for (.+)/i', $userMessage, $matches)) {
                $searchTerm = trim($matches[1]);
                error_log("Pattern: 'finding for' -> " . $searchTerm);
            }
            // ========== PATTERN 3: "Do you have a book named X?" ==========
            elseif (preg_match('/do you have a book named (.+)/i', $userMessage, $matches)) {
                $searchTerm = trim($matches[1]);
                error_log("Pattern: 'do you have a book named' -> " . $searchTerm);
            }
            // ========== PATTERN 4: "Do you have a book called X?" ==========
            elseif (preg_match('/do you have a book called (.+)/i', $userMessage, $matches)) {
                $searchTerm = trim($matches[1]);
                error_log("Pattern: 'do you have a book called' -> " . $searchTerm);
            }
            // ========== PATTERN 5: "Do you have X?" ==========
            elseif (preg_match('/do you have (.+)/i', $userMessage, $matches)) {
                $searchTerm = trim($matches[1]);
                $searchTerm = preg_replace('/^(a|an|the|book)\s+/i', '', $searchTerm);
                error_log("Pattern: 'do you have' -> " . $searchTerm);
            }
            // ========== PATTERN 6: "book named X" ==========
            elseif (preg_match('/book named (.+)/i', $userMessage, $matches)) {
                $searchTerm = trim($matches[1]);
                error_log("Pattern: 'book named' -> " . $searchTerm);
            }
            // ========== PATTERN 7: "book called X" ==========
            elseif (preg_match('/book called (.+)/i', $userMessage, $matches)) {
                $searchTerm = trim($matches[1]);
                error_log("Pattern: 'book called' -> " . $searchTerm);
            }
            // ========== PATTERN 8: "have a book named X" ==========
            elseif (preg_match('/have a book named (.+)/i', $userMessage, $matches)) {
                $searchTerm = trim($matches[1]);
                error_log("Pattern: 'have a book named' -> " . $searchTerm);
            }
            // ========== PATTERN 9: Books by author ==========
            elseif (preg_match('/books? by\s+([a-zA-Z\s\.\']+)/i', $cleanMessage, $matches)) {
                $searchTerm = trim($matches[1]);
                error_log("Pattern: 'books by' -> " . $searchTerm);
            }
            // ========== PATTERN 10: By author ==========
            elseif (preg_match('/by\s+([a-zA-Z\s\.\']+)$/i', $cleanMessage, $matches)) {
                $searchTerm = trim($matches[1]);
                error_log("Pattern: 'by' -> " . $searchTerm);
            }
            // ========== PATTERN 11: Quoted text ==========
            elseif (preg_match('/[\'"]{1}([^\'"]+)[\'"]{1}/', $userMessage, $matches)) {
                $searchTerm = trim($matches[1]);
                error_log("Pattern: quoted -> " . $searchTerm);
            }
            // ========== PATTERN 12: Standard extraction ==========
            else {
                // Start with the cleaned message
                $searchText = $cleanMessage;
                
                // Remove common question starters
                $searchText = preg_replace('/^(do you have|do you have any|have you|have you got|is there|can you find|can you search|i want to|i want|please|could you|would you)\s+/i', '', $searchText);
                
                // Remove "i" at the beginning
                $searchText = preg_replace('/^i\s+/i', '', $searchText);
                
                // Remove command words (including "searching for")
                $searchText = preg_replace('/^(searching for|search for|search|find|looking for|have|want|read)\s+/i', '', $searchText);
                
                // Remove "a", "an", "the", "book" from beginning
                $searchText = preg_replace('/^(a|an|the|book)\s+/i', '', $searchText);
                
                // Remove "named", "called", "titled"
                $searchText = preg_replace('/^(named|called|titled)\s+/i', '', $searchText);
                
                // Remove trailing "book" or "books"
                $searchText = preg_replace('/\s+(book|books)$/i', '', trim($searchText));
                
                // Remove "for" if it appears
                $searchText = preg_replace('/^for\s+/i', '', $searchText);

                // Handle "searching for" specifically
                if (preg_match('/searching for (.+)/i', $searchText, $matches)) {
                    $searchText = trim($matches[1]);
                }
                
                $searchTerm = trim($searchText);
                error_log("Pattern: standard -> " . $searchTerm);
            }
            
            // Clean up the search term
            $searchTerm = trim($searchTerm);
            $searchTerm = preg_replace('/[^\w\s]/u', '', $searchTerm);
            $searchTerm = preg_replace('/\s+/', ' ', $searchTerm);
            
            error_log("FINAL SEARCH TERM: '" . $searchTerm . "' from: '" . $userMessage . "'");
            
            // Perform the search
            if (strlen($searchTerm) > 2) {
                $books = searchBooks($pdo, $searchTerm);
                if (!empty($books)) {
                    $reply = "🔍 **Found these books matching '{$searchTerm}':**\n\n";
                    foreach ($books as $book) {
                        $reply .= "✨ **{$book['title']}** by {$book['author']}\n";
                        $location = '';
                        if ($book['floor'] && $book['rack'] && $book['row']) {
                            $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}, {$book['row']} Row\n";
                        } elseif ($book['floor'] && $book['rack']) {
                            $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}\n";
                        }
                        $reply .= $location;
                        $reply .= "   <button class=\"chat-go-btn\" data-book=\"" . htmlspecialchars($book['title']) . "\">🔍 Go to Book</button>\n\n";
                    }
                    $reply .= "Want to search for something else? Just say 'search for [book title]'! 📖";
                } else {
                    $reply = "🔍 I couldn't find any books matching '{$searchTerm}'.\n\nTry:\n• 'search for Harry Potter'\n• 'find Stephen King'\n• 'books by Nelson Mandela' 📚";
                }
            } else {
                $reply = "What would you like to search for? Try:\n• 'have harry potter'\n• 'search for Harry Potter'\n• 'find Stephen King'\n• 'books by Nelson Mandela'\n• 'book from Nelson Mandela' 📚";
            }
        }
    }
    elseif ($isAskingForNew) {
        $stmt = $pdo->prepare("
            SELECT b.title, b.author, bc.category, b.copies_available, b.created_at,
                   f.floor, f.rack, r.row
            FROM books b
            LEFT JOIN book_categories bc ON b.category_id = bc.id
            LEFT JOIN floor f ON b.floor_id = f.id
            LEFT JOIN row r ON b.row_id = r.id
            WHERE b.copies_available > 0 AND b.available = 1
            ORDER BY b.created_at DESC
            LIMIT 5
        ");
        $stmt->execute();
        $newBooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($newBooks)) {
            $reply = "🆕 **Newly Added Books:**\n\n";
            foreach ($newBooks as $book) {
                $reply .= "📖 **{$book['title']}** by {$book['author']}";
                if ($book['category']) {
                    $reply .= " - {$book['category']}";
                }
                $reply .= "\n   📅 Added: " . date('M d, Y', strtotime($book['created_at']));
                // Build location string
                $location = '';
                if ($book['floor'] && $book['rack'] && $book['row']) {
                    $location = "\n   📍 Floor {$book['floor']}, Rack {$book['rack']}, {$book['row']} Row";
                } elseif ($book['floor'] && $book['rack']) {
                    $location = "\n   📍 Floor {$book['floor']}, Rack {$book['rack']}";
                }
                $reply .= $location;
                $reply .= "\n   <button class=\"chat-go-btn\" data-book=\"" . htmlspecialchars($book['title']) . "\">🔍 Go to Book</button>\n\n";
            }
            $reply .= "Check out these latest additions to our library! 📚";
        } else {
            $reply = "😔 No newly added books found at the moment. Check back soon!";
        }
    }
}
// If still no reply, try searching with genre or book title or author
if (empty($reply)) {
    if (strlen($userMessage) > 2) {
        // First, check if it's a genre
        $genresList = getAllGenresIncludingEmpty($pdo);
        $isGenre = false;
        
        foreach ($genresList as $genre) {
            $genreLower = strtolower($genre['category']);
            if (strpos($messageLower, $genreLower) !== false) {
                $isGenre = true;
                break;
            }
        }
        
        // If not a genre, search for it
        if (!$isGenre) {
            $books = searchBooks($pdo, $userMessage, 5);
            if (!empty($books)) {
                $reply = "🔍 **Found these books matching '{$userMessage}':**\n\n";
                foreach ($books as $book) {
                    $reply .= "✨ **{$book['title']}** by {$book['author']}\n";
                    // Build location string
                    $location = '';
                    if ($book['floor'] && $book['rack'] && $book['row']) {
                        $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}, {$book['row']} Row\n";
                    } elseif ($book['floor'] && $book['rack']) {
                        $location = "   📍 Floor {$book['floor']}, Rack {$book['rack']}\n";
                    }
                    $reply .= $location;
                    $reply .= "   <button class=\"chat-go-btn\" data-book=\"" . htmlspecialchars($book['title']) . "\">🔍 Go to Book</button>\n\n";
                }
                $reply .= "Try being more specific for better results! 📖";
            }
        }
    }
    
    // If still no reply, show default help message
    if (empty($reply)) {
        $reply = "👋 **Hi! I'm your Library Assistant. I can help you with:**\n\n" .
                 "📚 **Get recommendations** - Type 'recommend fantasy books'\n" .
                 "✨ **View all genres** - Type 'genres'\n" .
                 "🔍 **Search for books** - Say 'search for Dune' or just type 'harry potter'\n" .
                 "⭐ **Popular books** - Say 'what's popular'\n" .
                 "🆕 **New books** - Say 'what's new'\n" .
                 "📖 **How to borrow** - Say 'how to borrow'\n\n" .
                 "**Quick tips:** Use the buttons provided to search for books!";
    }
}

// Save to session
if (!isset($_SESSION['chat_messages'])) {
    $_SESSION['chat_messages'] = [];
}
$_SESSION['chat_messages'][] = ['role' => 'user', 'text' => $userMessage];
$_SESSION['chat_messages'][] = ['role' => 'bot', 'text' => $reply];
// Limit to 100 messages
if (count($_SESSION['chat_messages']) > 100) {
    $_SESSION['chat_messages'] = array_slice($_SESSION['chat_messages'], -100);
}

echo json_encode(['reply' => $reply, 'showBubbles' => $showBubbles]);
?>