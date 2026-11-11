"""Settings for the voice service, all from environment variables.

The service is a sidecar of the Laravel app (docs/voice.md): it turns speech into
text, text into speech, and listens for the wake word. It keeps nothing: audio
is processed in memory and discarded when the request ends.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, field


def _list(name: str, default: str) -> list[str]:
    return [v.strip() for v in os.environ.get(name, default).split(",") if v.strip()]


@dataclass(frozen=True)
class Settings:
    # Shared with Laravel (VOICE_SERVICE_TOKEN): every HTTP call needs it
    token: str = field(default_factory=lambda: os.environ.get("VOICE_SERVICE_TOKEN", ""))
    # "real" loads faster-whisper, Piper and openWakeWord; "fake" answers
    # deterministically without models, for tests and CI
    engines: str = field(default_factory=lambda: os.environ.get("VOICE_ENGINES", "real"))

    # Speech to text (faster-whisper)
    stt_model: str = field(default_factory=lambda: os.environ.get("VOICE_STT_MODEL", "small"))
    stt_device: str = field(default_factory=lambda: os.environ.get("VOICE_STT_DEVICE", "cpu"))
    stt_compute_type: str = field(default_factory=lambda: os.environ.get("VOICE_STT_COMPUTE", "int8"))
    max_audio_bytes: int = field(default_factory=lambda: int(os.environ.get("VOICE_MAX_AUDIO_BYTES", str(10 * 1024 * 1024))))
    max_audio_seconds: float = field(default_factory=lambda: float(os.environ.get("VOICE_MAX_AUDIO_SECONDS", "60")))

    # Text to speech (Piper): voice files live in voices_dir as <name>.onnx + .onnx.json
    voices_dir: str = field(default_factory=lambda: os.environ.get("VOICE_TTS_DIR", "/models/piper"))
    voices: list[str] = field(default_factory=lambda: _list(
        "VOICE_TTS_VOICES",
        "en_GB-alan-medium,en_US-lessac-medium,en_GB-jenny_dioco-medium,en_US-ryan-high,el_GR-rapunzelina-low",
    ))
    max_tts_chars: int = field(default_factory=lambda: int(os.environ.get("VOICE_MAX_TTS_CHARS", "2000")))

    # Wake word (openWakeWord)
    wake_words: list[str] = field(default_factory=lambda: _list("VOICE_WAKE_WORDS", "hey_jarvis"))
    wake_threshold: float = field(default_factory=lambda: float(os.environ.get("VOICE_WAKE_THRESHOLD", "0.5")))
    # Browsers may only connect from these origins
    allowed_origins: list[str] = field(default_factory=lambda: _list("VOICE_ALLOWED_ORIGINS", "http://localhost:8000"))


def settings() -> Settings:
    return Settings()
