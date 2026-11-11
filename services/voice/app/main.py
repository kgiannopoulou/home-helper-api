"""Home Helper voice service (docs/voice.md).

    GET  /health                 liveness, which engines are loaded
    POST /v1/stt                 audio (multipart "audio") → text           Bearer token
    POST /v1/tts                 {"text", "voice", "rate"} → audio/wav      Bearer token
    GET  /v1/voices              the voices it can speak with              Bearer token
    WS   /v1/wake?ticket=…       16 kHz mono int16 PCM in → {"event": "wake"} out

Nothing is written to disk except a temporary file for the decoder, deleted
when the request ends; nothing is logged but sizes and timings.
"""

from __future__ import annotations

import json
import logging
import time
from functools import lru_cache

from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, Query, UploadFile, WebSocket, WebSocketDisconnect
from fastapi.responses import Response
from pydantic import BaseModel, Field

from .auth import bearer_ok, verify_ticket
from .config import Settings, settings
from .engines import AudioTooLong, Engines, UnknownVoice

log = logging.getLogger("voice")
app = FastAPI(title="Home Helper voice", version="1.0.0", docs_url=None, redoc_url=None)


@lru_cache
def engines() -> Engines:
    return Engines(settings())


def config() -> Settings:
    return settings()


def require_token(authorization: str | None = Header(default=None), s: Settings = Depends(config)) -> None:
    if not bearer_ok(authorization, s.token):
        raise HTTPException(status_code=401, detail="A valid service token is required.")


@app.get("/health")
def health(s: Settings = Depends(config)) -> dict:
    return {"status": "ok", "engines": s.engines, "stt_model": s.stt_model, "voices": s.voices, "wake_words": s.wake_words}


@app.post("/v1/stt", dependencies=[Depends(require_token)])
async def stt(
    audio: UploadFile = File(...),
    language: str | None = Form(default=None, max_length=5),
    s: Settings = Depends(config),
) -> dict:
    data = await audio.read(s.max_audio_bytes + 1)
    if len(data) > s.max_audio_bytes:
        raise HTTPException(status_code=413, detail=f"Audio larger than {s.max_audio_bytes // 1024 // 1024} MB.")
    if not data:
        raise HTTPException(status_code=422, detail="The audio is empty.")

    started = time.perf_counter()
    try:
        t = engines().stt.transcribe(data, audio.filename or "audio.webm", language or None)
    except AudioTooLong as e:
        raise HTTPException(status_code=413, detail=f"The recording is too long: {e}.") from e
    except Exception as e:  # a corrupt file, an unknown codec
        log.warning("stt failed: %s (%d bytes)", type(e).__name__, len(data))
        raise HTTPException(status_code=422, detail="The audio could not be decoded.") from e

    took = round(time.perf_counter() - started, 3)
    log.info("stt %d bytes, %.1f s of audio in %.2f s", len(data), t.duration, took)
    return {
        "text": t.text,
        "language": t.language,
        "language_probability": t.language_probability,
        "duration": t.duration,
        "segments": t.segments,
        "processing_seconds": took,
    }


class SpeakRequest(BaseModel):
    text: str = Field(min_length=1)
    voice: str | None = None
    rate: float = Field(default=1.0, ge=0.5, le=2.0)


@app.post("/v1/tts", dependencies=[Depends(require_token)])
def tts(body: SpeakRequest, s: Settings = Depends(config)) -> Response:
    if len(body.text) > s.max_tts_chars:
        raise HTTPException(status_code=413, detail=f"At most {s.max_tts_chars} characters.")
    voice = body.voice or s.voices[0]
    started = time.perf_counter()
    try:
        wav = engines().tts.synthesize(body.text, voice, body.rate)
    except UnknownVoice as e:
        raise HTTPException(status_code=422, detail=f"No voice called {e}.") from e
    log.info("tts %d chars with %s in %.2f s", len(body.text), voice, time.perf_counter() - started)
    return Response(content=wav, media_type="audio/wav", headers={"X-Voice": voice})


@app.get("/v1/voices", dependencies=[Depends(require_token)])
def voices() -> dict:
    return {"data": engines().tts.voices()}


@app.websocket("/v1/wake")
async def wake(socket: WebSocket, ticket: str | None = Query(default=None)) -> None:
    s = settings()
    origin = socket.headers.get("origin")
    user = verify_ticket(ticket, s.token)
    if user is None or (origin is not None and origin not in s.allowed_origins):
        await socket.close(code=4401)
        return

    await socket.accept()
    detector = engines().wake_detector()
    await socket.send_text(json.dumps({"event": "ready", "words": s.wake_words, "sample_rate": 16000}))
    try:
        while True:
            message = await socket.receive()
            if message.get("type") == "websocket.disconnect":
                break
            pcm = message.get("bytes")
            if not pcm:
                continue
            for word, score in detector.feed(pcm):
                await socket.send_text(json.dumps({"event": "wake", "word": word, "score": round(score, 3)}))
    except WebSocketDisconnect:
        pass
