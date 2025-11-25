import pymysql
from pymysql import Error
import warnings
import hashlib
warnings.filterwarnings('ignore')

ADMIN_SECRET = "*smwu*"  # 관리자 인증 코드

# 비밀번호 해시 함수
def hash_password(raw_password: str) -> str:
    return hashlib.sha256(raw_password.encode()).hexdigest()

# DB 연결
def get_db():
    try:
        conn = pymysql.connect(
            host="localhost",
            user="root",
            password="0000",
            db="esql_2413640",
            charset="utf8",
            autocommit=True,
            cursorclass=pymysql.cursors.DictCursor
        )
        return conn
    except Error as e:
        print("[DB 연결 오류]", e)
        return None


# 사용자 등록
def register_member():
    db = get_db()
    cursor = db.cursor()

    print("\n--- 회원 등록 ---")

    # 입력 검증
    student_no = input("학번 : ").strip()
    if not student_no:
        print("학번을 입력해주세요.\n")
        db.close()
        return
    
    # 중복 학번 체크
    cursor.execute("SELECT student_no FROM member WHERE student_no=%s", (student_no,))
    if cursor.fetchone():
        print("이미 등록된 학번입니다.\n")
        db.close()
        return
    
    name = input("이름 : ").strip()
    if not name:
        print("이름을 입력해주세요.\n")
        db.close()
        return
    
    phone = input("전화번호 : ").strip()
    if not phone:
        print("전화번호를 입력해주세요.\n")
        db.close()
        return
    
    raw_pw = input("비밀번호 : ").strip()
    if not raw_pw or len(raw_pw) < 4:
        print("비밀번호는 최소 4자 이상이어야 합니다.\n")
        db.close()
        return
    
    pw_hash = hash_password(raw_pw)
    bank_account = input("환급 계좌번호 : ").strip()
    if not bank_account:
        print("환급 계좌번호를 입력해주세요.\n")
        db.close()
        return

    # 관리자 여부 확인
    is_admin = 0
    while True:
        admin_yn = input("관리자 계정입니까? (Y/N) : ").strip().upper()

        if admin_yn == "Y":
            code = input("관리자 인증코드 입력 : ").strip()
            if code == ADMIN_SECRET:
                print("[관리자 권한 승인됨]")
                is_admin = 1
                break
            else:
                print("관리자 코드가 일치하지 않습니다.")
                retry = input("일반 회원으로 등록하시겠습니까? (Y/N) : ").upper()
                if retry == "Y":
                    is_admin = 0
                    break

        elif admin_yn == "N":
            is_admin = 0
            break

        else:
            print("Y 또는 N으로 입력해주세요.")

    try:
        sql = """
            INSERT INTO member(student_no, name, phone, password_hash, bank_account, is_admin)
            VALUES (%s, %s, %s, %s, %s, %s)
        """
        cursor.execute(sql, (student_no, name, phone, pw_hash, bank_account, is_admin))

        print(f"\n[회원 등록 완료]")
        print(f"  이름: {name}")
        print(f"  학번: {student_no}")
        print(f"  권한: {'관리자' if is_admin else '회원'}\n")
        
    except Error as e:
        print(f"[등록 오류] {e}\n")
    finally:
        db.close()


# 비품 등록
def register_item():
    db = get_db()
    cursor = db.cursor()

    print("\n[비품 등록 - 관리자 전용]")
    print("카테고리: UMBRELLA(우산, 보증금 6,000원) / BATTERY(보조배터리, 보증금 8,000원)\n")
    
    category = input("카테고리 : ").strip().upper()
    
    if category not in ['UMBRELLA', 'BATTERY']:
        print("UMBRELLA 또는 BATTERY를 입력해주세요.\n")
        db.close()
        return
    
    serial_no = input("비품 고유번호(serial_no) : ").strip()
    if not serial_no:
        print("고유번호를 입력해주세요.\n")
        db.close()
        return
    
    # 중복 확인
    cursor.execute("SELECT serial_no FROM item WHERE serial_no=%s", (serial_no,))
    if cursor.fetchone():
        print("⚠ 이미 등록된 고유번호입니다.\n")
        db.close()
        return
    
    # 카테고리에 따른 보증금 자동 설정
    deposit = 6000 if category == 'UMBRELLA' else 8000
    category_kr = "우산" if category == 'UMBRELLA' else "보조배터리"

    sql = """
        INSERT INTO item(category, serial_no, status, deposit_required)
        VALUES (%s, %s, 'AVAILABLE', %s)
    """
    try:
        cursor.execute(sql, (category, serial_no, deposit))
        print(f"\n비품 등록 성공")
        print(f"  카테고리 : {category_kr}")
        print(f"  고유번호 : {serial_no}")
        print(f"  보증금 : {deposit:,}원\n")
    except Error as e:
        print(f"[SQL 오류] {e}\n")
    finally:
        db.close()


