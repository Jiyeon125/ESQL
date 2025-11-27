<?php
/**
 * 비품 대여 시스템 (Web: PHP)
 * PHP & MySQL 기반 비품 관리 프로그램
 * 
 * @author 2413640 데이터사이언스전공 권지연
 */

session_start();

// 상수 정의
define('ADMIN_SECRET', '*smwu*');      // 관리자 인증 코드
define('DEPOSIT_UMBRELLA', 6000);      // 우산 보증금
define('DEPOSIT_BATTERY', 8000);       // 보조배터리 보증금
define('PENALTY_AMOUNT', 2000);        // 연체 페널티 금액
define('RENTAL_DAYS', 3);              // 대여 기간 (일)

// 데이터베이스 관련 함수

/**
 * DB 연결 반환
 * @return mysqli
 */
function get_db() {
    $conn = new mysqli('localhost', 'root', '0000', 'esql_2413640');
    
    if ($conn->connect_error) {
        die("DB 연결 오류: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8");
    return $conn;
}



// 보안 관련 함수

/**
 * 비밀번호 해시 생성
 * @param string $raw_password
 * @return string
 */
function hash_password($raw_password) {
    return hash('sha256', $raw_password);
}

/**
 * 로그인 체크
 * @return bool
 */
function check_login() {
    return isset($_SESSION['user_id']);
}

/**
 * 관리자 권한 체크
 * @return bool
 */
function check_admin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}



// 비즈니스 로직 함수

/**
 * 연체료 계산
 * @param string $category 비품 카테고리
 * @param int $overdue_days 연체 일수
 * @param float $deposit 보증금
 * @return float 환급액
 */
function calculate_refund($category, $overdue_days, $deposit) {
    if ($overdue_days <= 0) {
        return $deposit;  // 전액 환급
    } elseif ($overdue_days == 1) {
        return $deposit - PENALTY_AMOUNT;  // 2,000원 페널티
    } else {
        return 0;  // 환급 없음
    }
}

/**
 * 카테고리명 한글 변환
 * @param string $category
 * @return string
 */
function get_category_kr($category) {
    return ($category === 'UMBRELLA') ? '우산' : '보조배터리';
}

/**
 * 카테고리별 보증금 반환
 * @param string $category
 * @return int
 */
function get_deposit_by_category($category) {
    return ($category === 'UMBRELLA') ? DEPOSIT_UMBRELLA : DEPOSIT_BATTERY;
}



// 회원 관리 함수

/**
 * 회원 등록
 * @param mysqli $db
 * @param array $data
 * @return array [success, message]
 */
function register_member($db, $data) {
    // 입력 검증
    if (empty($data['student_no']) || empty($data['name']) || 
        empty($data['phone']) || empty($data['password']) || 
        empty($data['bank_account'])) {
        return [false, '모든 필드를 입력해주세요.'];
    }
    
    if (strlen($data['password']) < 4) {
        return [false, '비밀번호는 최소 4자 이상이어야 합니다.'];
    }
    
    // 중복 학번 체크
    $check_sql = "SELECT student_no FROM member WHERE student_no=?";
    $stmt = $db->prepare($check_sql);
    $stmt->bind_param("s", $data['student_no']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt->close();
        return [false, '이미 등록된 학번입니다.'];
    }
    $stmt->close();
    
    // 관리자 권한 확인
    $is_admin = 0;
    $admin_msg = '';
    if ($data['is_admin_yn'] === 'Y') {
        if ($data['admin_code'] === ADMIN_SECRET) {
            $is_admin = 1;
        } else {
            $admin_msg = ' (관리자 코드 불일치 - 일반 회원으로 등록됨)';
        }
    }
    
    // 회원 등록
    $password_hash = hash_password($data['password']);
    $sql = "INSERT INTO member(student_no, name, phone, password_hash, bank_account, is_admin) 
            VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("sssssi", 
        $data['student_no'], $data['name'], $data['phone'], 
        $password_hash, $data['bank_account'], $is_admin
    );
    
    if ($stmt->execute()) {
        $role = $is_admin ? '관리자' : '일반회원';
        $message = "회원 등록 완료! (이름: {$data['name']}, 학번: {$data['student_no']}, 권한: {$role}){$admin_msg}";
        $stmt->close();
        return [true, $message];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return [false, "SQL 오류: " . $error];
    }
}

