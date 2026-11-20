## Live drag-and-resize controls — 0.8.6.2-design-dev

The four trusted theme IDs are still exactly `noir-gold`, `velvet-plum`, `midnight-teal`, `rose-ivory`. No additional theme/duplicate is created. Their distinct CSS backgrounds and color palettes remain available.

1. **Back up your site database**, then run authenticated `/admin/setup` to apply additive `m12_cutout_placement_v1`; this adds `cutout_x`, `cutout_y`, `cutout_width` to `ac_design_settings` without dropping current settings or assets. Re-running the setup is safe; a partial MySQL ALTER can be resumed. Existing selected images are mapped from the previous Small/Medium/Large setting once.
2. Open `/admin/design`; choose an already uploaded hero cutout (transparent PNG or WebP recommended). If none is selected, the theme background remains untouched.
3. Drag the photo itself or the visible **Move ✥** handle across the live preview; pull the **↘** lower-corner handle to resize. Both work with pointer/mouse and touch. The handles accept arrow keys (Shift+arrow = ten percentage points); the labeled X, Y and Width range sliders provide keyboard-accessible fine control and live percentage readouts. Choose **Center and reset size** to restore X=50%, Y=50%, Width=80%.
4. Select a different built-in theme or color palette and the preview updates without resetting those slider values. Click **Save design changes** to commit the new geometry and homepage plaintext; this does not change the homepage draft/published status. Stored placement uses validated integer percentages (X/Y 0–100; Width 20–100). Placing a cutout near an edge can deliberately crop it inside its image frame.
5. The public `/design.css` endpoint emits the same validated X/Y/Width values as CSS custom properties; the public hero uses the matching `cutout-positioner` markup. A photo is only served publicly if it is the currently selected approved decorative asset **and** the homepage is independently published. Private preview images are never exposed by the public route.

Limitations: movement is within the hero picture region rather than an arbitrary page-wide freeform canvas; X/Y/Width are shared with the active homepage theme, not independent unsaved drafts for every theme. Background-removal automation, multiple layering, z-index management, crop masks, and undo history are not available. The included preview screenshot uses synthetic art, not a real creator photo. This is still a development build; age/consent, billing, Pro streaming and certification remain blocked.

# Visual Design Studio — 0.8.6-design-dev

**Development feature, not a production adult-content publishing workflow.** The visual studio supports **trusted built-in layouts** and private decorative photo assets. It does not execute third-party uploaded HTML/JS/PHP or treat an upload as verified adult content.

## Getting started

1. Back up a disposable test database and sign in as an authorized administrator.
2. Visit `/admin/setup`, click **Apply additive migrations** to add `m11_visual_design_v1`. This creates `ac_design_settings` and `ac_design_assets`; the previous page/member data is not reset.
3. Visit `/admin/design`. Pick a theme, accent color, placement and size. Click the large headline or paragraph in the preview to edit text directly. The plain-text inputs on the left remain usable without JavaScript. Click **Save design changes** to persist design + homepage copy as one transaction.
4. Visit `/admin/pages` to publish or unpublish the **home** page separately. Design Studio **never** silently publishes a draft homepage.
5. In **Upload photos & cutouts**, choose a local file (PNG, WebP, or JPEG, maximum 5 MiB and 4096 pixels). To use a background-removed cutout, upload your already-transparent PNG or WebP. This build **does not automatically remove backgrounds** or strip metadata. Prepare the image with an offline editor first and export only what you intend to publish.
6. New images default to **private preview**. The optional public-use confirmation checkbox is only for **non-explicit, rights-cleared decorative imagery**; selecting that box is an operator declaration, **not** independent age/performer consent verification. Uploaded images remain physically private under `storage/design-assets/`. To appear on the public home page, an image must be explicitly declared public-safe, selected in the saved design, and the homepage must be published.

## Themes, palettes and layouts