# 대여 가능한 비품 목록 조회
def show_available_items():
    db = get_db()
    cursor = db.cursor()

    print("\n[대여 가능한 비품 목록]")
    sql = """
        SELECT item_id, category, serial_no, deposit_required
        FROM item
        WHERE status = 'AVAILABLE'
        ORDER BY category, item_id
    """

    try:
        cursor.execute(sql)
        rows = cursor.fetchall()

        if not rows:
            print("현재 대여 가능한 비품이 없습니다.\n")
            return False
        
        print("-" * 70)
        print(f"{'ID':<8}{'카테고리':<20}{'고유번호':<25}{'보증금':<15}")
        print("-" * 70)
        for r in rows:
            category_kr = "우산" if r['category'] == 'UMBRELLA' else "보조배터리"
            print(f"{r['item_id']:<8}{category_kr:<20}{r['serial_no']:<25}{r['deposit_required']:>10,}원")
        print("-" * 70)
        print()
        return True

    except Error as e:
        print(f"[조회 오류] {e}")
        return False

    finally:
        db.close()


# 비품 대여
def rent_item(user_id):
    # 대여 가능한 비품 목록 먼저 표시
    if not show_available_items():
        return

    db = get_db()
    cursor = db.cursor()

    print("[비품 대여]")
    item = input("대여할 비품 ID (취소: 0): ").strip()
    
    if item == "0":
        print("대여를 취소했습니다.\n")
        db.close()
        return

    if not item.isdigit():
        print("올바른 비품 ID를 입력해주세요.\n")
        db.close()
        return

    try:
        # 비품 정보 확인
        sql_check = "SELECT * FROM item WHERE item_id=%s"
        cursor.execute(sql_check, (item,))
        item_info = cursor.fetchone()

        if not item_info:
            print(f"비품 ID {item}을(를) 찾을 수 없습니다.\n")
            return

        if item_info['status'] != 'AVAILABLE':
            print(f"해당 비품은 현재 대여 불가능합니다. (상태: {item_info['status']})\n")
            return

        # rental 레코드 생성
        sql1 = """
            INSERT INTO rental(member_id, item_id, rented_on, due_on)
            VALUES (%s, %s, NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY))
        """
        cursor.execute(sql1, (user_id, item))

        # item 상태 변경
        sql2 = "UPDATE item SET status='RENTED' WHERE item_id=%s"
        cursor.execute(sql2, (item,))

        category_kr = "우산" if item_info['category'] == 'UMBRELLA' else "보조배터리"
        deposit_amount = item_info['deposit_required']

        print(f"\n[대여 완료]")
        print(f"  비품: {category_kr} ({item_info['serial_no']})")
        print(f"  보증금: {deposit_amount:,}원")
        print(f"  반납기한: 대여일로부터 3일 이내")
        print(f"\n   공지된 계좌번호를 통해 보증금 {deposit_amount:,}원을 납부해주세요.")
        print(f"     납부 후 관리자가 거래 내역을 등록합니다.\n")

    except Exception as e:
        print(f"[대여 오류] {e}\n")

    finally:
        db.close()


