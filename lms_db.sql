CREATE DATABASE IF NOT EXISTS lms_db
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
 
USE lms_db;
 
-- ============================================================
--  TABLE: user_type
-- ============================================================
CREATE TABLE IF NOT EXISTS user_type (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(50) NOT NULL UNIQUE
);


-- ============================================================
--  TABLE: membership_type
-- ============================================================
CREATE TABLE IF NOT EXISTS membership_type (
  id    INT AUTO_INCREMENT PRIMARY KEY,
  type  VARCHAR(50) NOT NULL UNIQUE
);

 
-- ============================================================
--  TABLE: book_categories
-- ============================================================
CREATE TABLE IF NOT EXISTS book_categories (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  category  VARCHAR(50) NOT NULL UNIQUE
);


-- ============================================================
--  TABLE: users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  name                    VARCHAR(100)  NOT NULL,
  username                VARCHAR(50)   NOT NULL UNIQUE,
  email                   VARCHAR(100)  NOT NULL UNIQUE,
  password                CHAR(40)      NOT NULL,           -- SHA1 hash
  user_type_id            INT           NOT NULL DEFAULT 2,
  member_since            DATE          DEFAULT NULL,
  membership_type_id      INT           NOT NULL DEFAULT 1,
  membership_start_date   DATETIME      DEFAULT NULL,
  membership_end_date     DATE          DEFAULT NULL,
  email_notif             TINYINT(1)    NOT NULL DEFAULT 1,
  last_login              TIMESTAMP     NULL DEFAULT NULL,
  created_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  created_by              INT           DEFAULT NULL,
  updated_at              TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by              INT           DEFAULT NULL,
  FOREIGN KEY (user_type_id) REFERENCES user_type(id) ON DELETE RESTRICT,
  FOREIGN KEY (membership_type_id) REFERENCES membership_type(id) ON DELETE RESTRICT,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);
 
 
-- ============================================================
--  TABLE: user_favorite_categories
-- ============================================================
CREATE TABLE user_favorite_categories (
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (user_id, category_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES book_categories(id) ON DELETE CASCADE
);


-- ============================================================
--  TABLE: book_languages
-- ============================================================
CREATE TABLE IF NOT EXISTS book_languages (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  language  VARCHAR(50) NOT NULL UNIQUE
);

-- ============================================================
--  TABLE: Floor
-- ============================================================
CREATE TABLE IF NOT EXISTS floor (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  floor       INT           NOT NULL,
  rack        INT           NOT NULL
);


-- ============================================================
--  TABLE: Row
-- ============================================================
CREATE TABLE IF NOT EXISTS row (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  row         VARCHAR(50) NOT NULL UNIQUE
);
 
 
-- ============================================================
--  TABLE: books
-- ============================================================
CREATE TABLE IF NOT EXISTS books (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  title             VARCHAR(255)  NOT NULL,
  author            VARCHAR(255)  DEFAULT NULL,
  isbn              VARCHAR(30)   DEFAULT NULL,
  copies            INT           NOT NULL DEFAULT 1,
  copies_available  INT           NOT NULL DEFAULT 1,
  available         TINYINT(1)    NOT NULL DEFAULT 0,
  category_id       INT           DEFAULT NULL,
  description       TEXT          DEFAULT NULL,
  publish_date      DATE          DEFAULT NULL,
  language_id       INT           DEFAULT NULL,
  cover_image       VARCHAR(255)  DEFAULT NULL,
  floor_id          INT           DEFAULT NULL,
  row_id            INT           DEFAULT NULL,
  times_borrowed    INT           DEFAULT 0,
  created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  created_by        INT           DEFAULT NULL,
  updated_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by        INT           DEFAULT NULL,
  FOREIGN KEY (category_id) REFERENCES book_categories(id) ON DELETE SET NULL,
  FOREIGN KEY (language_id) REFERENCES book_languages(id) ON DELETE SET NULL,
  FOREIGN KEY (floor_id) REFERENCES floor(id) ON DELETE SET NULL,
  FOREIGN KEY (row_id) REFERENCES row(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);
 
 
-- ============================================================
--  TABLE: book_status
-- ============================================================
CREATE TABLE IF NOT EXISTS book_status (
  id      INT PRIMARY KEY,
  status  VARCHAR(50) NOT NULL UNIQUE
);
 
 
-- ============================================================
--  TABLE: borrow_requests
-- ============================================================
CREATE TABLE IF NOT EXISTS borrow_requests (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT           NOT NULL,
  book_id         INT           NOT NULL,
  request_date    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  rent_duration   INT           NOT NULL DEFAULT 14,
  due_date        DATE          DEFAULT NULL,
  book_status_id  INT           NOT NULL DEFAULT 1,
  returned_date   DATETIME      DEFAULT NULL,
  last_overdue_notification_sent DATE DEFAULT NULL,
  rating_given    TINYINT(1)    DEFAULT 0,
  created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  created_by      INT           DEFAULT NULL,
  updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by      INT           DEFAULT NULL,
  FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE,
  FOREIGN KEY (book_id)         REFERENCES books(id)         ON DELETE CASCADE,
  FOREIGN KEY (book_status_id)  REFERENCES book_status(id),
  FOREIGN KEY (created_by)      REFERENCES users(id)         ON DELETE SET NULL,
  FOREIGN KEY (updated_by)      REFERENCES users(id)         ON DELETE SET NULL
);


-- ============================================================
--  TABLE: book_ratings
-- ============================================================
CREATE TABLE IF NOT EXISTS book_ratings (
  id    INT AUTO_INCREMENT PRIMARY KEY,
  user_id             INT                 NOT NULL,
  book_id             INT                 NOT NULL,
  borrow_requests_id  INT                 NOT NULL,
  rating              INT                 NOT NULL,
  created_at          TIMESTAMP DEFAULT   CURRENT_TIMESTAMP,
  created_by          INT DEFAULT         NULL,
  updated_at          TIMESTAMP DEFAULT   CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by          INT DEFAULT         NULL,
  FOREIGN KEY (user_id)         REFERENCES users(id)       ON DELETE CASCADE,
  FOREIGN KEY (book_id)         REFERENCES books(id)       ON DELETE CASCADE,
  FOREIGN KEY (borrow_requests_id) REFERENCES borrow_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);


-- ============================================================
--  TABLE: notifications_title
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications_title (
  id    INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL UNIQUE
);


-- ============================================================
--  TABLE: notifications_type
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications_type (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(50) NOT NULL UNIQUE
);


-- ============================================================
--  TABLE: notifications_criteria
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications_criteria (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  title_id   INT NOT NULL,
  type_id    INT NOT NULL,
  UNIQUE (title_id, type_id),
  FOREIGN KEY (title_id) REFERENCES notifications_title(id) ON DELETE CASCADE,
  FOREIGN KEY (type_id)  REFERENCES notifications_type(id)  ON DELETE CASCADE
);


-- ============================================================
--  TABLE: notifications
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT           NOT NULL,
  criteria_id INT           NOT NULL,
  message     TEXT          NOT NULL,
  is_read     TINYINT(1)    NOT NULL DEFAULT 0,
  created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)     REFERENCES users(id)                    ON DELETE CASCADE,
  FOREIGN KEY (criteria_id) REFERENCES notifications_criteria(id)   ON DELETE CASCADE
);

 
-- ============================================================
--  SEED DATA — User Type
-- ============================================================
INSERT INTO user_type (id, type) VALUES
  (1, 'Admin'),
  (2, 'Librarian'),
  (3, 'User');


