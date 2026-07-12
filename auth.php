<?php
// Internal endpoint for nginx auth_request – protects static gallery files
// (photos and thumbnails). 204 = allow, 403 = deny.
session_start();
require __DIR__ . '/authLib.php';

$config = json_decode(@file_get_contents(__DIR__ . '/config.json'), true);
$user = resolveAuthUser(is_array($config) ? $config : null);
session_write_close();   // release the session lock quickly – images load in parallel

http_response_code($user !== null ? 204 : 403);
