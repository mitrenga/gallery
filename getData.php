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
        foreach ($files as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            $isImg = in_array($ext, $IMG_EXT);
            $isImg ? $photos++ : $videos++;
            if ($cover === null) {
                $thumbRel = "thumbs/$d/$f.jpg";
                $thumbAbs = "$ROOT/$thumbRel";
                if ($isImg) {   // generate the cover; for videos only use it if it already exists
                    @mkdir("$THUMBS/$d", 0775, true);
                    if (!is_file($thumbAbs) || filemtime($thumbAbs) < filemtime("$dir/$f")) {
                        makeImageThumb("$dir/$f", $thumbAbs, $THUMB_SIZE);
                    }
                }
                if (is_file($thumbAbs)) $cover = $thumbRel;
            }
        }

        $albums[] = ['id' => $d, 'title' => $title, 'photos' => $photos, 'videos' => $videos, 'cover' => $cover];
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
