import pymysql
from pymysql import Error
import warnings
import hashlib
warnings.filterwarnings('ignore')


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


# (1) 회원 등록
def register_member():
    db = get_db()
    cursor = db.cursor()

    print("\n[회원 등록]")
    student_no = input("학번 : ")
    name = input("이름 : ")
    phone = input("전화번호 : ")
    password_hash = input("비밀번호 해시 : ")
    bank_account = input("계좌번호 : ")

    sql = """
        INSERT INTO member(student_no, name, phone, password_hash, bank_account, is_admin)
        VALUES (%s, %s, %s, %s, %s, 0)
    """
    try:
        cursor.execute(sql, (student_no, name, phone, password_hash, bank_account))
        print("[회원 등록 성공]\n")
    except Error as e:
        print("[SQL 오류]", e)
    finally:
        db.close()


# (2) 비품 등록
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


# (3) 비품 대여
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


# (4) 비품 반납
def return_item():
    db = get_db()
    cursor = db.cursor()

    print("\n[비품 반납]")
    rental_id = input("반납할 rental_id: ")

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


# (5) 전체 대여 내역 조회 (JOIN)
def rental_list():
    db = get_db()
    cursor = db.cursor()

    print("\n[전체 대여 내역 조회]")

    sql = """
        SELECT r.rental_id,
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
        ORDER BY r.rental_id DESC
    """

    try:
        cursor.execute(sql)
        rows = cursor.fetchall()

        for r in rows:
            print(r)
        print()

    except Error as e:
        print("[조인 조회 오류]", e)

    finally:
        db.close()


# (6) 보증금 거래 입력 (deposit_txn)
def insert_deposit_txn():
    db = get_db()
    cursor = db.cursor()

    print("\n[보증금 거래 내역 입력]")
    member = input("member_id: ")
    item = input("item_id: ")
    amount = input("거래 금액(+/-): ")
    reason = input("거래 유형(INIT / DEPOSIT / REFUND): ")

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


# (7) 보증금 거래 조회
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


# 메인 메뉴
def main():
    while True:
        print("==== Python - 비품 대여 시스템 ====")
        print("1. 회원 등록")
        print("2. 비품 등록")
        print("3. 비품 대여")
        print("4. 비품 반납")
        print("5. 대여 내역 조회 (JOIN)")
        print("6. 보증금 거래 입력")
        print("7. 보증금 거래 조회")
        print("8. 종료\n")

        cmd = input("번호 선택: ")

        if cmd == "1":
            register_member()
        elif cmd == "2":
            register_item()
        elif cmd == "3":
            rent_item()
        elif cmd == "4":
            return_item()
        elif cmd == "5":
            rental_list()
        elif cmd == "6":
            insert_deposit_txn()
        elif cmd == "7":
            deposit_history()
        elif cmd == "8":
            print("프로그램 종료")
            break
        else:
            print("잘못된 번호입니다.\n")


main()
