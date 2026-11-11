# Home Helper voice service: faster-whisper, Piper and openWakeWord on a CPU.
# docker compose --profile voice up -d voice  (docs/voice.md)
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 \
    HF_HOME=/models/huggingface VOICE_TTS_DIR=/models/piper

WORKDIR /srv
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY download-models.sh .
# Models go in the image (about 1 GB with Whisper small): the service never
# downloads anything at run time, so it works with no internet at all
ARG VOICE_STT_MODEL=small
RUN VOICE_STT_MODEL=$VOICE_STT_MODEL sh download-models.sh

COPY app ./app
RUN useradd --system --uid 1001 voice && chown -R voice /models
USER voice

EXPOSE 8090
HEALTHCHECK --interval=30s --timeout=5s CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8090/health')"
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8090", "--ws-max-size", "65536"]
