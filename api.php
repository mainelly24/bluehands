<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

/* ---------- helpers ---------- */
class Fail extends Exception {}
function fail(string $m, int $c = 400) { throw new Fail($m, $c); }
function out($d, int $c = 200): never { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function db(): PDO {
  static $p;
  return $p ??= new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}
function q(string $sql, array $a = []): PDOStatement { $s = db()->prepare($sql); $s->execute($a); return $s; }
function atomic(callable $f) {
  $d = db(); $d->beginTransaction();
  try { $r = $f(); $d->commit(); return $r; } catch (Throwable $e) { $d->rollBack(); throw $e; }
}
function phone($v): string {
  $p = preg_replace('/[\s\-]/', '', (string)$v);
  if (!preg_match('/^\+?\d{9,13}$/', $p)) fail('Enter a valid phone number, like +2376XXXXXXXX.');
  return $p;
}
function amt($v, int $min = 500, int $max = 5000000): int {
  if (!is_numeric($v)) fail('Enter a valid amount.');
  $n = (int)round((float)$v);
  if ($n < $min) fail('The minimum is ' . number_format($min) . ' FCFA.');
  if ($n > $max) fail('The maximum is ' . number_format($max) . ' FCFA.');
  return $n;
}
function user_out(int $id): array {
  $u = q('SELECT id,name,phone,role,balance,(bio_token_hash IS NOT NULL) AS bio FROM users WHERE id=?', [$id])->fetch();
  if (!$u) { unset($_SESSION['uid']); fail('Not signed in.', 401); }
  return ['id' => (int)$u['id'], 'name' => $u['name'], 'phone' => $u['phone'], 'role' => $u['role'], 'balance' => (int)$u['balance'], 'bio' => (bool)$u['bio']];
}
function need(?string $role = null): array {
  if (empty($_SESSION['uid'])) fail('Please log in first.', 401);
  $u = user_out((int)$_SESSION['uid']);
  if ($role && $u['role'] !== $role) fail($role === 'employer' ? 'This needs an employer account.' : 'This needs a worker account.', 403);
  return $u;
}
function login_as(int $id): array { session_regenerate_id(true); $_SESSION['uid'] = $id; return user_out($id); }
function add_tx(int $uid, string $text, int $amount): void { q('INSERT INTO tx (user_id,text,amount) VALUES (?,?,?)', [$uid, $text, $amount]); }

function save_video(): ?string {
  if (empty($_FILES['video']) || $_FILES['video']['error'] === UPLOAD_ERR_NO_FILE) return null;
  $f = $_FILES['video'];
  if ($f['error'] !== UPLOAD_ERR_OK) fail('The video upload failed. Try a smaller file.');
  if ($f['size'] > MAX_VIDEO_MB * 1048576) fail('The video is too large. The limit is ' . MAX_VIDEO_MB . ' MB.', 413);
  $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  $ext = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'][$mime] ?? null;
  if (!$ext) fail('Use an MP4, WebM or MOV video.');
  $dir = __DIR__ . '/uploads';
  if (!is_dir($dir)) mkdir($dir, 0755, true);
  $name = bin2hex(random_bytes(16)) . '.' . $ext;
  if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail('Could not save the video.', 500);
  return "uploads/$name";
}

function interview(array $in): array {
  if (!ANTHROPIC_API_KEY) fail('The AI interview is not set up yet.', 503);
  need(); // only logged-in people can spend AI credit
  $role = ($in['role'] ?? '') === 'employer' ? 'employer' : 'worker';
  $trade = mb_substr(trim((string)($in['trade'] ?? '')), 0, 60);
  $msgs = [];
  foreach (array_slice((array)($in['messages'] ?? []), -20) as $m) {
    if (!is_array($m)) continue;
    $r = $m['role'] ?? ''; $c = mb_substr(trim((string)($m['content'] ?? '')), 0, 2000);
    if (($r === 'user' || $r === 'assistant') && $c !== '') $msgs[] = ['role' => $r, 'content' => $c];
  }
  while ($msgs && $msgs[0]['role'] !== 'user') array_shift($msgs);
  if (!$msgs) $msgs = [['role' => 'user', 'content' => 'Hello, please start the interview.']];

  $sys = $role === 'worker'
    ? "You interview tradespeople for BlueHands, a marketplace in Cameroon. Trade: " . ($trade ?: 'not given yet') . ". Ask ONE short question at a time about their trade, years of experience, certificates, tools, typical jobs, area and prices in FCFA. After 6 to 8 answers, write a friendly 3 to 4 sentence worker profile and stop asking questions. Use plain, simple words. Reply in the language the person writes in (English or French)."
    : "You help employers on BlueHands, a marketplace in Cameroon, write a clear job brief. Job type: " . ($trade ?: 'not given yet') . ". Ask ONE short question at a time about the work needed, location, timing, materials and budget in FCFA. After 5 to 7 answers, write a short job brief and stop asking questions. Use plain, simple words. Reply in the language the person writes in (English or French).";

  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
    CURLOPT_POSTFIELDS => json_encode(['model' => ANTHROPIC_MODEL, 'max_tokens' => 400, 'system' => $sys, 'messages' => $msgs])]);
  $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $data = json_decode((string)$res, true);
  $text = $data['content'][0]['text'] ?? null;
  if ($code !== 200 || !$text) { error_log("Interview API error $code: $res"); fail('The interviewer is busy. Try again in a moment.', 502); }
  return ['reply' => $text];
}