/**
 * 로그인 처리
 * @param mysqli $db
 * @param string $student_no
 * @param string $password
 * @return array [success, message, user_data]
 */
function login($db, $student_no, $password) {
    if (empty($student_no) || empty($password)) {
        return [false, '학번과 비밀번호를 입력해주세요.', null];
    }
    
    $password_hash = hash_password($password);
    $sql = "SELECT * FROM member WHERE student_no=? AND password_hash=?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ss", $student_no, $password_hash);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        $stmt->close();
        return [true, "로그인 성공! 환영합니다, {$user['name']}님!", $user];
    } else {
        $stmt->close();
        return [false, '로그인 실패: 학번 또는 비밀번호가 틀렸습니다.', null];
    }
}




// 비품 관리 함수

/**
 * 비품 등록
 * @param mysqli $db
 * @param string $category
 * @param string $serial_no
 * @return array [success, message]
 */
function register_item($db, $category, $serial_no) {
    if (empty($category) || empty($serial_no)) {
        return [false, '모든 필드를 입력해주세요.'];
    }
    
    if (!in_array($category, ['UMBRELLA', 'BATTERY'])) {
        return [false, 'UMBRELLA 또는 BATTERY를 선택해주세요.'];
    }
    
    // 중복 확인
    $check_sql = "SELECT serial_no FROM item WHERE serial_no=?";
    $stmt = $db->prepare($check_sql);
    $stmt->bind_param("s", $serial_no);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $stmt->close();
        return [false, '이미 등록된 고유번호입니다.'];
    }
    $stmt->close();
    
    // 비품 등록
    $deposit = get_deposit_by_category($category);
    $category_kr = get_category_kr($category);
    
    $sql = "INSERT INTO item(category, serial_no, status, deposit_required) 
            VALUES (?, ?, 'AVAILABLE', ?)";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ssd", $category, $serial_no, $deposit);
    
    if ($stmt->execute()) {
        $message = "비품 등록 성공! (카테고리: {$category_kr}, 고유번호: {$serial_no}, 보증금: " . number_format($deposit) . "원)";
        $stmt->close();
        return [true, $message];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return [false, "SQL 오류: " . $error];
    }
}



// 대여/반납 관리 함수

/**
 * 비품 대여
 * @param mysqli $db
 * @param int $member_id
 * @param int $item_id
 * @return array [success, message]
 */
function rent_item($db, $member_id, $item_id) {
    if (empty($item_id)) {
        return [false, '비품 ID를 입력해주세요.'];
    }
    
    $db->begin_transaction();
    
    try {
        // 비품 정보 확인
        $check_sql = "SELECT * FROM item WHERE item_id=?";
        $stmt = $db->prepare($check_sql);
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 0) {
            throw new Exception("비품을 찾을 수 없습니다.");
        }
        
        $item_info = $result->fetch_assoc();
        $stmt->close();
        
        if ($item_info['status'] !== 'AVAILABLE') {
            throw new Exception("해당 비품은 현재 대여 불가능합니다.");
        }
        
        // rental insert
        $sql1 = "INSERT INTO rental(member_id, item_id, rented_on, due_on) 
                VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))";
        $stmt1 = $db->prepare($sql1);
        $rental_days = RENTAL_DAYS;
        $stmt1->bind_param("iii", $member_id, $item_id, $rental_days);
        $stmt1->execute();
        $stmt1->close();

        // item 상태 변경
        $sql2 = "UPDATE item SET status='RENTED' WHERE item_id=?";
        $stmt2 = $db->prepare($sql2);
        $stmt2->bind_param("i", $item_id);
        $stmt2->execute();
        $stmt2->close();

        $db->commit();
        
        $category_kr = get_category_kr($item_info['category']);
        $deposit = number_format($item_info['deposit_required']);
        $message = "대여 완료! (비품: {$category_kr} ({$item_info['serial_no']}), 보증금: {$deposit}원, 반납기한: 대여일로부터 3일 이내)";
        
        return [true, $message];
    } catch (Exception $e) {
        $db->rollback();
        return [false, "대여 오류: " . $e->getMessage()];
    }
}

