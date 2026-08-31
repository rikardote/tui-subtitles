#!/usr/bin/env bash
# Genera un entorno de prueba: videos con pistas de subtítulos internas y externas.
set -euo pipefail

export PATH="$HOME/bin:$PATH"
LIB=/home/rishar/subtitles/storage/test-library
mkdir -p "$LIB/Movies/Interstellar (2014)" "$LIB/Movies/The Matrix (1999)" "$LIB/TV/Arcane/S01"

# ── Video de prueba base (2s de color) ──────────────────────────────
make_video() {
    ffmpeg -y -f lavfi -i "color=c=0x223344:s=640x360:d=8:r=24" \
        -f lavfi -i "sine=frequency=440:duration=8" \
        -c:v libx264 -preset ultrafast -c:a aac -shortest "$1" -loglevel error
}

# ── Pistas de subtítulos ─────────────────────────────────────────────
cat > /tmp/en1.srt <<'EOF'
1
00:00:00,000 --> 00:00:02,000
Welcome to the future of space travel.

2
00:00:02,500 --> 00:00:05,000
This is a test subtitle for the translation engine.

3
00:00:05,500 --> 00:00:08,000
Cooper, we need you to stay on this mission.
EOF

cat > /tmp/en2.srt <<'EOF'
1
00:00:00,000 --> 00:00:02,000
The machines have taken over the world.

2
00:00:02,500 --> 00:00:05,000
Follow the white rabbit, Neo.

3
00:00:05,500 --> 00:00:08,000
There is no spoon.
EOF

cat > /tmp/en3.srt <<'EOF'
1
00:00:00,000 --> 00:00:02,000
Welcome to the undercity, sister.

2
00:00:02,500 --> 00:00:05,000
Hextech is changing everything we know.

3
00:00:05,500 --> 00:00:08,000
Run, Jinx, run!
EOF

cat > /tmp/sp1.srt <<'EOF'
1
00:00:00,000 --> 00:00:02,000
Bienvenido al futuro de los viajes espaciales.

2
00:00:02,500 --> 00:00:05,000
Este es un subtítulo de prueba para el motor de traducción.

3
00:00:05,500 --> 00:00:08,000
Cooper, necesitamos que permanezcas en esta misión.
EOF

# ── Movies/Interstellar: video con pistas EN + SP y SDH, más .es.srt externo ──
make_video "$LIB/Movies/Interstellar (2014)/Interstellar (2014).mkv"
ffmpeg -y -i "$LIB/Movies/Interstellar (2014)/Interstellar (2014).mkv" \
    -i /tmp/en1.srt -i /tmp/sp1.srt \
    -map 0:v -map 0:a \
    -map 1 -map 2 \
    -c:v copy -c:a copy \
    -c:s srt \
    -metadata:s:s:0 language=eng -metadata:s:s:0 title="English" \
    -metadata:s:s:1 language=spa -metadata:s:s:1 title="Spanish" \
    "$LIB/Movies/Interstellar (2014)/Interstellar (2014).tmp.mkv" -loglevel error
mv "$LIB/Movies/Interstellar (2014)/Interstellar (2014).tmp.mkv" "$LIB/Movies/Interstellar (2014)/Interstellar (2014).mkv"
# Subtítulo externo en inglés (convención: <base>.en.srt) → traducible
cp /tmp/en1.srt "$LIB/Movies/Interstellar (2014)/Interstellar (2014).en.srt"

# ── Movies/The Matrix: solo pista EN, sin español (pendiente de traducción) ──
make_video "$LIB/Movies/The Matrix (1999)/The Matrix (1999).mp4"
ffmpeg -y -i "$LIB/Movies/The Matrix (1999)/The Matrix (1999).mp4" \
    -i /tmp/en2.srt \
    -map 0:v -map 0:a -map 1 \
    -c:v copy -c:a copy -c:s mov_text \
    -metadata:s:s:0 language=eng -metadata:s:s:0 title="English" \
    "$LIB/Movies/The Matrix (1999)/The Matrix (1999).tmp.mp4" -loglevel error
mv "$LIB/Movies/The Matrix (1999)/The Matrix (1999).tmp.mp4" "$LIB/Movies/The Matrix (1999)/The Matrix (1999).mp4"

# ── TV/Arcane: episodio con pista EN SDH ──
make_video "$LIB/TV/Arcane/S01/Arcane S01E01.mkv"
ffmpeg -y -i "$LIB/TV/Arcane/S01/Arcane S01E01.mkv" \
    -i /tmp/en3.srt \
    -map 0:v -map 0:a -map 1 \
    -c:v copy -c:a copy -c:s srt \
    -metadata:s:s:0 language=eng -metadata:s:s:0 title="English SDH" \
    "$LIB/TV/Arcane/S01/Arcane S01E01.tmp.mkv" -loglevel error
mv "$LIB/TV/Arcane/S01/Arcane S01E01.tmp.mkv" "$LIB/TV/Arcane/S01/Arcane S01E01.mkv"

# ── Archivo no soportado (debe ignorarse) ──
touch "$LIB/Movies/not_a_video.txt"

echo "Entorno de prueba creado:"
find "$LIB" -type f | sort
