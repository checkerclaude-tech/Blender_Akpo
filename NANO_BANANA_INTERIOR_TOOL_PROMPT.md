# Nano Banana — Consistent Interior Photo Generator: Build Prompt

How to use: paste everything under "THE PROMPT" into Google AI Studio (Build mode) or into Claude Code.
It builds a web tool that turns a room plan, a description, a style image and camera positions into 4 photo-real interior views that match each other.

Read "Why this design" first. It explains why the tool is built this way, and it sets honest expectations.

---

## Why this design (read first)

Nano Banana (Gemini image models) has no 3D memory. If you only send text, every view invents a slightly different room.
Consistency comes from the **tool**, not from a better adjective. The tool below does five things:

1. **Room Bible**: every fixed fact (dimensions, materials, colours, objects with x/y/z coordinates) is stored once as structured data. Every prompt is compiled from it, so nothing drifts.
2. **Camera geometry is calculated, not guessed.** The tool works out which walls and objects are in frame, and in what left-to-right order, for each camera. It writes that into the prompt.
3. **Layout guide image**: for each camera the tool draws a simple perspective box-model of the room (walls, floor, door and window openings, object boxes) from that exact camera. This is sent as a control image. It is the main lever for dimensional accuracy.
4. **Chained references**: view 1 (the anchor) is generated first and approved. Views 2–4 receive it as a reference ("same room, same objects, new camera").
5. **QA and repair loop**: a vision pass compares each result to the Bible and reports mismatches. The tool then fixes only those items with a targeted edit instead of regenerating everything.

Honest limit: this gets you very close (same items, positions, colours, proportions) and it is clearly better than prompting alone. A diffusion model still cannot guarantee exact millimetre geometry. For contract-grade accuracy, use the tool for client visuals and keep the Revit/SketchUp model as the authority. The QA loop and the "Accept / Fix item / Regenerate" buttons exist to close the remaining gap.

---

## THE PROMPT (copy from here)

