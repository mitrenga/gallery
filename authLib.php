<?php
// Shared authentication for getData.php and auth.php (nginx auth_request).
// Expects an already started session (session_start).

function ipMatches(string $ip, array $list): bool {
    foreach ($list as $allowed) {
        if (str_contains($allowed, '/')) {          // CIDR, e.g. 10.25.0.0/16
            [$net, $bits] = explode('/', $allowed, 2);
            $ipBin = @inet_pton($ip);
            $netBin = @inet_pton($net);
            if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) continue;
            $bits = (int)$bits;
            $bytes = intdiv($bits, 8);
            $rem = $bits % 8;
            if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) continue;
            if ($rem === 0 || ((ord($ipBin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rem)) & 0xFF) === 0) return true;
        } elseif ($ip === $allowed) {
            return true;
        }
    }
    return false;
}

// Returns the signed-in user name, or null. The session is re-validated on
// every request: removing an IP or deleting a user in the config takes effect
// immediately.
function resolveAuthUser(?array $config): ?string {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

    // without a config file nothing is locked (safeguard against a path typo)
    if ($config === null) {
        $_SESSION['user'] = $_SESSION['user'] ?? '@noconfig';
        return $_SESSION['user'];
    }

    $user = $_SESSION['user'] ?? null;

    // an auto-login session stays valid only while the IP is in the config
    if ($user !== null && str_starts_with($user, '@ip:')
            && !ipMatches($clientIp, $config['autoLoginIps'] ?? [])) {
        unset($_SESSION['user']);
        $user = null;
    }

    // a password-authenticated user must still exist in the config
    if ($user !== null && !str_starts_with($user, '@')) {
        $exists = false;
        foreach (($config['users'] ?? []) as $usr) {
            if ($usr['user'] === $user) { $exists = true; break; }
        }
        if (!$exists) {
            unset($_SESSION['user']);
            $user = null;
        }
    }

    // automatic sign-in by IP
    if ($user === null && ipMatches($clientIp, $config['autoLoginIps'] ?? [])) {
        $_SESSION['user'] = $user = '@ip:' . $clientIp;
    }

    return $user;
}