-- ============================================================
--  SEED DATA — Book Status
-- ============================================================
INSERT INTO book_status (id, status) VALUES
  (1, 'requested'),
  (2, 'borrowed'),
  (3, 'returned'),
  (4, 'canceled');
 
 
-- ============================================================
--  SEED DATA — Membership Type
-- ============================================================
INSERT INTO membership_type (id, type) VALUES
  (1, 'Standard'),
  (2, 'Premium'),
  (3, 'Staff');


-- ============================================================
--  SEED DATA — Notifications Title
-- ============================================================
INSERT INTO notifications_title (id, title) VALUES
  (1, 'Book Requested'),
  (2, 'Book Borrowed'),
  (3, 'Book Returned'),
  (4, 'Request Cancelled'),
  (5, 'Book Due Soon'),
  (6, 'Overdue Book'),
  (7, 'Membership Renewed'),
  (8, 'Membership Due Soon'),
  (9, 'Membership Expired'),
  (10, 'Password Updated'),
  (11, 'Profile Updated');


-- ============================================================
--  SEED DATA — Notifications Type
-- ============================================================
INSERT INTO notifications_type (id, type) VALUES
  (1, 'success'),
  (2, 'info'),
  (3, 'warning');


-- ============================================================
--  SEED DATA — Notifications Criteria
-- ============================================================
INSERT INTO notifications_criteria (title_id, type_id) VALUES
  (1, 1), -- Book Requested (Success)
  (2, 1), -- Book Borrowed (Success)
  (3, 1), -- Book Returned (Success)
  (4, 2), -- Request Cancelled (Info)
  (5, 2), -- Book Due Soon (Info)
  (6, 3), -- Overdue Book (Warning)
  (7, 1), -- Membership Renewed (Success)
  (8, 2), -- Membership Due Soon (Info)
  (9, 3), -- Membership Expired (Warning)
  (10, 2), -- Password Updated (Info)
  (11, 2); -- Profile Updated (Info)


-- ============================================================
--  SEED DATA — Book Categories
-- ============================================================
INSERT INTO book_categories (id, category) VALUES
  (1, 'Fantasy'),
  (2, 'Science Fiction'),
  (3, 'History'),
  (4, 'Horror'),
  (5, 'Mystery'),
  (6, 'Romance'),
  (7, 'Thriller'),
  (8, 'Classic'),
  (9, 'Self-Help'),
  (10, 'Biography'),
  (11, 'Psychology'),
  (12, 'Malay Literature'),
  (13, 'Chinese Literature'),
  (14, 'Indian Literature');
 
 
-- ============================================================
--  SEED DATA — Book Languages
-- ============================================================
INSERT INTO book_languages (id, language) VALUES
  (1, 'English'),
  (2, 'Malay'),
  (3, 'Chinese'),
  (4, 'Indian');


-- ============================================================
--  SEED DATA — Floor and Racks
-- ============================================================
INSERT INTO floor (id, floor, rack) VALUES
  (1, 1, 1),
  (2, 1, 2),
  (3, 1, 3),
  (4, 1, 4),
  (5, 1, 5),
  (6, 1, 6),
  (7, 1, 7),
  (8, 1, 8),
  (9, 1, 9),
  (10, 1, 10),
  (11, 2, 11),
  (12, 2, 12),
  (13, 2, 13),
  (14, 2, 14),
  (15, 2, 15),
  (16, 2, 16),
  (17, 2, 17),
  (18, 2, 18),
  (19, 2, 19),
  (20, 2, 20);


-- ============================================================
--  SEED DATA — Row
-- ============================================================
INSERT INTO row (id, row) VALUES
  (1, 'Top'),
  (2, 'Middle'),
  (3, 'Bottom');
 
 
-- ============================================================
--  SEED DATA — Users
-- ============================================================
-- Insert admin
INSERT INTO users
  (id, name, username, email, password, user_type_id, membership_type_id, member_since, membership_start_date, membership_end_date, last_login, created_by, updated_by)
VALUES
  (1, 'Admin', '111', 'admin111@student.mmu.edu.my',
   SHA1('admin111'), 1, 3, CURDATE(), NULL, NULL, NULL, NULL, NULL);

-- Insert other users with created by admin
INSERT INTO users
  (name, username, email, password, user_type_id, membership_type_id, member_since, membership_start_date, membership_end_date, last_login, created_by, updated_by)