```
ROLE
You are a senior full-stack engineer and architectural-visualisation technical artist.
Build a production-quality web app called "RoomSet Studio": a consistent multi-angle
interior photo generator using Google's Gemini image models ("Nano Banana").

GOAL
The user (an architect/designer) does this, in order:
  1. Chooses a Project and a Room (hall, passage, toilet, kitchen, office, bedroom, etc.).
  2. Describes how the room looks (free text AND structured fields).
  3. Uploads one or more inspiration images.
  4. Uploads or draws the room plan, and sets the room's real dimensions.
  5. Places up to 4 cameras on the plan (position, direction, field of view, height, tilt).
  6. Clicks GENERATE.
The app returns 4 photorealistic interior photographs of that room, one per camera, that
are CONSISTENT with each other and with the plan:
  - same room dimensions and proportions
  - every object appears in the same position, size, colour, material and count in every
    view in which it is visible
  - nothing appears or disappears between views unless it is outside that camera's frame
  - perspective and optics are physically plausible for the chosen lens
  - the position of every item matches the plan

TECH STACK
- React + TypeScript + Vite, Tailwind, Zustand (state), IndexedDB (idb) for persistence.
- @google/genai SDK, called from the browser using a user-supplied API key stored in
  localStorage (add a Settings panel; never hard-code keys).
- Image model IDs must be configurable in Settings, with defaults:
    PRIMARY  = "gemini-3-pro-image-preview"   (Nano Banana Pro: best consistency, up to 14 reference images)
    FAST     = "gemini-2.5-flash-image"       (Nano Banana: drafts)
    VISION   = "gemini-2.5-pro" or newest available (QA text/vision pass)
  Always read model IDs from config, because names change.
- Canvas: Konva.js (or fabric.js) for the plan editor. Three.js is used OFFSCREEN only to
  render the layout-guide images (see Module 5).
- Export: JSZip, plus a PDF contact sheet (jsPDF).

DATA MODEL (TypeScript; this is the single source of truth)
type Project  = { id; name; units:'mm'|'m'|'ft'; styleBible: StyleBible; rooms: Room[] }
type StyleBible = {                       // project-wide look, reused in every room
  palette: {name:string; hex:string; usage:string}[];   // e.g. "Wall white #F4F2EC - all walls"
  materials: {id:string; name:string; finish:string; hex?:string; refImageId?:string}[];
  lighting: {style:string; colorTempK:number; notes:string};
  doorWindowSpec: string;                // e.g. "black powder-coated aluminium frames, clear glass"
  doNotUse: string[];                    // negatives, e.g. "no gold, no chandeliers, no patterned wallpaper"
}
type Room = {
  id; name; type: 'hall'|'passage'|'toilet'|'kitchen'|'office'|'bedroom'|'stair'|'store'|'other';
  level: string;                         // "Ground floor"
  dims: { widthX:number; depthY:number; ceilingZ:number; unit:'mm' };   // interior clear size
  planImageId?: string;                  // uploaded plan crop
  planScale?: { pxPerUnit:number };      // set by user clicking two points + typing a real distance
  polygon?: {x:number;y:number}[];       // optional non-rectangular outline, in mm
  description: string;                   // free text from the user
  inspirationImageIds: string[];
  surfaces: { floor:Mat; ceiling:Mat; walls: {wallId:'N'|'E'|'S'|'W'|string; mat:Mat; note?:string}[] };
  openings: Opening[];                   // doors / windows / niches
  objects: RoomObject[];                 // every fixed or placed item
  lightingPlan: {fixtureType:string; x:number; y:number; z:number; note?:string}[];
  cameras: Camera[];                     // max 4
  generations: Generation[];
}
type Mat = { materialId:string; colorHex?:string; note?:string };
type Opening = { id; kind:'door'|'window'|'archway'|'niche'; wallId:string; offsetFromCornerMm:number;
                 widthMm:number; heightMm:number; sillMm:number; swing?:'in'|'out'|'sliding';
                 frame:string; glass?:string; state:'closed'|'open'|'ajar' };
type RoomObject = { id; label:string; category:string;
                 x:number; y:number; z:number;           // centre-bottom in room coords (mm), origin = SW corner floor
                 w:number; d:number; h:number;           // bounding box (mm)
                 rotationDeg:number; count?:number;
                 materialDesc:string; colorHex:string; refImageId?:string;
                 locked:boolean };                       // locked = must never change
type Camera = { id:'C1'|'C2'|'C3'|'C4'; label:string;
                x:number; y:number; heightZ:number;      // lens position, mm
                headingDeg:number;                       // 0 = North, clockwise
                tiltDeg:number;                          // + up, - down
                focalMm:number;                          // 35mm-equivalent: 16,18,24,28,35
                aspect:'3:2'|'4:3'|'16:9'|'1:1'|'4:5';
                sensor:'full-frame' };
type Generation = { id; cameraId; promptText; layoutGuideImageId; referenceIds:string[];
                    resultImageId; qaReport?: QAReport; status:'draft'|'approved'|'needs-fix'; createdAt };

MODULE 1 - PROJECT & STYLE BIBLE
Left sidebar tree: Project > Level > Room. A "Style Bible" tab lets the user define palette,
materials (with optional swatch photos), lighting, door/window spec and a do-not-use list.
Every room inherits it, so a whole building stays visually coherent. Include "Import from
inspiration image": send the image to the VISION model and ask it to return JSON
(palette hexes, materials, furniture style, lighting mood). The user edits it. It is never
auto-applied silently.

MODULE 2 - ROOM EDITOR
- Fields: scene/room type, name, free-text description, structured surfaces/openings/objects.
- "Describe -> Structure" button: the VISION model converts the free-text description into
  the structured Bible (surfaces, openings, objects with estimated x/y/z/w/d/h). Show a
  diff-style review table. Nothing is accepted until the user approves, and every number is editable.
- Inspiration images: multi-upload; each can be tagged "mood", "materials", "furniture",
  "lighting" or "exact object" so only the relevant role is used in prompts.
- Objects table + drag-to-place on the plan. Each object has a Lock toggle.
- Validation: warn if objects overlap, exceed room bounds, block a door swing, or are taller than the ceiling.

MODULE 3 - PLAN EDITOR (core UX)
- Upload a plan image/PDF page (rasterise PDF with pdf.js), crop to the room, set scale by
  clicking two points and entering the real distance. Or draw the room as a rectangle/polygon from dims.
- Show gridlines/axis labels, N arrow, wall labels (N/E/S/W or numbered).
- Camera tool: drag a camera icon to place it; drag the cone handle to rotate heading;
  drag cone width to change FOV (tied to focal length); sliders for height, tilt, focal length.
  Draw the view cone live and shade which wall segments and objects fall inside it.
- Presets: "Four corners facing centre", "Four walls straight-on", "Door POV", "Hero diagonal".
  Always user-editable.
- Show live readout per camera: horizontal FOV, vertical FOV, distance to the far wall, and
  the list of visible walls/objects (see Module 4).
- Lens guidance: 16-18 mm for small toilets/stores, 24 mm for offices/passages, 24-28 mm
  for halls and kitchens, 35 mm for detail. Warn if FOV would distort (below 16 mm) or
  include objects behind the camera.

MODULE 4 - CAMERA GEOMETRY ENGINE (deterministic; no AI)
Implement pure functions with unit tests:
- hFOV = 2*atan(36 / (2*focalMm)); vFOV from the chosen aspect ratio.
- For each camera, compute the view frustum on the plan and, for each wall and object,
  decide: fully visible / partly visible / hidden. Use ray casting against the room polygon
  and object boxes for occlusion.
- Project each visible object's bounding box into normalised image coordinates
  (0-1 horizontal, 0-1 vertical) using a pinhole model. From that produce, per camera:
    * `frameLayout`: a left-to-right, near-to-far ordered list of what appears where
      ("left third: 3 grey fabric sofas against the west wall, nearest 1.2 m from the lens", etc.)
    * `visibleWalls` with approximate percentage of frame width each occupies
    * `ceilingFloorRatio`: roughly what share of the frame is ceiling vs floor
    * `eyeLevelPosition`: where the horizon sits in frame
- This block of text is injected into every prompt.

MODULE 5 - LAYOUT GUIDE RENDERER (the accuracy lever)
For each camera, render offscreen with Three.js, using the same focal length / sensor /
aspect as the camera:
- walls, floor and ceiling as flat-shaded boxes in neutral grey with slightly different
  tones per wall (so the model can tell walls apart)
- door/window openings cut out and tinted light blue; doors tinted brown
- every object as a coloured box, one distinct flat colour per object, with a small
  legend mapping colour -> label
- a thin perspective grid on the floor, and vertical corner lines
Export as PNG at the target aspect ratio. This image is sent to Nano Banana as a
"layout/control image". The prompt tells the model: "Treat this as a strict geometric
layout. Keep every wall, opening and object box in exactly the same place and proportion.
Replace the flat coloured boxes with the real objects described below."
Also send an optional line-art version (edges only). Provide a toggle to preview the guide
before generating.

MODULE 6 - PROMPT COMPILER
Build each request from fixed sections, in this order, with no free-form improvisation:
  A. TASK + OUTPUT RULES
  B. ROOM FACTS (dimensions, ceiling height, wall IDs, which wall is which compass direction)
  C. SURFACES (floor, ceiling, each wall: material + hex colour + finish)
  D. OPENINGS (each door/window: wall, offset, size, frame colour, glass type, state)
  E. OBJECT INVENTORY (every object: label, count, position in plain words AND mm,
     size, material, hex colour, orientation, "LOCKED")
  F. LIGHTING (fixture types, positions, colour temperature, time of day, shadow direction)
  G. CAMERA (lens mm, sensor, height, tilt, heading, aspect) + frameLayout from Module 4
  H. STYLE / MOOD (from Style Bible + inspiration role tags)
  I. CONSISTENCY CLAUSES + NEGATIVES (below)
  J. REFERENCE MAP: which attached image is which ("Image 1 = layout guide for this camera;
     Image 2 = approved anchor view of the same room; Image 3 = inspiration, mood only; ...")

Use this runtime template (fill the {{placeholders}} from data):

"""
Create ONE photorealistic interior photograph of the room described below.

REFERENCE IMAGES
{{referenceMap}}

HARD RULES - geometry
- Follow the layout-guide image exactly: same walls, same openings, same object positions
  and proportions. Replace the flat coloured boxes with the real objects described below.
- Do NOT add, remove, move, resize, recolour or re-style any object, opening or finish
  listed in the inventory. If an item is outside this camera's frame, simply do not show it.
- Do not invent extra furniture, decor, signs, people, text or windows.
- Straight vertical lines must stay vertical (architectural two-point perspective).
- Camera: {{focalMm}} mm full-frame lens, lens height {{heightZ}} mm, tilt {{tilt}} deg,
  {{aspect}} frame. Natural, undistorted wide-angle look with no fisheye.

ROOM: {{roomName}} ({{roomType}}), interior {{widthX}} x {{depthY}} x {{ceilingZ}} mm.
Compass: wall N = {{wallN}}, E = {{wallE}}, S = {{wallS}}, W = {{wallW}}.

SURFACES
{{surfacesList}}

OPENINGS
{{openingsList}}

OBJECT INVENTORY (every item is LOCKED - identical everywhere it appears)
{{objectsList}}

WHAT THIS CAMERA SEES (left to right, near to far)
{{frameLayout}}

LIGHTING
{{lighting}}   Shadows fall consistently from {{lightDirection}}.

STYLE
{{styleBible}}   Mood reference: {{moodNote}}.

PHOTO LOOK
Professional architectural photography, natural exposure, true-to-life colours,
subtle depth of field, clean, sharp, no HDR halos, no stylised filters.

AVOID
{{doNotUse}}, text overlays, watermarks, people, distortion, warped walls, duplicated
objects, mirrored/flipped layout, floating furniture, extra doors or windows.
"""

Consistency clauses (always appended to views 2-4):
"""
This is view {{n}} of 4 of the SAME room. Image 2 is the approved anchor photograph.
Every object visible in both images must be the identical object: same colour, material,
size, count, and position relative to the walls. Only the camera position and angle have changed.
"""

MODULE 7 - GENERATION PIPELINE
Sequence (never fire all 4 blindly in parallel):
 1. Build anchor view = the camera the user marks as Anchor (default C1, or auto-pick the
    camera that sees the most objects). Send: layout guide + inspiration (role-tagged) +
    object reference photos + the plan crop with the camera marked.
 2. Show the anchor to the user. Buttons: Approve / Regenerate / Fix item (opens QA tools).
    Generate 2 candidates by default and let the user pick.
 3. After approval, generate C2-C4 in parallel (concurrency limit 2) with the anchor attached
    as a reference plus each camera's own layout guide.
 4. After each result, run QA (Module 8). Auto-repair up to 2 times; after that, flag for the user.
Settings: temperature low (0.2-0.4 if exposed), fixed seed where the API supports it,
store the seed and exact prompt with each Generation. Image size selector 1K / 2K / 4K.
Handle the response parts correctly (inlineData image parts + any text parts) and show clear
errors for safety blocks, quota and network failures, with a retry button.

MODULE 8 - QA & REPAIR LOOP
After each generation, send the result + layout guide + Bible JSON + anchor image to the
VISION model with this instruction:
"""
You are a strict architectural QA reviewer. Compare the photo with the Room Bible and layout
guide. Return ONLY JSON:
{ "pass": boolean,
  "issues": [ { "type": "missing|extra|moved|resized|recoloured|material|opening|perspective|text|other",
                "objectId": string|null, "description": string, "severity": 1-3,
                "fixInstruction": string } ],
  "consistencyWithAnchor": [ { "objectId": string, "match": boolean, "note": string } ] }
Check: object count, relative positions, door/window positions and sizes, wall/floor/ceiling
materials and colours, lighting direction, perspective verticals, any invented items.
"""
If issues exist, send an EDIT request to the image model: the current image + the layout guide
+ "Change only: {{fixInstruction}}. Keep everything else pixel-identical." Show before/after
and a per-issue checklist. The user can accept or reject each fix.
Add a manual "Fix item" tool: the user draws a box on the image, picks the object from the
Bible, and the tool sends an in-paint style edit: "In the marked region, render {{object}}
exactly as: {{materialDesc, colorHex, size}}. Match lighting and perspective."

MODULE 9 - OUTPUT & EXPORT
- Results grid (2x2) with camera label, lens info, status badge (draft/approved/needs-fix),
  side-by-side compare, and zoom.
- Mini plan thumbnail on each image showing its camera position/cone.
- Export: individual PNGs, a ZIP per room, a PDF contact sheet (plan + 4 images + schedule of
  materials/objects), and the Room Bible as JSON.
- "Clone room" and "Apply style to all rooms" actions.
- Version history per room (every generation stored with its prompt, seed and references).

NON-FUNCTIONAL
- Everything works offline except the Gemini calls. All data stays in the browser (IndexedDB);
  no uploads to any server other than the Gemini API. Provide JSON import/export of the project.
- Responsive desktop-first UI, dark/light theme, keyboard shortcuts for camera placement.
- Accessibility: labels on all inputs, focus states.
- Write unit tests for the geometry engine (projection, FOV, occlusion, ordering).
- Include a sample project (below) so the app is usable on first launch.

SAMPLE PROJECT TO PRE-LOAD (taken from the user's drawings; values are editable)
Project: "Al-Masih Gambia Foundation Worship Centre" - Bijilo, The Gambia.
Units mm. Structural grid A-J (6250 bays; E-F bay 4000), rows 1-6 at 6250. Building 54,000 x 31,250.
Style Bible: modern, clean, minimal, tropical-climate-appropriate. Palette: white walls #F4F2EC,
charcoal grey feature walls #6B6A68, black powder-coated aluminium window/door frames #1C1C1C,
light porcelain floor tiles #D9D6CF, warm-white LED lighting 3000-3500 K. Do not use: gold, ornate
classical moulding, patterned wallpaper, dark wood panelling.
Rooms to seed (dimensions are approximate from grid; the user must verify):
 - Ground floor: Sanctuary Hall (approx 25000 x 18750, seating rows facing a raised platform on the
   east side; double-height void above), Main Corridor (approx 4000 wide), Men's Toilet,
   Women's Toilet, Kitchen, Media Room, Storage, G-Offices A-F (6250 x 6250 each).
 - First floor: Pastor's Office, Meeting Room, IT Room, Work Room, F-Offices A-D, Living/Kitchen units, Bedrooms.
Pre-fill a "Men's Toilet" example with 3 urinals, 2 WC cubicles, 1 hand-wash counter with 2 basins,
mirror above basins, floor drain, one window, one door - so the user sees the object inventory
and camera preset workflow end-to-end.

DELIVERABLES
1. Full source code, runnable with `npm i && npm run dev`.
2. README with setup (API key), model-ID configuration, and a "how to get the best consistency" guide.
3. The unit-tested geometry engine and layout-guide renderer.
4. The prompt-compiler module with the template above, exposed in a "View prompt" drawer so
   the user can read and (optionally) edit the exact text sent for every image.

Start by outlining the architecture and file structure, then implement module by module.
After each module, summarise what works and what is stubbed. Do not stub the geometry engine,
layout-guide renderer, or prompt compiler.
```

