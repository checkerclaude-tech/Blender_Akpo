# Blender_Akpo – import all FBX files into one Blender scene

Loads every `.fbx` file in `C:\Users\HP\OneDrive\Desktop\All 3D Built` (and its sub-folders) into a single Blender window.

## How to use (Windows)

1. Download `import_all_fbx.py` and `Import_All_FBX.bat` into the **same folder**.
2. Double-click **`Import_All_FBX.bat`**.
   Blender opens with every FBX already imported.

**Alternative:** open Blender, switch to the **Scripting** tab, click **Open**, choose `import_all_fbx.py`, then press **Run Script** (▶).

## What it does

- Deletes the default cube, camera and light.
- Imports each FBX into its own collection named after the file, so you can hide or show each model in the Outliner.
- Places the models side by side along the X axis so they don't overlap.
- Skips any file that fails to import (for example, an old ASCII FBX) and lists it in the console.
- Saves everything as `All_FBX_Combined.blend` in the same folder, so next time you can just open that file.

To change the folder or options, edit the settings near the top of `import_all_fbx.py` (and `FBX_FOLDER` in the `.bat`). Options: `SEARCH_SUBFOLDERS`, `ARRANGE_IN_ROW`, `SPACING`, `SAVE_BLEND`.