/**
 * 비품 반납
 * @param mysqli $db
 * @param int $member_id
 * @param int $rental_id
 * @return array [success, message]
 */
function return_item($db, $member_id, $rental_id) {
    if (empty($rental_id)) {
        return [false, '대여 ID를 입력해주세요.'];
    }
    
    $db->begin_transaction();
    
    try {
        // 대여 정보 확인
        $check_sql = "SELECT r.*, i.deposit_required, i.serial_no, i.category,
                             DATEDIFF(NOW(), r.due_on) AS overdue_days
                      FROM rental r
                      JOIN item i ON r.item_id = i.item_id
                      WHERE r.rental_id = ? AND r.member_id = ?";
        $stmt = $db->prepare($check_sql);
        $stmt->bind_param("ii", $rental_id, $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 0) {
            throw new Exception("대여 정보를 찾을 수 없거나 권한이 없습니다.");
        }
        
        $rental_info = $result->fetch_assoc();
        $stmt->close();
        
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
        
        $category_kr = get_category_kr($category);
        
        $status_msg = "";
        if ($overdue_days <= 0) {
            $status_msg = "정상 반납 (기한 내), 페널티 없음";
        } elseif ($overdue_days == 1) {
            $status_msg = "4일차 반납 (1일 연체), 페널티: " . number_format($penalty_amount) . "원";
        } else {
            $status_msg = ($overdue_days + 3) . "일차 반납 ({$overdue_days}일 연체), 페널티: " . number_format($penalty_amount) . "원";
        }
        
        $message = "반납 완료! (비품: {$category_kr} ({$rental_info['serial_no']}), 보증금: " . number_format($deposit_amount) . "원, 상태: {$status_msg}, 환급액: " . number_format($refund_amount) . "원)";
        
        return [true, $message];
    } catch (Exception $e) {
        $db->rollback();
        return [false, "반납 오류: " . $e->getMessage()];
    }
}




// 보증금 거래 관리 함수

/**
 * 보증금 거래 입력
 * @param mysqli $db
 * @param array $data
 * @return array [success, message]
 */
function insert_deposit_txn($db, $data) {
    if (empty($data['member_id']) || empty($data['item_id']) || 
        empty($data['amount']) || empty($data['reason'])) {
        return [false, '모든 필드를 입력해주세요.'];
    }
    
    $sql = "INSERT INTO deposit_txn(member_id, item_id, amount, reason, created_at) 
            VALUES (?, ?, ?, ?, NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iids", 
        $data['member_id'], $data['item_id'], $data['amount'], $data['reason']
    );
    
    if ($stmt->execute()) {
        $action_kr = ($data['amount'] < 0) ? "차감" : (($data['reason'] === 'REFUND') ? "환급" : "입금");
        $message = "거래 입력 완료! ({$action_kr}: " . number_format($data['amount']) . "원)";
        $stmt->close();
        return [true, $message];
    } else {
        $error = $stmt->error;
        $stmt->close();
        return [false, "거래 입력 오류: " . $error];
    }
}



// POST 요청 처리

$toast_message = '';
$toast_type = '';

