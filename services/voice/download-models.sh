#!/bin/sh
# Downloads every model the service uses, at image build time.
set -eu
VOICES="${VOICE_TTS_VOICES:-en_GB-alan-medium en_US-lessac-medium en_GB-jenny_dioco-medium en_US-ryan-high el_GR-rapunzelina-low}"

mkdir -p /models/piper
python -m piper.download_voices --download-dir /models/piper $(echo "$VOICES" | tr ',' ' ')
python -c "from faster_whisper import WhisperModel; WhisperModel('${VOICE_STT_MODEL:-small}', device='cpu', compute_type='int8')"
python -c "import openwakeword.utils as u; u.download_models(['hey_jarvis'])"
