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

    student_no = input("학번 : ")
    name = input("이름 : ")
    phone = input("전화번호 : ")
    raw_pw = input("비밀번호 : ")
    pw_hash = hash_password(raw_pw)
    bank_account = input("환급 계좌번호 : ")

    # 관리자 여부 확인
    is_admin = 0
    while True:
        admin_yn = input("관리자 계정입니까? (Y/N) : ").strip().upper()

        if admin_yn == "Y":
            code = input("관리자 인증코드 입력 : ")
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

    sql = """
        INSERT INTO member(student_no, name, phone, password_hash, bank_account, is_admin)
        VALUES (%s, %s, %s, %s, %s, %s)
    """
    cursor.execute(sql, (student_no, name, phone, pw_hash, bank_account, is_admin))

    print("\n[회원 등록 완료]\n")
    db.close()


# 비품 등록
def register_item():
    db = get_db()
    cursor = db.cursor()

    print("\n[비품 등록]")
    category = input("카테고리(UMBRELLA / BATTERY) : ")
    serial_no = input("비품 고유번호(serial_no) : ")
    deposit = input("보증금 : ")

    sql = """
        INSERT INTO item(category, serial_no, status, deposit_required)
        VALUES (%s, %s, 'AVAILABLE', %s)
    """
    try:
        cursor.execute(sql, (category, serial_no, deposit))
        print("[비품 등록 성공]\n")
    except Error as e:
        print("[SQL 오류]", e)
    finally:
        db.close()


# 비품 대여
def rent_item():
    db = get_db()
    cursor = db.cursor()

    print("\n[비품 대여]")
    member = input("회원 ID : ")
    item = input("대여할 비품 ID : ")

    try:
        # rental insert
        sql1 = """
            INSERT INTO rental(member_id, item_id, rented_on, due_on)
            VALUES (%s, %s, NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY))
        """
        cursor.execute(sql1, (member, item))

        # item 상태 변경
        sql2 = "UPDATE item SET status='RENTED' WHERE item_id=%s AND status='AVAILABLE'"
        updated = cursor.execute(sql2, (item,))
        if updated == 0:
            raise Exception("대여 불가 : 비품 상태가 AVAILABLE하지 않음.")

        print("[대여 완료]\n")

    except Exception as e:
        print("[대여 오류]", e)

    finally:
        db.close()


# 비품 반납
def return_item():
    db = get_db()
    cursor = db.cursor()

    print("\n[비품 반납]")
    rental_id = input("반납할 rental_id : ")

    try:
        # rental 테이블 returned_on 갱신
        sql = """
            UPDATE rental
            SET returned_on = NOW()
            WHERE rental_id = %s
        """
        cursor.execute(sql, rental_id)

        # 해당 rental의 item_id 찾아서 status 복구
        sql2 = """
            UPDATE item
            SET status='AVAILABLE'
            WHERE item_id = (SELECT item_id FROM rental WHERE rental_id=%s)
        """
        cursor.execute(sql2, rental_id)

        print("[반납 완료]\n")

    except Error as e:
        print("[반납 오류]", e)

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
            i.status,
            r.rented_on,
            r.due_on,
            r.returned_on
        FROM rental r
        JOIN item i ON r.item_id = i.item_id
        WHERE r.member_id = %s
        ORDER BY r.rental_id DESC
    """

    try:
        cursor.execute(sql, (user_id,))
        rows = cursor.fetchall()

        for r in rows:
            print(r)
        print()

    except Error as e:
        print("[조회 오류]", e)

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
            i.status,
            r.rented_on,
            r.due_on,
            r.returned_on
        FROM rental r
        JOIN member m ON r.member_id = m.member_id
        JOIN item i ON r.item_id = i.item_id
        ORDER BY r.rental_id DESC
    """

    try:
        cursor.execute(sql)
        rows = cursor.fetchall()

        for r in rows:
            print(r)
        print()

    except Error as e:
        print("[관리자 조회 오류]", e)

    finally:
        db.close()


# 사용자 목록 조회
def member_list():
    db = get_db()
    cursor = db.cursor()

    print("\n[회원 목록 조회 - 관리자 전용]")

    sql = """
        SELECT member_id, student_no, name, phone, bank_account, is_admin
        FROM member
        ORDER BY member_id DESC
    """

    try:
        cursor.execute(sql)
        rows = cursor.fetchall()

        for r in rows:
            print(r)
        print()

    except Error as e:
        print("[회원 조회 오류]", e)

    finally:
        db.close()


# 보증금 거래 입력
def insert_deposit_txn():
    db = get_db()
    cursor = db.cursor()

    print("\n[보증금 거래 내역 입력]")
    member = input("member_id : ")
    item = input("item_id : ")
    amount = input("거래 금액(+/-) : ")
    reason = input("거래 유형(INIT / DEPOSIT / REFUND) : ")

    sql = """
        INSERT INTO deposit_txn(member_id, item_id, amount, reason, created_at)
        VALUES (%s, %s, %s, %s, NOW())
    """

    try:
        cursor.execute(sql, (member, item, amount, reason))
        print("[거래 입력 완료]\n")
    except Error as e:
        print("[거래 입력 오류]", e)
    finally:
        db.close()


# 보증금 거래 조회
def deposit_history():
    db = get_db()
    cursor = db.cursor()

    print("\n[보증금 거래 조회]")

    sql = """
        SELECT d.deposit_id, m.name, i.serial_no, d.amount, d.reason, d.created_at
        FROM deposit_txn d
        JOIN member m ON d.member_id = m.member_id
        JOIN item i ON d.item_id = i.item_id
        ORDER BY d.deposit_id DESC
    """

    try:
        cursor.execute(sql)
        rows = cursor.fetchall()

        for r in rows:
            print(r)
        print()

    except Error as e:
        print("[조회 오류]", e)

    finally:
        db.close()


# 로그인
def login():
    db = get_db()
    cursor = db.cursor()

    print("\n--- 로그인 ---")
    student_no = input("학번: ")
    pw = input("비밀번호: ")
    pw_hash = hash_password(pw)

    sql = "SELECT * FROM member WHERE student_no=%s AND password_hash=%s"
    cursor.execute(sql, (student_no, pw_hash))
    user = cursor.fetchone()

    if not user:
        print("로그인 실패: 학번 또는 비밀번호가 틀렸습니다.\n")
        return None

    print(f"\n환영합니다, {user['name']}님!")
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
        print("\n===== 메뉴 =====")
        print("1. 비품 대여")
        print("2. 비품 반납")
        print("3. 내 대여 내역 조회 (조인)")

        if user['is_admin']:   # 관리자 전용
            print("\n--- 관리자 기능 ---")
            print("A1. 비품 등록")
            print("A2. 전체 대여 내역 조회(관리자)")
            print("A3. 사용자 목록 조회")
            print("A4. 보증금 거래 입력")
            print("A5. 보증금 거래 조회")

        print("0. 종료")

        cmd = input("번호 선택: ").strip().upper()

        if cmd == "1":
            rent_item()
        elif cmd == "2":
            return_item()
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
            print("프로그램을 종료합니다.")
            break
        else:
            print("잘못된 번호 또는 권한이 없습니다.\n")


main()