# 내 대여중인 비품 조회 (반납용)
def show_my_rentals(user_id):
    db = get_db()
    cursor = db.cursor()

    print("\n[내 대여중인 비품]")
    sql = """
        SELECT r.rental_id,
            i.category,
            i.serial_no,
            i.deposit_required,
            r.rented_on,
            r.due_on,
            DATEDIFF(NOW(), r.due_on) AS overdue_days
        FROM rental r
        JOIN item i ON r.item_id = i.item_id
        WHERE r.member_id = %s AND r.returned_on IS NULL
        ORDER BY r.rental_id DESC
    """

    try:
        cursor.execute(sql, (user_id,))
        rows = cursor.fetchall()

        if not rows:
            print("현재 대여중인 비품이 없습니다.\n")
            return False
        
        print("-" * 90)
        print(f"{'대여ID':<8}{'카테고리':<15}{'고유번호':<20}{'대여일':<13}{'반납기한':<13}{'상태':<15}")
        print("-" * 90)
        for r in rows:
            overdue = r['overdue_days']
            category_kr = "우산" if r['category'] == 'UMBRELLA' else "보조배터리"
            
            if overdue <= 0:
                status = "정상"
            elif overdue <= 2:
                status = f"연체 {overdue}일"
            else:
                status = f"연체 {overdue}일"
            
            print(f"{r['rental_id']:<8}{category_kr:<15}{r['serial_no']:<20}{str(r['rented_on'])[:10]:<13}{str(r['due_on'])[:10]:<13}{status:<15}")
        print("-" * 90)
        print()
        return True

    except Error as e:
        print(f"[조회 오류] {e}")
        return False

    finally:
        db.close()


# 연체료 계산 함수
def calculate_refund(category, overdue_days, deposit):
    """
    기한 내(3일): 전액 환급
    4일차: 2,000원 페널티 (우산 4,000원/배터리 6,000원 환급)
    5일차~: 환급 없음
    """
    if overdue_days <= 0:  # 기한 내 반납 (3일 이내)
        return deposit  # 전액 환급
    elif overdue_days == 1:  # 4일차 반납
        return deposit - 2000  # 2,000원 페널티
    else:  # 5일차 이후
        return 0


# 비품 반납
def return_item(user_id):
    # 내 대여중인 비품 목록 먼저 표시
    if not show_my_rentals(user_id):
        return

    db = get_db()
    cursor = db.cursor()

    print("[비품 반납]")
    rental_id = input("반납할 대여 ID (취소: 0): ").strip()

    if rental_id == "0":
        print("반납을 취소했습니다.\n")
        db.close()
        return

    if not rental_id.isdigit():
        print("올바른 대여 ID를 입력해주세요.\n")
        db.close()
        return

    try:
        # 대여 정보 확인
        sql_check = """
            SELECT r.*, i.deposit_required, i.serial_no, i.category,
                   DATEDIFF(NOW(), r.due_on) AS overdue_days
            FROM rental r
            JOIN item i ON r.item_id = i.item_id
            WHERE r.rental_id = %s AND r.member_id = %s
        """
        cursor.execute(sql_check, (rental_id, user_id))
        rental_info = cursor.fetchone()

        if not rental_info:
            print(f"대여 ID {rental_id}을(를) 찾을 수 없거나 권한이 없습니다.\n")
            return

        if rental_info['returned_on']:
            print("이미 반납된 비품입니다.\n")
            return

        # rental 테이블 returned_on 갱신
        sql1 = "UPDATE rental SET returned_on = NOW() WHERE rental_id = %s"
        cursor.execute(sql1, (rental_id,))

        # item 상태 복구
        sql2 = "UPDATE item SET status='AVAILABLE' WHERE item_id = %s"
        cursor.execute(sql2, (rental_info['item_id'],))

        # 환급액 계산
        deposit_amount = rental_info['deposit_required']
        overdue_days = rental_info['overdue_days']
        category = rental_info['category']
        
        refund_amount = calculate_refund(category, overdue_days, deposit_amount)
        penalty_amount = deposit_amount - refund_amount

        category_kr = "우산" if category == 'UMBRELLA' else "보조배터리"

        print(f"\n[반납 완료]")
        print(f"  비품: {category_kr} ({rental_info['serial_no']})")
        print(f"  보증금: {deposit_amount:,}원")
        
        if overdue_days <= 0:
            print(f"  상태: 정상 반납 (기한 내)")
            print(f"  페널티: 없음 (보증금 전액 환급)")
        elif overdue_days == 1:
            print(f"  상태: 4일차 반납 (1일 연체)")
            print(f"  페널티: {penalty_amount:,}원")
        else:
            print(f"  상태: {overdue_days + 3}일차 반납 ({overdue_days}일 연체)")
            print(f"  페널티: {penalty_amount:,}원 (전액 차감)")
        
        print(f"\n  환급액: {refund_amount:,}원")
        
        
        if refund_amount > 0:
            print(f"\n  등록된 계좌로 {refund_amount:,}원이 환급될 예정입니다.")
        else:
            print(f"\n  연체로 인해 환급액이 없습니다.")
        print(f"     관리자가 거래 내역을 등록합니다.\n")

    except Exception as e:
        print(f"[반납 오류] {e}\n")

    finally:
        db.close()