| Preset ID | Name | Distinct layout | Default accent |
|---|---|---|---|
| `noir-gold` | Noir Atelier | Dark luxury / editorial split hero | Gold `#d9ae75` |
| `velvet-plum` | Velvet Club | Centered headline with media beneath | Pink `#f19ad0` |
| `midnight-teal` | Midnight Studio | Cool cinematic / image-first reversed hero | Teal `#6ce0d3` |
| `rose-ivory` | Rose Editorial | Light magazine composition | Rose `#b76b80` |

The administrator can choose any hexadecimal six-digit accent color; all dynamic CSS values pass a strict color allowlist before entering the `/design.css` stylesheet. Uploaded **signed** external packages are still CSS/data-only: they cannot supply executable templates. The fixed built-in themes are audited PHP/CSS layouts. Template selection is a separate capability from signed package selection.

## Security and limitations

- Full website and administrator installation gate remains mandatory. All admin design routes require a current authenticated administrator with `site.settings.manage` permission and HTTPS except local development loopback. All POST changes require existing CSRF checks.
- Custom text is **plain text** and always escaped on public output. The browser's `contenteditable` preview submits only text through the normal form; no arbitrary HTML or scripts are stored. No inline style or arbitrary style attribute is accepted from the form.
- Assets are `getimagesize`/`finfo` checked, limited in bytes and dimensions, randomly named, saved with restrictive file permissions outside `public/`, and accessed through checked routes. `/admin/design/asset/{id}` is authenticated; `/design/asset/{id}` only serves the asset currently selected by the site when it is explicitly public-safe AND the home page is published. Other assets get 404.
- A background-removed cutout still needs a rights-cleared source. **Do not upload real adult performances or identity/consent documents to Design Studio.** Use a separate independently verified media publishing process once completed and legally reviewed.
- The current inline preview edits the **homepage headline and body**; inner pages remain editable via `/admin/page` (not yet visual inline editing). Drag-to-position, section/block reordering, background-removal service, layered image editor, per-page theme overrides, theme history and signed rollback are **not implemented**.
- Existing selected signed CSS themes can override base look; combining custom CSS with presets requires testing. Changes are not a substitute for the remaining production age/consent, payment, MFA, and hosting-certification gates.

**Local PHP dev server:** run `php -S 127.0.0.1:8080 -t public public/router.php` instead of launching with only `-t public`; the router is necessary for dynamic `.css` and image endpoints on the PHP development server. Apache uses `public/.htaccess` rewriting.

## Test commands

```sh
php tests/design_tests.php
php tests/ui_tests.php
python3 tests/http_smoke.py
# Only on explicitly disposable MySQL (see docs/TESTING.md):
php tests/mysql_install.php
python3 tests/mysql_http_auth.py
```

The production image source still lives exclusively under the operator's install; documentation/examples never include customer photos or private signing secrets.

## 0.8.6.1-design-dev — Keep four presets, leave hero cutouts empty (2026-11-20)

- Retain the **four existing** built-in theme keys (Noir Atelier, Velvet Club, Midnight Studio, Rose Editorial) unchanged. No duplicate themes, new module IDs, or database changes.
- The Design Studio's empty-image option is now labelled **No cutout — keep theme background**. Its preview shows **Add your cutout photo** and retains the selected layout and palette; selecting an uploaded photo replaces only the empty slot. Theme switching does not inject any example model.
- The selected hero image remains optional and can be cleared without losing the background styling; the public site uses theme-native abstract decor if no image is selected. The current implementation saves one site-wide selected hero image, **not four separately saved hero images**.
- Four revised, standalone design **concept previews** with empty hero areas are packaged separately. They retain the backdrop scenes and gallery thumbnail examples. These screenshots are concept art, **not installed PHP theme assets or production-cleared photographs**, and the thumbnail models are not imported automatically into customer sites. Actual live themes use audited CSS layouts/backgrounds.
- User-provided transparent PNG/WebP remains private on upload and requires existing public-safe confirmation, selection, and a published home page before public delivery. Adults-only media requires independent identity/consent approval and is not accepted as public Design Studio imagery.
