# Media source

Drop **real brand photography** here, named by the `image_key` declared in
`data/products.php` (and any page section that uses one):

```
data/media/source/{image_key}.jpg   # or .png / .webp
```

On `wp haramara install`, `MediaImporter::ensure()` sideloads a matching file if it
exists; otherwise it generates a branded SVG placeholder so every product/section
has a featured image. Swapping in a real photo later is drop-in — no code change.

Two special keys:
- `marca-haramara` — the site logo (transparent PNG; the header inverts it for the
  hero). Seeded into the `custom_logo` theme mod.
- `favicon` — the square site icon. Falls back to the logo if absent.

This folder and its README are intentionally tracked; real photos you add are up
to you to commit (they can be large).
