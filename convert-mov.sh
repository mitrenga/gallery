#!/bin/bash
# Convert all .mov files in the gallery to browser-friendly .mp4 and DELETE
# the original .mov on success.
#
#   - h264 video  -> container remux only (fast, lossless), audio to AAC
#   - other codec -> re-encode to H.264 + AAC
#   - updates .order.json entries and removes the stale thumbnail
#
# Usage:  ./convert-mov.sh [directory]     (default: ./gallery next to the script)
set -u

DIR="${1:-$(dirname "$0")/gallery}"

if ! command -v ffmpeg >/dev/null || ! command -v ffprobe >/dev/null; then
    echo "ffmpeg/ffprobe is required"; exit 1
fi
if [ ! -d "$DIR" ]; then
    echo "directory not found: $DIR"; exit 1
fi

converted=0; skipped=0; failed=0

while IFS= read -r -d '' mov; do
    mp4="${mov%.*}.mp4"
    name_mov="$(basename "$mov")"
    name_mp4="$(basename "$mp4")"
    album_dir="$(dirname "$mov")"

    if [ -e "$mp4" ]; then
        echo "SKIP  $mov (target $name_mp4 already exists)"
        skipped=$((skipped + 1))
        continue
    fi

    codec="$(ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 "$mov")"

    # -nostdin: ffmpeg must not consume the while-loop's stdin (the file list)
    if [ "$codec" = "h264" ]; then
        echo "REMUX $mov (h264 – lossless)"
        ffmpeg -nostdin -y -loglevel error -i "$mov" -c:v copy -c:a aac -movflags +faststart "$mp4"
    else
        echo "ENCODE $mov ($codec -> h264)"
        ffmpeg -nostdin -y -loglevel error -i "$mov" -c:v libx264 -crf 20 -preset medium -c:a aac -movflags +faststart "$mp4"
    fi

    if [ $? -ne 0 ] || [ ! -s "$mp4" ]; then
        echo "FAIL  $mov (original kept)"
        rm -f "$mp4"
        failed=$((failed + 1))
        continue
    fi

    rm -f "$mov"
    converted=$((converted + 1))

    # keep the user-defined order: rename the entry in .order.json
    if [ -f "$album_dir/.order.json" ]; then
        python3 - "$album_dir/.order.json" "$name_mov" "$name_mp4" <<'EOF'
import json, sys
path, old, new = sys.argv[1:4]
names = json.load(open(path))
if old in names:
    json.dump([new if n == old else n for n in names],
              open(path, 'w'), indent=1)
EOF
    fi

    # remove the stale thumbnail of the .mov (regenerated for the .mp4 on demand)
    thumbs_dir="$(dirname "$album_dir")/../thumbs/$(basename "$album_dir")"
    rm -f "$thumbs_dir/$name_mov.jpg"
done < <(find "$DIR" -type f -iname "*.mov" -print0)

echo "----"
echo "converted: $converted, skipped: $skipped, failed: $failed"
