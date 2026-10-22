<?php
ini_set('session.save_path', '/tmp');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

$host = getenv('DB_HOST') ?: 'db';
$db = getenv('DB_NAME') ?: 'quizdb';
$user = getenv('DB_USER') ?: 'quizuser';
$pass = getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO("mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    error_log('Database connection failed: ' . $e->getMessage(), 3, '/var/log/quiz/app.log');
    http_response_code(503);
    exit('Không thể kết nối cơ sở dữ liệu. Hãy kiểm tra container db và cấu hình .env.');
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function flash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function require_user(): array
{
    if (!isset($_SESSION['user'])) {
        flash('Vui lòng đăng nhập để tiếp tục.', 'error');
        redirect('/?page=login');
    }
    return $_SESSION['user'];
}

function require_admin(): array
{
    $user = require_user();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Bạn không có quyền truy cập chức năng này.');
    }
    return $user;
}

function finish_attempt(PDO $pdo, int $attemptId, array $answers, bool $timedOut): void
{
    $query = $pdo->prepare(
        'SELECT aq.question_id, q.correct_option
         FROM attempt_questions aq JOIN questions q ON q.id = aq.question_id
         WHERE aq.attempt_id = ?'
    );
    $query->execute([$attemptId]);
    $questions = $query->fetchAll();
    $score = 0;
    $save = $pdo->prepare(
        'INSERT INTO attempt_answers (attempt_id, question_id, selected_option)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE selected_option = VALUES(selected_option)'
    );
    foreach ($questions as $question) {
        $questionId = (int) $question['question_id'];
        $selected = $answers[(string) $questionId] ?? null;
        if (!is_string($selected) || !in_array($selected, ['A', 'B', 'C', 'D'], true)) {
            $selected = null;
        }
        if (!$timedOut && $selected !== null) {
            $save->execute([$attemptId, $questionId, $selected]);
            if (hash_equals((string) $question['correct_option'], $selected)) {
                $score++;
            }
        }
    }
    $status = $timedOut ? 'timed_out' : 'submitted';
    $update = $pdo->prepare(
        'UPDATE attempts SET score = ?, total_questions = ?, status = ?, submitted_at = NOW()
         WHERE id = ? AND status = "in_progress"'
    );
    $update->execute([$score, count($questions), $status, $attemptId]);
}

