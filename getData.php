<?php
// Dynamic gallery data loading.
//   getData.php?action=whoami        -> login state (allowed IPs are signed in automatically)
//   getData.php?action=login         -> POST {user, password}
//   getData.php?action=logout        -> sign out
//   getData.php?action=albums        -> album list
//   getData.php?action=album&id=001  -> album contents (generates missing thumbnails)
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');   // responses must not be cached – data changes on disk
set_time_limit(0);   // thumbnail generation for a large album may exceed the default limit

$ROOT = __DIR__;
$SRC = "$ROOT/gallery";
$THUMBS = "$ROOT/thumbs";
$THUMB_SIZE = 400;
$IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$VID_EXT = ['mp4', 'webm', 'mov', 'm4v'];

// users and allowed-IPs configuration (in production the gallery sits in the web root)
$CONFIG_FILE = __DIR__ . '/config.json';

// short-lived password-reset tokens; kept outside the web root so they can never be
// downloaded, with an install-specific name so two installs cannot collide
$RESET_FILE = sys_get_temp_dir() . '/gallery-reset-' . md5(__DIR__) . '.json';
$RESET_TTL = 3600;   // reset link validity in seconds

$action = $_GET['action'] ?? 'albums';

// ---- authentication (shared logic in authLib.php) ----
require __DIR__ . '/authLib.php';

$config = is_file($CONFIG_FILE) ? json_decode(file_get_contents($CONFIG_FILE), true) : null;
$user = resolveAuthUser($config);
$authenticated = $user !== null;

if ($action === 'whoami') {
    echo json_encode([
        'auth' => $authenticated,
        'user' => $_SESSION['user'] ?? null,
        'title' => $config['title'] ?? null,   // gallery name from config.json
    ]);
    exit;
}

if ($action === 'login') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $u = (string)($body['user'] ?? '');
    $p = (string)($body['password'] ?? '');
    foreach (($config['users'] ?? []) as $usr) {
        if (hash_equals((string)$usr['user'], $u) && hash_equals((string)$usr['password'], $p)) {
            session_regenerate_id(true);
            $_SESSION['user'] = $u;
            echo json_encode(['auth' => true, 'user' => $u]);
            exit;
        }
    }
    http_response_code(401);
    echo json_encode(['auth' => false, 'error' => 'invalid username or password']);
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['auth' => false]);
    exit;
}

// POST {email} – e-mails a password-reset link. The response is always the same
// so it is impossible to probe which e-mail addresses exist.
if ($action === 'resetRequest') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = trim((string)($body['email'] ?? ''));

    $tokens = is_file($RESET_FILE) ? (json_decode(file_get_contents($RESET_FILE), true) ?: []) : [];
    $now = time();
    $tokens = array_filter($tokens, fn($t) => $t['expires'] > $now);   // drop expired tokens

    // at most 5 pending requests per IP – a cheap brake on mail spamming
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $fromIp = count(array_filter($tokens, fn($t) => ($t['ip'] ?? '') === $ip));

    if ($email !== '' && $fromIp < 5) {
        foreach (($config['users'] ?? []) as $usr) {
            if (($usr['email'] ?? '') !== '' && strcasecmp($usr['email'], $email) === 0) {
                $token = bin2hex(random_bytes(32));
                $tokens[$token] = ['user' => $usr['user'], 'expires' => $now + $RESET_TTL, 'ip' => $ip];

                // link back to the gallery, derived from the current request
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $link = $scheme . '://' . $host
                      . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . "/?reset=$token";

                $title = $config['title'] ?? 'Gallery';
                $subject = '=?UTF-8?B?' . base64_encode("$title – password reset") . '?=';
                $message = "Your user name: {$usr['user']}\n\n"
                         . "To set a new password open this link (valid for 1 hour):\n\n$link\n\n"
                         . "If you did not request a password reset, ignore this e-mail.";
                if (!empty($config['smtp']['host'])) {
                    smtpSend($config['smtp'], $email, $subject, $message);
                } else {   // fallback: local MTA via mail()
                    $from = 'gallery@' . preg_replace('/:\d+$/', '', $host);
                    mail($email, $subject, $message,
                        "From: $from\r\nContent-Type: text/plain; charset=utf-8");
                }
                break;
            }
        }
    }

    file_put_contents($RESET_FILE, json_encode($tokens), LOCK_EX);
    echo json_encode(['ok' => true]);
    exit;
}

