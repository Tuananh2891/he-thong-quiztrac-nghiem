# Đề 35 — Quiz System

Website thi trắc nghiệm PHP + MySQL, triển khai bằng Docker Compose; Nginx reverse proxy, phpMyAdmin, Prometheus, Grafana, cAdvisor, exporters, Loki và Promtail.

## Yêu cầu
- Windows 10/11, Docker Desktop chạy ở chế độ Linux containers
- Git
- OpenSSL (đi kèm Git for Windows thường có tại `C:\Program Files\Git\usr\bin\openssl.exe`)

## Khởi chạy
1. Sao chép `.env.example` thành `.env`.
2. Thay tất cả mật khẩu `REPLACE_...` bằng mật khẩu mạnh, riêng biệt.
3. Tạo chứng chỉ tự ký:
   ```powershell
   New-Item -ItemType Directory -Force nginx/certs
   openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout nginx/certs/localhost.key -out nginx/certs/localhost.crt -subj "/CN=localhost"
   ```
4. Kiểm tra cấu hình và khởi chạy:
   ```powershell
   docker compose config
   docker compose up -d --build
   docker compose ps
   ```
Lưu ý: MySQL chỉ chạy các script khởi tạo khi volume dữ liệu còn mới. Nếu đã khởi động DB trước khi thêm/sửa script init, hãy tự tạo user exporter hoặc chỉ khi chấp nhận mất dữ liệu mẫu mới dùng `docker compose down -v` rồi chạy lại.
5. Truy cập:
   - Website: http://localhost:8080
   - HTTPS tự ký: https://localhost:8443 (trình duyệt sẽ cảnh báo vì chứng chỉ tự ký)
   - phpMyAdmin: http://localhost:8081
   - Prometheus: http://localhost:9090
   - Grafana: http://localhost:3000
   - Loki API: http://localhost:3100/ready

## Kiểm tra
- Prometheus → Status → Targets: các target có trạng thái UP.
- Grafana: đăng nhập bằng `GRAFANA_USER` / `GRAFANA_PASSWORD`; datasource Prometheus và Loki được provision tự động.
- Grafana → Explore → chọn Loki:
  ```logql
  {container="nginx"}
  {container="nginx"} |= "POST"
  {container="quiz-app"}
  ```
  Log app chỉ xuất hiện sau khi ứng dụng ghi log; Nginx logs được ghi vào volume dùng chung.
- Tạo một kết quả thi trên website rồi kiểm tra bảng `quiz_results` trong phpMyAdmin.

## Lệnh hữu ích
```powershell
docker compose logs -f nginx app db
docker compose restart app
docker compose down
docker compose down -v
```
**Cảnh báo:** `docker compose down -v` sẽ xóa dữ liệu MySQL và dữ liệu giám sát trong các named volume.

## Lưu ý về hardening
- PHP-FPM chạy bằng user không phải root.
- App và DB nằm trên các network nội bộ; DB không publish port ra host.
- phpMyAdmin, Prometheus, Grafana và Loki chỉ bind vào loopback (127.0.0.1).
- Container app dùng read-only root filesystem, `cap_drop: ALL`, `no-new-privileges`.
- Nginx có security headers và TLS tự ký.
- Đây là bộ khung học tập. cAdvisor cần quyền đọc một số thông tin host nên được cấu hình riêng và là ngoại lệ bảo mật cần giải thích trong báo cáo. Không triển khai trực tiếp lên Internet nếu chưa rà soát cấu hình, phiên bản image, mật khẩu và quyền truy cập.
