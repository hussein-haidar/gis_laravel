#!/bin/sh
set -e

DATA_DIR="/data"
PBF_URL="${OSRM_PBF_URL:-https://download.geofabrik.de/asia/indonesia/jakarta-latest.osm.pbf}"
PBF_FILE="$DATA_DIR/jakarta-latest.osm.pbf"
PROFILE="${OSRM_PROFILE:-car.lua}"
PROFILE_PATH="/opt/$PROFILE"
BASE="$DATA_DIR/jakarta-${PROFILE%.lua}"
PORT="${OSRM_PORT:-5000}"

mkdir -p "$DATA_DIR"

echo "[osrm:$PROFILE] Memeriksa data..."

if [ ! -f "$PROFILE_PATH" ]; then
    echo "[osrm:$PROFILE] GAGAL: profil $PROFILE_PATH tidak ditemukan."
    exit 1
fi

if [ ! -f "$PBF_FILE" ]; then
    echo "[osrm:$PROFILE] Mengunduh PBF: $PBF_URL"
    wget -q -O "$PBF_FILE" "$PBF_URL"
    echo "[osrm:$PROFILE] Download selesai."
fi

if [ ! -f "$BASE.osrm" ]; then
    echo "[osrm:$PROFILE] Menjalankan osrm-extract..."
    osrm-extract -p "$PROFILE_PATH" "$PBF_FILE" --output "$BASE"

    echo "[osrm:$PROFILE] Menjalankan osrm-partition..."
    osrm-partition "$BASE.osrm"

    echo "[osrm:$PROFILE] Menjalankan osrm-customize..."
    osrm-customize "$BASE.osrm"
fi

echo "[osrm:$PROFILE] Menjalankan osrm-routed pada port $PORT..."
exec osrm-routed --algorithm mld --max-table-size 8000 "$BASE.osrm" --port "$PORT"
