<?php
session_start();
require_once 'db.php';

// Check if user is logged in
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ADD / UPDATE BOOK
    if ($action === 'add_book') {
        $stmt = $conn->prepare("INSERT INTO books (isbn_number,title,author,genre,publishing_year,quantity,publisher,status) VALUES (?,?,?,?,?,1,'','available')");
        $stmt->bind_param("ssssi", $_POST['isbn'], $_POST['title'], $_POST['author'], $_POST['genre'], $_POST['year']);
        $stmt->execute();
        $message = "✅ Book added!";
    }
    if ($action === 'update_book') {
        $quantity_borrowed = $_POST['quantity_borrowed'] ?? 0;
        $quantity = (int)($_POST['quantity'] ?? 0);
        $year = (int)($_POST['year'] ?? 0);
        
        if ($quantity < $quantity_borrowed) {
            $message = "❌ Cannot set quantity less than borrowed copies ($quantity_borrowed).";
        } else {
            $status = ($quantity == $quantity_borrowed) ? 'borrowed' : 'available';
            $stmt = $conn->prepare("UPDATE books SET title=?, author=?, genre=?, publishing_year=?, quantity=?, status=? WHERE isbn_number=?");
            $stmt->bind_param("sssisss", $_POST['title'], $_POST['author'], $_POST['genre'], $year, $quantity, $status, $_POST['isbn']);
            $stmt->execute();
            $message = "✅ Book updated!";
        }
    }

    // ADD / UPDATE MEMBER
    if ($action === 'add_member') {
        $stmt = $conn->prepare("INSERT INTO members (first_name,last_name,contact_number,status) VALUES (?,?,?,'active')");
        $stmt->bind_param("sss", $_POST['first'], $_POST['last'], $_POST['contact']);
        $stmt->execute();
        $message = "✅ Member added!";
    }
    if ($action === 'update_member') {
        $stmt = $conn->prepare("UPDATE members SET contact_number=?, email=? WHERE member_id=?");
        $stmt->bind_param("ssi", $_POST['contact'], $_POST['email'], $_POST['id']);
        $stmt->execute();
        $message = "✅ Member updated!";
    }

    // BORROW BOOK
    if ($action === 'borrow_book') {
        $isbn = $_POST['isbn'];
        $member_id = (int)$_POST['member_id'];
        $qty = (int)($_POST['qty'] ?? 1);
        
        $result = $conn->query("SELECT quantity, quantity_borrowed FROM books WHERE isbn_number='$isbn'");
        if ($book = $result->fetch_assoc()) {
            $available = $book['quantity'] - $book['quantity_borrowed'];
            if ($qty > $available) {
                $message = "❌ Not enough copies available. Available: $available";
            } else {
                $new_quantity_borrowed = $book['quantity_borrowed'] + $qty;
                $status = ($new_quantity_borrowed == $book['quantity']) ? 'borrowed' : 'available';
                $due_date = date('Y-m-d', strtotime('+14 days'));
                $librarian_id = 0;
                
                $stmt = $conn->prepare("INSERT INTO borrow_records (isbn_number, member_id, borrow_date, due_date, qty_borrowed, librarian_id, fine_paid, remarks) VALUES (?, ?, CURDATE(), ?, ?, ?, 0, '')");
                $stmt->bind_param("sisii", $isbn, $member_id, $due_date, $qty, $librarian_id);
                $stmt->execute();
                
                $conn->query("UPDATE books SET quantity_borrowed=$new_quantity_borrowed, status='$status' WHERE isbn_number='$isbn'");
                $message = "📖 Book borrowed successfully! ($qty copy/copies)";
            }
        }
    }

    // GET LATE FEE (AJAX)
    if ($action === 'get_late_fee') {
        $isbn = $_POST['isbn'] ?? '';
        $result = $conn->query("SELECT br.due_date FROM borrow_records br WHERE br.isbn_number='$isbn' AND br.date_returned IS NULL LIMIT 1");
        if ($row = $result->fetch_assoc()) {
            $days = max(0, (strtotime(date('Y-m-d')) - strtotime($row['due_date'])) / 86400);
            $late_fee = (int)($days * 10);
            header('Content-Type: application/json');
            echo json_encode(['late_fee' => $late_fee]);
            exit;
        }
    }


    if ($action === 'return_book') {
        $isbn = $_POST['isbn'];
        $password = $_POST['password'] ?? '';
        $remarks = $_POST['remarks'] ?? '';
        $custom_fine = (int)($_POST['custom_fine'] ?? 0);
        $username = $_SESSION['username'] ?? '';
        
        // Verify librarian password and get ID
        $stmt = $conn->prepare("SELECT librarian_id, password FROM accounts WHERE username=?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $acct = $result->fetch_assoc();
        
        if (!$acct || $password !== $acct['password']) {
            $message = "❌ Invalid password. Return cancelled.";
        } else {
            $librarian_id = $acct['librarian_id'] ?? 0;
            $result = $conn->query("SELECT br.qty_borrowed, br.due_date, b.quantity, b.quantity_borrowed FROM borrow_records br JOIN books b ON br.isbn_number=b.isbn_number WHERE br.isbn_number='$isbn' AND br.date_returned IS NULL LIMIT 1");
            if ($row = $result->fetch_assoc()) {
                $days = max(0, (strtotime(date('Y-m-d')) - strtotime($row['due_date'])) / 86400);
                $late_fee = (int)($days * 10);
                $final_fine = max($custom_fine, $late_fee);
                $qty_returned = $row['qty_borrowed'];
                $new_quantity_borrowed = max(0, $row['quantity_borrowed'] - $qty_returned);
                $status = 'available';
                
                $stmt = $conn->prepare("UPDATE borrow_records SET date_returned=CURDATE(), fine_paid=?, librarian_id=?, remarks=? WHERE isbn_number=? AND date_returned IS NULL LIMIT 1");
                $stmt->bind_param("iiss", $final_fine, $librarian_id, $remarks, $isbn);
                $stmt->execute();
                
                $conn->query("UPDATE books SET quantity_borrowed=$new_quantity_borrowed, status='$status' WHERE isbn_number='$isbn'");
                $message = "✅ Book returned! ($qty_returned copy/copies) Fine: ₱" . number_format($final_fine, 2);
            }
        }
    }

       // ARCHIVE / RESTORE
    if ($action === 'archive_book') {
        $isbn = $_POST['isbn'];
        
        // Check for active borrow records
        $check = $conn->query("SELECT COUNT(*) as count FROM borrow_records WHERE isbn_number='$isbn' AND date_returned IS NULL");
        $row = $check->fetch_assoc();
        
        if ($row['count'] > 0) {
            $message = "❌ Cannot archive book. It has active borrow records. Return all copies first.";
        } else {
            $stmt = $conn->prepare("UPDATE books SET status='archived' WHERE isbn_number=?"); 
            $stmt->bind_param("s", $isbn); 
            $stmt->execute();
            $message = "📦 Book archived.";
    }
}
    if ($action === 'restore_book') {
        $stmt = $conn->prepare("UPDATE books SET status='available' WHERE isbn_number=?"); 
        $stmt->bind_param("s", $_POST['isbn']); 
        $stmt->execute();
        $message = "✅ Book restored.";
    }
    if ($action === 'archive_member') {
        $member_id = $_POST['id'];
        
        // Check for active borrow records
        $check = $conn->query("SELECT COUNT(*) as count FROM borrow_records WHERE member_id=$member_id AND date_returned IS NULL");
        $row = $check->fetch_assoc();
        
        if ($row['count'] > 0) {
            $message = "❌ Cannot archive member. They have active borrow records. All books must be returned first.";
        } else {
            $stmt = $conn->prepare("UPDATE members SET status='archived' WHERE member_id=?"); 
            $stmt->bind_param("i", $member_id); 
            $stmt->execute();
            $message = "📦 Member archived.";
    }
}
    if ($action === 'restore_member') {
        $stmt = $conn->prepare("UPDATE members SET status='active' WHERE member_id=?"); 
        $stmt->bind_param("i", $_POST['id']); 
        $stmt->execute();
        $message = "✅ Member restored.";
    }

    // === DELETE ACTIONS ===
    if ($action === 'delete_book') {
        $stmt = $conn->prepare("DELETE FROM books WHERE isbn_number=?");
        $stmt->bind_param("s", $_POST['isbn']);
        $stmt->execute();
        $message = "🗑️ Book permanently deleted.";
    }

    if ($action === 'delete_member') {
        $stmt = $conn->prepare("DELETE FROM members WHERE member_id=?");
        $stmt->bind_param("i", $_POST['id']);
        $stmt->execute();
        $message = "🗑️ Member permanently deleted.";
    }
    // ADD LIBRARIAN
    if ($action === 'add_librarian') {
        $stmt = $conn->prepare("INSERT INTO librarians (first_name, last_name, mobile_number, email, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->bind_param("ssss", $_POST['first'], $_POST['last'], $_POST['mobile'], $_POST['email']);
        $stmt->execute();
        $message = "✅ Librarian added!";
    }

    // UPDATE LIBRARIAN
    if ($action === 'update_librarian') {
        $stmt = $conn->prepare("UPDATE librarians SET first_name=?, last_name=?, mobile_number=?, email=?, status=? WHERE librarian_id=?");
        $stmt->bind_param("sssssi", $_POST['first'], $_POST['last'], $_POST['mobile'], $_POST['email'], $_POST['status'], $_POST['id']);
        $stmt->execute();
        $message = "✅ Librarian updated!";
    }

    // RESTORE LIBRARIAN
    if ($action === 'restore_librarian') {
        $stmt = $conn->prepare("UPDATE librarians SET status='active' WHERE librarian_id=?");
        $stmt->bind_param("i", $_POST['id']);
        $stmt->execute();
        $message = "✅ Librarian restored.";
    }

    // ARCHIVE LIBRARIAN
    if ($action === 'archive_librarian') {
        $stmt = $conn->prepare("UPDATE librarians SET status='archived' WHERE librarian_id=?");
        $stmt->bind_param("i", $_POST['id']);
        $stmt->execute();
        $message = "📦 Librarian archived.";
    }

    // DELETE LIBRARIAN (with check)
    if ($action === 'delete_librarian') {
        $id = $_POST['id'];
        
        // Check for returns associated with this librarian
        $check = $conn->query("SELECT COUNT(*) as count FROM borrow_records WHERE librarian_id=$id AND date_returned IS NOT NULL");
        $row = $check->fetch_assoc();
        
        if ($row['count'] > 0) {
            $message = "❌ Cannot delete librarian. They have return records. Use archive instead.";
        } else {
            $stmt = $conn->prepare("DELETE FROM librarians WHERE librarian_id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $message = "🗑️ Librarian permanently deleted.";
        }
    }
}


// FETCH DATA
$active_books = $conn->query("SELECT * FROM books WHERE status != 'archived' ORDER BY title");
$archived_books = $conn->query("SELECT * FROM books WHERE status = 'archived' ORDER BY title");
$active_members = $conn->query("SELECT * FROM members WHERE status != 'archived' ORDER BY last_name");
$archived_members = $conn->query("SELECT * FROM members WHERE status = 'archived' ORDER BY last_name");
$active_borrows = $conn->query("SELECT br.*, b.title, CONCAT(m.first_name,' ',m.last_name) as member_name
                                FROM borrow_records br
                                JOIN books b ON br.isbn_number = b.isbn_number
                                JOIN members m ON br.member_id = m.member_id
                                WHERE br.date_returned IS NULL");
$overdue = $conn->query("SELECT br.*, b.title, CONCAT(m.first_name,' ',m.last_name) as member_name,
                        DATEDIFF(CURDATE(), br.due_date) as days_overdue
                        FROM borrow_records br
                        JOIN books b ON br.isbn_number = b.isbn_number
                        JOIN members m ON br.member_id = m.member_id
                        WHERE br.date_returned IS NULL AND br.due_date < CURDATE()");
$historical_loans = $conn->query("SELECT br.*, b.title, CONCAT(m.first_name,' ',m.last_name) as member_name
                                 FROM borrow_records br
                                 JOIN books b ON br.isbn_number = b.isbn_number
                                 JOIN members m ON br.member_id = m.member_id
                                 WHERE br.date_returned IS NOT NULL
                                 ORDER BY br.date_returned DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LibraSys - Librarian</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .navbar { background: linear-gradient(135deg, #1e3a8a, #3b82f6); }
        .card { border-radius: 16px; box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
        .nav-link.active { font-weight: 600; border-bottom: 4px solid #3b82f6; }
        .btn { border-radius: 8px; }
        .search-box { max-width: 350px; }
        #customPopup {
    position: fixed;
    top:0; left:0;
    width:100%; height:100%;
    background: rgba(0,0,0,0.5);
    display:flex;
    justify-content:center;
    align-items:center;
}

.popup-box {
    background:white;
    padding:20px;
    border-radius:10px;
    text-align:center;
}

.popup-box button {
    margin:5px;
    padding:5px 10px;
}
    </style>
</head>
<body>

<nav class="navbar navbar-dark py-3">
    <div class="container">
        <span class="navbar-brand fw-bold fs-3"><i class="fas fa-book"></i> LibraSys</span>
        <span class="text-white">Welcome, <?= htmlspecialchars(isset($_SESSION['username']) ? $_SESSION['username'] : 'Guest') ?> | <a href="<?= isset($_SESSION['username']) ? 'logout.php' : 'login.php' ?>" class="text-white"><?= isset($_SESSION['username']) ? 'Logout' : 'Login' ?></a></span>
    </div>
</nav>

<div class="container mt-4">
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>

    <ul class="nav nav-tabs mb-4">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#dashboard">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#books">Books</a></li>
        <?php if ($isLoggedIn): ?>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#members">Members</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#circulation">Borrow / Return</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#overdue">Overdue & Fines</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#archived">Archived</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#librarians">Librarians</a></li>
        <?php endif; ?>
    </ul>

    <div class="tab-content">

        <!-- DASHBOARD -->
        <div class="tab-pane fade show active" id="dashboard">
            <div class="row g-4">
                <div class="col-md-3"><div class="card p-4 text-center"><h3><?= $active_books->num_rows ?></h3>Books</div></div>
                <div class="col-md-3"><div class="card p-4 text-center"><h3><?= $active_members->num_rows ?></h3>Members</div></div>
                <div class="col-md-3"><div class="card p-4 text-center"><h3><?= $active_borrows->num_rows ?></h3>Borrowed</div></div>
                <div class="col-md-3"><div class="card p-4 text-center bg-danger text-white"><h3><?= $overdue->num_rows ?></h3>Overdue</div></div>
            </div>
        </div>

        <!-- BOOKS -->
        <div class="tab-pane fade" id="books">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4>Books</h4>
                <div class="d-flex gap-2">
                    <input type="text" id="bookSearch" class="form-control search-box" placeholder="Search book name...">
                    <?php if ($isLoggedIn): ?>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addBookModal">+ Add Book</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card">
                <table class="table table-hover" id="booksTable">
                    <thead><tr><th>ISBN</th><th>Title</th><th>Author</th><th>Genre</th><th>Status</th><th><?php if ($isLoggedIn): ?>Total number<?php else: ?>Number Available<?php endif; ?></th><?php if ($isLoggedIn): ?><th>Number Borrowed</th><th>Actions</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php while($b = $active_books->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['isbn_number']) ?></td>
                            <td><?= htmlspecialchars($b['title']) ?></td>
                            <td><?= htmlspecialchars($b['author']) ?></td>
                            <td><?= htmlspecialchars($b['genre'] ?? '-') ?></td>
                            <td><span class="badge bg-<?= $b['status']=='borrowed'?'warning':'success'?>"><?= $b['status'] ?></span></td>
                            <td><?php if ($isLoggedIn): ?><?= $b['quantity'] ?><?php else: ?><?= $b['quantity'] - ($b['quantity_borrowed'] ?? 0) ?><?php endif; ?></td>
                            <?php if ($isLoggedIn): ?>
                            <td><?= $b['quantity_borrowed'] ?? 0 ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="editBook('<?= $b['isbn_number'] ?>','<?= htmlspecialchars($b['title']) ?>','<?= htmlspecialchars($b['author']) ?>','<?= htmlspecialchars($b['genre']??'') ?>',<?= $b['publishing_year'] ?>,<?= $b['quantity'] ?>,<?= $b['quantity_borrowed'] ?? 0 ?>)">Edit</button>
                                <button class="btn btn-sm btn-warning" onclick="archiveBook('<?= $b['isbn_number'] ?>')">Archive</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MEMBERS -->
        <?php if ($isLoggedIn): ?>
        <div class="tab-pane fade" id="members">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4>Members</h4>
                <div class="d-flex gap-2">
                    <input type="text" id="memberSearch" class="form-control search-box" placeholder="Search member name...">
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addMemberModal">+ Add Member</button>
                </div>
            </div>
            <div class="card">
                <table class="table table-hover" id="membersTable">
                    <thead><tr><th>Name</th><th>Contact</th><th>Email</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php while($m = $active_members->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['first_name'].' '.$m['last_name']) ?></td>
                            <td><?= htmlspecialchars($m['contact_number'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($m['email'] ?? '-') ?></td>
                            <td>
                                <button class="btn btn-sm btn-primary" onclick="editMember(<?= $m['member_id'] ?>,'<?= htmlspecialchars($m['first_name']) ?>','<?= htmlspecialchars($m['last_name']) ?>','<?= htmlspecialchars($m['contact_number']??'') ?>','<?= htmlspecialchars($m['email']??'') ?>')">Edit</button>
                                <button class="btn btn-sm btn-warning" onclick="archiveMember(<?= $m['member_id'] ?>)">Archive</button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- BORROW / RETURN -->
        <?php if ($isLoggedIn): ?>
        <div class="tab-pane fade" id="circulation">
            <h5>Borrow Book</h5>
            <form method="POST" class="card p-4 mb-4">
                <input type="hidden" name="action" value="borrow_book">
                <div class="row g-3">
                    <div class="col-md-4">
                        <select name="isbn" class="form-select" required>
                            <option value="">Select Book</option>
                            <?php $avail = $conn->query("SELECT * FROM books WHERE status != 'archived'"); while($b=$avail->fetch_assoc()): ?>
                                <option value="<?= $b['isbn_number'] ?>"><?= htmlspecialchars($b['title']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select name="member_id" class="form-select" required>
                            <option value="">Select Member</option>
                            <?php $active_members->data_seek(0); while($m=$active_members->fetch_assoc()): ?>
                                <option value="<?= $m['member_id'] ?>"><?= htmlspecialchars($m['first_name'].' '.$m['last_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="qty" class="form-control" value="1" min="1" required>
                    </div>
                    <div class="col-md-2"><button class="btn btn-primary w-100">Borrow</button></div>
                </div>
            </form>

            <h5>Active Loans</h5>
            <div class="card">
                <table class="table">
                    <thead><tr><th>Book</th><th>Member</th><th>Qty</th><th>Due Date</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php while($br = $active_borrows->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($br['title']) ?></td>
                            <td><?= htmlspecialchars($br['member_name']) ?></td>
                            <td><?= $br['qty_borrowed'] ?? 1 ?></td>
                            <td><?= $br['due_date'] ?></td>
                            <td>
                                <button class="btn btn-success btn-sm" onclick="openReturnModal('<?= $br['isbn_number'] ?>', '<?= htmlspecialchars($br['title']) ?>')">Mark Returned</button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <h5 class="mt-4">Historical Loans</h5>
            <div class="card">
                <table class="table">
                    <thead><tr><th>Book</th><th>Member</th><th>Qty</th><th>Date Returned</th><th>Fines Paid</th><th>Remarks</th></tr></thead>
                    <tbody>
                    <?php while($hl = $historical_loans->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($hl['title']) ?></td>
                            <td><?= htmlspecialchars($hl['member_name']) ?></td>
                            <td><?= $hl['qty_borrowed'] ?? 1 ?></td>
                            <td><?= $hl['date_returned'] ?></td>
                            <td>₱<?= number_format($hl['fine_paid'] ?? 0, 2) ?></td>
                            <td><?= htmlspecialchars($hl['remarks'] ?? '-') ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- OVERDUE -->
        <?php if ($isLoggedIn): ?>
        <div class="tab-pane fade" id="overdue">
            <h4>Overdue Books & Fines</h4>
            <div class="card">
                <table class="table table-danger">
                    <thead><tr><th>Book</th><th>Member</th><th>Days Overdue</th><th>Fine (₱10/day)</th></tr></thead>
                    <tbody>
                    <?php while($o = $overdue->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($o['title']) ?></td>
                            <td><?= htmlspecialchars($o['member_name']) ?></td>
                            <td><?= $o['days_overdue'] ?> days</td>
                            <td><strong>₱<?= number_format($o['days_overdue'] * 10, 2) ?></strong></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

                <!-- ARCHIVED -->
        <?php if ($isLoggedIn): ?>
        <div class="tab-pane fade" id="archived">
            <div class="row g-4">
                <div class="col-md-6">
                    <h5>Archived Books</h5>
                    <div class="card">
                        <table class="table table-sm">
                            <?php while($b = $archived_books->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($b['title']) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-success me-1" onclick="restoreBook('<?= $b['isbn_number'] ?>')">Restore</button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteBook('<?= $b['isbn_number'] ?>')">Delete</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <h5>Archived Members</h5>
                    <div class="card">
                        <table class="table table-sm">
                            <?php while($m = $archived_members->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($m['first_name'].' '.$m['last_name']) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-success me-1" onclick="restoreMember(<?= $m['member_id'] ?>)">Restore</button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteMember(<?= $m['member_id'] ?>)">Delete</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
                <!-- LIBRARIANS -->
        <?php if ($isLoggedIn): ?>
        <div class="tab-pane fade" id="librarians">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4>Librarians</h4>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addLibrarianModal">+ Add Librarian</button>
            </div>

            <div class="card">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>

                    <?php
                    $result = $conn->query("SELECT * FROM librarians");

                    while($row = $result->fetch_assoc()):
                        // Check if this librarian has any returns
                        $check = $conn->query("SELECT COUNT(*) as count FROM borrow_records WHERE librarian_id=".$row['librarian_id']." AND date_returned IS NOT NULL");
                        $returnCount = $check->fetch_assoc()['count'];
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td><?= htmlspecialchars($row['mobile_number']) ?></td>
                            <td>
                                <span class="badge bg-<?= ($row['status'] ?? 'inactive') == 'active'?'success':'secondary' ?>">
                                    <?= $row['status'] ?? 'inactive' ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['status'] == 'active'): ?>
                                    <button class="btn btn-sm btn-primary" onclick="editLibrarian(<?= $row['librarian_id'] ?>,'<?= htmlspecialchars($row['first_name']) ?>','<?= htmlspecialchars($row['last_name']) ?>','<?= htmlspecialchars($row['mobile_number']) ?>','<?= htmlspecialchars($row['email']) ?>','<?= $row['status'] ?>')">Edit</button>
                                    <?php if ($returnCount > 0): ?>
                                        <button class="btn btn-sm btn-warning" onclick="archiveLibrarian(<?= $row['librarian_id'] ?>)">Archive</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-danger" onclick="deleteLibrarian(<?= $row['librarian_id'] ?>)">Delete</button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-success" onclick="restoreLibrarian(<?= $row['librarian_id'] ?>)">Restore</button>
                                    <button class="btn btn-sm btn-danger" onclick="deleteLibrarian(<?= $row['librarian_id'] ?>)">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>

                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

<!-- ====================== MODALS ====================== -->

<!-- Return Book Modal -->
<div class="modal fade" id="returnBookModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" onsubmit="return validateReturnForm()">
                <div class="modal-header"><h5>Return Book</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="return_book">
                    <input type="hidden" name="isbn" id="return_isbn">
                    <input type="hidden" id="return_late_fee_value">
                    <div class="mb-3">
                        <label class="form-label">Book</label>
                        <input id="return_book_title" class="form-control" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Librarian Password</label>
                        <input type="password" name="password" id="return_password" class="form-control" required>
                        <small class="text-danger" id="passwordError"></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Late Fee (Auto-calculated)</label>
                        <input type="text" id="return_late_fee" class="form-control" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Custom Fine Amount (optional, min. 0)</label>
                        <input type="number" name="custom_fine" id="return_custom_fine" class="form-control" value="0" min="0" step="1">
                        <small class="text-muted">Leave at 0 to use calculated late fees</small><br>
                        <small class="text-danger" id="fineError"></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" id="return_remarks" class="form-control" rows="2" placeholder="Optional remarks (e.g., damaged, lost page, etc.)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Complete Return</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Book Modal -->
<div class="modal fade" id="addBookModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5>Add New Book</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_book">
                    <input name="isbn" class="form-control mb-2" placeholder="ISBN" required>
                    <input name="title" class="form-control mb-2" placeholder="Title" required>
                    <input name="author" class="form-control mb-2" placeholder="Author" required>
                    <input name="genre" class="form-control mb-2" placeholder="Genre">
                    <input name="year" type="number" class="form-control" placeholder="Year">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Book</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Book Modal -->
<div class="modal fade" id="editBookModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5>Edit Book</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_book">
                    <input type="hidden" name="isbn" id="edit_isbn">
                    <input type="hidden" name="quantity_borrowed" id="edit_quantity_borrowed">
                    Title
                    <input name="title" id="edit_title" class="form-control mb-2" required>
                    Author
                    <input name="author" id="edit_author" class="form-control mb-2" required>
                    Genre
                    <input name="genre" id="edit_genre" class="form-control mb-2">
                    Year
                    <input name="year" id="edit_year" type="number" class="form-control mb-2">
                    Total Quantity
                    <input name="quantity" id="edit_quantity" type="number" class="form-control" placeholder="Total Quantity" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Member Modal -->
<div class="modal fade" id="addMemberModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5>Add New Member</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_member">
                    <input name="first" class="form-control mb-2" placeholder="First Name" required>
                    <input name="last" class="form-control mb-2" placeholder="Last Name" required>
                    <input name="contact" class="form-control" placeholder="Contact Number">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Member</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Member Modal -->
<div class="modal fade" id="editMemberModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5>Edit Member</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_member">
                    <input type="hidden" name="id" id="edit_member_id">
                    <label class="form-label">First Name</label>
                    <input id="edit_first" class="form-control mb-2" disabled>
                    <label class="form-label">Last Name</label>
                    <input id="edit_last" class="form-control mb-2" disabled>
                    <label class="form-label">Contact Number</label>
                    <input name="contact" id="edit_contact" class="form-control mb-2" required>
                    <label class="form-label">Email</label>
                    <input name="email" id="edit_email" class="form-control">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div id="customPopup" style="display:none;">
  <div class="popup-box">
    <p id="popupMessage"></p>
    <div id="popupButtons"></div>
  </div>
</div>
<!-- Add Librarian Modal -->
<div class="modal fade" id="addLibrarianModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5>Add New Librarian</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_librarian">
                    <input name="first" class="form-control mb-2" placeholder="First Name" required>
                    <input name="last" class="form-control mb-2" placeholder="Last Name" required>
                    <input name="mobile" class="form-control mb-2" placeholder="Mobile Number" required>
                    <input name="email" class="form-control" placeholder="Email" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Add Librarian</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Librarian Modal -->
<div class="modal fade" id="editLibrarianModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5>Edit Librarian</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_librarian">
                    <input type="hidden" name="id" id="edit_librarian_id">
                    <label class="form-label">First Name</label>
                    <input name="first" id="edit_lib_first" class="form-control mb-2" required>
                    <label class="form-label">Last Name</label>
                    <input name="last" id="edit_lib_last" class="form-control mb-2" required>
                    <label class="form-label">Mobile Number</label>
                    <input name="mobile" id="edit_lib_mobile" class="form-control mb-2" required>
                    <label class="form-label">Email</label>
                    <input name="email" id="edit_lib_email" class="form-control mb-2" required>
                    <label class="form-label">Status</label>
                    <select name="status" id="edit_lib_status" class="form-select">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ================= SEARCH =================

// Search Books
document.getElementById('bookSearch')?.addEventListener('keyup', function() {
    let value = this.value.toLowerCase();
    let rows = document.querySelectorAll("#booksTable tbody tr");
    rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(value) ? "" : "none";
    });
});

// Search Members
document.getElementById('memberSearch')?.addEventListener('keyup', function() {
    let value = this.value.toLowerCase();
    let rows = document.querySelectorAll("#membersTable tbody tr");
    rows.forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(value) ? "" : "none";
    });
});


// ================= MODALS =================

function openReturnModal(isbn, title) {
    document.getElementById('return_isbn').value = isbn;
    document.getElementById('return_book_title').value = title;
    document.getElementById('return_password').value = '';
    document.getElementById('return_custom_fine').value = 0;
    document.getElementById('return_remarks').value = '';
    document.getElementById('passwordError').textContent = '';
    document.getElementById('fineError').textContent = '';

    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=get_late_fee&isbn=' + isbn
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('return_late_fee').value = '₱' + parseFloat(data.late_fee).toFixed(2);
        document.getElementById('return_late_fee_value').value = data.late_fee;
    });

    new bootstrap.Modal(document.getElementById('returnBookModal')).show();
}

function editBook(isbn, title, author, genre, year, quantity, quantity_borrowed) {
    document.getElementById('edit_isbn').value = isbn;
    document.getElementById('edit_title').value = title;
    document.getElementById('edit_author').value = author;
    document.getElementById('edit_genre').value = genre;
    document.getElementById('edit_year').value = year;
    document.getElementById('edit_quantity').value = quantity;
    document.getElementById('edit_quantity_borrowed').value = quantity_borrowed;
    new bootstrap.Modal(document.getElementById('editBookModal')).show();
}

function editMember(id, first, last, contact, email) {
    document.getElementById('edit_member_id').value = id;
    document.getElementById('edit_first').value = first;
    document.getElementById('edit_last').value = last;
    document.getElementById('edit_contact').value = contact;
    document.getElementById('edit_email').value = email;
    new bootstrap.Modal(document.getElementById('editMemberModal')).show();
}

function editLibrarian(id, first, last, mobile, email, status) {
    document.getElementById('edit_librarian_id').value = id;
    document.getElementById('edit_lib_first').value = first;
    document.getElementById('edit_lib_last').value = last;
    document.getElementById('edit_lib_mobile').value = mobile;
    document.getElementById('edit_lib_email').value = email;
    document.getElementById('edit_lib_status').value = status;
    new bootstrap.Modal(document.getElementById('editLibrarianModal')).show();
}

function archiveLibrarian(id){
    confirmPopup('Archive this librarian?', 'archive_librarian', 'id', id);
}

function deleteLibrarian(id){
    confirmPopup('⚠️ Permanently delete this librarian?', 'delete_librarian', 'id', id);
}

function restoreLibrarian(id){
    confirmPopup('Restore this librarian?', 'restore_librarian', 'id', id);
}


// ================= VALIDATION =================

function validateReturnForm() {
    const password = document.getElementById('return_password').value;
    const customFine = parseInt(document.getElementById('return_custom_fine').value) || 0;
    const minFine = parseInt(document.getElementById('return_late_fee_value').value) || 0;

    document.getElementById('passwordError').textContent = '';
    document.getElementById('fineError').textContent = '';

    if (!password) {
        document.getElementById('passwordError').textContent = 'Password is required';
        return false;
    }

    if (customFine > 0 && customFine < minFine) {
        document.getElementById('fineError').textContent =
            `Fine cannot be less than ₱${minFine}`;
        return false;
    }

    return true;
}


// ================= CUSTOM POPUP =================

function showPopup(message) {
    document.getElementById("popupMessage").innerText = message;
    document.getElementById("popupButtons").innerHTML =
        `<button onclick="closePopup()">OK</button>`;
    document.getElementById("customPopup").style.display = "flex";
}

function confirmPopup(message, action, key, value) {
    document.getElementById("popupMessage").innerText = message;

    document.getElementById("popupButtons").innerHTML =
        `<button onclick="confirmYes('${action}','${key}','${value}')">Yes</button>
         <button onclick="closePopup()">Cancel</button>`;

    document.getElementById("customPopup").style.display = "flex";
}

function confirmYes(action, key, value) {
    closePopup();
    post(action, key, value);
}

function closePopup() {
    document.getElementById("customPopup").style.display = "none";
}


// ================= ACTION HANDLERS =================

// BOOKS
function archiveBook(isbn){
    confirmPopup('Archive this book?', 'archive_book', 'isbn', isbn);
}

function restoreBook(isbn){
    confirmPopup('Restore this book?', 'restore_book', 'isbn', isbn);
}

function deleteBook(isbn){
    confirmPopup('⚠️ Permanently delete this book?', 'delete_book', 'isbn', isbn);
}

// MEMBERS
function archiveMember(id){
    confirmPopup('Archive this member?', 'archive_member', 'id', id);
}

function restoreMember(id){
    confirmPopup('Restore this member?', 'restore_member', 'id', id);
}

function deleteMember(id){
    confirmPopup('⚠️ Permanently delete this member?', 'delete_member', 'id', id);
}

// ================= POST HELPER =================

function post(action, key, value) {
    const f = document.createElement('form');
    f.method = 'POST';

    f.innerHTML = `
        <input type="hidden" name="action" value="${action}">
        <input type="hidden" name="${key}" value="${value}">
    `;

    document.body.appendChild(f);
    f.submit();
}
</script>
</script>
</body>
</html>
