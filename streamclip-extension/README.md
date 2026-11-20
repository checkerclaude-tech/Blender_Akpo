# StreamClip – Stream Recorder (Chrome extension, Manifest V3)

Records whatever stream is playing in a tab (YouTube, Twitch, Kick, any web player) for
**5 min, 10 min, 30 min, 1 hour, 5 hours** or until you stop it, and saves the transcript and
comments alongside the video, each stamped with its time in the recording.

## Install
1. `chrome://extensions` → enable **Developer mode** → **Load unpacked** → pick this folder.
2. Open the stream tab, click the StreamClip icon, choose a duration, press **Start recording**.
3. Watch/export from the **Library** link in the popup.

## What gets captured
| Data | Source |
|---|---|
| Video + audio | `chrome.tabCapture` → `MediaRecorder` (WebM) in an offscreen document, written to IndexedDB in 5 s slices (a 5 h recording never sits in RAM) |
| Transcript | (a) the stream's own captions (`<track>` cues and on-page caption overlays), and/or (b) optional AI transcript: audio chunks sent to the OpenAI transcription API with **your** key (Settings) |
| Comments | live chat of YouTube (chat iframe), Twitch, Kick; any other site via CSS selectors in Settings; plus your own notes typed in the popup or `Alt+Shift+B` bookmarks |

Every comment/caption/transcript line is stored with milliseconds since the recording started.
The Library plays the video with a synced feed (click a line to jump there, markers on the
timeline for comments and notes) and exports: video `.webm`, `.srt`, `.vtt`, combined `.txt`,
comments `.csv`, everything `.json`.

## Limits / notes
- Recording is real-time: a 5 h recording takes 5 h. Keep the tab open (it can be in the background; the browser must stay running).
- Size: ~0.45 / 1.2 / 2.3 GB per hour at Low / Medium / High quality (popup shows an estimate).
- Without an API key, transcripts depend on the stream having captions. Browser speech recognition cannot listen to tab audio, so no free offline STT is included.
- Comment selectors for Twitch/Kick may need updating when those sites change their markup (use the custom selectors in Settings).
- Only record content you have the right to record.
- Not tested in a live Chrome in this build environment (JS syntax-checked only) — expect small fixes on first run.
