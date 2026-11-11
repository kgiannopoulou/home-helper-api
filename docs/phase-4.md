# Phase 4: Voice assistant

**Goal:** make it feel like JARVIS.
**Deliverable:** you can talk to Home Helper instead of typing.
**Done:** 2026-11-11. Full description: [voice.md](voice.md).

| Day | Task | Result |
|---:|---|---|
| 43 | Research and select speech-to-text | faster-whisper (local, CPU, int8) over whisper.cpp, Vosk, the browser's Web Speech API and cloud APIs; Piper for speech; openWakeWord for "Hey Jarvis". Privacy first: audio never leaves the house. [Comparison](voice.md#choosing-the-speech-technology-day-43) |
| 44 | Speech-to-text in a separate service | `services/voice`: FastAPI, `POST /v1/stt`, token auth, 10 MB / 60 s limits, models baked into the image, fake engines and a pytest suite with its own CI job |
| 45 | Text-to-speech | `POST /v1/tts` with Piper: five voices (British, American, Greek), speed 0.5–2× |
| 46 | The voice interaction API | `VoiceTurns`: audio (or the browser's text) → the assistant, told it is speaking → a speakable answer → speech → a signed audio URL. "Confirm"/"cancel" by voice. API and dashboard endpoints |
| 47 | Voice input on the dashboard | A voice bar on the assistant page: MediaRecorder, push to talk (button or Space), automatic end of speech |
| 48 | Spoken responses | The answer played from the service, or by `speechSynthesis`; `Speakable` turns lists, money, dates and units into words |
| 49 | Rest and review | Built and ran the real image: Piper → Whisper round trip word for word; "Hey Jarvis" detected at 0.91–0.96 and ignored otherwise. Fixed what only the real models showed: openWakeWord's Linux dependency, a PyAV incompatibility, a clashing user name. The Vitest suite also caught the key handler crashing when an event's target was the window. [Results](voice.md#checked-with-the-real-models-day-49-review) |
| 50 | Wake-word detection | openWakeWord's "hey jarvis" on the service; the browser streams 16 kHz PCM over a WebSocket only while resting, with a ticket Laravel signs |
| 51 | Microphone controls | The mic opens only while listening; a red dot; Mute; nothing listens in a background tab |
| 52 | Continuous conversation | After an answer it listens 6 s for a follow-up, in the same conversation |
| 53 | Interruption and cancellation | Talking over an answer stops it and is heard; Stop/Escape aborts the request and stops the turn on the server between model rounds, and the next turn knows the answer never came |
| 54 | Voices and response styles | Five voices, four styles (brief, conversational, detailed, butler), speed and language in Settings → Voice |
| 55 | Voice privacy controls | No recordings stored; answers gone after 10 minutes; wake word opt-in; retention of 1, 7 or 30 days; keep voice out of memory; delete all voice history |
| 56 | Test the whole experience | A spoken conversation on the demo household (question, follow-up, "yes" confirming a change), the machine, VAD and controls in Vitest, the service in pytest, and the real models by hand |

## In numbers

- A Python service (5 endpoints), 1 migration (`voice_settings`, `ai_conversations.channel`), 8 Laravel endpoints, a settings page, a voice bar, 1 scheduled job.
- Pest: 516 → **536** tests. Vitest: 30 → **46**. pytest: **15**.

## Not done yet

- The phone app: a talk button on the same API.
- Speaker recognition (who is talking), so "my" means the right person on a shared device.
- A custom wake word ("Hey Home Helper") trained with openWakeWord.
- Streaming: start speaking the first sentence while the rest is still being written.