---

## Notes for you (not part of the prompt)

**Where to paste it**
- **Google AI Studio → Build**: best fit, since it is already connected to Gemini. Add your API key in the app's Settings.
- **Claude Code** in this repo: also works. Tell it to put the app in a new `roomset-studio/` folder, because this repo currently holds Blender FBX import scripts.

**What to prepare per room, so the output is accurate**
1. A plan crop of just that room, plus its real dimensions. Your drawings have these on the grid. Example: one G-Office bay is 6250 × 6250.
2. Ceiling height. It isn't on the sheets I saw, so enter it from your sections or specs. The sanctuary is double-height because of the void on the first floor plan.
3. Door and window sizes and positions from the elevations.
4. A list of every object with its colour. The more specific it is, the less the model invents.
5. At least one inspiration image per room type, tagged for mood, materials or furniture.

**Tips for the best consistency**
- Generate and approve the anchor view first. Never batch all 4 without checking.
- Use **Nano Banana Pro** (`gemini-3-pro-image-preview`) for finals. It takes many reference images. Use the faster Nano Banana for drafts.
- Keep objects **Locked** once they look right.
- For the sanctuary, set the 4 cameras as: back-door view toward the platform, platform view toward the doors, left-side diagonal and right-side diagonal. That way every row of seats is visible from at least two cameras.

**Caveats**
- Model names change. The prompt makes them configurable, so check the current image model ID in AI Studio before you start.
- The plan shows no ceiling heights, finishes or furniture schedules, so the sample project's materials and colours are my assumptions based on your exterior renders (white walls, grey feature wall, black frames). Replace them with your real specifications.