# 일반 사용자용 rental_list() — "내 대여 내역 조회"
def rental_list(user_id):
    db = get_db()
    cursor = db.cursor()

    print("\n[내 대여 내역 조회]")

    sql = """
        SELECT r.rental_id,
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
        WHERE r.member_id = %s
        ORDER BY r.rental_id DESC
    """

    try:
        cursor.execute(sql, (user_id,))
        rows = cursor.fetchall()

        if not rows:
            print("대여 내역이 없습니다.\n")
            return

        print("-" * 100)
        print(f"{'대여ID':<8}{'카테고리':<15}{'고유번호':<20}{'대여일':<13}{'반납기한':<13}{'반납일':<13}{'상태':<15}")
        print("-" * 100)
        
        for r in rows:
            category_kr = "우산" if r['category'] == 'UMBRELLA' else "보조배터리"
            
            if r['returned_on']:
                status = "반납완료"
                returned_str = str(r['returned_on'])[:10]
            elif r['overdue_days'] > 0:
                status = f"연체 {r['overdue_days']}일"
                returned_str = "-"
            else:
                status = "대여중"
                returned_str = "-"
            
            print(f"{r['rental_id']:<8}{category_kr:<15}{r['serial_no']:<20}{str(r['rented_on'])[:10]:<13}{str(r['due_on'])[:10]:<13}{returned_str:<13}{status:<15}")
        
        print("-" * 100)
        print(f"\n총 {len(rows)}건의 대여 내역\n")

    except Error as e:
        print(f"[조회 오류] {e}\n")

    finally:
        db.close()

        
# 관리자용 전체 대여 내역 조회
def admin_rental_list():
    db = get_db()
    cursor = db.cursor()

    print("\n[전체 대여 내역 조회 - 관리자 전용]")

    sql = """
        SELECT r.rental_id,
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
        LIMIT 50
    """

    try:
        cursor.execute(sql)
        rows = cursor.fetchall()

        if not rows:
            print("대여 내역이 없습니다.\n")
            return

        print("-" * 120)
        print(f"{'ID':<6}{'회원명':<10}{'학번':<12}{'카테고리':<13}{'대여일':<13}{'반납기한':<13}{'반납일':<13}{'상태':<15}")
        print("-" * 120)
        
        for r in rows:
            category_kr = "우산" if r['category'] == 'UMBRELLA' else "보조배터리"
            
            if r['returned_on']:
                status = "반납완료"
                returned_str = str(r['returned_on'])[:10]
            elif r['overdue_days'] > 0:
                status = f"연체 {r['overdue_days']}일"
                returned_str = "-"
            else:
                status = "대여중"
                returned_str = "-"
            
            print(f"{r['rental_id']:<6}{r['member_name']:<10}{r['student_no']:<12}{category_kr:<13}{str(r['rented_on'])[:10]:<13}{str(r['due_on'])[:10]:<13}{returned_str:<13}{status:<15}")
        
        print("-" * 120)
        print(f"\n최근 {len(rows)}건의 대여 내역 (최대 50건)\n")

    except Error as e:
        print(f"[관리자 조회 오류] {e}\n")

    finally:
        db.close()


# 사용자 목록 조회
def member_list():
    db = get_db()
    cursor = db.cursor()

    print("\n[회원 목록 조회 - 관리자 전용]")

    sql = """
        SELECT m.member_id, 
               m.student_no, 
               m.name, 
               m.phone, 
               m.bank_account, 
               m.is_admin,
               COUNT(CASE WHEN r.returned_on IS NULL THEN 1 END) AS active_rentals
        FROM member m
        LEFT JOIN rental r ON m.member_id = r.member_id
        GROUP BY m.member_id, m.student_no, m.name, m.phone, m.bank_account, m.is_admin
        ORDER BY m.member_id DESC
    """

    try:
        cursor.execute(sql)
        rows = cursor.fetchall()

        if not rows:
            print("등록된 회원이 없습니다.\n")
            return

        print("-" * 100)
        print(f"{'ID':<6}{'학번':<12}{'이름':<10}{'전화번호':<15}{'계좌번호':<20}{'권한':<10}{'대여중':<10}")
        print("-" * 100)
        
        for r in rows:
            admin_str = "관리자" if r['is_admin'] else "일반"
            rental_str = f"{r['active_rentals']}건" if r['active_rentals'] > 0 else "-"
            print(f"{r['member_id']:<6}{r['student_no']:<12}{r['name']:<10}{r['phone']:<15}{r['bank_account']:<20}{admin_str:<10}{rental_str:<10}")
        
        print("-" * 100)
        print(f"\n총 {len(rows)}명의 회원\n")

    except Error as e:
        print(f"[회원 조회 오류] {e}\n")

    finally:
        db.close()