// POST {token, password} – sets a new password for the user the token belongs to
if ($action === 'resetPassword') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $token = (string)($body['token'] ?? '');
    $password = (string)($body['password'] ?? '');

    $tokens = is_file($RESET_FILE) ? (json_decode(file_get_contents($RESET_FILE), true) ?: []) : [];
    $entry = $tokens[$token] ?? null;
    if ($entry === null || $entry['expires'] < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid or expired link']);
        exit;
    }
    if (strlen($password) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'password too short (min 8 characters)']);
        exit;
    }

    $saved = false;
    foreach (($config['users'] ?? []) as $i => $usr) {
        if ($usr['user'] === $entry['user']) {
            $config['users'][$i]['password'] = $password;
            $saved = file_put_contents($CONFIG_FILE,
                json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                LOCK_EX) !== false;
            break;
        }
    }

    unset($tokens[$token]);   // the token is single-use
    file_put_contents($RESET_FILE, json_encode($tokens), LOCK_EX);

    if (!$saved) {   // user removed from config meanwhile, or config.json is not writable
        http_response_code(500);
        echo json_encode(['error' => 'could not save the new password']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// all other actions require authentication
if (!$authenticated) {
    http_response_code(401);
    echo json_encode(['error' => 'not authenticated']);
    exit;
}

// the session is read-only from here on – release the lock so parallel requests don't wait
session_write_close();

// album media files ordered by .order.json (new files go to the beginning)
function mediaFiles(string $dir, array $imgExt, array $vidExt): array {
    $files = [];
    foreach (scandir($dir) as $f) {
        if ($f[0] === '.' || !is_file("$dir/$f")) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $imgExt) || in_array($ext, $vidExt)) $files[] = $f;
    }
    sort($files);
    $orderFile = "$dir/.order.json";
    if (is_file($orderFile)) {
        $order = json_decode(file_get_contents($orderFile), true);
        if (is_array($order)) {
            $pos = array_flip(array_values($order));
            $known = [];
            $new = [];
            foreach ($files as $f) {
                if (isset($pos[$f])) $known[$pos[$f]] = $f;
                else $new[] = $f;   // new file – automatically goes to the beginning
            }
            ksort($known);
            $files = array_merge($new, array_values($known));
        }
    }
    return $files;
}

if ($action === 'albums') {
    $hasFfmpeg = trim(shell_exec('command -v ffmpeg') ?? '') !== '';
    $albums = [];
    foreach (scandir($SRC) as $d) {
        if ($d[0] === '.' || !is_dir("$SRC/$d")) continue;
        $dir = "$SRC/$d";

        // album title from the .title file, directory name otherwise
        $title = $d;
        if (is_file("$dir/.title")) {
            $t = trim(file_get_contents("$dir/.title"));
            if ($t !== '') $title = $t;
        }

        $files = mediaFiles($dir, $IMG_EXT, $VID_EXT);
        $photos = $videos = 0;
        $cover = null;
        $coverVideo = null;   // fallback: the browser renders the first video frame itself
        foreach ($files as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $isImg = in_array($ext, $IMG_EXT);
            $isImg ? $photos++ : $videos++;
            if ($cover === null) {
                $thumbRel = "thumbs/$d/$f.jpg";
                $thumbAbs = "$ROOT/$thumbRel";
                @mkdir("$THUMBS/$d", 0775, true);
                if ($isImg) {
                    if (!is_file($thumbAbs) || filemtime($thumbAbs) < filemtime("$dir/$f")) {
                        makeImageThumb("$dir/$f", $thumbAbs, $THUMB_SIZE);
                    }
                } elseif ($hasFfmpeg) {
                    if (!is_file($thumbAbs) || filemtime($thumbAbs) < filemtime("$dir/$f")) {
                        makeVideoThumb("$dir/$f", $thumbAbs, $THUMB_SIZE);
                    }
                }
                if (is_file($thumbAbs)) $cover = $thumbRel;
                elseif (!$isImg && $coverVideo === null) $coverVideo = "gallery/$d/$f";
            }
        }

        $album = ['id' => $d, 'title' => $title, 'photos' => $photos, 'videos' => $videos, 'cover' => $cover];
        if ($cover === null && $coverVideo !== null) $album['coverVideo'] = $coverVideo;
        $albums[] = $album;
    }
    echo json_encode(['albums' => $albums]);
    exit;
}

