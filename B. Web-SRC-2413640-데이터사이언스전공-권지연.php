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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏢 비품 대여 시스템</h1>
            <p>PHP & MySQL 기반 비품 관리 프로그램</p>
        </div>

        <div class="nav">
            <button onclick="showSection('register_member')">회원 등록</button>
            <button onclick="showSection('register_item')">비품 등록</button>
            <button onclick="showSection('rent_item')">비품 대여</button>
            <button onclick="showSection('return_item')">비품 반납</button>
            <button onclick="showSection('rental_list')">대여 내역 조회</button>
            <button onclick="showSection('deposit_txn')">보증금 거래 입력</button>
            <button onclick="showSection('deposit_history')">보증금 거래 조회</button>
        </div>

        <div class="content">
            <?php
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

            // 메시지 출력 함수
            function show_message($message, $type = 'success') {
                echo "<div class='message $type'>$message</div>";
            }

            // POST 요청 처리
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $action = $_POST['action'] ?? '';
                $db = get_db();

                // (1) 회원 등록
                if ($action === 'register_member') {
                    $student_no = $_POST['student_no'];
                    $name = $_POST['name'];
                    $phone = $_POST['phone'];
                    $password_hash = $_POST['password_hash'];
                    $bank_account = $_POST['bank_account'];

                    $sql = "INSERT INTO member(student_no, name, phone, password_hash, bank_account, is_admin) 
                            VALUES (?, ?, ?, ?, ?, 0)";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param("sssss", $student_no, $name, $phone, $password_hash, $bank_account);
                    
                    if ($stmt->execute()) {
                        show_message("✅ 회원 등록 성공!");
                    } else {
                        show_message("❌ SQL 오류: " . $stmt->error, 'error');
                    }
                    $stmt->close();
                }

                // (2) 비품 등록
                elseif ($action === 'register_item') {
                    $category = $_POST['category'];
                    $serial_no = $_POST['serial_no'];
                    $deposit = $_POST['deposit'];

                    $sql = "INSERT INTO item(category, serial_no, status, deposit_required) 
                            VALUES (?, ?, 'AVAILABLE', ?)";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param("ssd", $category, $serial_no, $deposit);
                    
                    if ($stmt->execute()) {
                        show_message("✅ 비품 등록 성공!");
                    } else {
                        show_message("❌ SQL 오류: " . $stmt->error, 'error');
                    }
                    $stmt->close();
                }

                // (3) 비품 대여
                elseif ($action === 'rent_item') {
                    $member_id = $_POST['member_id'];
                    $item_id = $_POST['item_id'];

                    // 트랜잭션 시작
                    $db->begin_transaction();

                    try {
                        // rental insert
                        $sql1 = "INSERT INTO rental(member_id, item_id, rented_on, due_on) 
                                VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY))";
                        $stmt1 = $db->prepare($sql1);
                        $stmt1->bind_param("ii", $member_id, $item_id);
                        $stmt1->execute();
                        $stmt1->close();

                        // item 상태 변경
                        $sql2 = "UPDATE item SET status='RENTED' WHERE item_id=? AND status='AVAILABLE'";
                        $stmt2 = $db->prepare($sql2);
                        $stmt2->bind_param("i", $item_id);
                        $stmt2->execute();
                        
                        if ($stmt2->affected_rows == 0) {
                            throw new Exception("대여 불가: 비품 상태가 AVAILABLE하지 않음.");
                        }
                        $stmt2->close();

                        $db->commit();
                        show_message("✅ 대여 완료!");
                    } catch (Exception $e) {
                        $db->rollback();
                        show_message("❌ 대여 오류: " . $e->getMessage(), 'error');
                    }
                }

                // (4) 비품 반납
                elseif ($action === 'return_item') {
                    $rental_id = $_POST['rental_id'];

                    $db->begin_transaction();

                    try {
                        // rental 테이블 returned_on 갱신
                        $sql1 = "UPDATE rental SET returned_on = NOW() WHERE rental_id = ?";
                        $stmt1 = $db->prepare($sql1);
                        $stmt1->bind_param("i", $rental_id);
                        $stmt1->execute();
                        $stmt1->close();

                        // item 상태 복구
                        $sql2 = "UPDATE item 
                                SET status='AVAILABLE' 
                                WHERE item_id = (SELECT item_id FROM rental WHERE rental_id=?)";
                        $stmt2 = $db->prepare($sql2);
                        $stmt2->bind_param("i", $rental_id);
                        $stmt2->execute();
                        $stmt2->close();

                        $db->commit();
                        show_message("✅ 반납 완료!");
                    } catch (Exception $e) {
                        $db->rollback();
                        show_message("❌ 반납 오류: " . $e->getMessage(), 'error');
                    }
                }

                // (6) 보증금 거래 입력
                elseif ($action === 'deposit_txn') {
                    $member_id = $_POST['member_id'];
                    $item_id = $_POST['item_id'];
                    $amount = $_POST['amount'];
                    $reason = $_POST['reason'];

                    $sql = "INSERT INTO deposit_txn(member_id, item_id, amount, reason, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->bind_param("iids", $member_id, $item_id, $amount, $reason);
                    
                    if ($stmt->execute()) {
                        show_message("✅ 거래 입력 완료!");
                    } else {
                        show_message("❌ 거래 입력 오류: " . $stmt->error, 'error');
                    }
                    $stmt->close();
                }

                $db->close();
            }
            ?>

            <!-- (1) 회원 등록 -->
            <div id="register_member" class="form-section">
                <h2 class="section-title">👤 회원 등록</h2>
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
                        <label>비밀번호 해시</label>
                        <input type="text" name="password_hash" required>
                    </div>
                    <div class="form-group">
                        <label>계좌번호</label>
                        <input type="text" name="bank_account" required>
                    </div>
                    <button type="submit" class="btn-submit">등록하기</button>
                </form>
            </div>

            <!-- (2) 비품 등록 -->
            <div id="register_item" class="form-section">
                <h2 class="section-title">📦 비품 등록</h2>
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
                    <div class="form-group">
                        <label>보증금</label>
                        <input type="number" name="deposit" step="0.01" required>
                    </div>
                    <button type="submit" class="btn-submit">등록하기</button>
                </form>
            </div>

            <!-- (3) 비품 대여 -->
            <div id="rent_item" class="form-section">
                <h2 class="section-title">📤 비품 대여</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="rent_item">
                    <div class="form-group">
                        <label>회원 ID</label>
                        <input type="number" name="member_id" required>
                    </div>
                    <div class="form-group">
                        <label>대여할 비품 ID</label>
                        <input type="number" name="item_id" required>
                    </div>
                    <button type="submit" class="btn-submit">대여하기</button>
                </form>
            </div>

            <!-- (4) 비품 반납 -->
            <div id="return_item" class="form-section">
                <h2 class="section-title">📥 비품 반납</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="return_item">
                    <div class="form-group">
                        <label>반납할 rental_id</label>
                        <input type="number" name="rental_id" required>
                    </div>
                    <button type="submit" class="btn-submit">반납하기</button>
                </form>
            </div>

            <!-- (5) 대여 내역 조회 -->
            <div id="rental_list" class="form-section">
                <h2 class="section-title">📋 전체 대여 내역 조회</h2>
                <?php
                $db = get_db();
                $sql = "SELECT r.rental_id,
                               m.name AS member_name,
                               m.student_no,
                               i.category,
                               i.serial_no,
                               r.rented_on,
                               r.due_on,
                               r.returned_on
                        FROM rental r
                        JOIN member m ON r.member_id = m.member_id
                        JOIN item i ON r.item_id = i.item_id
                        ORDER BY r.rental_id DESC";
                
                $result = $db->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    echo "<table>";
                    echo "<tr>
                            <th>대여ID</th>
                            <th>회원명</th>
                            <th>학번</th>
                            <th>카테고리</th>
                            <th>비품번호</th>
                            <th>대여일</th>
                            <th>반납예정일</th>
                            <th>반납일</th>
                          </tr>";
                    
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row['rental_id']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['member_name']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['student_no']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['category']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['serial_no']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['rented_on']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['due_on']) . "</td>";
                        echo "<td>" . ($row['returned_on'] ? htmlspecialchars($row['returned_on']) : '미반납') . "</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                } else {
                    echo "<p>대여 내역이 없습니다.</p>";
                }
                
                $db->close();
                ?>
            </div>

            <!-- (6) 보증금 거래 입력 -->
            <div id="deposit_txn" class="form-section">
                <h2 class="section-title">💰 보증금 거래 입력</h2>
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
                            <option value="DEPOSIT">DEPOSIT (보증금)</option>
                            <option value="REFUND">REFUND (환불)</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-submit">입력하기</button>
                </form>
            </div>

            <!-- (7) 보증금 거래 조회 -->
            <div id="deposit_history" class="form-section">
                <h2 class="section-title">💳 보증금 거래 조회</h2>
                <?php
                $db = get_db();
                $sql = "SELECT d.deposit_id, m.name, i.serial_no, d.amount, d.reason, d.created_at
                        FROM deposit_txn d
                        JOIN member m ON d.member_id = m.member_id
                        JOIN item i ON d.item_id = i.item_id
                        ORDER BY d.deposit_id DESC";
                
                $result = $db->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    echo "<table>";
                    echo "<tr>
                            <th>거래ID</th>
                            <th>회원명</th>
                            <th>비품번호</th>
                            <th>금액</th>
                            <th>유형</th>
                            <th>거래일시</th>
                          </tr>";
                    
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row['deposit_id']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['serial_no']) . "</td>";
                        echo "<td>" . number_format($row['amount'], 2) . "원</td>";
                        echo "<td>" . htmlspecialchars($row['reason']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                } else {
                    echo "<p>거래 내역이 없습니다.</p>";
                }
                
                $db->close();
                ?>
            </div>
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

        // 페이지 로드 시 첫 번째 섹션 표시
        document.addEventListener('DOMContentLoaded', function() {
            showSection('register_member');
        });
    </script>
</body>
</html>

