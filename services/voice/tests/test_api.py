"""The voice service's API with the fake engines (no models): authentication,
limits, the shapes Laravel relies on, and the wake-word socket."""

from __future__ import annotations

import io
import os
import struct
import time
import wave

import pytest
from fastapi.testclient import TestClient
from starlette.websockets import WebSocketDisconnect

os.environ.update({"VOICE_ENGINES": "fake", "VOICE_SERVICE_TOKEN": "secret", "VOICE_MAX_AUDIO_BYTES": "1000",
                   "VOICE_ALLOWED_ORIGINS": "http://localhost:8000"})

from app.auth import sign_ticket, verify_ticket  # noqa: E402
from app.engines import tone_wav, voice_info  # noqa: E402
from app.main import app  # noqa: E402

client = TestClient(app)
AUTH = {"Authorization": "Bearer secret"}


def test_health_needs_no_token():
    r = client.get("/health")
    assert r.status_code == 200
    assert r.json()["engines"] == "fake"
    assert "hey_jarvis" in r.json()["wake_words"]


@pytest.mark.parametrize("headers", [{}, {"Authorization": "Bearer wrong"}, {"Authorization": "secret"}])
def test_every_endpoint_needs_the_token(headers):
    assert client.post("/v1/stt", files={"audio": ("a.webm", b"TEXT:hi")}, headers=headers).status_code == 401
    assert client.post("/v1/tts", json={"text": "hi"}, headers=headers).status_code == 401
    assert client.get("/v1/voices", headers=headers).status_code == 401


def test_speech_to_text():
    r = client.post("/v1/stt", files={"audio": ("q.webm", b"TEXT:what do I need to buy", "audio/webm")}, data={"language": "en"}, headers=AUTH)

    assert r.status_code == 200
    body = r.json()
    assert body["text"] == "what do I need to buy"
    assert body["language"] == "en"
    assert body["segments"][0]["text"] == "what do I need to buy"
    assert body["duration"] > 0


def test_speech_to_text_limits():
    assert client.post("/v1/stt", files={"audio": ("q.webm", b"")}, headers=AUTH).status_code == 422
    assert client.post("/v1/stt", files={"audio": ("q.webm", b"x" * 1001)}, headers=AUTH).status_code == 413
    # Silence is not an error: there is just nothing to say
    assert client.post("/v1/stt", files={"audio": ("q.webm", b"\x00" * 100)}, headers=AUTH).json()["text"] == ""


def test_text_to_speech_is_a_playable_wav():
    r = client.post("/v1/tts", json={"text": "Good morning", "voice": "en_GB-alan-medium", "rate": 1.25}, headers=AUTH)

    assert r.status_code == 200
    assert r.headers["content-type"] == "audio/wav"
    assert r.headers["x-voice"] == "en_GB-alan-medium"
    with wave.open(io.BytesIO(r.content)) as w:
        assert w.getnchannels() == 1 and w.getnframes() > 0


def test_text_to_speech_validation():
    assert client.post("/v1/tts", json={"text": "hi", "voice": "nope"}, headers=AUTH).status_code == 422
    assert client.post("/v1/tts", json={"text": ""}, headers=AUTH).status_code == 422
    assert client.post("/v1/tts", json={"text": "hi", "rate": 3}, headers=AUTH).status_code == 422
    # Without a voice: the first configured one
    assert client.post("/v1/tts", json={"text": "hi"}, headers=AUTH).headers["x-voice"] == "en_GB-alan-medium"


def test_voices():
    data = client.get("/v1/voices", headers=AUTH).json()["data"]
    assert data[0] == {"id": "en_GB-alan-medium", "language": "en_GB", "speaker": "Alan", "quality": "medium", "installed": True}
    assert voice_info("en_GB-jenny_dioco-medium")["speaker"] == "Jenny Dioco"


def test_tickets_are_signed_and_expire():
    now = int(time.time())
    ticket = sign_ticket(7, now + 60, "secret")

    assert verify_ticket(ticket, "secret") == 7
    assert verify_ticket(ticket, "other") is None
    assert verify_ticket(ticket.replace("7.", "8.", 1), "secret") is None
    assert verify_ticket(sign_ticket(7, now - 1, "secret"), "secret") is None
    assert verify_ticket("garbage", "secret") is None
    # Laravel signs the same way (tests/Feature/Voice/WakeTicketTest pins this value)
    assert sign_ticket(42, 1900000000, "secret") == "42.1900000000.95e44af0e58fc92f30b4a439b1f1cf732f6a4617217f0acbd03a3bc154cf6cfa"


def pcm(amplitude: int, samples: int = 1280) -> bytes:
    return struct.pack(f"<{samples}h", *([amplitude] * samples))


def test_wake_socket_detects_and_stays_quiet_otherwise():
    ticket = sign_ticket(7, int(time.time()) + 60, "secret")
    with client.websocket_connect(f"/v1/wake?ticket={ticket}", headers={"origin": "http://localhost:8000"}) as ws:
        assert ws.receive_json() == {"event": "ready", "words": ["hey_jarvis"], "sample_rate": 16000}
        ws.send_bytes(pcm(100))       # quiet: nothing
        ws.send_bytes(pcm(20000))     # loud: the fake detector wakes
        assert ws.receive_json() == {"event": "wake", "word": "hey_jarvis", "score": 0.99}


@pytest.mark.parametrize("query,origin", [("", "http://localhost:8000"), ("?ticket=1.1.x", "http://localhost:8000"), ("?ticket=GOOD", "https://evil.example")])
def test_wake_socket_refuses_without_a_valid_ticket_or_from_another_site(query, origin):
    query = query.replace("GOOD", sign_ticket(7, int(time.time()) + 60, "secret"))
    with pytest.raises(WebSocketDisconnect) as e:
        with client.websocket_connect(f"/v1/wake{query}", headers={"origin": origin}) as ws:
            ws.receive_json()
    assert e.value.code == 4401


def test_tone_wav_length():
    with wave.open(io.BytesIO(tone_wav(0.5))) as w:
        assert abs(w.getnframes() / w.getframerate() - 0.5) < 0.01
