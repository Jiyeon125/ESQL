CREATE DATABASE esql_2413640;
USE esql_2413640;

CREATE TABLE `member` (
  `member_id` int PRIMARY KEY AUTO_INCREMENT,
  `student_no` varchar(20) UNIQUE NOT NULL,
  `name` varchar(50) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `bank_account` varchar(255) NOT NULL,
  `is_admin` boolean NOT NULL DEFAULT false,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `item` (
  `item_id` int PRIMARY KEY AUTO_INCREMENT,
  `category` enum('UMBRELLA','BATTERY') NOT NULL,
  `serial_no` varchar(50) UNIQUE,
  `status` enum('AVAILABLE','RENTED','LOST','BROKEN') NOT NULL DEFAULT 'AVAILABLE',
  `deposit_required` int NOT NULL DEFAULT 0
);

CREATE TABLE `rental` (
  `rental_id` int PRIMARY KEY AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `item_id` int NOT NULL,
  `rented_on` datetime NOT NULL,
  `due_on` datetime NOT NULL,
  `returned_on` datetime
);

CREATE TABLE `deposit_txn` (
  `deposit_id` int PRIMARY KEY AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `item_id` int NOT NULL,
  `amount` int NOT NULL,
  `reason` enum('INIT','DEPOSIT','REFUND') NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE `member` COMMENT = '학생(회원) 정보';

ALTER TABLE `item` COMMENT = '비품(우산·보조배터리 등)';

ALTER TABLE `rental` COMMENT = '대여/반납 정보';

ALTER TABLE `deposit_txn` COMMENT = '보증금 입출금 내역';

ALTER TABLE `rental` ADD FOREIGN KEY (`member_id`) REFERENCES `member` (`member_id`);

ALTER TABLE `rental` ADD FOREIGN KEY (`item_id`) REFERENCES `item` (`item_id`);

ALTER TABLE `deposit_txn` ADD FOREIGN KEY (`member_id`) REFERENCES `member` (`member_id`);

ALTER TABLE `deposit_txn` ADD FOREIGN KEY (`item_id`) REFERENCES `item` (`item_id`);

-- 테이블 세팅 완료


