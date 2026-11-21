#!/usr/bin/env python3
"""Cuts the raw recording into the narrated segments of the demo video.

Each segment is made of pieces of the recording: the user's interactions play
slightly accelerated, the agent's waits (thinking, searching, writing) are squeezed
into what the narration leaves. Frames are extracted with Playwright's ffmpeg (no
system ffmpeg needed); demo/assemble.swift turns them into the final MP4, with the
voice-over and the subtitles.

Usage: python3 demo/assemble.py <raw.webm> <marks.json>
"""
import json
import pathlib
import shutil
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parent
OUT = ROOT / 'output'
FRAMES = OUT / 'frames'
FPS = 25
INTERACTION_SPEED = 1.35
MIN_WAIT = 1.6
FFMPEG = sorted(pathlib.Path.home().glob('Library/Caches/ms-playwright/ffmpeg-*/ffmpeg-mac'))[-1]

# Segment id (as in narration.json) => pieces: (from mark, to mark, kind).
# "wait" pieces are squeezed, "act" pieces play at INTERACTION_SPEED, "card" at 1x.
SEGMENTS = [
    ('title', [('title', 'problem', 'card')]),
    ('problem', [('problem', 'start', 'card')]),
    ('start', [('start', 'sent', 'act')]),
    ('questions', [('sent', 'questions', 'wait'), ('questions', 'answered', 'act'), ('answered', 'plan', 'wait')]),
    ('plan', [('plan', 'validated', 'act')]),
    ('search', [('validated', 'findings', 'wait')]),
    ('findings', [('findings', 'write', 'act')]),
    ('cadrage', [('write', 'cadrage', 'wait'), ('cadrage', 'framed', 'act'), ('framed', 'redaction', 'wait')]),
    ('redaction', [('redaction', 'delivered', 'wait')]),
    ('document', [('delivered', 'exported', 'act')]),
    ('stack', [('stack', 'end', 'card')]),
    ('end', [('end', 'stop', 'card')]),
]
CAPTIONED = {'start', 'questions', 'plan', 'search', 'findings', 'cadrage', 'redaction', 'document'}


def main(raw: str, marks_file: str) -> None:
    marks = {m['id']: m['t'] for m in json.loads(pathlib.Path(marks_file).read_text())}
    narration = {n['id']: n['text'] for n in json.loads((ROOT / 'narration.json').read_text())}
    durations = json.loads((OUT / 'audio' / 'durations.json').read_text())

    shutil.rmtree(FRAMES, ignore_errors=True)
    FRAMES.mkdir(parents=True)

    spec = {'fps': FPS, 'frames': str(FRAMES), 'segments': [], 'output': str(OUT / 'judilibre-agent-demo.mp4')}
    index = 0
    start = 0.0
    for segment, pieces in SEGMENTS:
        pieces = [(marks[a], marks[b], kind) for a, b, kind in pieces if a in marks and b in marks and marks[b] > marks[a]]
        if not pieces:
            continue
        spoken = durations[segment] + 1.2
        fixed = sum((b - a) / (INTERACTION_SPEED if kind == 'act' else 1.0) for a, b, kind in pieces if kind != 'wait')
        waits = [p for p in pieces if p[2] == 'wait']
        if waits:
            total = max(spoken, fixed + MIN_WAIT * len(waits))
            per_wait = max(MIN_WAIT, (total - fixed) / len(waits))
        else:
            total = max(spoken, fixed)
            per_wait = 0.0
        # Cards are held as long as the narration needs.
        stretch = total / fixed if not waits and fixed > 0 else 1.0

        length = 0.0
        for a, b, kind in pieces:
            target = per_wait if kind == 'wait' else (b - a) / (INTERACTION_SPEED if kind == 'act' else 1.0) * stretch
            frames = max(1, round(target * FPS))
            rate = frames / (b - a)
            subprocess.run([
                str(FFMPEG), '-hide_banner', '-loglevel', 'error', '-ss', f'{a:.3f}', '-t', f'{b - a:.3f}', '-i', raw,
                '-vf', 'scale=1920:1080', '-r', f'{rate:.5f}', '-frames:v', str(frames),
                '-start_number', str(index), str(FRAMES / 'f%06d.png'),
            ], check=True)
            written = len(list(FRAMES.glob('f*.png'))) - index
            index += written
            length += written / FPS

        spec['segments'].append({
            'id': segment,
            'start': start,
            'duration': length,
            'audio': str(OUT / 'audio' / f'{segment}.aiff'),
            'caption': narration[segment] if segment in CAPTIONED else '',
        })
        print(f'{segment:10s} {start:6.1f}s  +{length:5.1f}s  (voice {durations[segment]:.1f}s)')
        start += length

    (OUT / 'spec.json').write_text(json.dumps(spec, ensure_ascii=False, indent=1))
    print(f'{index} frames, {start:.1f}s')


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
