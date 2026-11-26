<?php
// Dynamic gallery data loading.
//   getData.php?action=whoami        -> login state (allowed IPs are signed in automatically)
//   getData.php?action=login         -> POST {user, password}
//   getData.php?action=logout        -> sign out
//   getData.php?action=albums        -> album list
//   getData.php?action=album&id=001  -> album contents (generates missing thumbnails)
session_name('userSession');   // instead of the generic PHPSESSID (the domain is shared with other apps)
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
$rights = resolveRights($config, $user);

// 403 unless the signed-in user has the given right ("move", "delete", …)
function requireRight(array $rights, string $right): void {
    if (!in_array($right, $rights, true)) {
        http_response_code(403);
        echo json_encode(['error' => "missing '$right' permission"]);
        exit;
    }
}

if ($action === 'whoami') {
    echo json_encode([
        'auth' => $authenticated,
        'user' => $_SESSION['user'] ?? null,
        'rights' => $rights,
        'title' => $config['title'] ?? null,   // gallery name from config.json
    ]);
    exit;
}

if ($action === 'login') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $u = (string)($body['user'] ?? '');
    $p = (string)($body['password'] ?? '');
    if (verifyPassword($CONFIG_FILE, $config, $u, $p)) {   // password from config.json or the shared file
        session_regenerate_id(true);
        $_SESSION['user'] = $u;
        setRememberCookie($config, $u);   // stay signed in across browser restarts
        echo json_encode(['auth' => true, 'user' => $u, 'rights' => resolveRights($config, $u)]);
        exit;
    }
    http_response_code(401);
    echo json_encode(['auth' => false, 'error' => 'invalid username or password']);
    exit;
}

if ($action === 'logout') {
    clearRememberCookie();
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

    // stored as a hash – in the shared password file when configured, else in config.json
    $saved = savePassword($CONFIG_FILE, $config, (string)$entry['user'], $password);

    unset($tokens[$token]);   // the token is single-use
    file_put_contents($RESET_FILE, json_encode($tokens), LOCK_EX);

    if (!$saved) {   // user removed from config meanwhile, or the password file is not writable
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

// filesystem path -> URL: every segment percent-encoded, otherwise "#" in an
// album or file name would start a URL fragment and the browser would cut it off
function urlPath(string ...$segments): string {
    return implode('/', array_map('rawurlencode', $segments));
}

// URL of a thumbnail with its modification time as "?v=…": a regenerated
// thumbnail gets a new URL, so the browser cannot show a cached old picture
function thumbUrl(string $thumbAbs, string $album, string $f): string {
    clearstatcache(true, $thumbAbs);
    return urlPath('thumbs', $album, "$f.jpg") . '?v=' . filemtime($thumbAbs);
}

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
                } elseif ($hasFfmpeg && videoThumbStale($thumbAbs, $dir, $f)) {
                    makeVideoThumb("$dir/$f", $thumbAbs, $THUMB_SIZE, videoThumbAt($dir, $f));
                }
                if (is_file($thumbAbs)) $cover = thumbUrl($thumbAbs, $d, $f);
                elseif (!$isImg && $coverVideo === null) $coverVideo = urlPath('gallery', $d, $f);
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
        } elseif ($hasFfmpeg && videoThumbStale($thumbAbs, $dir, $f)) {
            makeVideoThumb($src, $thumbAbs, $THUMB_SIZE, videoThumbAt($dir, $f));
        }

        $item = ['name' => $f, 'type' => $isImg ? 'image' : 'video', 'src' => urlPath('gallery', $id, $f)];
        if (is_file($thumbAbs)) $item['thumb'] = thumbUrl($thumbAbs, $id, $f);
        $items[] = $item;
    }

    echo json_encode(['id' => $id, 'items' => $items]);
    exit;
}