if (isset($_GET['asset']) && $_GET['asset'] === 'timer.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo <<<'JS'
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('#exam-form');
    if (!form) return;
    const storageKey = `quiz-attempt-${form.dataset.attempt}`;
    const timer = document.querySelector('#exam-timer');
    const progress = document.querySelector('#answer-progress');
    let seconds = Number(form.dataset.seconds);
    let submitting = false;
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
        Object.entries(saved).forEach(([id, answer]) => {
            const input = form.querySelector(`input[name="answers[${id}]"][value="${answer}"]`);
            if (input) input.checked = true;
        });
    } catch (_) {}
    const update = () => {
        const total = form.querySelectorAll('[data-question]').length;
        const answered = form.querySelectorAll('input[type="radio"]:checked').length;
        progress.textContent = `${answered}/${total} câu đã trả lời`;
        const answers = {};
        form.querySelectorAll('input[type="radio"]:checked').forEach((input) => {
            const match = input.name.match(/^answers\[(\d+)\]$/);
            if (match) answers[match[1]] = input.value;
        });
        localStorage.setItem(storageKey, JSON.stringify(answers));
    };
    form.addEventListener('change', update);
    form.addEventListener('submit', (event) => {
        if (!submitting && !window.confirm('Nộp bài thi ngay bây giờ?')) {
            event.preventDefault();
            return;
        }
        submitting = true;
        localStorage.removeItem(storageKey);
    });
    update();
    const tick = () => {
        timer.textContent = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(Math.max(0, seconds % 60)).padStart(2, '0')}`;
        timer.classList.toggle('urgent', seconds <= 60);
        if (seconds <= 0) {
            submitting = true;
            form.requestSubmit();
            return;
        }
        seconds--;
        window.setTimeout(tick, 1000);
    };
    tick();
});
JS;
    exit;
}

$page = (string) ($_GET['page'] ?? 'home');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Phiên làm việc đã hết hạn. Hãy tải lại trang và thử lại.');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'register') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $setupKey = (string) ($_POST['setup_key'] ?? '');
        $expectedKey = (string) (getenv('ADMIN_SETUP_KEY') ?: '');
        if ($name === '' || strlen($name) > 300 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            flash('Hãy nhập tên, email hợp lệ và mật khẩu tối thiểu 8 ký tự.', 'error');
            redirect('/?page=register');
        }
        $adminExists = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin"')->fetchColumn() > 0;
        $role = !$adminExists && $expectedKey !== '' && hash_equals($expectedKey, $setupKey) ? 'admin' : 'student';
        try {
            $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
            $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => $role];
            flash($role === 'admin' ? 'Tài khoản quản trị đã sẵn sàng.' : 'Tạo tài khoản thành công.');
            redirect('/');
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                flash('Email này đã được sử dụng.', 'error');
                redirect('/?page=register');
            }
            throw $e;
        }
    }
    if ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $query = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = ?');
        $query->execute([$email]);
        $account = $query->fetch();
        if (!$account || !password_verify((string) ($_POST['password'] ?? ''), (string) $account['password_hash'])) {
            flash('Email hoặc mật khẩu không chính xác.', 'error');
            redirect('/?page=login');
        }
        session_regenerate_id(true);
        unset($account['password_hash']);
        $_SESSION['user'] = $account;
        flash('Đăng nhập thành công.');
        redirect('/');
    }
    if ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Bạn đã đăng xuất.');
        redirect('/');
    }
    if ($action === 'create_quiz') {
        $admin = require_admin();
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $duration = filter_var($_POST['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($title === '' || strlen($title) > 540 || $duration === false || $duration < 1 || $duration > 300) {
            flash('Tên đề thi và thời lượng 1-300 phút là bắt buộc.', 'error');
            redirect('/?page=admin');
        }
        $insert = $pdo->prepare('INSERT INTO quizzes (title, description, duration_minutes, created_by) VALUES (?, ?, ?, ?)');
        $insert->execute([$title, $description, $duration, $admin['id']]);
        flash('Đã tạo đề thi. Thêm câu hỏi rồi xuất bản để thí sinh có thể làm bài.');
        redirect('/?page=admin');
    }
    if ($action === 'add_question') {
        require_admin();
        $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
        $text = trim((string) ($_POST['question_text'] ?? ''));
        $options = $_POST['options'] ?? [];
        $correct = (string) ($_POST['correct_option'] ?? '');
        if (!$quizId || $text === '' || strlen($text) > 1500 || !is_array($options) || !in_array($correct, ['A', 'B', 'C', 'D'], true)) {
            flash('Thông tin câu hỏi chưa hợp lệ.', 'error');
            redirect('/?page=admin');
        }
        foreach (['A', 'B', 'C', 'D'] as $key) {
            if (!isset($options[$key]) || trim((string) $options[$key]) === '' || strlen(trim((string) $options[$key])) > 765) {
                flash('Cần nhập đủ 4 đáp án, mỗi đáp án tối đa 255 ký tự.', 'error');
                redirect('/?page=admin');
            }
        }
        $exists = $pdo->prepare('SELECT id FROM quizzes WHERE id = ?');
        $exists->execute([$quizId]);
        if (!$exists->fetch()) {
            flash('Không tìm thấy đề thi.', 'error');
            redirect('/?page=admin');
        }
        $pdo->beginTransaction();
        try {
            $add = $pdo->prepare('INSERT INTO questions (question_text, correct_option) VALUES (?, ?)');
            $add->execute([$text, $correct]);
            $questionId = (int) $pdo->lastInsertId();
            $addOption = $pdo->prepare('INSERT INTO question_options (question_id, option_key, option_text) VALUES (?, ?, ?)');
            foreach (['A', 'B', 'C', 'D'] as $key) {
                $addOption->execute([$questionId, $key, trim((string) $options[$key])]);
            }
            $positionQuery = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM quiz_questions WHERE quiz_id = ?');
            $positionQuery->execute([$quizId]);
            $link = $pdo->prepare('INSERT INTO quiz_questions (quiz_id, question_id, sort_order) VALUES (?, ?, ?)');
            $link->execute([$quizId, $questionId, (int) $positionQuery->fetchColumn()]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('Đã thêm câu hỏi vào đề thi.');
        redirect('/?page=admin');
    }
    if ($action === 'toggle_quiz') {
        require_admin();
        $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
        $update = $pdo->prepare(
            'UPDATE quizzes q SET is_published = IF(is_published = 1, 0, 1)
             WHERE q.id = ? AND (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) > 0'
        );
        $update->execute([$quizId]);
        $changed = $update->rowCount() > 0;
        flash($changed ? 'Đã cập nhật trạng thái đề thi.' : 'Đề thi cần có ít nhất một câu hỏi trước khi xuất bản.', $changed ? 'success' : 'error');
        redirect('/?page=admin');
    }
    if ($action === 'start_attempt') {
        $student = require_user();
        $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
        $query = $pdo->prepare('SELECT id FROM quizzes WHERE id = ? AND is_published = 1');
        $query->execute([$quizId]);
        if (!$query->fetch()) {
            flash('Đề thi không tồn tại hoặc chưa được xuất bản.', 'error');
            redirect('/');
        }
        $query = $pdo->prepare('SELECT question_id FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order, question_id');
        $query->execute([$quizId]);
        $questionIds = array_map('intval', array_column($query->fetchAll(), 'question_id'));
        if (!$questionIds) {
            flash('Đề thi chưa có câu hỏi.', 'error');
            redirect('/');
        }
        shuffle($questionIds);
        $pdo->beginTransaction();
        $create = $pdo->prepare('INSERT INTO attempts (user_id, quiz_id, started_at, total_questions, status) VALUES (?, ?, NOW(), ?, "in_progress")');
        $create->execute([$student['id'], $quizId, count($questionIds)]);
        $attemptId = (int) $pdo->lastInsertId();
        $add = $pdo->prepare('INSERT INTO attempt_questions (attempt_id, question_id, position) VALUES (?, ?, ?)');
        foreach ($questionIds as $position => $questionId) {
            $add->execute([$attemptId, $questionId, $position + 1]);
        }
        $pdo->commit();
        redirect('/?page=take&id=' . $attemptId);
    }
    if ($action === 'submit_attempt') {
        $student = require_user();
        $attemptId = filter_var($_POST['attempt_id'] ?? null, FILTER_VALIDATE_INT);
        $query = $pdo->prepare('SELECT a.*, q.duration_minutes FROM attempts a JOIN quizzes q ON q.id = a.quiz_id WHERE a.id = ? AND a.user_id = ?');
        $query->execute([$attemptId, $student['id']]);
        $attempt = $query->fetch();
        if (!$attempt) {
            http_response_code(404);
            exit('Không tìm thấy lượt thi.');
        }
        if ($attempt['status'] === 'in_progress') {
            $elapsed = time() - strtotime((string) $attempt['started_at']);
            $timedOut = $elapsed > ((int) $attempt['duration_minutes'] * 60 + 20);
            $answers = $_POST['answers'] ?? [];
            finish_attempt($pdo, (int) $attemptId, is_array($answers) ? $answers : [], $timedOut);
        }
        redirect('/?page=result&id=' . (int) $attemptId);
    }
    http_response_code(400);
    exit('Yêu cầu không hợp lệ.');
}

$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$userSession = $_SESSION['user'] ?? null;
$page = (string) ($_GET['page'] ?? 'home');
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f3ed">
    <title>Trắc nghiệm | <?= h($page) ?></title>
    <style>
        :root{--ink:#172b2a;--muted:#63716e;--paper:#f4f3ed;--surface:#fffefa;--line:#d9dfd8;--green:#176b57;--green-dark:#0d463b;--lime:#d8ef76;--coral:#e66c52;--danger:#a42f25;font-family:ui-sans-serif,system-ui,"Segoe UI",sans-serif;color:var(--ink);background:var(--paper)}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(ellipse at 84% 0%,#e8ebda 0,transparent 38%),var(--paper)}a{color:inherit}button,input,textarea,select{font:inherit}button,.button{border:0;border-radius:4px;background:var(--green);color:#fff;padding:11px 16px;font-weight:700;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px}button:hover,.button:hover{background:var(--green-dark)}button.secondary,.button.secondary{background:transparent;color:var(--green-dark);border:1px solid #aac2b6}.masthead{border-bottom:1px solid var(--line);background:#f8f8f2e8}.nav{max-width:1160px;margin:auto;min-height:72px;padding:12px 24px;display:flex;align-items:center;gap:24px}.brand{font-family:Georgia,"Times New Roman",serif;font-size:22px;font-weight:700;text-decoration:none}.brand-mark{display:inline-grid;place-items:center;width:29px;height:29px;background:var(--lime);border-radius:50%;margin-right:8px;color:var(--green-dark);font-family:ui-sans-serif,sans-serif;font-size:15px}.nav-links{display:flex;gap:20px;align-items:center;margin-left:auto}.nav-links a{text-decoration:none;color:#435650;font-size:14px;font-weight:650}.nav-links a:hover{color:var(--green)}.user-chip{font-size:13px;color:var(--muted);padding-left:16px;border-left:1px solid var(--line)}
        main{max-width:1160px;margin:0 auto;padding:38px 24px 72px}.page-heading{display:flex;justify-content:space-between;align-items:end;gap:24px;margin-bottom:28px}.eyebrow{text-transform:uppercase;letter-spacing:1.3px;font-size:11px;font-weight:800;color:var(--green)}h1,h2,h3,p{margin-top:0}h1,h2{font-family:Georgia,"Times New Roman",serif}h1{font-size:44px;line-height:1.05;margin:6px 0 10px}h2{font-size:25px;margin-bottom:8px}h3{font-size:16px}.muted,.small{color:var(--muted)}.small{font-size:13px}.lead{font-size:16px;color:#4d625c;max-width:640px;line-height:1.6;margin-bottom:0}.layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:42px}.section-head{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:12px}.quiz-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:20px;padding:20px 0;border-bottom:1px solid var(--line)}.quiz-row h3{font-family:Georgia,"Times New Roman",serif;font-size:20px;margin:0 0 6px}.quiz-meta{display:flex;gap:14px;flex-wrap:wrap;color:var(--muted);font-size:13px;margin-top:12px}.pill{display:inline-block;padding:5px 9px;background:#e6eee6;border-radius:20px;color:#285b4d;font-size:12px;font-weight:700}.stats{display:grid;grid-template-columns:repeat(3,1fr);border-block:1px solid var(--line);margin:26px 0}.stat{padding:17px 10px 17px 0}.stat+.stat{border-left:1px solid var(--line);padding-left:20px}.stat strong{display:block;font-family:Georgia,"Times New Roman",serif;font-size:28px}.stat span{font-size:12px;color:var(--muted)}.aside{border-left:1px solid var(--line);padding-left:26px}.aside h2{font-size:20px}.attempt-row{display:flex;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid var(--line);font-size:13px}.attempt-row strong{display:block;margin-bottom:4px}.empty{padding:36px 0;color:var(--muted);border-bottom:1px solid var(--line)}
        .notice{padding:13px 16px;margin:0 0 22px;border-left:4px solid var(--green);background:#e7eee8}.notice.error{border-color:var(--coral);background:#f9e8e1}.form-panel{max-width:620px;background:var(--surface);padding:28px;border:1px solid var(--line);border-radius:5px}.field{display:grid;gap:6px;margin:16px 0}.field label{font-size:13px;font-weight:750}.field input,.field textarea,.field select{width:100%;border:1px solid #c5cec6;border-radius:3px;background:white;padding:11px 12px;color:var(--ink)}.field textarea{min-height:90px;resize:vertical}.field input:focus,.field textarea:focus,.field select:focus{outline:2px solid #92b6a8;outline-offset:1px}.form-actions{display:flex;gap:12px;align-items:center;margin-top:22px}.auth-wrap{max-width:500px;margin:30px auto}.admin-grid{display:grid;grid-template-columns:1fr 1fr;gap:38px}.admin-section{border-top:2px solid var(--green);padding-top:16px}.admin-quiz{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;padding:16px 0;border-bottom:1px solid var(--line);align-items:center}.admin-quiz form{margin:0}.options-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);white-space:nowrap}th{font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted)}
        .exam-top{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:20px}.timer{font-variant-numeric:tabular-nums;background:var(--green-dark);color:white;padding:12px 17px;border-radius:4px;font-size:22px;font-weight:800;min-width:110px;text-align:center}.timer.urgent{background:var(--coral)}.exam-progress{font-size:13px;color:var(--muted)}.question-list{border-top:1px solid var(--line)}.question-block{padding:24px 0;border-bottom:1px solid var(--line)}.question-block h3{font-family:Georgia,"Times New Roman",serif;font-size:20px;line-height:1.4}.answer{display:flex;align-items:flex-start;gap:11px;padding:10px 12px;border:1px solid transparent;border-radius:3px;cursor:pointer}.answer:hover{background:#e9eee7}.answer input{accent-color:var(--green);margin-top:4px}.submit-bar{position:sticky;bottom:0;background:#f4f3edee;border-top:1px solid var(--line);padding:15px 0;display:flex;justify-content:space-between;align-items:center;gap:16px}.score-line{display:flex;align-items:baseline;gap:12px;border-bottom:1px solid var(--line);padding:20px 0}.score-number{font-family:Georgia,"Times New Roman",serif;font-size:52px;color:var(--green);font-weight:700}.review-question{padding:18px 0;border-bottom:1px solid var(--line)}.correct{color:var(--green-dark);font-weight:700}.wrong{color:var(--danger);font-weight:700}footer{max-width:1160px;padding:18px 24px 36px;margin:auto;border-top:1px solid var(--line);color:var(--muted);font-size:12px}
        @media(max-width:800px){.layout{grid-template-columns:1fr;gap:28px}.aside{border-left:0;border-top:1px solid var(--line);padding:22px 0 0}.admin-grid{grid-template-columns:1fr;gap:30px}.nav{gap:12px}.user-chip{display:none}.nav-links{gap:12px}.nav-links a{font-size:12px}.page-heading{align-items:start;flex-direction:column}}@media(max-width:520px){main{padding:26px 18px 52px}h1{font-size:32px}.nav{padding:10px 16px;flex-wrap:wrap}.nav-links{width:100%;margin-left:0;justify-content:space-between;border-top:1px solid var(--line);padding-top:9px}.brand{margin-right:auto}.quiz-row{grid-template-columns:1fr}.quiz-row .button{justify-self:start}.options-grid{grid-template-columns:1fr}.form-panel{padding:20px}.stat+.stat{padding-left:10px}.stat strong{font-size:24px}.exam-top{align-items:flex-start}.score-number{font-size:42px}}
        h1,h2,h3,.brand,.quiz-row h3,.stat strong,.score-number{font-family:"Segoe UI",Tahoma,sans-serif;letter-spacing:0}
    </style>
</head>
<body>
<header class="masthead"><nav class="nav" aria-label="Điều hướng chính">
    <a class="brand" href="/"><span class="brand-mark">Q</span>Trắc nghiệm</a><div class="nav-links"><a href="/">Đề thi</a>
    <?php if ($userSession && $userSession['role'] === 'admin'): ?><a href="/?page=admin">Quản trị</a><a href="/?page=results">Báo cáo</a><?php endif; ?>
    <?php if ($userSession): ?><a href="/#history">Lịch sử</a><span class="user-chip"><?= h($userSession['name']) ?></span><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="secondary" type="submit">Đăng xuất</button></form>
    <?php else: ?><a href="/?page=login">Đăng nhập</a><a class="button" href="/?page=register">Tạo tài khoản</a><?php endif; ?></div>
</nav></header>
<main>
    <?php if ($flashMessage): ?><div class="notice <?= $flashMessage['kind'] === 'error' ? 'error' : '' ?>" role="status"><?= h($flashMessage['message']) ?></div><?php endif; ?>
    <?php if (in_array($page, ['register', 'login'], true)): ?>
        <div class="auth-wrap"><p class="eyebrow">Tài khoản thí sinh</p><h1><?= $page === 'register' ? 'Bắt đầu hành trình.' : 'Chào mừng trở lại.' ?></h1><p class="lead"><?= $page === 'register' ? 'Tạo tài khoản để tham gia kỳ thi và theo dõi kết quả.' : 'Đăng nhập để tiếp tục bài thi và xem lịch sử của bạn.' ?></p>
        <form class="form-panel" method="post" style="margin-top:24px"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $page === 'register' ? 'register' : 'login' ?>">
        <?php if ($page === 'register'): ?><div class="field"><label for="name">Họ và tên</label><input id="name" name="name" maxlength="100" autocomplete="name" required></div><?php endif; ?>
        <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" maxlength="190" autocomplete="email" required></div><div class="field"><label for="password">Mật khẩu</label><input id="password" type="password" name="password" minlength="8" autocomplete="<?= $page === 'register' ? 'new-password' : 'current-password' ?>" required></div>
        <?php if ($page === 'register' && getenv('ADMIN_SETUP_KEY')): ?><div class="field"><label for="setup_key">Mã thiết lập quản trị <span class="muted">(để trống nếu là thí sinh)</span></label><input id="setup_key" type="password" name="setup_key" autocomplete="off"></div><?php endif; ?>
        <div class="form-actions"><button type="submit"><?= $page === 'register' ? 'Tạo tài khoản' : 'Đăng nhập' ?></button><a href="/?page=<?= $page === 'register' ? 'login' : 'register' ?>"><?= $page === 'register' ? 'Đã có tài khoản?' : 'Tạo tài khoản mới' ?></a></div></form></div>
    <?php elseif ($page === 'admin'): ?>
        <?php require_admin(); $quizzes = $pdo->query('SELECT q.*, (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=q.id) AS question_count, (SELECT COUNT(*) FROM attempts WHERE quiz_id=q.id AND status<>"in_progress") AS attempt_count FROM quizzes q ORDER BY q.created_at DESC')->fetchAll(); ?>
        <div class="page-heading"><div><p class="eyebrow">Không gian quản trị</p><h1>Quản lý kỳ thi</h1><p class="lead">Tạo đề, biên soạn câu hỏi, xuất bản và theo dõi kết quả.</p></div><a class="button secondary" href="/?page=results">Báo cáo kết quả</a></div><div class="admin-grid">
        <section class="admin-section"><h2>Đề thi</h2><?php if (!$quizzes): ?><p class="empty">Chưa có đề thi nào. Tạo đề đầu tiên ở biểu mẫu bên cạnh.</p><?php endif; ?><?php foreach ($quizzes as $quiz): ?><article class="admin-quiz"><div><strong><?= h($quiz['title']) ?></strong><div class="quiz-meta"><span><?= (int) $quiz['question_count'] ?> câu</span><span><?= (int) $quiz['duration_minutes'] ?> phút</span><span><?= (int) $quiz['attempt_count'] ?> lượt</span><span class="pill"><?= $quiz['is_published'] ? 'Đã xuất bản' : 'Bản nháp' ?></span></div></div><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_quiz"><input type="hidden" name="quiz_id" value="<?= (int) $quiz['id'] ?>"><button class="secondary" type="submit"><?= $quiz['is_published'] ? 'Ẩn đề' : 'Xuất bản' ?></button></form></article><?php endforeach; ?></section>
        <div><section class="admin-section"><h2>Tạo đề thi</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create_quiz"><div class="field"><label for="title">Tên đề thi</label><input id="title" name="title" maxlength="180" required></div><div class="field"><label for="description">Mô tả</label><textarea id="description" name="description" maxlength="1000"></textarea></div><div class="field"><label for="duration">Thời lượng (phút)</label><input id="duration" type="number" name="duration_minutes" min="1" max="300" value="30" required></div><button type="submit">Tạo đề</button></form></section>
        <section class="admin-section" style="margin-top:36px"><h2>Thêm câu hỏi</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_question"><div class="field"><label for="quiz_id">Đề thi</label><select id="quiz_id" name="quiz_id" required><?php foreach ($quizzes as $quiz): ?><option value="<?= (int) $quiz['id'] ?>"><?= h($quiz['title']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="question_text">Nội dung câu hỏi</label><textarea id="question_text" name="question_text" maxlength="500" required></textarea></div><div class="options-grid"><?php foreach (['A','B','C','D'] as $key): ?><div class="field"><label for="option_<?= $key ?>">Đáp án <?= $key ?></label><input id="option_<?= $key ?>" name="options[<?= $key ?>]" maxlength="255" required></div><?php endforeach; ?></div><div class="field"><label for="correct_option">Đáp án đúng</label><select id="correct_option" name="correct_option"><?php foreach (['A','B','C','D'] as $key): ?><option value="<?= $key ?>"><?= $key ?></option><?php endforeach; ?></select></div><button type="submit">Lưu câu hỏi</button></form></section></div></div>
    <?php elseif ($page === 'results'): ?>
        <?php require_admin(); $results = $pdo->query('SELECT a.id,a.score,a.total_questions,a.status,a.submitted_at,u.name,u.email,q.title FROM attempts a JOIN users u ON u.id=a.user_id JOIN quizzes q ON q.id=a.quiz_id WHERE a.status<>"in_progress" ORDER BY a.submitted_at DESC LIMIT 200')->fetchAll(); ?>
        <div class="page-heading"><div><p class="eyebrow">Báo cáo</p><h1>Kết quả dự thi</h1><p class="lead">Tối đa 200 lượt thi gần nhất.</p></div></div><div class="table-wrap"><table><thead><tr><th>Thí sinh</th><th>Đề thi</th><th>Điểm</th><th>Trạng thái</th><th>Thời gian nộp</th></tr></thead><tbody><?php foreach ($results as $result): ?><tr><td><?= h($result['name']) ?><br><span class="small"><?= h($result['email']) ?></span></td><td><?= h($result['title']) ?></td><td><strong><?= (int) $result['score'] ?>/<?= (int) $result['total_questions'] ?></strong></td><td><?= $result['status'] === 'timed_out' ? 'Hết giờ' : 'Đã nộp' ?></td><td><?= h($result['submitted_at']) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$results): ?><p class="empty">Chưa có kết quả nào.</p><?php endif; ?></div>
    <?php elseif ($page === 'take'): ?>
        <?php
        $student = require_user(); $attemptId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $query = $pdo->prepare('SELECT a.*,q.title,q.duration_minutes FROM attempts a JOIN quizzes q ON q.id=a.quiz_id WHERE a.id=? AND a.user_id=?');
        $query->execute([$attemptId, $student['id']]); $attempt = $query->fetch();
        if (!$attempt) { http_response_code(404); exit('Không tìm thấy lượt thi.'); }
        if ($attempt['status'] !== 'in_progress') { redirect('/?page=result&id=' . (int) $attemptId); }
        $remaining = max(0, (int) $attempt['duration_minutes'] * 60 - max(0, time() - strtotime((string) $attempt['started_at'])));
        if ($remaining === 0) { finish_attempt($pdo, (int) $attemptId, [], true); redirect('/?page=result&id=' . (int) $attemptId); }
        $query = $pdo->prepare('SELECT q.id,q.question_text,aq.position FROM attempt_questions aq JOIN questions q ON q.id=aq.question_id WHERE aq.attempt_id=? ORDER BY aq.position');
        $query->execute([$attemptId]); $examQuestions = $query->fetchAll(); $optionQuery = $pdo->prepare('SELECT option_key,option_text FROM question_options WHERE question_id=? ORDER BY RAND()');
        ?>
        <div class="exam-top"><div><p class="eyebrow">Bài thi đang diễn ra</p><h1><?= h($attempt['title']) ?></h1><p class="exam-progress" id="answer-progress">Đang tải tiến độ</p></div><div><div class="small" style="text-align:center;margin-bottom:5px">Thời gian còn lại</div><div class="timer" id="exam-timer" role="timer">--:--</div></div></div>
        <form id="exam-form" method="post" data-attempt="<?= (int) $attemptId ?>" data-seconds="<?= $remaining ?>"><?= csrf_field() ?><input type="hidden" name="action" value="submit_attempt"><input type="hidden" name="attempt_id" value="<?= (int) $attemptId ?>"><div class="question-list"><?php foreach ($examQuestions as $question): ?><section class="question-block" data-question><h3>Câu <?= (int) $question['position'] ?>. <?= h($question['question_text']) ?></h3><?php $optionQuery->execute([$question['id']]); foreach ($optionQuery->fetchAll() as $option): ?><label class="answer"><input type="radio" name="answers[<?= (int) $question['id'] ?>]" value="<?= h($option['option_key']) ?>"><span><strong><?= h($option['option_key']) ?>.</strong> <?= h($option['option_text']) ?></span></label><?php endforeach; ?></section><?php endforeach; ?></div><div class="submit-bar"><span class="small">Câu trả lời được lưu trên thiết bị này khi bạn chọn.</span><button type="submit">Nộp bài thi</button></div></form><script src="/?asset=timer.js" defer></script>
    <?php elseif ($page === 'result'): ?>
        <?php
        $student = require_user(); $attemptId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $query = $pdo->prepare('SELECT a.*,q.title FROM attempts a JOIN quizzes q ON q.id=a.quiz_id WHERE a.id=? AND a.user_id=?');
        $query->execute([$attemptId, $student['id']]); $attempt = $query->fetch();
        if (!$attempt || $attempt['status'] === 'in_progress') { http_response_code(404); exit('Chưa có kết quả cho lượt thi này.'); }
        $query = $pdo->prepare('SELECT q.question_text,q.correct_option,aq.position,aa.selected_option,correct.option_text AS correct_text,selected.option_text AS selected_text FROM attempt_questions aq JOIN questions q ON q.id=aq.question_id LEFT JOIN attempt_answers aa ON aa.attempt_id=aq.attempt_id AND aa.question_id=aq.question_id LEFT JOIN question_options correct ON correct.question_id=q.id AND correct.option_key=q.correct_option LEFT JOIN question_options selected ON selected.question_id=q.id AND selected.option_key=aa.selected_option WHERE aq.attempt_id=? ORDER BY aq.position');
        $query->execute([$attemptId]); $reviews = $query->fetchAll();
        ?>
        <div class="page-heading"><div><p class="eyebrow">Kết quả bài thi</p><h1><?= h($attempt['title']) ?></h1><p class="lead"><?= $attempt['status'] === 'timed_out' ? 'Bài thi đã hết thời gian.' : 'Bài thi đã được chấm tự động.' ?></p></div><a class="button secondary" href="/">Về trang đề thi</a></div><div class="score-line"><span class="score-number"><?= (int) $attempt['score'] ?>/<?= (int) $attempt['total_questions'] ?></span><span><strong>câu trả lời đúng</strong><br><span class="small">Nộp lúc <?= h($attempt['submitted_at']) ?></span></span></div><h2 style="margin-top:30px">Xem lại đáp án</h2>
        <?php foreach ($reviews as $review): ?><section class="review-question"><h3>Câu <?= (int) $review['position'] ?>. <?= h($review['question_text']) ?></h3><p class="<?= $review['selected_option'] === $review['correct_option'] ? 'correct' : 'wrong' ?>">Bạn chọn: <?= $review['selected_option'] ? h($review['selected_option'] . '. ' . $review['selected_text']) : 'Chưa trả lời' ?></p><p class="correct">Đáp án đúng: <?= h($review['correct_option'] . '. ' . $review['correct_text']) ?></p></section><?php endforeach; ?>
    <?php else: ?>
        <?php
        $published = $pdo->query('SELECT q.id,q.title,q.description,q.duration_minutes,(SELECT COUNT(*) FROM quiz_questions WHERE quiz_id=q.id) AS question_count FROM quizzes q WHERE q.is_published=1 ORDER BY q.created_at DESC')->fetchAll();
        $attempts = []; $stats = ['count' => 0, 'average' => 0, 'passed' => 0];
        if ($userSession) {
            $query = $pdo->prepare('SELECT a.id,a.score,a.total_questions,a.submitted_at,q.title FROM attempts a JOIN quizzes q ON q.id=a.quiz_id WHERE a.user_id=? AND a.status<>"in_progress" ORDER BY a.submitted_at DESC LIMIT 10');
            $query->execute([$userSession['id']]); $attempts = $query->fetchAll();
            $query = $pdo->prepare('SELECT COUNT(*) AS count,COALESCE(AVG(score*100.0/NULLIF(total_questions,0)),0) AS average,COALESCE(SUM(score*2>=total_questions),0) AS passed FROM attempts WHERE user_id=? AND status<>"in_progress"');
            $query->execute([$userSession['id']]); $stats = $query->fetch();
        }
        ?>
        <div class="page-heading"><div><p class="eyebrow">Không gian học tập</p><h1><?= $userSession ? 'Chọn thử thách tiếp theo.' : 'Mỗi câu trả lời là một bước tiến.' ?></h1><p class="lead">Tham gia đề thi, làm bài trong thời gian quy định và xem lại kết quả ngay sau khi nộp.</p></div><?php if ($userSession && $userSession['role'] === 'admin'): ?><a class="button" href="/?page=admin">Mở quản trị</a><?php endif; ?></div>
        <?php if ($userSession): ?><div class="stats"><div class="stat"><strong><?= (int) $stats['count'] ?></strong><span>Lượt thi hoàn thành</span></div><div class="stat"><strong><?= number_format((float) $stats['average'], 0) ?>%</strong><span>Điểm trung bình</span></div><div class="stat"><strong><?= (int) $stats['passed'] ?></strong><span>Lượt đạt từ 50%</span></div></div><?php endif; ?>
        <div class="layout"><section><div class="section-head"><h2>Đề thi đang mở</h2><span class="small"><?= count($published) ?> đề</span></div><?php if (!$published): ?><p class="empty">Chưa có đề thi được xuất bản.</p><?php endif; ?>
        <?php foreach ($published as $quiz): ?><article class="quiz-row"><div><h3><?= h($quiz['title']) ?></h3><p class="muted" style="margin:0"><?= h($quiz['description'] ?: 'Bài kiểm tra trắc nghiệm') ?></p><div class="quiz-meta"><span><?= (int) $quiz['question_count'] ?> câu hỏi</span><span><?= (int) $quiz['duration_minutes'] ?> phút</span><span>Chấm điểm tự động</span></div></div><?php if ($userSession): ?><form method="post" onsubmit="return confirm('Bắt đầu bài thi? Thời gian sẽ tính sau khi xác nhận.')"><?= csrf_field() ?><input type="hidden" name="action" value="start_attempt"><input type="hidden" name="quiz_id" value="<?= (int) $quiz['id'] ?>"><button type="submit">Bắt đầu thi <span aria-hidden="true">→</span></button></form><?php else: ?><a class="button" href="/?page=login">Đăng nhập để thi</a><?php endif; ?></article><?php endforeach; ?></section>
        <aside class="aside" id="history"><h2>Lịch sử gần đây</h2><?php if (!$userSession): ?><p class="small">Đăng nhập để tham gia thi và lưu kết quả của bạn.</p><a href="/?page=register">Tạo tài khoản →</a><?php elseif (!$attempts): ?><p class="small">Các bài đã hoàn thành sẽ xuất hiện ở đây.</p><?php else: ?><?php foreach ($attempts as $attempt): ?><a class="attempt-row" href="/?page=result&id=<?= (int) $attempt['id'] ?>" style="text-decoration:none"><span><strong><?= h($attempt['title']) ?></strong><?= h($attempt['submitted_at']) ?></span><span class="pill"><?= (int) $attempt['score'] ?>/<?= (int) $attempt['total_questions'] ?></span></a><?php endforeach; ?><?php endif; ?></aside></div>
    <?php endif; ?>
</main><footer>Hệ thống thi trắc nghiệm · PHP · MySQL</footer>
</body>
</html>
