"""The three speech engines behind small interfaces, so the API can be tested
without models (FakeEngines) and an engine can be swapped without touching it.

Real engines load lazily on first use and stay loaded: a Whisper model takes
seconds to load and should not be loaded per request.
"""

from __future__ import annotations

import io
import math
import os
import struct
import tempfile
import threading
import wave
from dataclasses import dataclass, field
from typing import Protocol

from .config import Settings

SAMPLE_RATE = 16000  # what the wake-word socket expects: mono int16 PCM
WAKE_FRAME = 1280    # 80 ms at 16 kHz, openWakeWord's frame size


@dataclass
class Transcript:
    text: str
    language: str | None
    language_probability: float | None
    duration: float
    segments: list[dict] = field(default_factory=list)


class SpeechToText(Protocol):
    def transcribe(self, audio: bytes, filename: str, language: str | None) -> Transcript: ...


class TextToSpeech(Protocol):
    def voices(self) -> list[dict]: ...

    def synthesize(self, text: str, voice: str, rate: float) -> bytes: ...


class WakeWordDetector(Protocol):
    """One per socket: holds that listener's audio buffer."""

    def feed(self, pcm16: bytes) -> list[tuple[str, float]]: ...


class AudioTooLong(ValueError):
    pass


class UnknownVoice(ValueError):
    pass


def voice_info(name: str) -> dict:
    """en_GB-alan-medium → language en_GB, speaker alan, quality medium."""
    parts = name.split("-")
    return {
        "id": name,
        "language": parts[0] if parts else name,
        "speaker": parts[1].replace("_", " ").title() if len(parts) > 1 else name,
        "quality": parts[2] if len(parts) > 2 else None,
    }


# ---------------------------------------------------------------- real engines


class WhisperSpeechToText:
    """faster-whisper (CTranslate2): Whisper at int8 on a CPU, 4× faster than the
    reference implementation. It decodes any container the browser records
    (WebM/Opus, Ogg, MP4, WAV) through PyAV."""

    def __init__(self, s: Settings):
        self.s = s
        self._model = None
        self._lock = threading.Lock()

    def _load(self):
        with self._lock:
            if self._model is None:
                from faster_whisper import WhisperModel

                self._model = WhisperModel(self.s.stt_model, device=self.s.stt_device, compute_type=self.s.stt_compute_type)
        return self._model

    def transcribe(self, audio: bytes, filename: str, language: str | None) -> Transcript:
        model = self._load()
        suffix = os.path.splitext(filename)[1] or ".webm"
        # A named file: PyAV probes the container from it more reliably than from a stream
        with tempfile.NamedTemporaryFile(suffix=suffix) as f:
            f.write(audio)
            f.flush()
            segments, info = model.transcribe(
                f.name,
                language=language,
                beam_size=1,
                vad_filter=True,  # drops silence before and after, and hallucinated text in it
                condition_on_previous_text=False,
            )
            if info.duration > self.s.max_audio_seconds:
                raise AudioTooLong(f"{info.duration:.0f} s is longer than {self.s.max_audio_seconds:.0f} s")
            segs = [{"start": round(s.start, 2), "end": round(s.end, 2), "text": s.text.strip()} for s in segments]
        return Transcript(
            text=" ".join(s["text"] for s in segs).strip(),
            language=info.language,
            language_probability=round(info.language_probability, 3),
            duration=round(info.duration, 2),
            segments=segs,
        )


class PiperTextToSpeech:
    """Piper (OHF-Voice/piper1-gpl): small neural voices that run faster than
    real time on a CPU. Each voice is an .onnx model with its .onnx.json config."""

    def __init__(self, s: Settings):
        self.s = s
        self._voices: dict[str, object] = {}
        self._lock = threading.Lock()

    def voices(self) -> list[dict]:
        return [voice_info(v) | {"installed": os.path.exists(self._path(v))} for v in self.s.voices]

    def _path(self, voice: str) -> str:
        return os.path.join(self.s.voices_dir, f"{voice}.onnx")

    def _load(self, voice: str):
        if voice not in self.s.voices:
            raise UnknownVoice(voice)
        with self._lock:
            if voice not in self._voices:
                from piper import PiperVoice

                self._voices[voice] = PiperVoice.load(self._path(voice))
        return self._voices[voice]

    def synthesize(self, text: str, voice: str, rate: float) -> bytes:
        from piper import SynthesisConfig

        piper_voice = self._load(voice)
        buf = io.BytesIO()
        with wave.open(buf, "wb") as wav:
            # length_scale is the inverse of speed: 0.8 speaks 25 % faster
            piper_voice.synthesize_wav(text, wav, syn_config=SynthesisConfig(length_scale=1 / rate))
        return buf.getvalue()


