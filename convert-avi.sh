#!/bin/bash
# Convert all .avi videos (old digital cameras, DV camcorders, DivX/Xvid rips)
# in the gallery to browser-friendly .mp4 and DELETE the original on success.
#
#   - AVI is only a container: inside is MJPEG, DV, DivX/Xvid (MPEG-4 ASP),
#     Cinepak ... none of which browsers play, and the container itself is not
#     supported either, so it is always re-encoded to H.264 + AAC
#     (pcm/adpcm/mp3/ac3 audio is converted too)
#   - interlaced material (typical for DV) is deinterlaced (yadif) so it
#     does not show comb artefacts on a progressive screen
#   - odd frame sizes are cropped by one pixel to even ones (H.264 4:2:0
#     requires even width and height)
#   - updates .order.json entries and removes the stale thumbnail
#
# Usage:  ./convert-avi.sh [directory]     (default: ./gallery next to the script)
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

while IFS= read -r -d '' avi; do
    mp4="${avi%.*}.mp4"
    name_avi="$(basename "$avi")"
    name_mp4="$(basename "$mp4")"
    album_dir="$(dirname "$avi")"

    if [ -e "$mp4" ]; then
        echo "SKIP  $avi (target $name_mp4 already exists)"
        skipped=$((skipped + 1))
        continue
    fi

    codec="$(ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=nw=1:nk=1 "$avi")"
    field="$(ffprobe -v error -select_streams v:0 -show_entries stream=field_order -of default=nw=1:nk=1 "$avi")"

    # AVI has no field-order header (DV, MJPEG report "unknown"), so fall back
    # to the flag the decoder sets on the first frame
    if [ -z "$field" ] || [ "$field" = "unknown" ]; then
        il="$(ffprobe -v error -select_streams v:0 -read_intervals "%+#1" -show_entries frame=interlaced_frame -of default=nw=1:nk=1 "$avi" | head -n 1)"
        [ "$il" = "1" ] && field="tb"
    fi

    # tt/bb/tb/bt = interlaced; "progressive" or empty (unknown) = leave as is
    vf="crop=trunc(iw/2)*2:trunc(ih/2)*2,format=yuv420p"
    note=""
    case "$field" in
        tt|bb|tb|bt) vf="yadif,$vf"; note=", interlaced -> deinterlace" ;;
    esac

    echo "ENCODE $avi ($codec -> h264$note)"
    # -nostdin: ffmpeg must not consume the while-loop's stdin (the file list)
    # -fflags +genpts: AVI stores no presentation timestamps (packed B-frames
    #   of DivX/Xvid), generate them so the MP4 gets a valid timeline
    # -sn -dn, -write_tmcd 0: drop subtitle/data streams and do not create
    #   a timecode track from the DV timecode (they do not belong in a web MP4)
    ffmpeg -nostdin -y -loglevel error -fflags +genpts -i "$avi" -sn -dn \
        -c:v libx264 -crf "$CRF" -preset medium -vf "$vf" \
        -c:a aac -b:a 160k -write_tmcd 0 -movflags +faststart "$mp4"

    if [ $? -ne 0 ] || [ ! -s "$mp4" ]; then
        echo "FAIL  $avi (original kept)"
        rm -f "$mp4"
        failed=$((failed + 1))
        continue
    fi

    rm -f "$avi"
    converted=$((converted + 1))

    # keep the user-defined order: rename the entry in .order.json
    if [ -f "$album_dir/.order.json" ]; then
        python3 - "$album_dir/.order.json" "$name_avi" "$name_mp4" <<'PY'
import json, sys
path, old, new = sys.argv[1:4]
names = json.load(open(path))
if old in names:
    json.dump([new if n == old else n for n in names],
              open(path, 'w'), indent=1)
PY
    fi

    # remove the stale thumbnail of the .avi (regenerated for the .mp4 on demand)
    thumbs_dir="$(dirname "$album_dir")/../thumbs/$(basename "$album_dir")"
    rm -f "$thumbs_dir/$name_avi.jpg"
done < <(find "$DIR" -type f -iname "*.avi" -print0)

echo "----"
echo "converted: $converted, skipped: $skipped, failed: $failed"
