#!/bin/bash
# Convert all .mpg/.mpeg videos (MPEG-1 / MPEG-2 program streams from old
# camcorders, DVDs, TV cards) in the gallery to browser-friendly .mp4 and
# DELETE the original on success.
#
#   - browsers do not play MPEG-1/2 video at all, so it is always re-encoded
#     to H.264 + AAC (mp2/ac3 audio is converted too)
#   - interlaced material (typical for DV/DVD) is deinterlaced (yadif) so it
#     does not show comb artefacts on a progressive screen
#   - updates .order.json entries and removes the stale thumbnail
#
# Usage:  ./convert-mpg.sh [directory]     (default: ./gallery next to the script)
set -u

DIR="${1:-$(dirname "$0")/gallery}"
CRF=20

if ! command -v ffmpeg >/dev/null || ! command -v ffprobe >/dev/null; then
    echo "ffmpeg/ffprobe is required"; exit 1
fi
if [ ! -d "$DIR" ]; then
    echo "directory not found: $DIR"; exit 1
fi

converted=0; skipped=0; failed=0

while IFS= read -r -d '' mpg; do
    mp4="${mpg%.*}.mp4"
    name_mpg="$(basename "$mpg")"
    name_mp4="$(basename "$mp4")"
    album_dir="$(dirname "$mpg")"

    if [ -e "$mp4" ]; then
        echo "SKIP  $mpg (target $name_mp4 already exists)"
        skipped=$((skipped + 1))
        continue
    fi

    codec="$(ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=nw=1:nk=1 "$mpg")"
    field="$(ffprobe -v error -select_streams v:0 -show_entries stream=field_order -of default=nw=1:nk=1 "$mpg")"

    # tt/bb/tb/bt = interlaced; "progressive" or empty (unknown) = leave as is
    vf="format=yuv420p"
    note=""
    case "$field" in
        tt|bb|tb|bt) vf="yadif,$vf"; note=", interlaced ($field) -> deinterlace" ;;
    esac

    echo "ENCODE $mpg ($codec -> h264$note)"
    # -nostdin: ffmpeg must not consume the while-loop's stdin (the file list)
    # -sn: drop DVD subtitle streams (they do not fit the MP4 container)
    ffmpeg -nostdin -y -loglevel error -i "$mpg" -sn \
        -c:v libx264 -crf "$CRF" -preset medium -vf "$vf" \
        -c:a aac -b:a 160k -movflags +faststart "$mp4"

    if [ $? -ne 0 ] || [ ! -s "$mp4" ]; then
        echo "FAIL  $mpg (original kept)"
        rm -f "$mp4"
        failed=$((failed + 1))
        continue
    fi

    rm -f "$mpg"
    converted=$((converted + 1))

    # keep the user-defined order: rename the entry in .order.json
    if [ -f "$album_dir/.order.json" ]; then
        python3 - "$album_dir/.order.json" "$name_mpg" "$name_mp4" <<'PY'
import json, sys
path, old, new = sys.argv[1:4]
names = json.load(open(path))
if old in names:
    json.dump([new if n == old else n for n in names],
              open(path, 'w'), indent=1)
PY
    fi

    # remove the stale thumbnail of the .mpg (regenerated for the .mp4 on demand)
    thumbs_dir="$(dirname "$album_dir")/../thumbs/$(basename "$album_dir")"
    rm -f "$thumbs_dir/$name_mpg.jpg"
done < <(find "$DIR" -type f \( -iname "*.mpg" -o -iname "*.mpeg" \) -print0)

echo "----"
echo "converted: $converted, skipped: $skipped, failed: $failed"