if ($action === 'album') {
    $id = basename($_GET['id'] ?? '');   // basename() prevents escaping the gallery directory
    $dir = "$SRC/$id";
    if ($id === '' || !is_dir($dir)) {
        http_response_code(404);
        echo json_encode(['error' => 'album not found']);
        exit;
    }
    @mkdir("$THUMBS/$id", 0775, true);

    $hasFfmpeg = trim(shell_exec('command -v ffmpeg') ?? '') !== '';

    $items = [];
    foreach (mediaFiles($dir, $IMG_EXT, $VID_EXT) as $f) {
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $src = "$dir/$f";
        $thumbRel = "thumbs/$id/$f.jpg";
        $thumbAbs = "$ROOT/$thumbRel";
        $isImg = in_array($ext, $IMG_EXT);

        // generate the thumbnail only if missing or older than the original
        if ($isImg) {
            if (!is_file($thumbAbs) || filemtime($thumbAbs) < filemtime($src)) {
                makeImageThumb($src, $thumbAbs, $THUMB_SIZE);
            }
        } elseif ($hasFfmpeg && (!is_file($thumbAbs) || filemtime($thumbAbs) < filemtime($src))) {
            makeVideoThumb($src, $thumbAbs, $THUMB_SIZE);
        }

        $item = ['name' => $f, 'type' => $isImg ? 'image' : 'video', 'src' => "gallery/$id/$f"];
        if (is_file($thumbAbs)) $item['thumb'] = $thumbRel;
        $items[] = $item;
    }

    echo json_encode(['id' => $id, 'items' => $items]);
    exit;
}

// POST getData.php?action=saveOrder&id=001  (body: JSON array of file names)
if ($action === 'saveOrder') {
    $id = basename($_GET['id'] ?? '');
    $dir = "$SRC/$id";
    if ($id === '' || !is_dir($dir)) {
        http_response_code(404);
        echo json_encode(['error' => 'album not found']);
        exit;
    }
    $names = json_decode(file_get_contents('php://input'), true);
    if (!is_array($names)) {
        http_response_code(400);
        echo json_encode(['error' => 'expected a JSON array of file names']);
        exit;
    }
    // keep only names of files that actually exist in the album (no paths)
    $names = array_values(array_filter($names,
        fn($n) => is_string($n) && basename($n) === $n && is_file("$dir/$n")));
    file_put_contents("$dir/.order.json",
        json_encode($names, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo json_encode(['ok' => true, 'count' => count($names)]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);

// Minimal SMTP client (implicit TLS + AUTH LOGIN) – works with Gmail using an
// app password; the "smtp" section of config.json provides host/port/user/password/from.
function smtpSend(array $smtp, string $to, string $subject, string $body): bool {
    $host = (string)($smtp['host'] ?? '');
    $port = (int)($smtp['port'] ?? 465);
    $from = (string)($smtp['from'] ?? $smtp['user'] ?? '');
    $fp = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 15);
    if (!$fp) return false;
    stream_set_timeout($fp, 15);

    // reads a (possibly multi-line) reply and checks the status code
    $expect = function (string $code) use ($fp): bool {
        do { $line = fgets($fp, 1024); } while ($line !== false && isset($line[3]) && $line[3] === '-');
        return $line !== false && str_starts_with($line, $code);
    };
    $send = function (string $cmd, string $code) use ($fp, $expect): bool {
        fwrite($fp, $cmd . "\r\n");
        return $expect($code);
    };

    $body = preg_replace('/\r?\n/', "\r\n", $body);
    $body = str_replace("\r\n.", "\r\n..", $body);   // SMTP dot-stuffing

    $ok = $expect('220')
        && $send('EHLO gallery', '250')
        && $send('AUTH LOGIN', '334')
        && $send(base64_encode((string)($smtp['user'] ?? '')), '334')
        && $send(base64_encode((string)($smtp['password'] ?? '')), '235')
        && $send("MAIL FROM:<$from>", '250')
        && $send("RCPT TO:<$to>", '250')
        && $send('DATA', '354')
        && $send("From: $from\r\nTo: $to\r\nSubject: $subject\r\n"
               . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n"
               . $body . "\r\n.", '250');
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

function makeImageThumb(string $src, string $dst, int $size): void {
    $cmd = sprintf(
        'convert %s -auto-orient -thumbnail %dx%d^ -gravity center -extent %dx%d -quality 82 %s 2>/dev/null',
        escapeshellarg($src), $size, $size, $size, $size, escapeshellarg($dst)
    );
    exec($cmd);
}

function makeVideoThumb(string $src, string $dst, int $size): void {
    $cmd = sprintf(
        'ffmpeg -y -loglevel error -ss 1 -i %s -frames:v 1 -vf "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d" -q:v 4 %s 2>/dev/null',
        escapeshellarg($src), $size, $size, $size, $size, escapeshellarg($dst)
    );
    exec($cmd);
}
