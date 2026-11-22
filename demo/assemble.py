#!/usr/bin/env python3
"""Cuts the raw recording into the segments of the demo video, one minute at most.

No voice-over: each segment has a fixed length, enough to read its card or its caption.
The user's interactions are sped up to fit it, the video freezes on each spotlight, the agent's waits (thinking, searching,
writing) are squeezed into short cuts, flagged with their real duration. Frames are
extracted with Playwright's ffmpeg (no system ffmpeg needed); demo/assemble.swift turns
them into the final MP4, with the captions and the spotlights.

Usage: python3 demo/assemble.py <raw.webm> <marks.json>
(marks.json: what demo/record.js returns, or at least its "marks" and "spots")
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
WAIT = 0.8  # seconds of video for each wait of the agent, whatever its real length
HOLD = 1.1  # seconds the video freezes on each spotlight
MAX_LENGTH = 60.0
FFMPEG = sorted(pathlib.Path.home().glob('Library/Caches/ms-playwright/ffmpeg-*/ffmpeg-mac'))[-1]

# Segment id => (seconds in the video, pieces: (from mark, to mark, kind)).
# "wait" pieces are squeezed to WAIT, each spotlight freezes the video for HOLD,
# "act" and "card" pieces share the rest of the segment.
SEGMENTS = [
    ('title', 3.0, [('title', 'problem', 'card')]),
    ('problem', 3.5, [('problem', 'workflow', 'card')]),
    ('workflow', 4.5, [('workflow', 'start', 'card')]),
    ('start', 3.0, [('start', 'sent', 'act')]),
    ('questions', 5.5, [('sent', 'questions', 'wait'), ('questions', 'answered', 'act'), ('answered', 'plan', 'wait')]),
    ('plan', 6.0, [('plan', 'validated', 'act')]),
    ('search', 3.5, [('validated', 'findings', 'wait')]),
    ('findings', 6.0, [('findings', 'write', 'act')]),
    ('cadrage', 3.0, [('write', 'cadrage', 'wait'), ('cadrage', 'framed', 'act'), ('framed', 'redaction', 'wait')]),
    ('redaction', 3.5, [('redaction', 'delivered', 'wait')]),
    ('document', 4.5, [('delivered', 'exported', 'act')]),
    ('symfony', 6.0, [('symfony', 'end', 'card')]),
    ('end', 4.0, [('end', 'stop', 'card')]),
]


def real(seconds: float) -> str:
    minutes = round(seconds / 60)
    return f'{minutes} min in real time' if minutes >= 1 else f'{round(seconds)} s in real time'


def main(raw: str, marks_file: str) -> None:
    recorded = json.loads(pathlib.Path(marks_file).read_text())
    marks = {m['id']: m['t'] for m in recorded['marks']} if isinstance(recorded, dict) else {m['id']: m['t'] for m in recorded}
    spots = recorded.get('spots', []) if isinstance(recorded, dict) else []
    captions = {c['id']: c for c in json.loads((ROOT / 'captions.json').read_text())}

    shutil.rmtree(FRAMES, ignore_errors=True)
    FRAMES.mkdir(parents=True)

    spec = {'fps': FPS, 'frames': str(FRAMES), 'segments': [], 'waits': [], 'spots': [], 'output': str(OUT / 'cassius-demo.mp4')}
    index = 0
    start = 0.0
    for segment, length, pieces in SEGMENTS:
        pieces = [(marks[a], marks[b], kind, None) for a, b, kind in pieces if a in marks and b in marks and marks[b] > marks[a]]
        if not pieces:
            continue
        # The video freezes on each spotlight, so it is read on the element it points at.
        for spot in spots:
            for i, (a, b, kind, _) in enumerate(pieces):
                if a <= spot['t'] < b and kind != 'card':
                    pieces[i:i + 1] = [p for p in [(a, spot['t'], kind, None), (spot['t'], spot['t'], 'hold', spot), (spot['t'], b, kind, None)] if p[2] == 'hold' or p[1] - p[0] > 0.05]
                    break
        waits = sum(1 for p in pieces if p[2] == 'wait')
        holds = sum(1 for p in pieces if p[2] == 'hold')
        moving = sum(b - a for a, b, kind, _ in pieces if kind in ('act', 'card'))
        per_wait = WAIT if moving else (length - HOLD * holds) / max(1, waits)
        speed = moving / max(0.5, length - per_wait * waits - HOLD * holds) if moving else 1.0

        at = start
        for a, b, kind, spot in pieces:
            if kind == 'hold':
                frames = round(HOLD * FPS)
                subprocess.run([
                    str(FFMPEG), '-hide_banner', '-loglevel', 'error', '-ss', f'{a:.3f}', '-i', raw,
                    '-vf', 'scale=1920:1080', '-frames:v', '1', str(FRAMES / f'f{index:06d}.png'),
                ], check=True)
                for i in range(1, frames):
                    shutil.copyfile(FRAMES / f'f{index:06d}.png', FRAMES / f'f{index + i:06d}.png')
                index += frames
                spec['spots'].append({'start': at, 'duration': HOLD, 'label': spot['label'], 'box': spot['box']})
                at += frames / FPS
                continue
            target = per_wait if kind == 'wait' else (b - a) / speed
            frames = max(1, round(target * FPS))
            subprocess.run([
                str(FFMPEG), '-hide_banner', '-loglevel', 'error', '-ss', f'{a:.3f}', '-t', f'{b - a:.3f}', '-i', raw,
                '-vf', 'scale=1920:1080', '-r', f'{frames / (b - a):.5f}', '-frames:v', str(frames),
                '-start_number', str(index), str(FRAMES / 'f%06d.png'),
            ], check=True)
            written = len(list(FRAMES.glob('f*.png'))) - index
            index += written
            if kind == 'wait' and b - a > 3:
                spec['waits'].append({'start': at, 'duration': written / FPS, 'label': '⏩ ' + real(b - a)})
            at += written / FPS

        caption = captions.get(segment)
        spec['segments'].append({
            'id': segment, 'start': start, 'duration': at - start,
            'step': caption['step'] if caption else '', 'caption': caption['text'] if caption else '',
        })
        print(f'{segment:10s} {start:5.1f}s  +{at - start:4.1f}s  (x{speed:.1f}, {holds} spotlight(s))')
        start = at

    (OUT / 'spec.json').write_text(json.dumps(spec, ensure_ascii=False, indent=1))
    print(f'{index} frames, {start:.1f}s')
    if start > MAX_LENGTH:
        sys.exit(f'The video lasts {start:.1f}s, more than {MAX_LENGTH:.0f}s: shorten SEGMENTS.')


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