# 보증금 거래 입력
def insert_deposit_txn():
    db = get_db()
    cursor = db.cursor()

    print("\n[보증금 거래 내역 입력 - 관리자 전용]")
    print("대여/반납 시 담당자가 직접 받은 보증금 거래를 기록해 주세요요.\n")
    
    try:
        # 회원 목록 간단히 표시
        sql_members = """
            SELECT m.member_id, m.name, m.student_no,
                   COUNT(CASE WHEN r.returned_on IS NULL THEN 1 END) AS active_rentals
            FROM member m
            LEFT JOIN rental r ON m.member_id = r.member_id
            GROUP BY m.member_id, m.name, m.student_no
            ORDER BY m.member_id DESC
            LIMIT 20
        """
        cursor.execute(sql_members)
        members = cursor.fetchall()
        
        print("[회원 목록]")
        print("-" * 60)
        print(f"{'ID':<6}{'이름':<10}{'학번':<12}{'대여중':<10}")
        print("-" * 60)
        for m in members:
            rental_str = f"{m['active_rentals']}건" if m['active_rentals'] > 0 else "-"
            print(f"{m['member_id']:<6}{m['name']:<10}{m['student_no']:<12}{rental_str:<10}")
        print("-" * 60)
        
        member = input("\nmember_id : ").strip()
        
        if not member.isdigit():
            print("올바른 회원 ID를 입력해주세요.\n")
            return
        
        # 비품 목록 표시
        sql_items = "SELECT item_id, category, serial_no, status FROM item ORDER BY item_id DESC LIMIT 20"
        cursor.execute(sql_items)
        items = cursor.fetchall()
        
        print("\n[비품 목록]")
        print("-" * 60)
        print(f"{'ID':<6}{'카테고리':<15}{'고유번호':<25}{'상태':<15}")
        print("-" * 60)
        for i in items:
            category_kr = "우산" if i['category'] == 'UMBRELLA' else "보조배터리"
            status_kr = "대여가능" if i['status'] == 'AVAILABLE' else "대여중"
            print(f"{i['item_id']:<6}{category_kr:<15}{i['serial_no']:<25}{status_kr:<15}")
        print("-" * 60)
        
        item = input("\nitem_id : ").strip()
        
        if not item.isdigit():
            print("올바른 비품 ID를 입력해주세요.\n")
            return
        
        print("\n[거래 유형]")
        print("  DEPOSIT  - 보증금 입금 (양수로 입력)")
        print("  REFUND   - 보증금 환급 (음수로 입력)")
        print("  INIT     - 초기 보증금 예산 (양수로 입력)")
        
        reason = input("\n거래 유형 : ").strip().upper()
        
        if reason not in ['DEPOSIT', 'REFUND', 'INIT']:
            print("DEPOSIT, REFUND, INIT 중 하나를 입력해주세요.\n")
            return
        
        amount = input("거래 금액 (예: -6000, +4000) : ").strip()
        
        try:
            amount_int = int(amount)
        except ValueError:
            print("올바른 금액을 입력해주세요.\n")
            return

        sql = """
            INSERT INTO deposit_txn(member_id, item_id, amount, reason, created_at)
            VALUES (%s, %s, %s, %s, NOW())
        """

        cursor.execute(sql, (member, item, amount_int, reason))
        
        action = "차감" if amount_int < 0 else "환급" if reason == 'REFUND' else "입금"
        print(f"\n거래 입력 완료 ({action}: {amount_int:+,}원)\n")
        
    except Error as e:
        print(f"[거래 입력 오류] {e}\n")
    finally:
        db.close()


