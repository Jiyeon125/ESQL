<?php
session_start();

// DB 연결 함수
function get_db() {
    $host = 'localhost';
    $user = 'root';
    $password = '0000';
    $database = 'esql_2413640';
    
    $conn = new mysqli($host, $user, $password, $database);
    
    if ($conn->connect_error) {
        die("DB 연결 오류: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8");
    return $conn;
}

// 비밀번호 해시 함수
function hash_password($raw_password) {
    return hash('sha256', $raw_password);
}

// 관리자 인증 코드
define('ADMIN_SECRET', '*smwu*');

// 연체료 계산 함수
function calculate_refund($category, $overdue_days, $deposit) {
    if ($overdue_days <= 0) {  // 기한 내 반납 (3일 이내)
        return $deposit;  // 전액 환급
    } elseif ($overdue_days == 1) {  // 4일차 반납
        return $deposit - 2000;  // 2,000원 페널티
    } else {  // 5일차 이후
        return 0;
    }
}

// 메시지 출력 함수
function show_message($message, $type = 'success') {
    echo "<div class='message $type'>$message</div>";
}

// 로그인 체크
function check_login() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    return true;
}

// 관리자 체크
function check_admin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

// POST 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $db = get_db();

    // 로그인
    if ($action === 'login') {
        $student_no = $_POST['student_no'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($student_no) || empty($password)) {
            show_message("학번과 비밀번호를 입력해주세요.", 'error');
        } else {
            $password_hash = hash_password($password);
            
            $sql = "SELECT * FROM member WHERE student_no=? AND password_hash=?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("ss", $student_no, $password_hash);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($user = $result->fetch_assoc()) {
                $_SESSION['user_id'] = $user['member_id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['is_admin'] = $user['is_admin'];
                $_SESSION['student_no'] = $user['student_no'];
                show_message("✅ 로그인 성공! 환영합니다, {$user['name']}님!");
            } else {
                show_message("❌ 로그인 실패: 학번 또는 비밀번호가 틀렸습니다.", 'error');
            }
            $stmt->close();
        }
    }
    
    // 로그아웃
    elseif ($action === 'logout') {
        $name = $_SESSION['user_name'] ?? '';
        session_destroy();
        show_message("👋 {$name}님, 안녕히 가세요!");
    }

    // 회원 등록
    elseif ($action === 'register_member') {
        $student_no = $_POST['student_no'] ?? '';
        $name = $_POST['name'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $password = $_POST['password'] ?? '';
        $bank_account = $_POST['bank_account'] ?? '';
        $is_admin_yn = $_POST['is_admin_yn'] ?? 'N';
        $admin_code = $_POST['admin_code'] ?? '';
        
        // 입력 검증
        if (empty($student_no) || empty($name) || empty($phone) || empty($password) || empty($bank_account)) {
            show_message("❌ 모든 필드를 입력해주세요.", 'error');
        } elseif (strlen($password) < 4) {
            show_message("❌ 비밀번호는 최소 4자 이상이어야 합니다.", 'error');
        } else {
            // 중복 학번 체크
            $check_sql = "SELECT student_no FROM member WHERE student_no=?";
            $check_stmt = $db->prepare($check_sql);
            $check_stmt->bind_param("s", $student_no);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                show_message("❌ 이미 등록된 학번입니다.", 'error');
                $check_stmt->close();
            } else {
                $check_stmt->close();
                
                // 관리자 권한 확인
                $is_admin = 0;
                if ($is_admin_yn === 'Y') {
                    if ($admin_code === ADMIN_SECRET) {
                        $is_admin = 1;
                    } else {
                        show_message("❌ 관리자 코드가 일치하지 않습니다. 일반 회원으로 등록됩니다.", 'error');
                    }
                }
                
                $password_hash = hash_password($password);
                
                $sql = "INSERT INTO member(student_no, name, phone, password_hash, bank_account, is_admin) 
                        VALUES (?, ?, ?, ?, ?, ?)";
                
                $stmt = $db->prepare($sql);
                $stmt->bind_param("sssssi", $student_no, $name, $phone, $password_hash, $bank_account, $is_admin);
                
                if ($stmt->execute()) {
                    $role = $is_admin ? '관리자' : '일반회원';
                    show_message("✅ 회원 등록 완료! (이름: {$name}, 학번: {$student_no}, 권한: {$role})");
                } else {
                    show_message("❌ SQL 오류: " . $stmt->error, 'error');
                }
                $stmt->close();
            }
        }
    }

    // 비품 등록 (관리자 전용)
    elseif ($action === 'register_item') {
        if (!check_login() || !check_admin()) {
            show_message("❌ 관리자만 접근 가능합니다.", 'error');
        } else {
            $category = $_POST['category'] ?? '';
            $serial_no = $_POST['serial_no'] ?? '';
            
            if (empty($category) || empty($serial_no)) {
                show_message("❌ 모든 필드를 입력해주세요.", 'error');
            } elseif (!in_array($category, ['UMBRELLA', 'BATTERY'])) {
                show_message("❌ UMBRELLA 또는 BATTERY를 선택해주세요.", 'error');
            } else {
                // 중복 확인
                $check_sql = "SELECT serial_no FROM item WHERE serial_no=?";
                $check_stmt = $db->prepare($check_sql);
                $check_stmt->bind_param("s", $serial_no);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    show_message("⚠ 이미 등록된 고유번호입니다.", 'error');
                    $check_stmt->close();
                } else {
                    $check_stmt->close();
                    
                    // 카테고리에 따른 보증금 자동 설정
                    $deposit = ($category === 'UMBRELLA') ? 6000 : 8000;
                    $category_kr = ($category === 'UMBRELLA') ? '우산' : '보조배터리';
                    
                    $sql = "INSERT INTO item(category, serial_no, status, deposit_required) 
                            VALUES (?, ?, 'AVAILABLE', ?)";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param("ssd", $category, $serial_no, $deposit);
                    
                    if ($stmt->execute()) {
                        show_message("✅ 비품 등록 성공! (카테고리: {$category_kr}, 고유번호: {$serial_no}, 보증금: " . number_format($deposit) . "원)");
                    } else {
                        show_message("❌ SQL 오류: " . $stmt->error, 'error');
                    }
                    $stmt->close();
                }
            }
        }
    }

    // 비품 대여
    elseif ($action === 'rent_item') {
        if (!check_login()) {
            show_message("❌ 로그인이 필요합니다.", 'error');
        } else {
            $item_id = $_POST['item_id'] ?? '';
            $member_id = $_SESSION['user_id'];
            
            if (empty($item_id)) {
                show_message("❌ 비품 ID를 입력해주세요.", 'error');
            } else {
                // 트랜잭션 시작
                $db->begin_transaction();
                
                try {
                    // 비품 정보 확인
                    $check_sql = "SELECT * FROM item WHERE item_id=?";
                    $check_stmt = $db->prepare($check_sql);
                    $check_stmt->bind_param("i", $item_id);
                    $check_stmt->execute();
                    $item_result = $check_stmt->get_result();
                    
                    if ($item_result->num_rows == 0) {
                        throw new Exception("비품을 찾을 수 없습니다.");
                    }
                    
                    $item_info = $item_result->fetch_assoc();
                    $check_stmt->close();
                    
                    if ($item_info['status'] !== 'AVAILABLE') {
                        throw new Exception("해당 비품은 현재 대여 불가능합니다.");
                    }
                    
                    // rental insert
                    $sql1 = "INSERT INTO rental(member_id, item_id, rented_on, due_on) 
                            VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY))";
                    $stmt1 = $db->prepare($sql1);
                    $stmt1->bind_param("ii", $member_id, $item_id);
                    $stmt1->execute();
                    $stmt1->close();

                    // item 상태 변경
                    $sql2 = "UPDATE item SET status='RENTED' WHERE item_id=?";
                    $stmt2 = $db->prepare($sql2);
                    $stmt2->bind_param("i", $item_id);
                    $stmt2->execute();
                    $stmt2->close();

                    $db->commit();
                    
                    $category_kr = ($item_info['category'] === 'UMBRELLA') ? '우산' : '보조배터리';
                    $deposit = number_format($item_info['deposit_required']);
                    show_message("✅ 대여 완료! (비품: {$category_kr} ({$item_info['serial_no']}), 보증금: {$deposit}원, 반납기한: 대여일로부터 3일 이내)");
                } catch (Exception $e) {
                    $db->rollback();
                    show_message("❌ 대여 오류: " . $e->getMessage(), 'error');
                }
            }
        }
    }

    // 비품 반납
    elseif ($action === 'return_item') {
        if (!check_login()) {
            show_message("❌ 로그인이 필요합니다.", 'error');
        } else {
            $rental_id = $_POST['rental_id'] ?? '';
            $member_id = $_SESSION['user_id'];
            
            if (empty($rental_id)) {
                show_message("❌ 대여 ID를 입력해주세요.", 'error');
            } else {
                $db->begin_transaction();
                
                try {
                    // 대여 정보 확인
                    $check_sql = "SELECT r.*, i.deposit_required, i.serial_no, i.category,
                                         DATEDIFF(NOW(), r.due_on) AS overdue_days
                                  FROM rental r
                                  JOIN item i ON r.item_id = i.item_id
                                  WHERE r.rental_id = ? AND r.member_id = ?";
                    $check_stmt = $db->prepare($check_sql);
                    $check_stmt->bind_param("ii", $rental_id, $member_id);
                    $check_stmt->execute();
                    $rental_result = $check_stmt->get_result();
                    
                    if ($rental_result->num_rows == 0) {
                        throw new Exception("대여 정보를 찾을 수 없거나 권한이 없습니다.");
                    }
                    
                    $rental_info = $rental_result->fetch_assoc();
                    $check_stmt->close();
                    
                    if ($rental_info['returned_on']) {
                        throw new Exception("이미 반납된 비품입니다.");
                    }
                    
                    // rental 테이블 returned_on 갱신
                    $sql1 = "UPDATE rental SET returned_on = NOW() WHERE rental_id = ?";
                    $stmt1 = $db->prepare($sql1);
                    $stmt1->bind_param("i", $rental_id);
                    $stmt1->execute();
                    $stmt1->close();

                    // item 상태 복구
                    $sql2 = "UPDATE item SET status='AVAILABLE' WHERE item_id = ?";
                    $stmt2 = $db->prepare($sql2);
                    $stmt2->bind_param("i", $rental_info['item_id']);
                    $stmt2->execute();
                    $stmt2->close();

                    $db->commit();
                    
                    // 환급액 계산
                    $deposit_amount = $rental_info['deposit_required'];
                    $overdue_days = $rental_info['overdue_days'];
                    $category = $rental_info['category'];
                    
                    $refund_amount = calculate_refund($category, $overdue_days, $deposit_amount);
                    $penalty_amount = $deposit_amount - $refund_amount;
                    
                    $category_kr = ($category === 'UMBRELLA') ? '우산' : '보조배터리';
                    
                    $status_msg = "";
                    if ($overdue_days <= 0) {
                        $status_msg = "정상 반납 (기한 내), 페널티 없음";
                    } elseif ($overdue_days == 1) {
                        $status_msg = "4일차 반납 (1일 연체), 페널티: " . number_format($penalty_amount) . "원";
                    } else {
                        $status_msg = ($overdue_days + 3) . "일차 반납 ({$overdue_days}일 연체), 페널티: " . number_format($penalty_amount) . "원";
                    }
                    
                    show_message("✅ 반납 완료! (비품: {$category_kr} ({$rental_info['serial_no']}), 보증금: " . number_format($deposit_amount) . "원, 상태: {$status_msg}, 환급액: " . number_format($refund_amount) . "원)");
                } catch (Exception $e) {
                    $db->rollback();
                    show_message("❌ 반납 오류: " . $e->getMessage(), 'error');
                }
            }
        }
    }

    // 보증금 거래 입력 (관리자 전용)
    elseif ($action === 'deposit_txn') {
        if (!check_login() || !check_admin()) {
            show_message("❌ 관리자만 접근 가능합니다.", 'error');
        } else {
            $member_id = $_POST['member_id'] ?? '';
            $item_id = $_POST['item_id'] ?? '';
            $amount = $_POST['amount'] ?? '';
            $reason = $_POST['reason'] ?? '';
            
            if (empty($member_id) || empty($item_id) || empty($amount) || empty($reason)) {
                show_message("❌ 모든 필드를 입력해주세요.", 'error');
            } else {
                $sql = "INSERT INTO deposit_txn(member_id, item_id, amount, reason, created_at) 
                        VALUES (?, ?, ?, ?, NOW())";
                
                $stmt = $db->prepare($sql);
                $stmt->bind_param("iids", $member_id, $item_id, $amount, $reason);
                
                if ($stmt->execute()) {
                    $action_kr = ($amount < 0) ? "차감" : (($reason === 'REFUND') ? "환급" : "입금");
                    show_message("✅ 거래 입력 완료! ({$action_kr}: " . number_format($amount) . "원)");
                } else {
                    show_message("❌ 거래 입력 오류: " . $stmt->error, 'error');
                }
                $stmt->close();
            }
        }
    }

    $db->close();
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>비품 대여 시스템</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 2em;
            margin-bottom: 10px;
        }
        .user-info {
            background: rgba(255,255,255,0.2);
            padding: 10px 20px;
            border-radius: 8px;
            margin-top: 15px;
            display: inline-block;
        }
        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }
        .nav button {
            flex: 1;
            min-width: 150px;
            padding: 12px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .nav button:hover {
            background: #764ba2;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .nav button.logout {
            background: #dc3545;
        }
        .nav button.logout:hover {
            background: #c82333;
        }
        .nav button.admin-only {
            background: #28a745;
        }
        .nav button.admin-only:hover {
            background: #218838;
        }
        .content {
            padding: 30px;
        }
        .form-section {
            display: none;
            animation: fadeIn 0.5s;
        }
        .form-section.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: 600;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #dee2e6;
        }
        table tr:last-child td {
            border-bottom: none;
        }
        table tr:hover {
            background: #f8f9fa;
        }
        .section-title {
            font-size: 1.5em;
            margin-bottom: 20px;
            color: #495057;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏢 비품 대여 시스템 (Web: PHP)</h1>
            <p>PHP & MySQL 기반 비품 관리 프로그램</p>
            <?php if (check_login()): ?>
                <div class="user-info">
                    👤 <?php echo htmlspecialchars($_SESSION['user_name']); ?>님 
                    <?php echo check_admin() ? '[관리자]' : '[일반회원]'; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!check_login()): ?>
            <!-- 로그인 전 화면 -->
            <div class="nav">
                <button onclick="showSection('login')">로그인</button>
                <button onclick="showSection('register_member')">회원가입</button>
            </div>
        <?php else: ?>
            <!-- 로그인 후 화면 -->
            <div class="nav">
                <button onclick="showSection('available_items')">대여 가능 비품</button>
                <button onclick="showSection('rent_item')">비품 대여</button>
                <button onclick="showSection('my_rentals')">내 대여중 비품</button>
                <button onclick="showSection('return_item')">비품 반납</button>
                <button onclick="showSection('my_rental_list')">내 대여 내역</button>
                
                <?php if (check_admin()): ?>
                    <button onclick="showSection('register_item')" class="admin-only">비품 등록</button>
                    <button onclick="showSection('admin_rental_list')" class="admin-only">전체 대여 내역</button>
                    <button onclick="showSection('member_list')" class="admin-only">회원 목록</button>
                    <button onclick="showSection('deposit_txn')" class="admin-only">보증금 거래 입력</button>
                    <button onclick="showSection('deposit_history')" class="admin-only">보증금 거래 조회</button>
                <?php endif; ?>
                
                <button onclick="showSection('logout_form')" class="logout">로그아웃</button>
            </div>
        <?php endif; ?>

        <div class="content">
            <?php if (!check_login()): ?>
                <!-- 로그인 폼 -->
                <div id="login" class="form-section">
                    <h2 class="section-title">🔐 로그인</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="form-group">
                            <label>학번</label>
                            <input type="text" name="student_no" required>
                        </div>
                        <div class="form-group">
                            <label>비밀번호</label>
                            <input type="password" name="password" required>
                        </div>
                        <button type="submit" class="btn-submit">로그인</button>
                    </form>
                </div>

                <!-- 회원가입 폼 -->
                <div id="register_member" class="form-section">
                    <h2 class="section-title">👤 회원가입</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="register_member">
                        <div class="form-group">
                            <label>학번</label>
                            <input type="text" name="student_no" required>
                        </div>
                        <div class="form-group">
                            <label>이름</label>
                            <input type="text" name="name" required>
                        </div>
                        <div class="form-group">
                            <label>전화번호</label>
                            <input type="text" name="phone" required>
                        </div>
                        <div class="form-group">
                            <label>비밀번호 (최소 4자)</label>
                            <input type="password" name="password" required minlength="4">
                        </div>
                        <div class="form-group">
                            <label>환급 계좌번호</label>
                            <input type="text" name="bank_account" required>
                        </div>
                        <div class="form-group">
                            <label>관리자 계정입니까?</label>
                            <select name="is_admin_yn" id="is_admin_yn" onchange="toggleAdminCode()">
                                <option value="N">아니오</option>
                                <option value="Y">예</option>
                            </select>
                        </div>
                        <div class="form-group" id="admin_code_group" style="display: none;">
                            <label>관리자 인증코드</label>
                            <input type="text" name="admin_code" id="admin_code">
                            <small style="color: #666;">관리자 인증코드: *smwu*</small>
                        </div>
                        <button type="submit" class="btn-submit">회원가입</button>
                    </form>
                </div>
            <?php else: ?>
                <!-- 대여 가능 비품 목록 -->
                <div id="available_items" class="form-section">
                    <h2 class="section-title">📦 대여 가능한 비품 목록</h2>
                    <?php
                    $db = get_db();
                    $sql = "SELECT item_id, category, serial_no, deposit_required
                            FROM item
                            WHERE status = 'AVAILABLE'
                            ORDER BY category, item_id";
                    
                    $result = $db->query($sql);
                    
                    if ($result && $result->num_rows > 0) {
                        echo "<table>";
                        echo "<tr>
                                <th>ID</th>
                                <th>카테고리</th>
                                <th>고유번호</th>
                                <th>보증금</th>
                              </tr>";
                        
                        while ($row = $result->fetch_assoc()) {
                            $category_kr = ($row['category'] === 'UMBRELLA') ? '우산' : '보조배터리';
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($row['item_id']) . "</td>";
                            echo "<td>" . $category_kr . "</td>";
                            echo "<td>" . htmlspecialchars($row['serial_no']) . "</td>";
                            echo "<td>" . number_format($row['deposit_required']) . "원</td>";
                            echo "</tr>";
                        }
                        echo "</table>";
                    } else {
                        echo "<p>현재 대여 가능한 비품이 없습니다.</p>";
                    }
                    
                    $db->close();
                    ?>
                </div>

                <!-- 비품 대여 -->
                <div id="rent_item" class="form-section">
                    <h2 class="section-title">📤 비품 대여</h2>
                    <div class="info-box">
                        💡 대여 가능 비품 목록을 먼저 확인하세요. 반납 기한은 대여일로부터 3일입니다.
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="rent_item">
                        <div class="form-group">
                            <label>대여할 비품 ID</label>
                            <input type="number" name="item_id" required>
                        </div>
                        <button type="submit" class="btn-submit">대여하기</button>
                    </form>
                </div>

                <!-- 내 대여중인 비품 -->
                <div id="my_rentals" class="form-section">
                    <h2 class="section-title">📋 내 대여중인 비품</h2>
                    <?php
                    $db = get_db();
                    $user_id = $_SESSION['user_id'];
                    $sql = "SELECT r.rental_id,
                                   i.category,
                                   i.serial_no,
                                   i.deposit_required,
                                   r.rented_on,
                                   r.due_on,
                                   DATEDIFF(NOW(), r.due_on) AS overdue_days
                            FROM rental r
                            JOIN item i ON r.item_id = i.item_id
                            WHERE r.member_id = ? AND r.returned_on IS NULL
                            ORDER BY r.rental_id DESC";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result && $result->num_rows > 0) {
                        echo "<table>";
                        echo "<tr>
                                <th>대여ID</th>
                                <th>카테고리</th>
                                <th>고유번호</th>
                                <th>대여일</th>
                                <th>반납기한</th>
                                <th>상태</th>
                              </tr>";
                        
                        while ($row = $result->fetch_assoc()) {
                            $category_kr = ($row['category'] === 'UMBRELLA') ? '우산' : '보조배터리';
                            $overdue = $row['overdue_days'];
                            
                            if ($overdue <= 0) {
                                $status = "정상";
                            } else {
                                $status = "연체 {$overdue}일";
                            }
                            
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($row['rental_id']) . "</td>";
                            echo "<td>" . $category_kr . "</td>";
                            echo "<td>" . htmlspecialchars($row['serial_no']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['rented_on']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['due_on']) . "</td>";
                            echo "<td>" . $status . "</td>";
                            echo "</tr>";
                        }
                        echo "</table>";
                    } else {
                        echo "<p>현재 대여중인 비품이 없습니다.</p>";
                    }
                    
                    $stmt->close();
                    $db->close();
                    ?>
                </div>

                <!-- 비품 반납 -->
                <div id="return_item" class="form-section">
                    <h2 class="section-title">📥 비품 반납</h2>
                    <div class="info-box">
                        💡 내 대여중인 비품에서 대여 ID를 확인하세요.<br>
                        • 기한 내(3일): 전액 환급<br>
                        • 4일차: 2,000원 페널티<br>
                        • 5일차 이후: 환급 없음
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="return_item">
                        <div class="form-group">
                            <label>반납할 대여 ID</label>
                            <input type="number" name="rental_id" required>
                        </div>
                        <button type="submit" class="btn-submit">반납하기</button>
                    </form>
                </div>

                <!-- 내 대여 내역 -->
                <div id="my_rental_list" class="form-section">
                    <h2 class="section-title">📋 내 대여 내역 조회</h2>
                    <?php
                    $db = get_db();
                    $user_id = $_SESSION['user_id'];
                    $sql = "SELECT r.rental_id,
                                   i.category,
                                   i.serial_no,
                                   r.rented_on,
                                   r.due_on,
                                   r.returned_on,
                                   CASE 
                                       WHEN r.returned_on IS NULL AND DATEDIFF(NOW(), r.due_on) > 0 
                                       THEN DATEDIFF(NOW(), r.due_on)
                                       ELSE 0
                                   END AS overdue_days
                            FROM rental r
                            JOIN item i ON r.item_id = i.item_id
                            WHERE r.member_id = ?
                            ORDER BY r.rental_id DESC";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result && $result->num_rows > 0) {
                        echo "<table>";
                        echo "<tr>
                                <th>대여ID</th>
                                <th>카테고리</th>
                                <th>고유번호</th>
                                <th>대여일</th>
                                <th>반납기한</th>
                                <th>반납일</th>
                                <th>상태</th>
                              </tr>";
                        
                        while ($row = $result->fetch_assoc()) {
                            $category_kr = ($row['category'] === 'UMBRELLA') ? '우산' : '보조배터리';
                            
                            if ($row['returned_on']) {
                                $status = "반납완료";
                                $returned_str = $row['returned_on'];
                            } elseif ($row['overdue_days'] > 0) {
                                $status = "연체 {$row['overdue_days']}일";
                                $returned_str = "-";
                            } else {
                                $status = "대여중";
                                $returned_str = "-";
                            }
                            
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($row['rental_id']) . "</td>";
                            echo "<td>" . $category_kr . "</td>";
                            echo "<td>" . htmlspecialchars($row['serial_no']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['rented_on']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['due_on']) . "</td>";
                            echo "<td>" . $returned_str . "</td>";
                            echo "<td>" . $status . "</td>";
                            echo "</tr>";
                        }
                        echo "</table>";
                        echo "<p style='margin-top: 15px;'>총 " . $result->num_rows . "건의 대여 내역</p>";
                    } else {
                        echo "<p>대여 내역이 없습니다.</p>";
                    }
                    
                    $stmt->close();
                    $db->close();
                    ?>
                </div>

                <?php if (check_admin()): ?>
                    <!-- 비품 등록 (관리자 전용) -->
                    <div id="register_item" class="form-section">
                        <h2 class="section-title">📦 비품 등록 [관리자 전용]</h2>
                        <div class="info-box">
                            💡 카테고리별 보증금: UMBRELLA(우산) 6,000원 / BATTERY(보조배터리) 8,000원
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="register_item">
                            <div class="form-group">
                                <label>카테고리</label>
                                <select name="category" required>
                                    <option value="">선택하세요</option>
                                    <option value="UMBRELLA">UMBRELLA (우산)</option>
                                    <option value="BATTERY">BATTERY (배터리)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>비품 고유번호 (serial_no)</label>
                                <input type="text" name="serial_no" required>
                            </div>
                            <button type="submit" class="btn-submit">등록하기</button>
                        </form>
                    </div>

                    <!-- 전체 대여 내역 (관리자 전용) -->
                    <div id="admin_rental_list" class="form-section">
                        <h2 class="section-title">📋 전체 대여 내역 조회 [관리자 전용]</h2>
                        <?php
                        $db = get_db();
                        $sql = "SELECT r.rental_id,
                                       m.name AS member_name,
                                       m.student_no,
                                       i.category,
                                       i.serial_no,
                                       r.rented_on,
                                       r.due_on,
                                       r.returned_on,
                                       CASE 
                                           WHEN r.returned_on IS NULL AND DATEDIFF(NOW(), r.due_on) > 0 
                                           THEN DATEDIFF(NOW(), r.due_on)
                                           ELSE 0
                                       END AS overdue_days
                                FROM rental r
                                JOIN member m ON r.member_id = m.member_id
                                JOIN item i ON r.item_id = i.item_id
                                ORDER BY r.rental_id DESC
                                LIMIT 50";
                        
                        $result = $db->query($sql);
                        
                        if ($result && $result->num_rows > 0) {
                            echo "<table>";
                            echo "<tr>
                                    <th>ID</th>
                                    <th>회원명</th>
                                    <th>학번</th>
                                    <th>카테고리</th>
                                    <th>대여일</th>
                                    <th>반납기한</th>
                                    <th>반납일</th>
                                    <th>상태</th>
                                  </tr>";
                            
                            while ($row = $result->fetch_assoc()) {
                                $category_kr = ($row['category'] === 'UMBRELLA') ? '우산' : '보조배터리';
                                
                                if ($row['returned_on']) {
                                    $status = "반납완료";
                                    $returned_str = $row['returned_on'];
                                } elseif ($row['overdue_days'] > 0) {
                                    $status = "연체 {$row['overdue_days']}일";
                                    $returned_str = "-";
                                } else {
                                    $status = "대여중";
                                    $returned_str = "-";
                                }
                                
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($row['rental_id']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['member_name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['student_no']) . "</td>";
                                echo "<td>" . $category_kr . "</td>";
                                echo "<td>" . htmlspecialchars($row['rented_on']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['due_on']) . "</td>";
                                echo "<td>" . $returned_str . "</td>";
                                echo "<td>" . $status . "</td>";
                                echo "</tr>";
                            }
                            echo "</table>";
                            echo "<p style='margin-top: 15px;'>최근 " . $result->num_rows . "건의 대여 내역 (최대 50건)</p>";
                        } else {
                            echo "<p>대여 내역이 없습니다.</p>";
                        }
                        
                        $db->close();
                        ?>
                    </div>

                    <!-- 회원 목록 (관리자 전용) -->
                    <div id="member_list" class="form-section">
                        <h2 class="section-title">👥 회원 목록 조회 [관리자 전용]</h2>
                        <?php
                        $db = get_db();
                        $sql = "SELECT m.member_id, 
                                       m.student_no, 
                                       m.name, 
                                       m.phone, 
                                       m.bank_account, 
                                       m.is_admin,
                                       COUNT(CASE WHEN r.returned_on IS NULL THEN 1 END) AS active_rentals
                                FROM member m
                                LEFT JOIN rental r ON m.member_id = r.member_id
                                GROUP BY m.member_id, m.student_no, m.name, m.phone, m.bank_account, m.is_admin
                                ORDER BY m.member_id DESC";
                        
                        $result = $db->query($sql);
                        
                        if ($result && $result->num_rows > 0) {
                            echo "<table>";
                            echo "<tr>
                                    <th>ID</th>
                                    <th>학번</th>
                                    <th>이름</th>
                                    <th>전화번호</th>
                                    <th>계좌번호</th>
                                    <th>권한</th>
                                    <th>대여중</th>
                                  </tr>";
                            
                            while ($row = $result->fetch_assoc()) {
                                $admin_str = ($row['is_admin']) ? "관리자" : "일반";
                                $rental_str = ($row['active_rentals'] > 0) ? $row['active_rentals'] . "건" : "-";
                                
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($row['member_id']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['student_no']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['phone']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['bank_account']) . "</td>";
                                echo "<td>" . $admin_str . "</td>";
                                echo "<td>" . $rental_str . "</td>";
                                echo "</tr>";
                            }
                            echo "</table>";
                            echo "<p style='margin-top: 15px;'>총 " . $result->num_rows . "명의 회원</p>";
                        } else {
                            echo "<p>등록된 회원이 없습니다.</p>";
                        }
                        
                        $db->close();
                        ?>
                    </div>

                    <!-- 보증금 거래 입력 (관리자 전용) -->
                    <div id="deposit_txn" class="form-section">
                        <h2 class="section-title">💰 보증금 거래 입력 [관리자 전용]</h2>
                        <div class="info-box">
                            💡 대여/반납 시 담당자가 직접 받은 보증금 거래를 기록해 주세요.<br>
                            • INIT: 초기 보증금 예산 (양수)<br>
                            • DEPOSIT: 보증금 입금 (양수)<br>
                            • REFUND: 보증금 환급 (음수)
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="deposit_txn">
                            <div class="form-group">
                                <label>회원 ID</label>
                                <input type="number" name="member_id" required>
                            </div>
                            <div class="form-group">
                                <label>비품 ID</label>
                                <input type="number" name="item_id" required>
                            </div>
                            <div class="form-group">
                                <label>거래 금액 (+/-)</label>
                                <input type="number" name="amount" step="0.01" required>
                            </div>
                            <div class="form-group">
                                <label>거래 유형</label>
                                <select name="reason" required>
                                    <option value="">선택하세요</option>
                                    <option value="INIT">INIT (초기화)</option>
                                    <option value="DEPOSIT">DEPOSIT (보증금 입금)</option>
                                    <option value="REFUND">REFUND (보증금 환급)</option>
                                </select>
                            </div>
                            <button type="submit" class="btn-submit">입력하기</button>
                        </form>
                    </div>

                    <!-- 보증금 거래 조회 (관리자 전용) -->
                    <div id="deposit_history" class="form-section">
                        <h2 class="section-title">💳 보증금 거래 조회 [관리자 전용]</h2>
                        <?php
                        $db = get_db();
                        $sql = "SELECT d.deposit_id, 
                                       m.name, 
                                       m.student_no,
                                       i.category,
                                       i.serial_no, 
                                       d.amount, 
                                       d.reason, 
                                       d.created_at
                                FROM deposit_txn d
                                JOIN member m ON d.member_id = m.member_id
                                JOIN item i ON d.item_id = i.item_id
                                ORDER BY d.deposit_id DESC
                                LIMIT 50";
                        
                        $result = $db->query($sql);
                        
                        if ($result && $result->num_rows > 0) {
                            echo "<table>";
                            echo "<tr>
                                    <th>ID</th>
                                    <th>회원명</th>
                                    <th>학번</th>
                                    <th>비품</th>
                                    <th>거래구분</th>
                                    <th>금액</th>
                                    <th>날짜</th>
                                  </tr>";
                            
                            $reason_map = [
                                'INIT' => '초기입금',
                                'DEPOSIT' => '보증금입금',
                                'REFUND' => '보증금환급'
                            ];
                            
                            while ($row = $result->fetch_assoc()) {
                                $category_kr = ($row['category'] === 'UMBRELLA') ? '우산' : '보조배터리';
                                $reason_kr = $reason_map[$row['reason']] ?? $row['reason'];
                                $amount_str = number_format($row['amount']) . "원";
                                
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($row['deposit_id']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['student_no']) . "</td>";
                                echo "<td>" . $category_kr . "</td>";
                                echo "<td>" . $reason_kr . "</td>";
                                echo "<td>" . $amount_str . "</td>";
                                echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
                                echo "</tr>";
                            }
                            echo "</table>";
                            echo "<p style='margin-top: 15px;'>최근 " . $result->num_rows . "건의 거래 내역 (최대 50건)</p>";
                        } else {
                            echo "<p>거래 내역이 없습니다.</p>";
                        }
                        
                        $db->close();
                        ?>
                    </div>
                <?php endif; ?>

                <!-- 로그아웃 확인 -->
                <div id="logout_form" class="form-section">
                    <h2 class="section-title">👋 로그아웃</h2>
                    <p>정말 로그아웃 하시겠습니까?</p>
                    <form method="POST" style="margin-top: 20px;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn-submit">로그아웃</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function showSection(sectionId) {
            // 모든 섹션 숨기기
            const sections = document.querySelectorAll('.form-section');
            sections.forEach(section => {
                section.classList.remove('active');
            });
            
            // 선택한 섹션만 보이기
            const targetSection = document.getElementById(sectionId);
            if (targetSection) {
                targetSection.classList.add('active');
            }
        }

        function toggleAdminCode() {
            const isAdminYn = document.getElementById('is_admin_yn').value;
            const adminCodeGroup = document.getElementById('admin_code_group');
            const adminCodeInput = document.getElementById('admin_code');
            
            if (isAdminYn === 'Y') {
                adminCodeGroup.style.display = 'block';
                adminCodeInput.required = true;
            } else {
                adminCodeGroup.style.display = 'none';
                adminCodeInput.required = false;
            }
        }

        // 페이지 로드 시 첫 번째 섹션 표시
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (check_login()): ?>
                showSection('available_items');
            <?php else: ?>
                showSection('login');
            <?php endif; ?>
        });
    </script>
</body>
</html>
