#!/bin/bash
# Convert all .heic/.heif photos in the gallery to browser-friendly .jpg and
# DELETE the original .heic on success.
#
#   - uses ImageMagick (convert/magick) or heif-convert (libheif-examples)
#   - keeps EXIF metadata (orientation is applied, so the .jpg displays upright)
#   - updates .order.json entries and removes the stale thumbnail
#
# Usage:  ./convert-heic.sh [directory]     (default: ./gallery next to the script)
set -u

DIR="${1:-$(dirname "$0")/gallery}"
QUALITY=92

if command -v magick >/dev/null; then
    TOOL=magick
elif command -v convert >/dev/null; then
    TOOL=convert
elif command -v heif-convert >/dev/null; then
    TOOL=heif-convert
else
    echo "ImageMagick (with HEIC support) or heif-convert is required"; exit 1
fi
if [ ! -d "$DIR" ]; then
    echo "directory not found: $DIR"; exit 1
fi

converted=0; skipped=0; failed=0

while IFS= read -r -d '' heic; do
    jpg="${heic%.*}.jpg"
    name_heic="$(basename "$heic")"
    name_jpg="$(basename "$jpg")"
    album_dir="$(dirname "$heic")"

    if [ -e "$jpg" ]; then
        echo "SKIP  $heic (target $name_jpg already exists)"
        skipped=$((skipped + 1))
        continue
    fi

    echo "CONV  $heic -> $name_jpg ($TOOL)"
    case "$TOOL" in
        heif-convert)
            heif-convert -q "$QUALITY" "$heic" "$jpg" >/dev/null 2>&1 ;;
        *)
            # -auto-orient bakes the EXIF rotation into the pixels; without it
            # thumbnails made from the .jpg could end up sideways
            "$TOOL" "$heic" -auto-orient -quality "$QUALITY" "$jpg" 2>/dev/null ;;
    esac

    if [ $? -ne 0 ] || [ ! -s "$jpg" ]; then
        echo "FAIL  $heic (original kept)"
        rm -f "$jpg"
        failed=$((failed + 1))
        continue
    fi

    rm -f "$heic"
    converted=$((converted + 1))

    # keep the user-defined order: rename the entry in .order.json
    if [ -f "$album_dir/.order.json" ]; then
        python3 - "$album_dir/.order.json" "$name_heic" "$name_jpg" <<'PY'
import json, sys
path, old, new = sys.argv[1:4]
names = json.load(open(path))
if old in names:
    json.dump([new if n == old else n for n in names],
              open(path, 'w'), indent=1)
PY
    fi

    # remove the stale thumbnail of the .heic (regenerated for the .jpg on demand)
    thumbs_dir="$(dirname "$album_dir")/../thumbs/$(basename "$album_dir")"
    rm -f "$thumbs_dir/$name_heic.jpg"
done < <(find "$DIR" -type f \( -iname "*.heic" -o -iname "*.heif" \) -print0)

echo "----"
echo "converted: $converted, skipped: $skipped, failed: $failed"