# 보증금 거래 조회
def deposit_history():
    db = get_db()
    cursor = db.cursor()

    print("\n[보증금 거래 조회 - 관리자 전용]")

    sql = """
        SELECT d.deposit_id, 
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
        LIMIT 50
    """

    try:
        cursor.execute(sql)
        rows = cursor.fetchall()

        if not rows:
            print("거래 내역이 없습니다.\n")
            return

        print("-" * 110)
        print(f"{'ID':<6}{'회원명':<10}{'학번':<12}{'비품':<13}{'거래구분':<12}{'금액':<15}{'날짜':<20}")
        print("-" * 110)
        
        reason_map = {
            'INIT': '초기입금',
            'DEPOSIT': '보증금입금',
            'REFUND': '보증금환급'
        }
        
        for r in rows:
            category_kr = "우산" if r['category'] == 'UMBRELLA' else "보조배터리"
            reason_kr = reason_map.get(r['reason'], r['reason'])
            amount_str = f"{r['amount']:+,}원"
            
            print(f"{r['deposit_id']:<6}{r['name']:<10}{r['student_no']:<12}{category_kr:<13}{reason_kr:<12}{amount_str:<15}{str(r['created_at'])[:19]:<20}")
        
        print("-" * 110)
        print(f"\n최근 {len(rows)}건의 거래 내역 (최대 50건)\n")

    except Error as e:
        print(f"[조회 오류] {e}\n")

    finally:
        db.close()


# 로그인
def login():
    db = get_db()
    cursor = db.cursor()

    print("\n--- 로그인 ---")
    student_no = input("학번: ").strip()
    
    if not student_no:
        print("학번을 입력해주세요.\n")
        db.close()
        return None
    
    pw = input("비밀번호: ").strip()
    
    if not pw:
        print("비밀번호를 입력해주세요.\n")
        db.close()
        return None
    
    pw_hash = hash_password(pw)

    sql = "SELECT * FROM member WHERE student_no=%s AND password_hash=%s"
    cursor.execute(sql, (student_no, pw_hash))
    user = cursor.fetchone()

    db.close()

    if not user:
        print("로그인 실패: 학번 또는 비밀번호가 틀렸습니다.\n")
        return None

    print(f"\n로그인 성공!")
    print(f"  환영합니다, {user['name']}님! {'[관리자]' if user['is_admin'] else '[일반회원]'}\n")
    return user  # is_admin 포함


# 메인 메뉴
def main():
    print("\n---- 비품 대여 시스템 (HLL: Python) ----\n")

    # 첫 화면: 회원가입/로그인 선택
    user = None
    while not user:
        print("\n===== 시작 메뉴 =====")
        print("1. 회원가입")
        print("2. 로그인")
        print("3. 종료")
        
        choice = input("\n선택: ").strip()
        
        if choice == "1":
            register_member()
        elif choice == "2":
            user = login()
        elif choice == "3":
            print("프로그램을 종료합니다.")
            return
        else:
            print("잘못된 선택입니다. 1, 2, 3 중 선택해주세요.\n")
    
    user_id = user['member_id']

    # 로그인 후 메인 메뉴
    while True:
        print("\n" + "="*60)
        print(f"  {user['name']}님 {'[관리자]' if user['is_admin'] else '[일반회원]'}")
        print("="*60)
        print("\n[일반 기능]")
        print("1. 비품 대여")
        print("2. 비품 반납")
        print("3. 내 대여 내역 조회")

        if user['is_admin']:   # 관리자 전용
            print("\n[관리자 기능]")
            print("A1. 비품 등록")
            print("A2. 전체 대여 내역 조회")
            print("A3. 회원 목록 조회")
            print("A4. 보증금 거래 입력")
            print("A5. 보증금 거래 조회")

        print("\n0. 로그아웃")

        cmd = input("\n메뉴 선택: ").strip().upper()

        if cmd == "1":
            rent_item(user_id)
        elif cmd == "2":
            return_item(user_id)
        elif cmd == "3":
            rental_list(user_id)

        # --- 관리자 전용 메뉴 ---
        elif cmd == "A1" and user['is_admin']:
            register_item()
        elif cmd == "A2" and user['is_admin']:
            admin_rental_list()
        elif cmd == "A3" and user['is_admin']:
            member_list()
        elif cmd == "A4" and user['is_admin']:
            insert_deposit_txn()
        elif cmd == "A5" and user['is_admin']:
            deposit_history()

        elif cmd == "0":
            print(f"\n{user['name']}님, 안녕히 가세요!\n")
            break
        else:
            print("\n잘못된 번호이거나 권한이 없습니다.\n")


main()
