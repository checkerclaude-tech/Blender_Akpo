"""
Import every FBX file from a folder into ONE Blender scene.

Usage
-----
1. Easiest (Windows): double-click ``Import_All_FBX.bat`` (next to this file).
2. From Blender: open the "Scripting" tab, open this file, press "Run Script".
3. Command line:
       blender --python import_all_fbx.py -- "C:\\path\\to\\folder"

Each FBX goes into its own collection (named after the file), and the models
are laid out side by side so they don't overlap. After importing, the scene
is saved as ``All_FBX_Combined.blend`` inside the FBX folder.
"""

import os
import sys

import bpy
from mathutils import Vector

# ---------------------------------------------------------------------------
# Settings
# ---------------------------------------------------------------------------
FBX_FOLDER = r"C:\Users\HP\OneDrive\Desktop\All 3D Built"

SEARCH_SUBFOLDERS = True   # also pick up FBX files inside sub-folders
CLEAR_SCENE = True         # delete the default cube / camera / light first
ARRANGE_IN_ROW = True      # place models side by side instead of all at 0,0,0
SPACING = 1.0              # gap between models (Blender units / metres)
SAVE_BLEND = True          # save the result as a .blend file
BLEND_NAME = "All_FBX_Combined.blend"


def get_folder():
    """Folder from the command line (after ``--``) or the setting above."""
    if "--" in sys.argv:
        args = sys.argv[sys.argv.index("--") + 1:]
        if args:
            return args[0]
    return FBX_FOLDER


def find_fbx_files(folder):
    files = []
    if SEARCH_SUBFOLDERS:
        for root, _dirs, names in os.walk(folder):
            files += [os.path.join(root, n) for n in names if n.lower().endswith(".fbx")]
    else:
        files = [os.path.join(folder, n) for n in os.listdir(folder) if n.lower().endswith(".fbx")]
    return sorted(files, key=str.lower)


def clear_scene():
    for obj in list(bpy.data.objects):
        bpy.data.objects.remove(obj, do_unlink=True)
    for coll in list(bpy.data.collections):
        if not coll.objects and not coll.children:
            bpy.data.collections.remove(coll)


def world_bounds(objects):
    """World-space (min, max) corners of the given objects, or None."""
    lo = Vector((float("inf"),) * 3)
    hi = Vector((float("-inf"),) * 3)
    found = False
    for obj in objects:
        corners = [obj.matrix_world @ Vector(c) for c in obj.bound_box] \
            if obj.type in {"MESH", "CURVE", "SURFACE", "META", "FONT", "ARMATURE", "LATTICE"} \
            else [obj.matrix_world.translation]
        for c in corners:
            lo = Vector(map(min, lo, c))
            hi = Vector(map(max, hi, c))
            found = True
    return (lo, hi) if found else None


def import_fbx(path, scene, view_layer):
    name = os.path.splitext(os.path.basename(path))[0]
    coll = bpy.data.collections.new(name)
    scene.collection.children.link(coll)
    view_layer.active_layer_collection = view_layer.layer_collection.children[coll.name]

    before = set(bpy.data.objects)
    try:
        bpy.ops.import_scene.fbx(filepath=path)
    except Exception:
        bpy.data.collections.remove(coll)
        raise
    new_objects = [o for o in bpy.data.objects if o not in before]
    return coll, new_objects


def main():
    folder = get_folder()
    if not os.path.isdir(folder):
        raise SystemExit(f"[import_all_fbx] Folder not found: {folder}")

    files = find_fbx_files(folder)
    print(f"[import_all_fbx] Found {len(files)} FBX file(s) in {folder}")
    if not files:
        return

    if CLEAR_SCENE:
        clear_scene()

    scene = bpy.context.scene
    view_layer = bpy.context.view_layer
    cursor_x = 0.0
    imported, failed = [], []

    for i, path in enumerate(files, 1):
        print(f"[import_all_fbx] ({i}/{len(files)}) {path}")
        try:
            coll, objs = import_fbx(path, scene, view_layer)
        except Exception as exc:  # keep going if one file is broken
            print(f"[import_all_fbx]   FAILED: {exc}")
            failed.append(path)
            continue
        imported.append(path)

        if ARRANGE_IN_ROW and objs:
            view_layer.update()
            bounds = world_bounds(objs)
            if bounds:
                lo, hi = bounds
                shift = cursor_x - lo.x
                for obj in objs:
                    if obj.parent is None:
                        obj.location.x += shift
                cursor_x += (hi.x - lo.x) + SPACING

    # Back to the top-level collection
    view_layer.active_layer_collection = view_layer.layer_collection

    print(f"[import_all_fbx] Imported {len(imported)} file(s), {len(failed)} failed.")
    for path in failed:
        print(f"[import_all_fbx]   failed: {path}")

    if SAVE_BLEND and imported:
        out = os.path.join(folder, BLEND_NAME)
        try:
            bpy.ops.wm.save_as_mainfile(filepath=out)
            print(f"[import_all_fbx] Saved: {out}")
        except Exception as exc:
            print(f"[import_all_fbx] Could not save .blend: {exc}")

    # Zoom every 3D viewport to show all models (only when Blender has a UI)
    wm = bpy.context.window_manager
    for window in (wm.windows if wm else []):
        for area in window.screen.areas:
            if area.type == "VIEW_3D":
                region = next((r for r in area.regions if r.type == "WINDOW"), None)
                if region:
                    with bpy.context.temp_override(window=window, area=area, region=region):
                        bpy.ops.view3d.view_all()


if __name__ == "__main__":
    main()
