# DN Bangla Newsroom Dashboard

This repository contains a standalone visual preview (`index.html`) and an early, installable WordPress plugin implementation.

The requested server-backed dashboard cannot run on GitHub Pages alone. Use the plugin at [wp-content/plugins/dnbangla-dashboard](wp-content/plugins/dnbangla-dashboard/) on a WordPress staging site. Its Bengali installation steps, currently working features, validation commands, and known gaps are documented in the plugin [README](wp-content/plugins/dnbangla-dashboard/README.md).

The plugin is not a production-complete release yet. Social-media publishing, queue retries, card cropping, and verified end-to-end WordPress deployment remain outstanding. Do not treat demo content or unconnected platforms as live integrations.

## Opening PhotoCard

The static preview includes a 1920×1920 Opening PhotoCard editor with two-column layout, draggable/resizable subject, date/logo/text controls, local templates, footer/ad toggles, cutout PNG export, and final JPG export. Background removal runs in the browser with `@imgly/background-removal` 1.7.0; the library is AGPL-3.0 and loads its model/WASM assets from IMG.LY on first use. Check AGPL obligations or obtain a commercial license before embedding this engine in a proprietary production service.

JPEG does not support alpha transparency. The isolated subject is therefore exported as a transparent PNG; the composed card is exported as 1920×1920 JPG. Opening-card templates are currently stored in this browser only. GitHub Pages does not provide database synchronization, user authentication, or the remaining server-side publishing/scheduling features.