class OpenWakeWordDetector:
    """openWakeWord: small ONNX models scoring each 80 ms frame; "hey_jarvis"
    is one of its pre-trained words. One model per listener (it keeps state)."""

    def __init__(self, s: Settings):
        from openwakeword.model import Model

        self.s = s
        self.model = Model(wakeword_models=s.wake_words, inference_framework="onnx")
        self.buffer = b""

    def feed(self, pcm16: bytes) -> list[tuple[str, float]]:
        import numpy as np

        self.buffer += pcm16
        hits: list[tuple[str, float]] = []
        frame_bytes = WAKE_FRAME * 2
        while len(self.buffer) >= frame_bytes:
            frame, self.buffer = self.buffer[:frame_bytes], self.buffer[frame_bytes:]
            scores = self.model.predict(np.frombuffer(frame, dtype=np.int16))
            for word, score in scores.items():
                if score >= self.s.wake_threshold:
                    hits.append((word, float(score)))
                    self.model.reset()  # one detection per utterance
        return hits


# ---------------------------------------------------------------- fake engines


class FakeSpeechToText:
    """Audio whose bytes start with ``TEXT:`` "says" the rest; anything else is silence."""

    def transcribe(self, audio: bytes, filename: str, language: str | None) -> Transcript:
        text = audio[5:].decode("utf-8", "replace").strip() if audio.startswith(b"TEXT:") else ""
        return Transcript(text=text, language=language or "en", language_probability=1.0,
                          duration=round(len(text.split()) * 0.4, 2),
                          segments=[{"start": 0.0, "end": round(len(text.split()) * 0.4, 2), "text": text}] if text else [])


class FakeTextToSpeech:
    """A real, playable WAV: a quiet tone, 60 ms per character at rate 1."""

    def __init__(self, s: Settings):
        self.s = s

    def voices(self) -> list[dict]:
        return [voice_info(v) | {"installed": True} for v in self.s.voices]

    def synthesize(self, text: str, voice: str, rate: float) -> bytes:
        if voice not in self.s.voices:
            raise UnknownVoice(voice)
        return tone_wav(seconds=min(30.0, 0.06 * len(text) / rate))


class FakeWakeWordDetector:
    """Wakes on any frame louder than -10 dBFS: tests send a loud burst."""

    def __init__(self, s: Settings):
        self.s = s

    def feed(self, pcm16: bytes) -> list[tuple[str, float]]:
        return [(self.s.wake_words[0], 0.99)] if rms(pcm16) > 0.3 else []


def rms(pcm16: bytes) -> float:
    n = len(pcm16) // 2
    if n == 0:
        return 0.0
    samples = struct.unpack(f"<{n}h", pcm16[: n * 2])
    return math.sqrt(sum(s * s for s in samples) / n) / 32768


def tone_wav(seconds: float, hz: float = 440.0, rate: int = 22050) -> bytes:
    buf = io.BytesIO()
    with wave.open(buf, "wb") as wav:
        wav.setnchannels(1)
        wav.setsampwidth(2)
        wav.setframerate(rate)
        frames = int(seconds * rate)
        wav.writeframes(b"".join(struct.pack("<h", int(1500 * math.sin(2 * math.pi * hz * i / rate))) for i in range(frames)))
    return buf.getvalue()


class Engines:
    def __init__(self, s: Settings):
        self.s = s
        if s.engines == "fake":
            self.stt: SpeechToText = FakeSpeechToText()
            self.tts: TextToSpeech = FakeTextToSpeech(s)
        else:
            self.stt = WhisperSpeechToText(s)
            self.tts = PiperTextToSpeech(s)

    def wake_detector(self) -> WakeWordDetector:
        return FakeWakeWordDetector(self.s) if self.s.engines == "fake" else OpenWakeWordDetector(self.s)
