# CBP Update Metadata

Simple WordPress plugin to bulk update Yoast SEO meta title and meta description values from a spreadsheet.

## Features

- Upload `CSV` or `XLSX`
- Match rows to WordPress content by `URL`
- Safe URL fallbacks for normalized, relative, and path-only URLs
- Update `Meta Title`, `Meta Description`, or both
- Dry run mode to preview changes without writing to the database
- Admin results table showing skipped and updated rows

## Expected Columns

Use a header row with:

- `URL` (required)
- `Meta Title` (optional if only updating descriptions)
- `Meta Description` (optional if only updating titles)

Accepted header aliases:

- `Meta Title`, `title`, `meta_title`
- `Meta Description`, `description`, `meta_description`, `meta desc`

## Install

1. Copy the plugin folder into `wp-content/plugins/`.
2. Activate `CBP Update Metadata` in WordPress.
3. Open `Tools > Update Yoast Metadata`.

## Notes

- The plugin first tries WordPress `url_to_postid()` and then falls back to normalized URLs, home-relative paths, and path/slug matching.
- Ambiguous fallback matches are skipped and reported instead of guessed.
- The plugin writes Yoast values to `_yoast_wpseo_title` and `_yoast_wpseo_metadesc`.
- XLSX support requires PHP `ZipArchive`.
