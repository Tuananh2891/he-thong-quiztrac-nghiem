SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_text VARCHAR(500) NOT NULL,
  correct_option CHAR(1) NOT NULL
);
CREATE TABLE IF NOT EXISTS question_options (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  option_key CHAR(1) NOT NULL,
  option_text VARCHAR(255) NOT NULL,
  CONSTRAINT fk_option_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS quiz_results (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  score INT NOT NULL,
  total_questions INT NOT NULL,
  created_at DATETIME NOT NULL
);
INSERT INTO questions (question_text, correct_option) VALUES
('HTML được sử dụng chủ yếu để làm gì?', 'B'),
('SQL dùng để làm gì?', 'A'),
('Docker thường được dùng để làm gì?', 'C'),
('Nginx có thể đảm nhiệm vai trò nào?', 'B'),
('Prometheus chủ yếu dùng để làm gì?', 'A');
INSERT INTO question_options (question_id, option_key, option_text) VALUES
(1,'A','Lưu trữ dữ liệu quan hệ'),(1,'B','Xây dựng cấu trúc nội dung trang web'),(1,'C','Giám sát container'),(1,'D','Mã hóa ổ đĩa'),
(2,'A','Truy vấn và quản lý dữ liệu trong cơ sở dữ liệu'),(2,'B','Thiết kế ảnh'),(2,'C','Tạo container'),(2,'D','Định tuyến mạng Wi-Fi'),
(3,'A','Chỉ để viết tài liệu'),(3,'B','Chỉ để chỉnh sửa ảnh'),(3,'C','Đóng gói và chạy ứng dụng trong container'),(3,'D','Thay thế mọi cơ sở dữ liệu'),
(4,'A','Chỉ là trình soạn thảo'),(4,'B','Web server và reverse proxy'),(4,'C','Công cụ tạo slide'),(4,'D','Hệ điều hành'),
(5,'A','Thu thập và lưu trữ metrics chuỗi thời gian'),(5,'B','Quản lý mật khẩu người dùng'),(5,'C','Biên dịch PHP'),(5,'D','Tạo biểu mẫu HTML');
