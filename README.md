# Gallery

A web photo gallery with albums, slideshows and user login. No database —
everything is read directly from disk, thumbnails are generated on the fly.

## Features

- **Album overview** — title, photo/video counts, cover image; a single fast
  server request
- **Album view** — thumbnail grid; album contents are fetched only when the
  album is opened
- **Detail (lightbox)** — photos and videos scaled to the full stage, browse
  with ←/→ arrows, Esc closes level by level (photo → album → overview)
- **Transitions** — a random one of ~18 animations on every switch (crossfade,
  slide, zoom, 3D flip, blinds, cube…), direction is randomized too
- **Slideshow** — ▶ sequential loop / ⚄ random; photos for 5 s, videos play in
  full and advance right after they end; Ken Burns effect (slow drift/zoom);
  the next image is preloaded in the background
- **All-albums slideshow** — ▶/⚄ on the overview: albums play one after
  another, or are picked randomly (contents always play in order)
- **Drag & drop ordering** — drag a thumbnail with the mouse; the order is
  saved on the server
- **Fullscreen** — ⛶ button in the bottom right corner
- **Login** — allowed IPs sign in automatically, everyone else via
  username + password

## Layout

```
gallery/               application root (webroot or a webroot subdirectory)
├── index.php          application page (SPA)
├── app.js             browser logic
├── style.css          styling + transition animations
├── getData.php        API (album list, album contents, login, order saving)
├── auth.php           verification endpoint for nginx auth_request
├── authLib.php        shared authentication logic (session, IPs, users)
├── convert-mov.sh     converts .mov videos to browser-friendly .mp4
├── config.json        users and allowed IPs (MUST NOT be committed to git!)
├── gallery/           albums – each subdirectory = one album
│   └── 001/
│       ├── *.jpg, *.mp4   photos and videos
│       ├── .title         optional album title (directory name is used without it)
│       └── .order.json    file order (created by drag & drop, can be written by hand)
└── thumbs/            generated 400×400 thumbnails (reproducible, not in git)
```

## Requirements

- nginx + PHP-FPM (PHP 8.1+)
- ImageMagick (`convert`) — photo thumbnails
- **ffmpeg — required for video thumbnails** (grid, covers, slideshows):

  ```bash
  sudo apt install ffmpeg
  ```

  ffmpeg is detected at runtime — no configuration needed, thumbnails start
  generating as soon as it is installed. Without it the gallery still works,
  but video previews fall back to the browser rendering the first frame of
  each video, which is slower (metadata + part of the video is downloaded
  for every preview).

## Converting .mov videos — convert-mov.sh

Browsers play `.mov` files unreliably (Firefox refuses the QuickTime
container, HEVC from newer iPhones needs hardware decoding). The
`convert-mov.sh` script converts all `.mov` files in the gallery to
browser-friendly `.mp4` and **deletes the original on success**:

```bash
./convert-mov.sh                      # processes ./gallery next to the script
./convert-mov.sh /path/to/gallery    # or any given directory
```

What it does:

- `.mov` with **h264** video → lossless container remux (fast); PCM audio from
  older cameras is converted to AAC so it fits the MP4 container
- any **other codec** (HEVC, mpeg4, …) → re-encode to H.264 + AAC with
  `+faststart` for smooth web streaming
- the original `.mov` is deleted only after a successful conversion; on
  failure it is kept and the partial `.mp4` is removed
- renames the entry in the album's `.order.json` (custom ordering survives)
  and deletes the stale `.mov` thumbnail
- never overwrites an existing `.mp4` (reports SKIP); handles spaces in names

## Configuration — config.json

A template is provided in `config.json.sample` — copy it to `config.json`
and edit:

```bash
cp config.json.sample config.json
```

```json
{
  "title": "My Gallery",
  "users": [
    { "user": "name", "password": "password", "email": "user@sample.com" }
  ],
  "smtp": {
    "host": "smtp.gmail.com",
    "port": 465,
    "user": "you@gmail.com",
    "password": "gmail-app-password",
    "from": "you@gmail.com"
  },
  "autoLoginIps": [ "127.0.0.1", "::1", "10.25.0.0/16", "10.25.101.11" ]
}
```

- `title` — gallery name shown in the page header and browser tab
  (defaults to "Gallery" when omitted)
- `users` — who can sign in with username and password; the optional `email`
  enables the "Forgot password?" reset link for that user
- `smtp` — outgoing mail for password-reset e-mails (implicit TLS + AUTH
  LOGIN). For Gmail create an *app password* (Google account → Security →
  2-Step Verification → App passwords) — a normal account password will not
  work. When the section is missing, PHP `mail()` is used as a fallback.
- `autoLoginIps` — IP addresses (single or CIDR) that are signed in
  automatically without the login dialog