/* ---------- router ---------- */
$a = $_GET['a'] ?? '';
try {
  $reads = ['me', 'workers', 'wallet'];
  $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
  if (!in_array($a, $reads, true) && !$isPost) fail('Method not allowed.', 405);
  // A custom header cannot be sent cross-site without a CORS preflight, which blocks forged requests.
  if ($isPost && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'bh') fail('Bad request.', 403);
  if ($isPost && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES && !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json'))
    fail('That upload is too large. Choose a smaller video.', 413);
  $in = $_POST ?: (json_decode(file_get_contents('php://input') ?: '', true) ?: []);

  switch ($a) {
    case 'me': out(need());

    case 'workers':
      $rows = q("SELECT u.id,u.name,u.trade,u.area,u.category,u.detail,u.video_path AS video,
                 (SELECT COUNT(*) FROM vouches v WHERE v.worker_id=u.id) AS vouches
                 FROM users u WHERE u.role='worker' ORDER BY vouches DESC, u.id DESC LIMIT 200")->fetchAll();
      foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['vouches'] = (int)$r['vouches']; $r['category'] = $r['category'] ?: 'exp'; }
      out($rows);

    case 'signup':
      $name = trim((string)($in['name'] ?? '')); $role = $in['role'] ?? '';
      if (mb_strlen($name) < 2 || mb_strlen($name) > 80) fail('Enter your full name.');
      $ph = phone($in['phone'] ?? '');
      $pw = (string)($in['password'] ?? '');
      if (strlen($pw) < 8) fail('Your password needs at least 8 characters.');
      if (strlen($pw) > 72) fail('Your password is too long. Use 72 characters or fewer.');
      if (!in_array($role, ['worker', 'employer'], true)) fail('Choose worker or employer.');
      $trade = $area = $cat = $detail = $video = null;
      if ($role === 'worker') {
        $trade = mb_substr(trim((string)($in['trade'] ?? '')), 0, 60);
        $area = mb_substr(trim((string)($in['area'] ?? '')), 0, 60);
        if ($trade === '' || $area === '') fail('Add your trade and your area so people can find you.');
        $cat = ($in['category'] ?? '') === 'cert' ? 'cert' : 'exp';
        $detail = mb_substr(trim((string)($in['detail'] ?? '')), 0, 80) ?: null;
        $video = save_video();
      }
      try {
        q('INSERT INTO users (name,phone,password_hash,role,trade,area,category,detail,video_path) VALUES (?,?,?,?,?,?,?,?,?)',
          [$name, $ph, password_hash($pw, PASSWORD_DEFAULT), $role, $trade, $area, $cat, $detail, $video]);
      } catch (PDOException $e) {
        if ($video) @unlink(__DIR__ . '/' . $video);
        if ($e->getCode() === '23000') fail('That phone number already has an account. Log in instead.', 409);
        throw $e;
      }
      out(login_as((int)db()->lastInsertId()));

    case 'login':
      $ph = phone($in['phone'] ?? '');
      $r = q('SELECT id,password_hash,failed_logins,(locked_until > NOW()) AS locked FROM users WHERE phone=?', [$ph])->fetch();
      if ($r && $r['locked']) fail('Too many attempts. Try again in 10 minutes.', 429);
      if (!$r || !password_verify((string)($in['password'] ?? ''), $r['password_hash'])) {
        if ($r) {
          $n = (int)$r['failed_logins'] + 1;
          if ($n >= 5) q('UPDATE users SET failed_logins=0, locked_until=DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id=?', [$r['id']]);
          else q('UPDATE users SET failed_logins=? WHERE id=?', [$n, $r['id']]);
        }
        fail('Wrong phone number or password.', 401);
      }
      q('UPDATE users SET failed_logins=0, locked_until=NULL WHERE id=?', [$r['id']]);
      out(login_as((int)$r['id']));

    case 'logout':
      $_SESSION = []; session_destroy(); out(['ok' => true]);

    case 'bio_enrol':
      $u = need(); $token = bin2hex(random_bytes(32));
      q('UPDATE users SET bio_token_hash=? WHERE id=?', [hash('sha256', $token), $u['id']]);
      out(['phone' => $u['phone'], 'token' => $token]);

    case 'bio_off':
      $u = need(); q('UPDATE users SET bio_token_hash=NULL WHERE id=?', [$u['id']]); out(['ok' => true]);

    case 'bio_login':
      $ph = phone($in['phone'] ?? '');
      $r = q('SELECT id,bio_token_hash FROM users WHERE phone=?', [$ph])->fetch();
      if (!$r || !$r['bio_token_hash'] || !hash_equals($r['bio_token_hash'], hash('sha256', (string)($in['token'] ?? ''))))
        fail('Biometric sign-in expired. Log in with your password.', 401);
      out(login_as((int)$r['id']));

    case 'wallet':
      $u = need();
      $col = $u['role'] === 'employer' ? 'j.employer_id' : 'j.worker_id';
      $jobs = q("SELECT j.id,j.title,j.amount,j.status,w.name AS worker_name,e.name AS employer_name
                 FROM jobs j JOIN users w ON w.id=j.worker_id JOIN users e ON e.id=j.employer_id
                 WHERE $col=? ORDER BY j.id DESC LIMIT 50", [$u['id']])->fetchAll();
      foreach ($jobs as &$j) { $j['id'] = (int)$j['id']; $j['amount'] = (int)$j['amount']; }
      $tx = q('SELECT text,amount FROM tx WHERE user_id=? ORDER BY id DESC LIMIT 20', [$u['id']])->fetchAll();
      foreach ($tx as &$t) $t['amount'] = (int)$t['amount'];
      out(['user' => $u, 'jobs' => $jobs, 'tx' => $tx]);

    case 'topup':
      $u = need('employer'); $n = amt($in['amount'] ?? 0);
      if (!in_array($in['method'] ?? '', ['MTN Mobile Money', 'Orange Money', 'Bank card'], true)) fail('Choose a payment method.');
      // DEMO: credit instantly. Replace with your mobile money provider's confirmed callback.
      atomic(function () use ($u, $n, $in) {
        q('UPDATE users SET balance=balance+? WHERE id=?', [$n, $u['id']]);
        add_tx($u['id'], 'Added via ' . $in['method'], $n);
      });
      out(['ok' => true]);

    case 'withdraw':
      $u = need('worker'); $n = amt($in['amount'] ?? 0);
      if (!in_array($in['method'] ?? '', ['MTN Mobile Money', 'Orange Money'], true)) fail('Choose a payout method.');
      // DEMO: debit instantly. Replace with a real payout API call and only debit on success.
      atomic(function () use ($u, $n, $in) {
        if (q('UPDATE users SET balance=balance-? WHERE id=? AND balance>=?', [$n, $u['id'], $n])->rowCount() !== 1) fail('Not enough balance.');
        add_tx($u['id'], 'Withdrawn to ' . $in['method'], -$n);
      });
      out(['ok' => true]);

    case 'hire':
      $u = need('employer'); $n = amt($in['amount'] ?? 0);
      $title = trim((string)($in['title'] ?? ''));
      if (mb_strlen($title) < 3 || mb_strlen($title) > 120) fail('Describe the job in 3 to 120 characters.');
      $w = q("SELECT id,name FROM users WHERE id=? AND role='worker'", [(int)($in['worker_id'] ?? 0)])->fetch();
      if (!$w) fail('That worker was not found.', 404);
      atomic(function () use ($u, $w, $n, $title) {
        if (q('UPDATE users SET balance=balance-? WHERE id=? AND balance>=?', [$n, $u['id'], $n])->rowCount() !== 1)
          fail('Not enough balance. Add money in your wallet first.');
        q('INSERT INTO jobs (employer_id,worker_id,title,amount) VALUES (?,?,?,?)', [$u['id'], $w['id'], $title, $n]);
        add_tx($u['id'], "Escrow funded: $title", -$n);
      });
      out(['ok' => true]);

    case 'release':
    case 'refund':
      $u = need('employer');
      atomic(function () use ($u, $a, $in) {
        $j = q('SELECT * FROM jobs WHERE id=? FOR UPDATE', [(int)($in['job_id'] ?? 0)])->fetch();
        if (!$j || (int)$j['employer_id'] !== $u['id']) fail('Job not found.', 404);
        if ($j['status'] !== 'held') fail('This job is already settled.');
        $n = (int)$j['amount'];
        if ($a === 'release') {
          $fee = intdiv($n * FEE_PERCENT, 100);
          q("UPDATE jobs SET status='paid', settled_at=NOW() WHERE id=?", [$j['id']]);
          q('UPDATE users SET balance=balance+? WHERE id=?', [$n - $fee, $j['worker_id']]);
          add_tx((int)$j['worker_id'], 'Paid for: ' . $j['title'] . ' (fee ' . number_format($fee) . ' FCFA)', $n - $fee);
        } else {
          q("UPDATE jobs SET status='refunded', settled_at=NOW() WHERE id=?", [$j['id']]);
          q('UPDATE users SET balance=balance+? WHERE id=?', [$n, $u['id']]);
          add_tx($u['id'], 'Refund: ' . $j['title'], $n);
        }
      });
      out(['ok' => true]);

    case 'vouch': // not used by the page yet; lets an employer vouch after paying a worker
      $u = need('employer'); $wid = (int)($in['worker_id'] ?? 0);
      if (!q("SELECT 1 FROM jobs WHERE employer_id=? AND worker_id=? AND status='paid' LIMIT 1", [$u['id'], $wid])->fetch())
        fail('You can vouch for a worker after paying them for a job.', 403);
      q('INSERT IGNORE INTO vouches (worker_id,voucher_id) VALUES (?,?)', [$wid, $u['id']]);
      out(['ok' => true]);

    case 'interview': out(interview($in));

    default: fail('Unknown request.', 404);
  }
} catch (Fail $e) {
  out(['error' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
  error_log($e);
  out(['error' => 'Server error'], 500);
}