// POST getData.php?action=saveOrder&id=001  (body: JSON array of file names)
if ($action === 'saveOrder') {
    requireRight($rights, 'move');
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

// POST getData.php?action=delete&id=001  (body: JSON {"name": "photo.jpg"})
// removes the file, its thumbnail and the entry in .order.json
if ($action === 'delete') {
    requireRight($rights, 'delete');
    $id = basename($_GET['id'] ?? '');
    $dir = "$SRC/$id";
    if ($id === '' || !is_dir($dir)) {
        http_response_code(404);
        echo json_encode(['error' => 'album not found']);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = (string)($body['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    // only media files inside the album can be deleted (no paths, no dotfiles)
    if ($name === '' || basename($name) !== $name || $name[0] === '.'
            || !in_array($ext, array_merge($IMG_EXT, $VID_EXT)) || !is_file("$dir/$name")) {
        http_response_code(404);
        echo json_encode(['error' => 'file not found']);
        exit;
    }
    if (!@unlink("$dir/$name")) {
        http_response_code(500);
        echo json_encode(['error' => 'could not delete the file (permissions?)']);
        exit;
    }
    @unlink("$THUMBS/$id/$name.jpg");

    $orderFile = "$dir/.order.json";
    if (is_file($orderFile)) {
        $order = json_decode(file_get_contents($orderFile), true);
        if (is_array($order)) {
            $order = array_values(array_filter($order, fn($n) => $n !== $name));
            file_put_contents($orderFile,
                json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    // drop the video's entry in .thumbs.json; the file keeps its mtime so that
    // the remaining video thumbnails of the album are not regenerated
    $thumbsFile = "$dir/.thumbs.json";
    if (is_file($thumbsFile)) {
        $conf = json_decode(file_get_contents($thumbsFile), true);
        if (is_array($conf) && is_array($conf['files'] ?? null) && array_key_exists($name, $conf['files'])) {
            $mtime = filemtime($thumbsFile);
            unset($conf['files'][$name]);
            if (!$conf['files']) $conf['files'] = new stdClass();   // keep "{}", not "[]"
            file_put_contents($thumbsFile,
                json_encode($conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            @touch($thumbsFile, $mtime);
        }
    }
    echo json_encode(['ok' => true]);
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

// Second of the video its thumbnail is taken from, set by hand in the album's
// .thumbs.json:  { "default": 10, "files": { "clip.mp4": 42.5 } }
// The entry of the file wins over the album default; null = nothing set,
// the frame is picked automatically.
function videoThumbAt(string $dir, string $f): ?float {
    static $cache = [];
    if (!array_key_exists($dir, $cache)) {
        $file = "$dir/.thumbs.json";
        $conf = is_file($file) ? json_decode(file_get_contents($file), true) : null;
        $cache[$dir] = is_array($conf) ? $conf : [];
    }
    $conf = $cache[$dir];
    $at = $conf['files'][$f] ?? $conf['default'] ?? null;
    return is_int($at) || is_float($at) ? max(0.0, (float)$at) : null;
}

// True when the video thumbnail has to be (re)generated: it is missing, or
// older than the video, or older than the album's .thumbs.json (after a
// change there all video thumbnails of the album are made again).
function videoThumbStale(string $thumb, string $dir, string $f): bool {
    if (!is_file($thumb)) return true;
    $t = filemtime($thumb);
    if ($t < filemtime("$dir/$f")) return true;
    $conf = "$dir/.thumbs.json";
    return is_file($conf) && $t < filemtime($conf);
}

// $at = second set by hand (videoThumbAt), null = automatic choice
function makeVideoThumb(string $src, string $dst, int $size, ?float $at = null): void {
    // Work on a temporary file with a plain name and move it into place at the
    // end: ffmpeg and convert both expand "%d" in an output file name.
    $tmp = tempnam(sys_get_temp_dir(), 'gth');
    if ($tmp === false) return;

    // Frame at 1 s (clips shorter than that produce nothing, so the first
    // frame is taken instead). When it is empty (black / single colour –
    // fade-in, leader), the frames at 30 s and 60 s are tried as well.
    $grab = function (float $at) use ($src, $tmp, $size): bool {
        $cmd = sprintf(
            'ffmpeg -y -loglevel error -ss %.3F -i %s -frames:v 1 -vf "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d" -q:v 4 -f image2 -c:v mjpeg %s 2>/dev/null',
            $at, escapeshellarg($src), $size, $size, $size, $size, escapeshellarg($tmp)
        );
        exec($cmd);
        clearstatcache(true, $tmp);
        return filesize($tmp) > 0;   // nothing is written past the end of the video
    };

    // A second set by hand is taken as it is, even when the frame is dark;
    // only when it lies past the end of the video the automatic choice follows.
    $ok = $at !== null && $grab($at);
    if (!$ok) {
        $ok = ($grab(1) || $grab(0)) && !isEmptyThumb($tmp);
        foreach ([30, 60] as $try) {
            if ($ok) break;
            $ok = $grab($try) && !isEmptyThumb($tmp);
        }
    }
    // still nothing to show (or no video stream at all): a preview showing
    // nothing is replaced by the file name
    if (!$ok) {
        makeNameThumb(basename($src), $tmp, $size);
        clearstatcache(true, $tmp);
    }

    if (filesize($tmp) > 0 && @rename($tmp, $dst)) {
        @chmod($dst, 0664);   // tempnam() creates the file as 0600
    } else {
        @unlink($tmp);
    }
}

// True when the picture carries no information: (nearly) one flat colour, or
// almost black. Measured on the grayscale thumbnail, values in 0..1.
function isEmptyThumb(string $file): bool {
    $out = shell_exec(sprintf(
        'convert %s -colorspace Gray -format "%%[fx:mean] %%[fx:standard_deviation]" info: 2>/dev/null',
        escapeshellarg('jpg:' . $file)
    ));
    if (!preg_match('/^\s*([\d.eE+-]+)\s+([\d.eE+-]+)/', (string)$out, $m)) return false;
    $mean = (float)$m[1];
    $dev = (float)$m[2];
    return $dev < 0.02 || ($mean < 0.03 && $dev < 0.05);
}

// Splits a file name into lines of at most $max characters, preferably
// after a space, "_", "-" or "." (file names often have no spaces at all).
function wrapName(string $name, int $max): array {
    $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($name);
    $lines = [];
    while (count($chars) > $max) {
        $cut = $max;
        for ($i = $max - 1; $i >= (int)($max / 2); $i--) {
            if (in_array($chars[$i], [' ', '_', '-', '.'], true)) { $cut = $i + 1; break; }
        }
        $lines[] = trim(implode('', array_splice($chars, 0, $cut)));
    }
    $lines[] = trim(implode('', $chars));
    return array_values(array_filter($lines, fn($l) => $l !== ''));
}

// Placeholder thumbnail: black background with the file name in a sans-serif
// font at a quarter of the height; long names are wrapped and the font size is fitted
// to the box automatically. $dst must not contain "%".
function makeNameThumb(string $name, string $dst, int $size): void {
    // the system default sans-serif (covers diacritics), DejaVu Sans as a fallback
    $font = trim((string)shell_exec('fc-match -f "%{file}" sans-serif 2>/dev/null'));
    if ($font === '' || !is_file($font)) $font = 'DejaVu-Sans';
    // label: text is not literal – neutralise backslash and percent escapes and
    // a leading "@", which would mean "read the text from this file"
    $lines = wrapName($name, 18);
    $text = str_replace(['\\', '%'], ['\\\\', '%%'], implode("\n", $lines));
    if ($text !== '' && $text[0] === '@') $text = '\\' . $text;
    // the text is centred at 25 % of the height: the centre of the tile is
    // covered by the play icon (52 px on a tile from 180 px wide, i.e. the
    // middle ~30 % of the thumbnail), so the band may be at most 20 % high
    $boxH = min((int)($size * 0.20), (int)(count($lines) * $size * 0.11));
    $top = (int)($size * 0.25 - $boxH / 2);
    $cmd = sprintf(
        'convert -background black -fill white -font %s -gravity center -size %dx%d %s -gravity North -splice 0x%d -extent %dx%d -quality 82 %s 2>/dev/null',
        escapeshellarg($font), $size - 40, $boxH, escapeshellarg('label:' . $text),
        $top, $size, $size, escapeshellarg('jpg:' . $dst)
    );
    exec($cmd);
}