- Changes take effect immediately — the config is read on every request and
  sessions are re-validated continuously (a removed IP / deleted user means
  immediate logout)
- If config.json is missing, authentication is disabled (a safeguard against
  locking yourself out)
- Password reset rewrites config.json, so the file must be **writable by the
  web-server user** (e.g. `chgrp www-data config.json`); otherwise resetting
  fails with HTTP 500 while everything else keeps working

## API (getData.php)

| Action | Description |
|---|---|
| `?action=whoami` | login state (IP auto-login happens here) |
| `?action=login` | POST `{user, password}` |
| `?action=logout` | destroy the session |
| `?action=resetRequest` | POST `{email}` → e-mails a password-reset link (always replies `ok`) |
| `?action=resetPassword` | POST `{token, password}` → sets a new password, token is single-use |
| `?action=albums` | album list: id, title, counts, cover |
| `?action=album&id=X` | album contents, generates missing thumbnails |
| `?action=saveOrder&id=X` | POST an array of file names → writes `.order.json` |

All data actions require authentication (HTTP 401 otherwise). Responses are
sent with `Cache-Control: no-store`.

## nginx security — IMPORTANT

The application relies on two nginx rules. **Without them, passwords and
photos are publicly accessible!**

### 1. Deny downloading config.json

The password file lives inside the webroot; nginx must refuse to serve it:

```nginx
location ~ /config\.json$ { deny all; }
```

### 2. Protecting photos and thumbnails (auth_request)

Static images are served directly by nginx — but before serving, a subrequest
verifies the session against `auth.php`. Snippet
`/etc/nginx/snippets/gallery-auth.conf`:

```nginx
location ~ ^/gallery/(?:gallery|thumbs)/ {
	auth_request /gallery-auth-check;
}

location = /gallery-auth-check {
	internal;
	include fastcgi_params;
	fastcgi_pass unix:/run/php/php-fpm.sock;
	fastcgi_param SCRIPT_FILENAME /home/libmit/sw/gallery/auth.php;
	fastcgi_pass_request_body off;
	fastcgi_param CONTENT_LENGTH "";
}
```

Each server block then needs:

```nginx
include snippets/gallery-auth.conf;
```

**Production:** when the gallery sits in the web root (not in a `/gallery`
subdirectory), adjust the regex to `^/(?:gallery|thumbs)/` and point
`SCRIPT_FILENAME` at the real path of `auth.php`.

### 3. Filesystem permissions

PHP-FPM runs as `www-data` and needs **write** access to:
- `thumbs/` — thumbnail generation
- `gallery/` and the album directories — saving `.order.json`

Simplest setup: `chmod 777 thumbs gallery gallery/*/`. Deleting
server-generated thumbnails then requires sudo (`sudo rm -rf thumbs/*`).

## Security and functionality checklist

Run after every nginx config change or deployment (adjust BASE for your
environment):

```bash
BASE="http://localhost/gallery"

# 1. config.json MUST NOT be downloadable -> expect 403
curl -s -o /dev/null -w "config.json: %{http_code}\n" "$BASE/config.json"

# 2. API without login -> 401 (unless your IP is in autoLoginIps)
curl -s -o /dev/null -w "API: %{http_code}\n" "$BASE/getData.php?action=albums"

# 3. photo and thumbnail without login -> 403
curl -s -o /dev/null -w "photo: %{http_code}\n" "$BASE/gallery/001/some.jpg"
curl -s -o /dev/null -w "thumb: %{http_code}\n" "$BASE/thumbs/001/some.jpg.jpg"

# 4. the app itself must stay reachable for anonymous users (login dialog) -> 200
curl -s -o /dev/null -w "index: %{http_code}\n" "$BASE/"
curl -s -o /dev/null -w "app.js: %{http_code}\n" "$BASE/app.js"

# 5. login + access with a session -> 200 everywhere
curl -s -c /tmp/gc -X POST -d '{"user":"name","password":"password"}' \
     "$BASE/getData.php?action=login"; echo
curl -s -b /tmp/gc -o /dev/null -w "API after login: %{http_code}\n" \
     "$BASE/getData.php?action=albums"
curl -s -b /tmp/gc -o /dev/null -w "photo after login: %{http_code}\n" \
     "$BASE/gallery/001/some.jpg"
rm /tmp/gc
```

Expected results: **403 / 401 / 403+403 / 200+200 / 200+200+200.**
Anything else indicates an nginx config problem (missing deny rule, snippet
not included, `systemctl reload nginx` not run) or wrong filesystem
permissions.

Quick thumbnail-generation check: delete one thumbnail
(`sudo rm thumbs/001/file.jpg.jpg`) and open the album in the browser —
the thumbnail must be regenerated automatically.

## License

[CC BY-NC-SA 4.0](LICENSE) — Attribution – NonCommercial – ShareAlike.
