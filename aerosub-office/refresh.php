<?php
declare(strict_types=1);
// Clears what this browser or app saved for the site (old copies of the pages), then opens the app again. You stay signed in. Open it once on a device that still shows an old version.
header('Clear-Site-Data: "cache", "storage"'); header('Cache-Control: no-store'); header('Content-Type: text/html; charset=utf-8');
?><!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Refreshing…</title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:60px auto;padding:0 16px;color:#111}</style>
<h1>Refreshing the app…</h1><p>The old saved copy on this device is being cleared. In a moment the app opens with the newest version.</p>
<p><a href="index.html#/version">Open the app now</a></p>
<script src="refresh.js"></script>
