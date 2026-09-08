# CBP Update Metadata

Simple WordPress plugin to bulk update and export SEO meta title and meta description values.

## Features

- Upload `CSV` or `XLSX` to import
- Export existing metadata to `CSV` (same columns as import, so you can migrate between plugins)
- Match rows to WordPress content by `URL` (with safe fallbacks for normalized, relative, and path-only URLs)
- Update `Meta Title`, `Meta Description`, or both
- Dry run mode to preview changes without writing to the database
- Admin results table showing skipped and updated rows
- Auto-detects the active SEO plugin (`Yoast SEO`, `Yeast SEO`, or `SEOPress`) and reads/writes the correct post meta keys
- Export lets you pick post types per export and optionally skips rows with no metadata set

## Expected Columns

Use a header row with:

- `URL` (required)
- `Meta Title` (optional if only updating descriptions)
- `Meta Description` (optional if only updating titles)

Accepted header aliases:

- `Meta Title`, `title`, `meta_title`
- `Meta Description`, `description`, `meta_description`, `meta desc`

## Export / Migration

`Tools > Update SEO Metadata` now has an **Export** section above Import.

- Select the post types to export (only `publish` is exported).
- Choose whether to include only rows that have a title or description set.
- The downloaded `seo-export-*.csv` uses relative URLs (e.g. `/about/`), plus `Meta Title`, `Meta Description` — identical to the import format, so the same file works on any domain.

Workflow for Yoast → Yeast:

1. On the source site (Yoast active) → Export → download CSV.
2. On the destination / same site with Yeast SEO active → Import the same CSV (dry-run first).

## Install

1. Copy the plugin folder into `wp-content/plugins/`.
2. Activate `CBP Update Metadata` in WordPress.
3. Open `Tools > Update SEO Metadata`.

## Notes

- The plugin first tries WordPress `url_to_postid()` and then falls back to normalized URLs, home-relative paths, and path/slug matching.
- Ambiguous fallback matches are skipped and reported instead of guessed.
- The detected SEO plugin is shown on the admin page; import and export both use that engine.
- Export is relative-path based (`/page/` not `https://example.com/page/`) for portability; import accepts both absolute and relative URLs.
- Skipped rows (no matching URL or missing data) are counted and flagged in red in the results table.
- For `Yoast SEO`, the plugin reads/writes `_yoast_wpseo_title` and `_yoast_wpseo_metadesc`.
- For `Yeast SEO`, the plugin reads/writes `cbp_yeast_seo_title` and `cbp_yeast_seo_meta_description`.
- For `SEOPress`, the plugin reads/writes `_seopress_titles_title` and `_seopress_titles_desc`.
- XLSX import requires PHP `ZipArchive`; export is CSV only.