VALUES
  -- Sample librarian (user_type_id = 2 for librarian)
  ('testing_librarian', 'testing', 'testing@gmail.com',
   SHA1('22222222'), 2, 3, '2025-05-01', NULL, NULL, NULL, 1, 1),

  -- Sample user (user_type_id = 3 for user)
  ('testing_user1', 'user1', 'user1@gmail.com',
   SHA1('user1111'), 3, 1, '2025-05-01', NULL, NULL, NULL, 1, 1),

  -- Sample user (user_type_id = 3 for user)
  ('testing_user2', 'user2', 'user2@gmail.com',
   SHA1('user2222'), 3, 1, '2025-05-01', NULL, NULL, NULL, 1, 1);
 
 
-- ============================================================
--  SEED DATA — Books
-- ============================================================
INSERT INTO books
  (title, author, isbn, copies, copies_available, available, times_borrowed,
   cover_image, category_id, description, publish_date, language_id, floor_id, row_id, created_by, updated_by)
VALUES
  ('Harry Potter and the Sorcerer\'s Stone', 'J.K. Rowling',
   '978-0-7475-3269-9', 5, 5, 1, 0, 'cover/harry_potter_1.jpg', 1,
   'The first book in the beloved Harry Potter series, following a young wizard\'s first year at Hogwarts School of Witchcraft and Wizardry.',
   '1997-06-26', 1, 1, 1, 1, 1),
 
  ('Harry Potter and the Chamber of Secrets', 'J.K. Rowling',
   '999-1-6475-3789-10', 5, 5, 1, 0, 'cover/harry_potter_2.jpg', 1,
   'Harry Potter returns for his second year at Hogwarts, where a mysterious monster has been attacking students.',
   '1998-07-02', 1, 1, 1, 1, 1),
 
  ('Harry Potter and the Prisoner of Azkaban', 'J.K. Rowling',
   '978-0-7475-4511-08', 5, 5, 1, 0, 'cover/harry_potter_3.jpg', 1,
   'Harry discovers a dangerous prisoner has escaped from Azkaban and may be coming after him.',
   '1999-07-08', 1, 1, 1, 1, 1),
 
  ('Harry Potter and the Goblet of Fire', 'J.K. Rowling',
   '978-0-4391-3960-1', 5, 5, 1, 0, 'cover/harry_potter_4.jpg', 1,
   'Harry is unexpectedly entered into a dangerous magical tournament in his fourth year at Hogwarts.',
   '2000-07-08', 1, 1, 2, 1, 1),
 
  ('Harry Potter and the Order of the Phoenix', 'J.K. Rowling',
   '978-0-4393-5807-1', 5, 5, 1, 0, 'cover/harry_potter_5.jpg', 1,
   'Harry forms a secret student group to resist the Ministry of Magic\'s interference at Hogwarts.',
   '2003-06-21', 1, 1, 2, 1, 1),
 
  ('Harry Potter and the Half-Blood Prince', 'J.K. Rowling',
   '978-1-4088-5570-6', 5, 5, 0, 0, 'cover/harry_potter_6.jpg', 1,
   'Harry learns about Voldemort\'s past and the Horcruxes that hold the key to defeating him.',
   '2005-07-16', 1, 1, 2, 1, 1),
 
  ('Harry Potter and the Deathly Hallows', 'J.K. Rowling',
   '978-0-5451-3970-0', 5, 5, 1, 0, 'cover/harry_potter_7.jpg', 1,
   'The epic conclusion to the Harry Potter series as Harry hunts down the final Horcruxes.',
   '2007-07-21', 1, 1, 3, 1, 1),

  ('The Hobbit', 'J.R.R. Tolkien',
   '978-0-618-00221-3', 5, 5, 1, 0, 'cover/The_Hobbit.jpg', 1,
   'A fantastical journey of a hobbit who joins a company of dwarves on a quest for dragon-guarded treasure.',
   '1937-09-21', 1, 1, 3, 1, 1),

  ('A Game of Thrones', 'George R.R. Martin',
   '978-0-553-89784-5', 6, 6, 1, 0, 'cover/Game_of_Thrones.jpg', 1,
   'Noble families wage war over the Iron Throne of the Seven Kingdoms while an ancient enemy stirs beyond the Wall.',
   '1996-08-06', 1, 1, 3, 1, 1),

  ('The Wise Man\'s Fear', 'Patrick Rothfuss',
   '978-0-7564-0791-9', 5, 5, 1, 0, 'cover/The_Wise_Man_Fear.jpg', 1, 
   'The continuing story of Kvothe, the legendary magician, as he continues his journey of discovery and revenge.', 
   '2011-03-01', 1, 2, 1, 1, 1),

  ('Mistborn: The Final Empire', 'Brandon Sanderson', 
   '978-0-7653-5178-0', 8, 8, 1, 0, 'cover/Mistborn.jpg', 1, 
   'A street thief discovers she has the power of Allomancy and joins a rebellion against the immortal Lord Ruler.', 
   '2006-07-17', 1, 2, 1, 1, 1),

  ('The Way of Kings', 'Brandon Sanderson', 
   '978-0-7653-2635-1', 6, 6, 1, 0, 'cover/The_Way_Of_Kings.png', 1, 
   'The first book in The Stormlight Archive, following multiple characters in a richly detailed fantasy world.', 
   '2010-08-31', 1, 2, 1, 1, 1),

  ('The Lies of Locke Lamora', 'Scott Lynch', 
   '978-0-553-58633-6', 4, 4, 1, 0, 'cover/Locke_Lamora.jpg', 1, 
   'A master thief and his gang pull off heists in a fantasy city inspired by Venice.', 
   '2006-06-27', 1, 2, 2, 1, 1),

  ('The Blade Itself', 'Joe Abercrombie', 
   '978-0-575-07979-3', 4, 4, 1, 0, 'cover/The_Blade_Itself.png', 1, 
   'A grimdark fantasy following a group of flawed heroes in a brutal world.',
   '2006-05-04', 1, 2, 2, 1, 1),

  ('The Priory of the Orange Tree', 'Samantha Shannon', 
   '978-1-5286-7829-9', 4, 4, 1, 0, 'cover/The_Priory_of_the_Orange_Tree.png', 1, 
   'An epic fantasy with dragons, magic, and a cast of strong female characters.', 
   '2019-02-26', 1, 2, 2, 1, 1),

  ('The Bear and the Nightingale', 'Katherine Arden', 
   '978-1-101-88593-2', 4, 4, 1, 0, 'cover/The_Bear_and_the_Nightingale_Book.jpg', 1,
   'A Russian folklore-inspired fantasy about a young girl who can see spirits.', 
   '2017-01-10', 1, 2, 3, 1, 1),

  ('Jade City', 'Fonda Lee', 
    '978-0-316-44086-2', 3, 3, 1, 0, 'cover/Jade_City.jpg', 1, 
    'A gangster family saga set in a fantasy world inspired by 1970s Hong Kong.', 
    '2017-11-07', 1, 2, 3, 1, 1),

  ('The Name of the Wind', 'Patrick Rothfuss', 
   '978-0-7564-0474-1', 5, 5, 1, 0, 'cover/The_Name_of_the_Wind.jpg', 1, 
   'The tale of a legendary magician and his rise from a homeless orphan to the most infamous wizard his world has ever seen.', 
   '2007-03-27', 1, 2, 3, 1, 1),

  ('The Little Prince', 'Antoine de Saint-Exupéry', 
   '978-0-15-601219-5', 8, 8, 1, 0, 'cover/The_Little_Prince.jpg', 1,
   'A poetic and philosophical tale about a young prince who travels from planet to planet, meeting various adults and learning about love, friendship, and what truly matters in life.',
   '1943-04-06', 1, 3, 1, 1, 1),

  ('Alice\'s Adventures in Wonderland', 'Lewis Carroll', 
   '978-0-14-143976-1', 7, 7, 1, 0, 'cover/Alices_Adventures_in_Wonderland.jpg', 1,
   'A whimsical and imaginative story about a young girl named Alice who falls down a rabbit hole into a fantastical world filled with peculiar creatures, nonsensical logic, and unforgettable characters like the Cheshire Cat, Mad Hatter, and Queen of Hearts.',
   '1865-11-26', 1, 3, 1, 1, 1),

  ('Dune', 'Frank Herbert',
   '978-0-441-17271-9', 10, 10, 1, 0, 'cover/Dune.jpg', 2,
   'A sweeping saga set in a distant future, following Paul Atreides as he navigates political intrigue and prophecy on the desert planet Arrakis.',
   '1965-08-01', 1, 4, 1, 1, 1),

  ('Ender\'s Game', 'Orson Scott Card',
   '978-0-8125-5070-2', 10, 10, 0, 0, 'cover/Enders_game.jpg', 2,
   'A child prodigy is trained at a military academy in space to lead humanity\'s defence against an alien invasion.',
   '1985-01-15', 1, 4, 1, 1, 1),

  ('Foundation', 'Isaac Asimov', 
  '978-0-553-80371-6', 8, 8, 1, 0, 'cover/Foundation.jpg', 2, 
  'The first book in the Foundation series about a galactic empire\'s decline and the scientists trying to preserve knowledge.', 
   '1951-06-01', 1, 4, 1, 1, 1),

  ('Neuromancer', 'William Gibson', 
   '978-0-441-56959-5', 6, 6, 1, 0, 'cover/Neuromancer.jpg', 2, 
   'The seminal cyberpunk novel about a washed-up computer hacker hired for one last job.', 
   '1984-07-01', 1, 4, 2, 1, 1),

  ('Snow Crash', 'Neal Stephenson', 
   '978-0-553-38095-8', 5, 5, 1, 0, 'cover/Snow_Crash.jpg', 2, 
   'A fast-paced cyberpunk adventure set in a future where the Metaverse and reality collide.', 
   '1992-06-01', 1, 4, 2, 1, 1),

  ('The Left Hand of Darkness', 'Ursula K. Le Guin', 
   '978-0-441-47812-5', 5, 5, 1, 0, 'cover/The_Left_Hand_Of_Darkness.jpg', 2, 
   'A human ambassador tries to convince the inhabitants of a cold, alien planet to join an intergalactic alliance.', 
   '1969-03-01', 1, 4, 2, 1, 1),

  ('Hyperion', 'Dan Simmons', 
   '978-0-553-28368-6', 5, 5, 1, 0, 'cover/Hyperion.jpg', 2, 
   'Seven pilgrims journey to the mysterious Time Tombs on the planet Hyperion.', 
   '1989-05-01', 1, 4, 3, 1, 1),

  ('Old Man\'s War', 'John Scalzi', 
   '978-0-7653-4853-7', 5, 5, 1, 0, 'cover/Old_Mans_War.jpg', 2, 
   'An elderly man joins the military to get a new younger body and fight in interstellar wars.', 
   '2005-01-15', 1, 4, 3, 1, 1),

  ('Children of Time', 'Adrian Tchaikovsky', 
   '978-0-316-45230-8', 4, 4, 1, 0, 'cover/Children_of_Time.jpg', 2, 
   'A terraforming project goes wrong and creates a civilization of intelligent spiders.', 
   '2015-06-04', 1, 4, 3, 1, 1),

  ('Project Hail Mary', 'Andy Weir', 
   '978-0-593-35643-4', 8, 8, 1, 0, 'cover/Project_Hail_Mary.jpg', 2, 
   'A lone astronaut must save humanity by solving an impossible scientific mystery.', 
   '2021-05-04', 1, 5, 1, 1, 1),

  ('Sapiens: A Brief History of Humankind', 'Yuval Noah Harari',
   '978-0-06-231609-7', 10, 10, 1, 0, 'cover/Sapiens_a_brief.jpg', 3,
   'A brief history of humankind exploring how Homo sapiens came to dominate the world.',
   '2011-06-04', 1, 6, 1, 1, 1),

  ('Guns, Germs, and Steel', 'Jared Diamond',
   '978-0-393-31755-8', 10, 10, 1, 0, 'cover/Guns_Gearms_and_Steel.jpg', 3,
   'An examination of why some civilisations came to dominate others through geography, biology, and environment.',
   '1997-03-01', 1, 6, 1, 1, 1),

  ('The Wright Brothers', 'David McCullough', 
   '978-1-4767-2037-1', 4, 4, 1, 0, 'cover/The_Wright_Brothers.jpg', 3, 
   'The story of the two brothers who invented the airplane.', 
   '2015-05-05', 1, 6, 1, 1, 1),

  ('The Guns of August', 'Barbara W. Tuchman', 
   '978-0-345-47609-8', 3, 3, 1, 0, 'cover/The_Guns_Of_August.jpg', 3, 
   'A detailed account of the first month of World War I.', 
   '1962-02-01', 1, 6, 2, 1, 1),

  ('1776', 'David McCullough', 
   '978-0-7432-2672-1', 4, 4, 1, 0, 'cover/1776.jpg', 3, 
   'The story of America\'s founding year and the Revolutionary War.', 
   '2005-05-24', 1, 6, 2, 1, 1),

  ('The Rise and Fall of the Third Reich', 'William L. Shirer', 
   '978-0-671-72868-7', 3, 3, 1, 0, 'cover/The_Rise_and_Fall_of_the_Third_Reich.jpg', 3, 
   'A comprehensive history of Nazi Germany.', 
   '1960-10-17', 1, 6, 2, 1, 1),

  ('A People\'s History of the United States', 'Howard Zinn', 
   '978-0-06-196558-6', 5, 5, 1, 0, 'cover/Peoples_History.jpg', 3, 
   'A different perspective on American history from the viewpoint of ordinary people.', 
   '1980-01-01', 1, 6, 3, 1, 1),

  ('The Silk Roads', 'Peter Frankopan', 
   '978-1-101-94632-9', 3, 3, 1, 0, 'cover/The_Silk_Roads.jpg', 3, 
   'A new history of the world focusing on the ancient trade routes of Asia.', 
   '2015-08-27', 1, 6, 3, 1, 1),

  ('Coraline', 'Neil Gaiman',
   '978-0-06-113937-6', 3, 3, 1, 0, 'cover/Coraline.jpg', 4,
   'A girl discovers a secret door in her new home that leads to a parallel world where everything seems perfect — until it isn\'t.',
   '2002-08-04', 1, 7, 1, 1, 1),
 
  ('The Shining', 'Stephen King',
   '978-0-385-12167-5', 10, 10, 1, 0, 'cover/The_Shining.jpg', 4,
   'A family isolated in a haunted hotel for the winter confronts supernatural terror and a father\'s descent into madness.',
   '1977-01-28', 1, 7, 1, 1, 1),

  ('IT', 'Stephen King',
   '978-0-451-16951-8', 10, 10, 1, 0, 'cover/IT.jpg', 4,
   'A group of childhood friends are reunited to battle an ancient evil that terrorises their town every 27 years.',
   '1986-09-15', 1, 7, 1, 1, 1),
 
  ('The Haunting of Hill House', 'Shirley Jackson',
   '978-0-14-303998-3', 10, 10, 1, 0, 'cover/The_Haunting_Hill_House.jpg', 4,
   'Four people gather in a notoriously haunted mansion, where the house itself seems to have a will of its own.',
   '1959-10-16', 1, 7, 2, 1, 1),

  ('Bird Box', 'Josh Malerman',
   '978-0-06-225965-3', 3, 3, 1, 0, 'cover/Bird_Box.jpg', 4,
   'A mother must lead her children to safety in a world where looking at mysterious creatures drives people to madness.',
   '2014-05-13', 1, 7, 2, 1, 1),

  ('Dracula', 'Bram Stoker', 
   '978-0-14-143984-6', 8, 8, 1, 0, 'cover/Dracula.jpg', 4, 
   'The classic vampire novel told through letters and diary entries.', 
   '1897-05-26', 1, 7, 2, 1, 1),

  ('Frankenstein', 'Mary Shelley', 
   '978-0-14-143947-1', 8, 8, 1, 0, 'cover/Frankenstein.jpg', 4, 
   'The classic gothic novel about a scientist who creates a monster.', 
   '1818-01-01', 1, 7, 3, 1, 1),

  ('The Exorcist', 'William Peter Blatty', 
   '978-0-06-209587-9', 4, 4, 1, 0, 'cover/The_Exorcist.jpg', 4, 
   'A demonic possession thriller that became a cultural phenomenon.', 
   '1971-05-04', 1, 7, 3, 1, 1),

  ('Hell House', 'Richard Matheson', 
   '978-0-7653-1700-7', 3, 3, 1, 0, 'cover/Hell_House.jpg', 4, 
   'A team of investigators spend a week in the most haunted house in the world.', 
   '1971-10-01', 1, 7, 3, 1, 1),

  ('The Terror', 'Dan Simmons', 
   '978-0-316-00807-1', 4, 4, 1, 0, 'cover/The_Terror.jpg', 4, 
   'A horror novel based on the lost Franklin expedition to the Arctic.', 
   '2007-01-08', 1, 8, 1, 1, 1),

  ('The Girl with the Dragon Tattoo', 'Stieg Larsson',
   '978-0-307-45454-6', 10, 10, 1, 0, 'cover/The_Girl_with_the_dragon.jpg', 5,
   'A journalist and a hacker investigate a decades-old disappearance within a wealthy Swedish family.',
   '2005-08-01', 1, 9, 1, 1, 1),
 
  ('Gone Girl', 'Gillian Flynn',
   '978-0-307-58836-4', 3, 3, 1, 0, 'cover/Gone_Girl.jpg', 5,
   'A woman goes missing on her fifth wedding anniversary and suspicion quickly falls on her husband.',
   '2012-06-05', 1, 9, 1, 1, 1),

  ('And Then There Were None', 'Agatha Christie', 
   '978-0-00-713683-4', 8, 8, 1, 0, 'cover/And_Then_There_Were_None.jpg', 5, 
   'Ten strangers are invited to an island where they are murdered one by one.', 
   '1939-11-06', 1, 9, 1, 1, 1),

  ('Murder on the Orient Express', 'Agatha Christie', 
   '978-0-00-711931-8', 8, 8, 1, 0, 'cover/Murder_on_the_Orient_Express.jpg', 5, 
   'Detective Hercule Poirot solves a murder on a luxury train.', 
   '1934-01-01', 1, 9, 1, 1, 1),

  ('The Big Sleep', 'Raymond Chandler', 
   '978-0-394-75828-2', 5, 5, 1, 0, 'cover/The_Big_Sleep.jpg', 5, 
   'The first Philip Marlowe novel about a detective investigating a blackmail case.', 
   '1939-01-01', 1, 9, 2, 1, 1),

  ('The Maltese Falcon', 'Dashiell Hammett', 
   '978-0-679-72264-9', 5, 5, 1, 0, 'cover/The_Maltese_Falcon.jpg', 5, 
   'A detective gets caught up in the search for a priceless statue.', 
   '1930-02-14', 1, 9, 2, 1, 1),

  ('The Cuckoo\'s Calling', 'Robert Galbraith', 
   '978-0-316-20684-2', 5, 5, 1, 0, 'cover/The_Cuckoos_Calling.jpg', 5, 
   'Private investigator Cormoran Strike investigates a supermodel\'s death.', 
   '2013-04-18', 1, 9, 2, 1, 1),

  ('The Silent Patient', 'Alex Michaelides', 
   '978-1-250-30169-7', 6, 6, 1, 0, 'cover/The_Silent_Patient.png', 5, 
   'A therapist tries to uncover why a famous painter shot her husband and stopped speaking.', 
   '2019-02-05', 1, 9, 3, 1, 1),

  ('Me Before You', 'Jojo Moyes',
   '978-0-14-312454-2', 3, 3, 1, 0, 'cover/Me_Before_you.jpg', 6,
   'A young woman takes a job caring for a paralysed man and the two form an unexpected bond.',
   '2012-01-05', 1, 10, 1, 1, 1),

  ('Pride and Prejudice', 'Jane Austen', 
   '978-0-14-143951-8', 8, 8, 1, 0, 'cover/Pride_And_Prejudice.jpg', 6, 
   'The classic romance between Elizabeth Bennet and Mr. Darcy.', 
   '1813-01-28', 1, 10, 1, 1, 1),

  ('Jane Eyre', 'Charlotte Brontë', 
   '978-0-14-144114-6', 8, 8, 1, 0, 'cover/Jane_Eyre.jpg', 6, 
   'A governess falls in love with her mysterious employer.', 
   '1847-10-16', 1, 10, 1, 1, 1),

  ('Wuthering Heights', 'Emily Brontë', 
   '978-0-14-143955-6', 8, 8, 1, 0, 'cover/Wuthering_Heights.jpg', 6, 
   'A dark tale of passionate love and revenge on the Yorkshire moors.', 
   '1847-12-01', 1, 10, 2, 1, 1),

  ('The Notebook', 'Nicholas Sparks', 
   '978-0-446-60523-8', 6, 6, 1, 0, 'cover/The_Notebook.jpg', 6, 
   'A love story spanning decades about a couple reunited after years apart.', 
   '1996-10-01', 1, 10, 2, 1, 1),

  ('Outlander', 'Diana Gabaldon', 
   '978-0-440-21256-0', 6, 6, 1, 0, 'cover/Outlander.jpg', 6, 
   'A WWII nurse is transported back to 18th-century Scotland.', 
   '1991-06-01', 1, 10, 2, 1, 1),

  ('The Da Vinci Code', 'Dan Brown', 
   '978-0-385-50420-5', 8, 8, 1, 0, 'cover/The_Da_Vinci_Code.jpg', 7, 
   'A symbologist uncovers a secret that could shake Christianity to its core.', 
   '2003-03-18', 1, 11, 1, 1, 1),

  ('Angels & Demons', 'Dan Brown', 
   '978-0-671-02735-3', 8, 8, 1, 0, 'cover/Angels_And_Demons.jpg', 7, 
   'Robert Langdon races to stop a secret society from destroying the Vatican.', 
   '2000-05-01', 1, 11, 1, 1, 1),

  ('The Girl on the Train', 'Paula Hawkins', 
   '978-1-59463-366-9', 6, 6, 1, 0, 'cover/The_Girl_On_The_Train.png', 7, 
   'An unreliable narrator becomes entangled in a missing person investigation.', 
   '2015-01-13', 1, 11, 1, 1, 1),

  ('The Bourne Identity', 'Robert Ludlum', 
   '978-0-553-26011-3', 5, 5, 1, 0, 'cover/The_Bourne_Identity.png', 7, 
   'A man with amnesia discovers he is a trained assassin being hunted.', 
   '1980-02-01', 1, 11, 2, 1, 1),

  ('The Hunt for Red October', 'Tom Clancy', 
   '978-0-425-10383-4', 5, 5, 1, 0, 'cover/The_Hunt_For_Red_October.jpg', 7, 
   'A Soviet submarine captain tries to defect to the United States.', 
   '1984-08-01', 1, 11, 2, 1, 1),

  ('Moby-Dick', 'Herman Melville', 
   '978-0-14-243724-7', 6, 6, 1, 0, 'cover/Moby-Dick.jpg', 8, 
   'Captain Ahab\'s obsessive quest to kill the white whale.', 
   '1851-10-18', 1, 12, 1, 1, 1),

  ('The Great Gatsby', 'F. Scott Fitzgerald', 
   '978-0-7432-7356-5', 8, 8, 1, 0, 'cover/The_Great_Gatsby.jpg', 8, 
   'A story of wealth, love, and the American Dream in the Jazz Age.', 
   '1925-04-10', 1, 12, 1, 1, 1),

  ('To Kill a Mockingbird', 'Harper Lee', 
   '978-0-06-112008-4', 8, 8, 1, 0, 'cover/To_Kill_a_Mockingbird.jpg', 8, 
   'A young girl\'s father defends a black man falsely accused of rape in the Depression-era South.', 
   '1960-07-11', 1, 12, 1, 1, 1),

  ('1984', 'George Orwell', 
   '978-0-452-28423-4', 8, 8, 1, 0, 'cover/1984.jpg', 8, 
   'A dystopian novel about a totalitarian regime that controls every aspect of life.', 
   '1949-06-08', 1, 12, 2, 1, 1),

  ('Animal Farm', 'George Orwell', 
   '978-0-452-28424-1', 8, 8, 1, 0, 'cover/Animal_Farm.jpg', 8, 
   'An allegorical novella about a farm where animals overthrow their human owner.', 
   '1945-08-17', 1, 12, 2, 1, 1),

  ('Brave New World', 'Aldous Huxley', 
   '978-0-06-085052-4', 6, 6, 1, 0, 'cover/Brave_New_World.jpg', 8, 
   'A dystopian future where humans are engineered and conditioned for their social roles.', 
   '1932-01-01', 1, 12, 2, 1, 1),

  ('A Tale of Two Cities', 'Charles Dickens', 
   '978-0-14-143960-0', 6, 6, 1, 0, 'cover/A_Tale_of_Two_Cities.jpg', 8,
   'Set against the backdrop of the French Revolution, this classic novel follows the lives of Charles Darnay, Sydney Carton, and Lucie Manette.',
   '1859-01-01', 1, 12, 3, 1, 1),

  ('Atomic Habits', 'James Clear', 
   '978-0-7352-1129-2', 10, 10, 1, 0, 'cover/Atomic_Habits.jpg', 9, 
   'A practical guide to building good habits and breaking bad ones.', 
   '2018-10-16', 1, 13, 1, 1, 1),

  ('The 7 Habits of Highly Effective People', 'Stephen R. Covey', 
   '978-0-7432-6951-3', 8, 8, 1, 0, 'cover/The_7_Habits_of_Highly_Effective_People.jpg', 9, 
   'A classic guide to personal and professional effectiveness.', 
   '1989-08-15', 1, 13, 1, 1, 1),

  ('How to Win Friends and Influence People', 'Dale Carnegie', 
   '978-1-4391-6734-2', 8, 8, 1, 0, 'cover/How-to-win-friends-and-influence-people.jpg', 9, 
   'Timeless advice on interpersonal skills and communication.', 
   '1936-10-01', 1, 13, 1, 1, 1),

  ('The Power of Now', 'Eckhart Tolle', 
   '978-1-57731-480-6', 6, 6, 1, 0, 'cover/The_Power_Of_Now.jpg', 9, 
   'A guide to spiritual enlightenment through living in the present moment.', 
   '1997-01-01', 1, 13, 2, 1, 1),

  ('Deep Work: Rules for Focused Success in a Distracted World', 'Cal Newport', 
   '978-1-4555-8669-1', 5, 5, 1, 0, 'cover/Deep_Work.jpg', 9, 
   'Rules for focused success in a distracted world.', 
   '2016-01-05', 1, 13, 2, 1, 1),

  ('Steve Jobs', 'Walter Isaacson', 
   '978-1-4516-4853-9', 5, 5, 1, 0, 'cover/Steve_Jobs.jpg', 10, 
   'The biography of Apple co-founder Steve Jobs.', 
   '2011-10-24', 1, 14, 1, 1, 1),

  ('Becoming', 'Michelle Obama', 
   '978-1-5247-6313-8', 8, 8, 1, 0, 'cover/Becoming.jpg', 10, 
   'The memoir of former First Lady Michelle Obama.', 
   '2018-11-13', 1, 14, 1, 1, 1),

  ('Long Walk to Freedom', 'Nelson Mandela', 
   '978-0-316-54818-2', 4, 4, 1, 0, 'cover/Long_Walk_to_Freedom.jpg', 10, 
   'The autobiography of South Africa\'s first black president.', 
   '1994-11-01', 1, 14, 1, 1, 1),

  ('The Story of My Experiments with Truth', 'Mahatma Gandhi', 
   '978-0-8070-5909-6', 3, 3, 1, 0, 'cover/The_Story_of_My_Experiments_with_Truth.jpg', 10, 
   'The autobiography of the leader of India\'s independence movement.', 
   '1927-01-01', 1, 14, 2, 1, 1),

  ('Benjamin Franklin: An American Life', 'Walter Isaacson', 
   '978-0-7432-5807-4', 5, 5, 1, 0, 'cover/Benjamin_Franklin.jpg', 10,
   'A detailed and engaging biography of one of America\'s most remarkable founding fathers.',
   '2003-07-01', 1, 14, 2, 1, 1),

  ('Einstein: His Life and Universe', 'Walter Isaacson', 
   '978-0-7432-6473-0', 5, 5, 1, 0, 'cover/Einstein.jpg', 10,
   'A comprehensive biography of Albert Einstein, revealing the man behind the scientific genius.',
   '2007-04-10', 1, 14, 2, 1, 1),

  ('Leonardo da Vinci', 'Walter Isaacson', 
   '978-1-5011-3915-4', 5, 5, 1, 0, 'cover/Leonardo_da_Vinci.jpg', 10,
   'A biography of the ultimate Renaissance man, based on thousands of pages from Leonardo\'s astonishing notebooks.',
   '2017-10-17', 1, 14, 3, 1, 1),

  ('Elon Musk', 'Walter Isaacson', 
   '978-1-9821-8128-4', 6, 6, 1, 0, 'cover/Elon_Musk.jpg', 10,
   'A deeply revealing biography of the world\'s most controversial and innovative entrepreneur.',
   '2023-09-12', 1, 14, 3, 1, 1),

  ('Thinking, Fast and Slow', 'Daniel Kahneman', 
   '978-0-374-53355-7', 6, 6, 1, 0, 'cover/Thinking,_Fast_and_Slow.jpg', 11, 
   'A Nobel laureate explores the two systems that drive our thinking.', 
   '2011-10-25', 1, 15, 1, 1, 1),

  ('Influence: The Psychology of Persuasion', 'Robert B. Cialdini', 
   '978-0-06-124189-5', 6, 6, 1, 0, 'cover/Influence.jpg', 11, 
   'The classic book on the psychology of persuasion and compliance.', 
   '1984-01-01', 1, 15, 1, 1, 1),

  ('The Interpretation of Dreams', 'Sigmund Freud', 
   '978-0-465-01977-7', 4, 4, 1, 0, 'cover/The_Interpretation_of_Dreams.jpg', 11, 
   'The seminal work of Freud on dream analysis and the unconscious mind.', 
   '1899-11-04', 1, 15, 1, 1, 1),

  ('Salina', 'A. Samad Said', 
   '978-983-62-2101-6', 5, 5, 1, 0, 'cover/Salina.jpg', 12, 
   'A classic Malay novel about life in post-war Singapore.', 
   '1961-01-01', 2, 16, 1, 1, 1),

  ('Interlok', 'Abdullah Hussain', 
   '978-983-62-9600-7', 5, 5, 1, 0, 'cover/Interlok.jpg', 12, 
   'A novel about the three main races in Malaysia.', 
   '1971-01-01', 2, 16, 1, 1, 1),

  ('Tenggelamnya Kapal van der Wijck', 'Hamka', 
   '978-983-62-1201-4', 5, 5, 1, 0, 'cover/Tenggelamnya_Kapal_van_der_Wijck.jpg', 12, 
   'A classic Malay love story about cultural differences.', 
   '1938-01-01', 2, 16, 1, 1, 1),

  ('Tuan Direktur', 'Hamka', 
   '978-967-481-963-7', 5, 5, 1, 0, 'cover/Tuan_Direktur.jpg', 12, 
   'A novel by Hamka, exploring the life, challenges, and moral dilemmas of a director.',
   '1939-01-01', 2, 16, 2, 1, 1),

  ('Di Bawah Lindungan Ka\'bah', 'Hamka', 
   '978-983-62-1202-1', 5, 5, 1, 0, 'cover/Di_Bawah_Lindungan_Kabah.jpg', 12, 
   'A love story set in Mecca, exploring faith and tradition.',
   '1938-01-01', 2, 16, 2, 1, 1),

  ('Dream of the Red Chamber 红楼梦', 'Cao Xueqin', 
   '978-7-02-003213-4', 4, 4, 1, 0, 'cover/Dream_of_the_Red_Chamber.jpg', 13, 
   'One of China\'s Four Great Classical Novels, about a noble family in decline.',
   '1791-01-01', 3, 17, 1, 1, 1),

  ('Journey to the West 西游记', 'Wu Cheng\'en', 
   '978-7-02-003214-1', 4, 4, 1, 0, 'cover/Journey_to_the_West.jpg', 13, 
   'A classic Chinese novel about the monk Xuanzang\'s journey to India.',
   '1592-01-01', 3, 17, 1, 1, 1),

  ('Water Margin 水浒传', 'Shi Nai\'an', 
   '978-7-02-003215-8', 4, 4, 1, 0, 'cover/Water_Margin.jpg', 13, 
   'A classic Chinese novel about 108 outlaws who gather at Mount Liang.',
   '1400-01-01', 3, 17, 1, 1, 1),

  ('Romance of the Three Kingdoms 三国演义', 'Luo Guanzhong', 
   '978-7-02-003216-5', 4, 4, 1, 0, 'cover/Romance_of_the_Three_Kingdoms.jpg', 13, 
   'A historical novel about the turbulent Three Kingdoms period.',
   '1522-01-01', 3, 17, 2, 1, 1),

  ('To Live 活着', 'Yu Hua', 
   '978-7-5000-0000-0', 5, 5, 1, 0, 'cover/To_Live.jpg', 13, 
   'A powerful novel about a man\'s struggle to survive through China\'s turbulent 20th century.',
   '1993-01-01', 3, 17, 2, 1, 1),

  ('The God of Small Things', 'Arundhati Roy', 
   '978-0-06-097749-8', 5, 5, 1, 0, 'cover/The_God_of_Small_Things.jpg', 14, 
   'A stunning debut novel about fraternal twins growing up in Kerala, exploring love, politics, and the caste system.',
   '1997-04-01', 1, 18, 1, 1, 1),

  ('A Suitable Boy', 'Vikram Seth', 
   '978-0-06-078653-4', 4, 4, 1, 0, 'cover/A_Suitable_Boy.jpg', 14, 
   'A sweeping family saga set in post-independence India, following a young woman\'s search for a suitable husband.',
   '1993-05-03', 1, 18, 1, 1, 1),

  ('The White Tiger', 'Aravind Adiga', 
   '978-1-4165-6259-0', 5, 5, 1, 0, 'cover/The_White_Tiger.jpg', 14, 
   'A darkly humorous novel about a poor Indian villager who becomes a successful entrepreneur and murderer.',
   '2008-04-11', 1, 18, 1, 1, 1),

  ('Midnight\'s Children', 'Salman Rushdie', 
   '978-0-8129-7653-3', 5, 5, 1, 0, 'cover/Midnights_Children.jpg', 14, 
   'A magical realism novel about children born at the exact moment of India\'s independence.',
   '1981-04-01', 1, 18, 2, 1, 1),

  ('The Inheritance of Loss', 'Kiran Desai', 
   '978-0-8021-4320-0', 4, 4, 1, 0, 'cover/The_Inheritance_Of_Loss.jpg', 14, 
   'A novel that weaves together stories of immigrants, judges, and cooks in India and the United States.',
   '2006-01-10', 1, 18, 2, 1, 1);

 
-- ============================================================
--  LOGIN CREDENTIALS
--  Admin  →  username: 111       password: admin111
--  Librarian   →  username: testing   password: 22222222
--  User1   →  username: user1   password: user1111
--  User2   →  username: user2   password: user2222
-- ============================================================