// 플래시 메시지 확인
if (isset($_SESSION['flash_message'])) {
    $toast_message = $_SESSION['flash_message'];
    $toast_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $db = get_db();
    
    switch ($action) {
        case 'login':
            list($success, $message, $user) = login($db, 
                $_POST['student_no'] ?? '', 
                $_POST['password'] ?? ''
            );
            
            if ($success) {
                $_SESSION['user_id'] = $user['member_id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['is_admin'] = $user['is_admin'];
                $_SESSION['student_no'] = $user['student_no'];
                $toast_type = 'success';
            } else {
                $toast_type = 'error';
            }
            $toast_message = $message;
            break;
            
        case 'logout':
            $name = $_SESSION['user_name'] ?? '';
            
            // 로그아웃 전에 메시지를 임시로 저장
            session_start();
            $_SESSION = array();      // 세션 배열 비우기
            $_SESSION['flash_message'] = "{$name}님, 안녕히 가세요!";
            $_SESSION['flash_type'] = 'success';
            session_write_close();
            
            // 세션 파괴
            session_start();
            $flash_msg = $_SESSION['flash_message'] ?? '';
            $flash_type = $_SESSION['flash_type'] ?? 'success';
            session_destroy();
            
            // 새 세션 시작하고 플래시 메시지 저장
            session_start();
            $_SESSION['flash_message'] = $flash_msg;
            $_SESSION['flash_type'] = $flash_type;
            
            // 로그아웃 후 페이지 리다이렉트
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            break;
            
        case 'register_member':
            list($success, $message) = register_member($db, [
                'student_no' => $_POST['student_no'] ?? '',
                'name' => $_POST['name'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'password' => $_POST['password'] ?? '',
                'bank_account' => $_POST['bank_account'] ?? '',
                'is_admin_yn' => $_POST['is_admin_yn'] ?? 'N',
                'admin_code' => $_POST['admin_code'] ?? ''
            ]);
            $toast_message = $message;
            $toast_type = $success ? 'success' : 'error';
            break;
            
        case 'register_item':
            if (!check_login() || !check_admin()) {
                $toast_message = '관리자만 접근 가능합니다.';
                $toast_type = 'error';
            } else {
                list($success, $message) = register_item($db, 
                    $_POST['category'] ?? '', 
                    $_POST['serial_no'] ?? ''
                );
                $toast_message = $message;
                $toast_type = $success ? 'success' : 'error';
            }
            break;
            
        case 'rent_item':
            if (!check_login()) {
                $toast_message = '로그인이 필요합니다.';
                $toast_type = 'error';
            } else {
                list($success, $message) = rent_item($db, 
                    $_SESSION['user_id'], 
                    $_POST['item_id'] ?? ''
                );
                $toast_message = $message;
                $toast_type = $success ? 'success' : 'error';
            }
            break;
            
        case 'return_item':
            if (!check_login()) {
                $toast_message = '로그인이 필요합니다.';
                $toast_type = 'error';
            } else {
                list($success, $message) = return_item($db, 
                    $_SESSION['user_id'], 
                    $_POST['rental_id'] ?? ''
                );
                $toast_message = $message;
                $toast_type = $success ? 'success' : 'error';
            }
            break;
            
        case 'deposit_txn':
            if (!check_login() || !check_admin()) {
                $toast_message = '관리자만 접근 가능합니다.';
                $toast_type = 'error';
            } else {
                list($success, $message) = insert_deposit_txn($db, [
                    'member_id' => $_POST['member_id'] ?? '',
                    'item_id' => $_POST['item_id'] ?? '',
                    'amount' => $_POST['amount'] ?? '',
                    'reason' => $_POST['reason'] ?? ''
                ]);
                $toast_message = $message;
                $toast_type = $success ? 'success' : 'error';
            }
            break;
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
        /* ===== 기본 스타일 ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: #f5f6fa;
            color: #2c3e50;
            line-height: 1.6;
        }
        
        /* ===== 레이아웃 ===== */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* ===== 헤더 ===== */
        .header {
            background: white;
            padding: 25px 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title h1 {
            font-size: 24px;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .header-title p {
            font-size: 14px;
            color: #7f8c8d;
        }
        
        .user-info {
            background: #f8f9fa;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            color: #495057;
        }
        
        .user-info .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            margin-left: 8px;
            font-weight: 600;
        }
        
        .badge-admin {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .badge-user {
            background: #e3f2fd;
            color: #1565c0;
        }
        
        /* ===== 네비게이션 ===== */
        .nav {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .nav-group {
            margin-bottom: 15px;
        }
        
        .nav-group:last-child {
            margin-bottom: 0;
        }
        
        .nav-group-title {
            font-size: 12px;
            color: #7f8c8d;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .nav-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .nav-buttons button {
            flex: 1;
            min-width: 140px;
            padding: 10px 16px;
            background: white;
            color: #495057;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .nav-buttons button:hover {
            background: #f8f9fa;
            border-color: #adb5bd;
            transform: translateY(-1px);
        }
        
        .nav-buttons button.active {
            background: #495057;
            color: white;
            border-color: #495057;
        }
        
        .nav-buttons button.admin-btn {
            border-color: #28a745;
            color: #28a745;
        }
        
        .nav-buttons button.admin-btn:hover {
            background: #28a745;
            color: white;
        }
        
        .nav-buttons button.logout-btn {
            border-color: #dc3545;
            color: #dc3545;
        }
        
        .nav-buttons button.logout-btn:hover {
            background: #dc3545;
            color: white;
        }
        
        /* ===== 콘텐츠 영역 ===== */
        .content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            min-height: 500px;
        }
        
        .section {
            display: none;
            animation: fadeIn 0.3s ease-in;
        }
        
        .section.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .section-title {
            font-size: 20px;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f8f9fa;
        }
        
        /* ===== 폼 스타일 ===== */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #495057;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #495057;
        }
        
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #6c757d;
            font-size: 12px;
        }
        
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #495057;
            color: white;
        }
        
        .btn-primary:hover {
            background: #343a40;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        /* ===== 정보 박스 ===== */
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #6c757d;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .info-box-primary {
            background: #e7f3ff;
            border-left-color: #2196F3;
        }
        
        /* ===== 테이블 스타일 ===== */
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        table th {
            background: #f8f9fa;
            color: #495057;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        
        table td {
            padding: 12px;
            border-bottom: 1px solid #f1f3f5;
        }
        
        table tr:hover {
            background: #f8f9fa;
        }
        
        table tr:last-child td {
            border-bottom: none;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        .table-footer {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #dee2e6;
            color: #6c757d;
            font-size: 14px;
        }
        
        /* ===== 토스트 알림 ===== */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 16px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: none;
            z-index: 9999;
            max-width: 400px;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .toast.show {
            display: block;
        }
        
        .toast.success {
            border-left: 4px solid #28a745;
        }
        
        .toast.error {
            border-left: 4px solid #dc3545;
        }
        
        .toast-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .toast-icon {
            font-size: 20px;
        }
        
        .toast-message {
            flex: 1;
            font-size: 14px;
            color: #2c3e50;
        }
        
        /* ===== 모달 ===== */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 8px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease-out;
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 20px;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .modal-body {
            margin-bottom: 20px;
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        /* ===== 반응형 ===== */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .nav-buttons button {
                min-width: 100%;
            }
            
            .container {
                padding: 10px;
            }
            
            .content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- 토스트 알림 -->
    <div id="toast" class="toast">
        <div class="toast-content">
            <span class="toast-icon"></span>
            <div class="toast-message"></div>
        </div>
    </div>

    <!-- 로그아웃 모달 -->
    <div id="logoutModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">로그아웃</h3>
            </div>
            <div class="modal-body">
                <p>정말 로그아웃 하시겠습니까?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('logoutModal')">취소</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="btn btn-danger">로그아웃</button>
                </form>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- 헤더 -->
        <div class="header">
            <div class="header-title">
                <h1>비품 대여 시스템</h1>
                <p>PHP & MySQL 기반 비품 관리 프로그램</p>
            </div>
            <?php if (check_login()): ?>
                <div class="user-info">
                    <?php echo htmlspecialchars($_SESSION['user_name']); ?>님
                    <span class="badge <?php echo check_admin() ? 'badge-admin' : 'badge-user'; ?>">
                        <?php echo check_admin() ? '관리자' : '일반회원'; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!check_login()): ?>
            <!-- 로그인 전 네비게이션 -->
            <div class="nav">
                <div class="nav-buttons">
                    <button onclick="showSection('login')">로그인</button>
                    <button onclick="showSection('register_member')">회원가입</button>
                </div>
            </div>
        <?php else: ?>
            <!-- 로그인 후 네비게이션 -->
            <div class="nav">
                <div class="nav-group">
                    <div class="nav-group-title">일반 기능</div>
                    <div class="nav-buttons">
                        <button onclick="showSection('available_items')">대여 가능 비품</button>
                        <button onclick="showSection('rent_item')">비품 대여</button>
                        <button onclick="showSection('my_rentals')">내 대여중 비품</button>
                        <button onclick="showSection('return_item')">비품 반납</button>
                        <button onclick="showSection('my_rental_list')">내 대여 내역</button>
                    </div>
                </div>
                
                <?php if (check_admin()): ?>
                    <div class="nav-group">
                        <div class="nav-group-title">관리자 기능</div>
                        <div class="nav-buttons">
                            <button onclick="showSection('register_item')" class="admin-btn">비품 등록</button>
                            <button onclick="showSection('admin_rental_list')" class="admin-btn">전체 대여 내역</button>
                            <button onclick="showSection('member_list')" class="admin-btn">회원 목록</button>
                            <button onclick="showSection('deposit_txn')" class="admin-btn">보증금 거래 입력</button>
                            <button onclick="showSection('deposit_history')" class="admin-btn">보증금 거래 조회</button>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="nav-group">
                    <div class="nav-buttons">
                        <button onclick="openModal('logoutModal')" class="logout-btn">로그아웃</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 콘텐츠 영역 -->
        <div class="content">
            <?php if (!check_login()): ?>
                <!-- 로그인 폼 -->
                <div id="login" class="section">
                    <h2 class="section-title">로그인</h2>
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
                        <button type="submit" class="btn btn-primary">로그인</button>
                    </form>
                </div>

                <!-- 회원가입 폼 -->
                <div id="register_member" class="section">
                    <h2 class="section-title">회원가입</h2>
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
                            <label>비밀번호</label>
                            <input type="password" name="password" required minlength="4">
                            <small>최소 4자 이상 입력해주세요</small>
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
                            <small>관리자 인증코드: *smwu*</small>
                        </div>
                        <button type="submit" class="btn btn-primary">회원가입</button>
                    </form>
                </div>
            <?php else: ?>
                <!-- 대여 가능 비품 목록 -->
                <div id="available_items" class="section">
                    <h2 class="section-title">대여 가능한 비품 목록</h2>
                    <?php
                    $db = get_db();
                    $sql = "SELECT item_id, category, serial_no, deposit_required
                            FROM item
                            WHERE status = 'AVAILABLE'
                            ORDER BY category, item_id";
                    
                    $result = $db->query($sql);
                    
                    if ($result && $result->num_rows > 0) {
                        echo "<div class='table-container'>";
                        echo "<table>";
                        echo "<thead><tr>
                                <th>ID</th>
                                <th>카테고리</th>
                                <th>고유번호</th>
                                <th>보증금</th>
                              </tr></thead><tbody>";
                        
                        while ($row = $result->fetch_assoc()) {
                            $category_kr = get_category_kr($row['category']);
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($row['item_id']) . "</td>";
                            echo "<td>" . $category_kr . "</td>";
                            echo "<td>" . htmlspecialchars($row['serial_no']) . "</td>";
                            echo "<td>" . number_format($row['deposit_required']) . "원</td>";
                            echo "</tr>";
                        }
                        echo "</tbody></table></div>";
                    } else {
                        echo "<div class='empty-state'>현재 대여 가능한 비품이 없습니다.</div>";
                    }
                    
                    $db->close();
                    ?>
                </div>

                <!-- 비품 대여 -->
                <div id="rent_item" class="section">
                    <h2 class="section-title">비품 대여</h2>
                    <div class="info-box info-box-primary">
                        <strong>안내사항</strong><br>
                        • 대여 가능 비품 목록에서 비품 ID를 확인하세요<br>
                        • 반납 기한은 대여일로부터 3일입니다<br>
                        • 보증금은 공지된 계좌로 입금해주세요
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="rent_item">
                        <div class="form-group">
                            <label>대여할 비품 ID</label>
                            <input type="number" name="item_id" required>
                        </div>
                        <button type="submit" class="btn btn-primary">대여하기</button>
                    </form>
                </div>

                <!-- 내 대여중인 비품 -->
                <div id="my_rentals" class="section">
                    <h2 class="section-title">내 대여중인 비품</h2>
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
                        echo "<div class='table-container'>";
                        echo "<table>";
                        echo "<thead><tr>
                                <th>대여ID</th>
                                <th>카테고리</th>
                                <th>고유번호</th>
                                <th>대여일</th>
                                <th>반납기한</th>
                                <th>상태</th>
                              </tr></thead><tbody>";
                        
                        while ($row = $result->fetch_assoc()) {
                            $category_kr = get_category_kr($row['category']);
                            $overdue = $row['overdue_days'];
                            $status = ($overdue <= 0) ? "정상" : "연체 {$overdue}일";
                            
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($row['rental_id']) . "</td>";
                            echo "<td>" . $category_kr . "</td>";
                            echo "<td>" . htmlspecialchars($row['serial_no']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['rented_on']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['due_on']) . "</td>";
                            echo "<td>" . $status . "</td>";
                            echo "</tr>";
                        }
                        echo "</tbody></table></div>";
                    } else {
                        echo "<div class='empty-state'>현재 대여중인 비품이 없습니다.</div>";
                    }
                    
                    $stmt->close();
                    $db->close();
                    ?>
                </div>

                <!-- 비품 반납 -->
                <div id="return_item" class="section">
                    <h2 class="section-title">비품 반납</h2>
                    <div class="info-box info-box-primary">
                        <strong>환급 정책</strong><br>
                        • 기한 내(3일): 전액 환급<br>
                        • 4일차: 2,000원 페널티<br>
                        • 5일차 이후: 환급 없음
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="return_item">
                        <div class="form-group">
                            <label>반납할 대여 ID</label>
                            <input type="number" name="rental_id" required>
                            <small>내 대여중 비품에서 대여 ID를 확인하세요</small>
                        </div>
                        <button type="submit" class="btn btn-primary">반납하기</button>
                    </form>
                </div>

                <!-- 내 대여 내역 -->
                <div id="my_rental_list" class="section">
                    <h2 class="section-title">내 대여 내역 조회</h2>
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
                        echo "<div class='table-container'>";
                        echo "<table>";
                        echo "<thead><tr>
                                <th>대여ID</th>
                                <th>카테고리</th>
                                <th>고유번호</th>
                                <th>대여일</th>
                                <th>반납기한</th>
                                <th>반납일</th>
                                <th>상태</th>
                              </tr></thead><tbody>";
                        
                        while ($row = $result->fetch_assoc()) {
                            $category_kr = get_category_kr($row['category']);
                            
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
                        echo "</tbody></table>";
                        echo "<div class='table-footer'>총 " . $result->num_rows . "건의 대여 내역</div>";
                        echo "</div>";
                    } else {
                        echo "<div class='empty-state'>대여 내역이 없습니다.</div>";
                    }
                    
                    $stmt->close();
                    $db->close();
                    ?>
                </div>

                <?php if (check_admin()): ?>
                    <!-- 비품 등록 (관리자 전용) -->
                    <div id="register_item" class="section">
                        <h2 class="section-title">비품 등록 [관리자 전용]</h2>
                        <div class="info-box">
                            <strong>카테고리별 보증금</strong><br>
                            • UMBRELLA (우산): <?php echo number_format(DEPOSIT_UMBRELLA); ?>원<br>
                            • BATTERY (보조배터리): <?php echo number_format(DEPOSIT_BATTERY); ?>원
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
                            <button type="submit" class="btn btn-primary">등록하기</button>
                        </form>
                    </div>

                    <!-- 전체 대여 내역 (관리자 전용) -->
                    <div id="admin_rental_list" class="section">
                        <h2 class="section-title">전체 대여 내역 조회 [관리자 전용]</h2>
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
                            echo "<div class='table-container'>";
                            echo "<table>";
                            echo "<thead><tr>
                                    <th>ID</th>
                                    <th>회원명</th>
                                    <th>학번</th>
                                    <th>카테고리</th>
                                    <th>대여일</th>
                                    <th>반납기한</th>
                                    <th>반납일</th>
                                    <th>상태</th>
                                  </tr></thead><tbody>";
                            
                            while ($row = $result->fetch_assoc()) {
                                $category_kr = get_category_kr($row['category']);
                                
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
                            echo "</tbody></table>";
                            echo "<div class='table-footer'>최근 " . $result->num_rows . "건의 대여 내역 (최대 50건)</div>";
                            echo "</div>";
                        } else {
                            echo "<div class='empty-state'>대여 내역이 없습니다.</div>";
                        }
                        
                        $db->close();
                        ?>
                    </div>

                    <!-- 회원 목록 (관리자 전용) -->
                    <div id="member_list" class="section">
                        <h2 class="section-title">회원 목록 조회 [관리자 전용]</h2>
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
                            echo "<div class='table-container'>";
                            echo "<table>";
                            echo "<thead><tr>
                                    <th>ID</th>
                                    <th>학번</th>
                                    <th>이름</th>
                                    <th>전화번호</th>
                                    <th>계좌번호</th>
                                    <th>권한</th>
                                    <th>대여중</th>
                                  </tr></thead><tbody>";
                            
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
                            echo "</tbody></table>";
                            echo "<div class='table-footer'>총 " . $result->num_rows . "명의 회원</div>";
                            echo "</div>";
                        } else {
                            echo "<div class='empty-state'>등록된 회원이 없습니다.</div>";
                        }
                        
                        $db->close();
                        ?>
                    </div>

                    <!-- 보증금 거래 입력 (관리자 전용) -->
                    <div id="deposit_txn" class="section">
                        <h2 class="section-title">보증금 거래 입력 [관리자 전용]</h2>
                        <div class="info-box">
                            <strong>거래 유형</strong><br>
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
                                <small>입금은 양수(+), 환급은 음수(-)</small>
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
                            <button type="submit" class="btn btn-primary">입력하기</button>
                        </form>
                    </div>

                    <!-- 보증금 거래 조회 (관리자 전용) -->
                    <div id="deposit_history" class="section">
                        <h2 class="section-title">보증금 거래 조회 [관리자 전용]</h2>
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
                            echo "<div class='table-container'>";
                            echo "<table>";
                            echo "<thead><tr>
                                    <th>ID</th>
                                    <th>회원명</th>
                                    <th>학번</th>
                                    <th>비품</th>
                                    <th>거래구분</th>
                                    <th>금액</th>
                                    <th>날짜</th>
                                  </tr></thead><tbody>";
                            
                            $reason_map = [
                                'INIT' => '초기입금',
                                'DEPOSIT' => '보증금입금',
                                'REFUND' => '보증금환급'
                            ];
                            
                            while ($row = $result->fetch_assoc()) {
                                $category_kr = get_category_kr($row['category']);
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
                            echo "</tbody></table>";
                            echo "<div class='table-footer'>최근 " . $result->num_rows . "건의 거래 내역 (최대 50건)</div>";
                            echo "</div>";
                        } else {
                            echo "<div class='empty-state'>거래 내역이 없습니다.</div>";
                        }
                        
                        $db->close();
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ===== 섹션 표시 함수 =====
        function showSection(sectionId) {
            const sections = document.querySelectorAll('.section');
            sections.forEach(section => section.classList.remove('active'));
            
            const targetSection = document.getElementById(sectionId);
            if (targetSection) {
                targetSection.classList.add('active');
            }
            
            // 네비게이션 버튼 활성화 상태 업데이트
            const buttons = document.querySelectorAll('.nav-buttons button');
            buttons.forEach(btn => btn.classList.remove('active'));
            
            const activeButton = Array.from(buttons).find(btn => 
                btn.getAttribute('onclick') === `showSection('${sectionId}')`
            );
            if (activeButton) {
                activeButton.classList.add('active');
            }
        }

        // ===== 관리자 코드 토글 =====
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

        // ===== 토스트 알림 =====
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = toast.querySelector('.toast-message');
            const toastIcon = toast.querySelector('.toast-icon');
            
            toastMessage.textContent = message;
            toast.className = 'toast show ' + type;
            toastIcon.textContent = type === 'success' ? '✓' : '✕';
            
            setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }

        // ===== 모달 관리 =====
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('show');
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('show');
            }
        }

        // 모달 외부 클릭 시 닫기
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }

        // ===== 페이지 로드 시 초기화 =====
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (check_login()): ?>
                showSection('available_items');
            <?php else: ?>
                showSection('login');
            <?php endif; ?>
            
            // 토스트 메시지 표시
            <?php if (!empty($toast_message)): ?>
                showToast(<?php echo json_encode($toast_message); ?>, <?php echo json_encode($toast_type); ?>);
            <?php endif; ?>
        });
    </script>
</body>
</html>
