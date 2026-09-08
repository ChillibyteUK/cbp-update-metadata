<?php // phpcs:ignore WodPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: CBP Update Metadata
 * Description: Upload CSV or XLSX files to update SEO meta titles and descriptions by URL, with dry-run support. Auto-detects Yoast, Yeast SEO, or SEOPress. Also exports existing metadata to CSV for migration between plugins.
 * Version: 1.2.0
 * Author: Chillibyte - DS
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose

/**
 * Class CBP_Update_Metadata_Plugin
 *
 * Handles the admin interface and logic for updating SEO meta titles and descriptions via CSV/XLSX upload.
 * Auto-detects the active SEO plugin (Yoast, Yeast SEO, or SEOPress) and writes to the appropriate post meta keys.
 *
 * @package CBP_Update_Metadata
 */
class CBP_Update_Metadata_Plugin {

	private const NONCE_ACTION             = 'cbp_update_metadata_upload';
	private const NONCE_ACTION_EXPORT      = 'cbp_update_metadata_export';
	private const YOAST_TITLE_KEY          = '_yoast_wpseo_title';
	private const YOAST_DESCRIPTION_KEY    = '_yoast_wpseo_metadesc';
	private const YEAST_TITLE_KEY          = 'cbp_yeast_seo_title';
	private const YEAST_DESCRIPTION_KEY    = 'cbp_yeast_seo_meta_description';
	private const YEAST_ROBOTS_KEY         = 'cbp_yeast_seo_robots_index';
	private const YOAST_ROBOTS_NOINDEX_KEY = '_yoast_wpseo_meta-robots-noindex';
	private const SEOPRESS_TITLE_KEY       = '_seopress_titles_title';
	private const SEOPRESS_DESCRIPTION_KEY = '_seopress_titles_desc';
	private const SEOPRESS_ROBOTS_KEY      = '_seopress_robots_index';

	/**
	 * CBP_Update_Metadata_Plugin constructor.
	 * Registers admin menu and upload handler actions.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_post_cbp_update_metadata', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_cbp_update_metadata_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_cbp_update_metadata_export_skipped', array( $this, 'handle_export_skipped' ) );
		add_action( 'admin_init', array( $this, 'register_list_table_columns' ) );
		add_action( 'admin_head', array( $this, 'print_list_table_styles' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_global_noindex_warning' ) );
		add_action( 'quick_edit_custom_box', array( $this, 'add_quick_edit_fields' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_quick_edit' ) );
		add_action( 'admin_footer', array( $this, 'print_quick_edit_script' ) );
	}

	/**
	 * Determines whether the active SEO plugin is SEOPress.
	 *
	 * @return bool True if SEOPress is active, false otherwise.
	 */
	private function is_seopress_active(): bool {
		return defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Determines whether Yoast SEO is active.
	 *
	 * @return bool
	 */
	private function is_yoast_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Determines whether Yeast SEO is active.
	 *
	 * @return bool
	 */
	private function is_yeast_active(): bool {
		return defined( 'CBP_YEAST_SEO_VERSION' ) || defined( 'CBP_YEAST_SEO_TITLE_META_KEY' );
	}

	/**
	 * Returns the post meta keys for the active SEO plugin (import target).
	 *
	 * Prefers Yeast SEO when active so that Yoast -> Yeast migrations land in the new plugin.
	 * Falls back to Yoast keys when no known SEO plugin is detected.
	 *
	 * @return array{title:string,description:string} The meta keys to use.
	 */
	private function get_meta_keys(): array {
		return $this->get_import_meta_keys();
	}

	/**
	 * Returns the post meta keys for import (write target).
	 *
	 * Priority: SEOPress > Yeast > Yoast.
	 *
	 * @return array{title:string,description:string}
	 */
	private function get_import_meta_keys(): array {
		if ( $this->is_seopress_active() ) {
			return array(
				'title'       => self::SEOPRESS_TITLE_KEY,
				'description' => self::SEOPRESS_DESCRIPTION_KEY,
			);
		}

		if ( $this->is_yeast_active() ) {
			return array(
				'title'       => self::YEAST_TITLE_KEY,
				'description' => self::YEAST_DESCRIPTION_KEY,
			);
		}

		return array(
			'title'       => self::YOAST_TITLE_KEY,
			'description' => self::YOAST_DESCRIPTION_KEY,
		);
	}

	/**
	 * Returns the post meta keys for export (read source).
	 *
	 * Priority: SEOPress > Yoast > Yeast — prefers Yoast so that a site running
	 * both Yoast and Yeast simultaneously still exports the legacy Yoast data
	 * for migration. When only Yeast is active this naturally returns Yeast keys.
	 *
	 * @return array{title:string,description:string}
	 */
	private function get_export_meta_keys(): array {
		if ( $this->is_seopress_active() ) {
			return array(
				'title'       => self::SEOPRESS_TITLE_KEY,
				'description' => self::SEOPRESS_DESCRIPTION_KEY,
			);
		}

		if ( $this->is_yoast_active() ) {
			return array(
				'title'       => self::YOAST_TITLE_KEY,
				'description' => self::YOAST_DESCRIPTION_KEY,
			);
		}

		if ( $this->is_yeast_active() ) {
			return array(
				'title'       => self::YEAST_TITLE_KEY,
				'description' => self::YEAST_DESCRIPTION_KEY,
			);
		}

		return array(
			'title'       => self::YOAST_TITLE_KEY,
			'description' => self::YOAST_DESCRIPTION_KEY,
		);
	}

	/**
	 * Returns the human-readable label of the detected SEO plugin (import target).
	 *
	 * @return string The detected SEO plugin label.
	 */
	private function get_detected_engine(): string {
		if ( $this->is_seopress_active() ) {
			return 'SEOPress';
		}

		if ( $this->is_yeast_active() ) {
			return 'Yeast SEO';
		}

		return 'Yoast SEO';
	}

	/**
	 * Returns the human-readable label of the export source.
	 *
	 * @return string
	 */
	private function get_export_engine(): string {
		if ( $this->is_seopress_active() ) {
			return 'SEOPress';
		}

		if ( $this->is_yoast_active() ) {
			return 'Yoast SEO';
		}

		if ( $this->is_yeast_active() ) {
			return 'Yeast SEO';
		}

		return 'Yoast SEO';
	}

	/**
	 * Returns public post types eligible for export.
	 *
	 * @return array<string,string> Map of slug => label.
	 */
	private function get_exportable_post_types(): array {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		$options    = array();

		foreach ( $post_types as $slug => $obj ) {
			$options[ $slug ] = $obj->labels->singular_name . ' (' . $slug . ')';
		}

		return $options;
	}

	/**
	 * Registers SEO columns on post/page list tables.
	 */
	public function register_list_table_columns(): void {
		foreach ( array_keys( $this->get_exportable_post_types() ) as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'filter_list_columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_list_column' ), 10, 2 );
		}
	}

	public function filter_list_columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['cbp_seo_title']       = __( 'Meta Title', 'cbp-update-metadata' );
				$out['cbp_seo_description'] = __( 'Meta Description', 'cbp-update-metadata' );
				$out['cbp_seo_indexable']   = __( 'Indexable', 'cbp-update-metadata' );
			}
		}
		return $out;
	}

	public function render_list_column( string $column, int $post_id ): void {
		if ( 'cbp_seo_indexable' === $column ) {
			$indexable = $this->is_post_indexable( $post_id );
			if ( $indexable ) {
				echo '<span class="dashicons dashicons-yes-alt" style="color:#46b450;" title="' . esc_attr__( 'Indexable', 'cbp-update-metadata' ) . '"></span>';
			} else {
				echo '<span class="dashicons dashicons-dismiss" style="color:#d63638;" title="' . esc_attr__( 'Noindex', 'cbp-update-metadata' ) . '"></span>';
			}
			return;
		}

		if ( 'cbp_seo_title' !== $column && 'cbp_seo_description' !== $column ) {
			return;
		}

		$keys  = $this->get_meta_keys();
		$key   = 'cbp_seo_title' === $column ? $keys['title'] : $keys['description'];
		$value = trim( (string) get_post_meta( $post_id, $key, true ) );

		if ( '' === $value ) {
			echo '<span style="color:#999;">—</span>';
			return;
		}

		echo '<span title="' . esc_attr( $value ) . '">' . esc_html( $value ) . '</span>';
	}

	private function is_post_indexable( int $post_id ): bool {
		$value = get_post_meta( $post_id, self::YEAST_ROBOTS_KEY, true );
		return 'noindex' !== $value;
	}

	public function maybe_show_global_noindex_warning(): void {
		if ( get_option( 'blog_public' ) ) {
			return;
		}

		echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'Search engines discouraged:', 'cbp-update-metadata' ) . '</strong> ' . esc_html__( 'This site is blocked from indexing (Settings → Reading → Discourage search engines). All pages will show as Noindex regardless of per-post settings.', 'cbp-update-metadata' ) . '</p></div>';
	}

	public function add_quick_edit_fields( string $column_name, string $post_type ): void {
		if ( 'cbp_seo_indexable' !== $column_name || ! isset( $this->get_exportable_post_types()[ $post_type ] ) ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right" style="margin-top:8px;">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php echo esc_html__( 'Indexable', 'cbp-update-metadata' ); ?></span>
					<select name="cbp_yeast_seo_robots_index">
						<option value="index"><?php echo esc_html__( 'Index', 'cbp-update-metadata' ); ?></option>
						<option value="noindex"><?php echo esc_html__( 'Noindex', 'cbp-update-metadata' ); ?></option>
					</select>
				</label>
			</div>
		</fieldset>
		<?php
	}

	public function save_quick_edit( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['cbp_yeast_seo_robots_index'] ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( ! $post_type || ! isset( $this->get_exportable_post_types()[ $post_type ] ) ) {
			return;
		}

		$value = 'noindex' === $_POST['cbp_yeast_seo_robots_index'] ? 'noindex' : 'index';
		update_post_meta( $post_id, self::YEAST_ROBOTS_KEY, $value );
	}

	public function print_quick_edit_script(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || empty( $screen->post_type ) || ! isset( $this->get_exportable_post_types()[ $screen->post_type ] ) ) {
			return;
		}
		?>
		<script>
		(function(){
			var orig = window.inlineEditPost && window.inlineEditPost.edit;
			if (!orig) return;
			window.inlineEditPost.edit = function(id){
				orig.apply(this, arguments);
				var postId = 0;
				if (typeof id === 'object') postId = parseInt(this.getId(id), 10);
				else postId = parseInt(id, 10);
				if (!postId) return;
				var row = document.getElementById('post-' + postId);
				if (!row) return;
				var isNoindex = row.querySelector('.column-cbp_seo_indexable .dashicons-dismiss') !== null;
				var sel = document.querySelector('.inline-edit-row select[name="cbp_yeast_seo_robots_index"]');
				if (sel) sel.value = isNoindex ? 'noindex' : 'index';
			};
		})();
		</script>
		<?php
	}

	public function print_list_table_styles(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || empty( $screen->post_type ) || ! isset( $this->get_exportable_post_types()[ $screen->post_type ] ) ) {
			return;
		}
		echo '<style>.column-cbp_seo_title,.column-cbp_seo_description{width:20%;}.column-cbp_seo_indexable{width:70px;text-align:center;}.column-cbp_seo_title span,.column-cbp_seo_description span{word-break:break-word;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}</style>';
	}

	/**
	 * Registers the admin page for updating SEO metadata.
	 */
	public function register_admin_page(): void {
		add_management_page(
			'Update SEO Metadata',
			'Update SEO Metadata',
			'manage_options',
			'cbp-update-metadata',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Renders the admin page for uploading and updating SEO metadata.
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'cbp-update-metadata' ) );
		}

		$result = get_transient( 'cbp_update_metadata_result' );
		if ( $result ) {
			delete_transient( 'cbp_update_metadata_result' );
		}

		$exportable_types = $this->get_exportable_post_types();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Update SEO Metadata', 'cbp-update-metadata' ); ?></h1>
			<p><?php echo esc_html__( 'Upload a CSV or XLSX file with columns for URL, Meta Title, and optionally Meta Description.', 'cbp-update-metadata' ); ?></p>
			<p><strong><?php echo esc_html__( 'Detected SEO plugin:', 'cbp-update-metadata' ); ?></strong> <?php echo esc_html( $this->get_detected_engine() ); ?></p>
			<p class="description"><?php echo esc_html__( 'Auto-detects Yoast SEO, Yeast SEO, or SEOPress. CSV uses relative URLs (e.g. /about/) so the same file works on any domain or environment.', 'cbp-update-metadata' ); ?></p>

			<?php if ( $result ) : ?>
				<?php
				$skipped = 0;
				$updated = 0;
				$dry     = 0;
				if ( ! empty( $result['rows'] ) ) {
					foreach ( $result['rows'] as $r ) {
						if ( 'Skipped' === $r['status'] ) {
							++$skipped;
						} elseif ( 'Updated' === $r['status'] ) {
							++$updated;
						} elseif ( 'Dry run' === $r['status'] ) {
							++$dry;
						}
					}
				}
				?>
				<div class="notice notice-<?php echo esc_attr( $result['success'] ? 'success' : 'error' ); ?>">
					<p><?php echo esc_html( $result['message'] ); ?></p>
					<?php if ( $skipped ) : ?>
						<p style="color:#d63638;font-weight:600;"><?php echo esc_html( sprintf( __( '%d row(s) skipped — no matching URL found or missing data. See flagged rows below.', 'cbp-update-metadata' ), $skipped ) ); ?></p>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $result['rows'] ) ) : ?>
					<table class="widefat striped" style="max-width: 1200px; margin-top: 1rem;">
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Row', 'cbp-update-metadata' ); ?></th>
								<th><?php echo esc_html__( 'Source URL', 'cbp-update-metadata' ); ?></th>
								<th><?php echo esc_html__( 'Matched URL', 'cbp-update-metadata' ); ?></th>
								<th><?php echo esc_html__( 'Status', 'cbp-update-metadata' ); ?></th>
								<th><?php echo esc_html__( 'Details', 'cbp-update-metadata' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $result['rows'] as $row ) : ?>
								<?php $is_skipped = 'Skipped' === $row['status']; ?>
								<tr <?php echo $is_skipped ? 'style="background:#fcf0f1;"' : ''; ?>>
									<td><?php echo esc_html( (string) $row['row'] ); ?></td>
									<td style="word-break:break-all;"><?php echo esc_html( $row['url'] ); ?></td>
									<td style="word-break:break-all;"><?php echo ! empty( $row['matched_url'] ) ? esc_html( $row['matched_url'] ) : '<span style="color:#999;">—</span>'; ?></td>
									<td><?php echo $is_skipped ? '<span style="color:#d63638;font-weight:600;">' . esc_html( $row['status'] ) . '</span>' : esc_html( $row['status'] ); ?></td>
									<td><?php echo esc_html( $row['message'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( $skipped ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1rem;">
							<input type="hidden" name="action" value="cbp_update_metadata_export_skipped">
							<?php wp_nonce_field( self::NONCE_ACTION_EXPORT ); ?>
							<?php submit_button( sprintf( __( 'Download %d skipped row(s) as CSV', 'cbp-update-metadata' ), $skipped ), 'secondary', 'submit', false ); ?>
							<p class="description" style="display:inline;margin-left:8px;"><?php echo esc_html__( 'Fix the URLs and re-upload — same columns as import.', 'cbp-update-metadata' ); ?></p>
						</form>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>

			<hr>

			<h2><?php echo esc_html__( 'Export', 'cbp-update-metadata' ); ?></h2>
			<p><?php echo esc_html__( 'Download a CSV of existing titles and descriptions. The file uses the same URL / Meta Title / Meta Description columns as import, so you can export from one engine and import into another.', 'cbp-update-metadata' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cbp_update_metadata_export">
				<?php wp_nonce_field( self::NONCE_ACTION_EXPORT ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Post Types', 'cbp-update-metadata' ); ?></th>
						<td>
							<?php foreach ( $exportable_types as $slug => $label ) : ?>
								<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="export_post_types[]" value="<?php echo esc_attr( $slug ); ?>" checked> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php echo esc_html__( 'Only published posts of the selected types are exported.', 'cbp-update-metadata' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Options', 'cbp-update-metadata' ); ?></th>
						<td>
							<label><input type="checkbox" name="export_only_with_meta" value="1" checked> <?php echo esc_html__( 'Only include rows with a title or description set', 'cbp-update-metadata' ); ?></label>
							<p class="description"><?php echo esc_html__( 'Uncheck to include every URL even when both fields are empty.', 'cbp-update-metadata' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Download CSV', 'cbp-update-metadata' ) ); ?>
			</form>

			<hr>

			<h2><?php echo esc_html__( 'Import', 'cbp-update-metadata' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="cbp_update_metadata">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cbp_metadata_file"><?php echo esc_html__( 'Spreadsheet', 'cbp-update-metadata' ); ?></label></th>
						<td>
							<input type="file" id="cbp_metadata_file" name="cbp_metadata_file" accept=".csv,.xlsx" required>
							<p class="description"><?php echo esc_html__( 'Headers should include URL, Meta Title, and optionally Meta Description.', 'cbp-update-metadata' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Fields To Update', 'cbp-update-metadata' ); ?></th>
						<td>
							<label><input type="checkbox" name="update_title" value="1" checked> <?php echo esc_html__( 'Meta Title', 'cbp-update-metadata' ); ?></label><br>
							<label><input type="checkbox" name="update_description" value="1" checked> <?php echo esc_html__( 'Meta Description', 'cbp-update-metadata' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Mode', 'cbp-update-metadata' ); ?></th>
						<td>
							<label><input type="checkbox" name="dry_run" value="1" checked> <?php echo esc_html__( 'Dry run only', 'cbp-update-metadata' ); ?></label>
							<p class="description"><?php echo esc_html__( 'When enabled, the plugin validates rows and shows what would change without updating the database.', 'cbp-update-metadata' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Process File', 'cbp-update-metadata' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles the CSV export of existing SEO metadata.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'cbp-update-metadata' ) );
		}

		check_admin_referer( self::NONCE_ACTION_EXPORT );

		$raw_types       = isset( $_POST['export_post_types'] ) ? (array) wp_unslash( $_POST['export_post_types'] ) : array();
		$only_with_meta  = ! empty( $_POST['export_only_with_meta'] );
		$exportable      = array_keys( $this->get_exportable_post_types() );
		$selected_types  = array_values( array_intersect( array_map( 'sanitize_key', $raw_types ), $exportable ) );

		if ( empty( $selected_types ) ) {
			$this->redirect_with_result( false, 'Select at least one post type to export.', array() );
		}

		$meta_keys = $this->get_export_meta_keys();

		$posts = get_posts(
			array(
				'post_type'      => $selected_types,
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'suppress_filters' => false,
			)
		);

		$rows = array();

		foreach ( $posts as $post ) {
			$permalink = get_permalink( $post );
			if ( ! $permalink ) {
				continue;
			}
			$url = wp_make_link_relative( $permalink );
			if ( '' === $url ) {
				$url = '/';
			}
			$title       = trim( (string) get_post_meta( $post->ID, $meta_keys['title'], true ) );
			$description = trim( (string) get_post_meta( $post->ID, $meta_keys['description'], true ) );

			if ( $only_with_meta && '' === $title && '' === $description ) {
				continue;
			}

			$rows[] = array(
				$url,
				$title,
				$description,
			);
		}

		if ( empty( $rows ) ) {
			$this->redirect_with_result( false, 'No metadata found to export for the selected post types.', array() );
		}

		$filename = sprintf( 'seo-export-%s.csv', gmdate( 'Y-m-d' ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'URL', 'Meta Title', 'Meta Description' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Exports only the skipped rows from the last import (re-uploadable CSV).
	 *
	 * @return void
	 */
	public function handle_export_skipped(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'cbp-update-metadata' ) );
		}

		check_admin_referer( self::NONCE_ACTION_EXPORT );

		$result = get_transient( 'cbp_update_metadata_result' );
		if ( empty( $result['rows'] ) || ! is_array( $result['rows'] ) ) {
			$this->redirect_with_result( false, 'No recent import to export skipped rows from. Run an import first.', array() );
		}

		$skipped = array_filter(
			$result['rows'],
			static function ( $r ) {
				return isset( $r['status'] ) && 'Skipped' === $r['status'];
			}
		);

		if ( empty( $skipped ) ) {
			$this->redirect_with_result( false, 'No skipped rows to export.', array() );
		}

		$filename = sprintf( 'seo-missing-urls-%s.csv', gmdate( 'Y-m-d' ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'URL', 'Meta Title', 'Meta Description' ) );
		foreach ( $skipped as $row ) {
			fputcsv(
				$out,
				array(
					$row['url'] ?? '',
					$row['meta_title'] ?? '',
					$row['meta_description'] ?? '',
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Handles the upload and processing of the metadata file from the admin form.
	 *
	 * @return void
	 */
	public function handle_upload(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'cbp-update-metadata' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$update_title       = ! empty( $_POST['update_title'] );
		$update_description = ! empty( $_POST['update_description'] );
		$dry_run            = ! empty( $_POST['dry_run'] );

		if ( ! $update_title && ! $update_description ) {
			$this->redirect_with_result( false, 'Select at least one field to update.', array() );
		}

		if ( empty( $_FILES['cbp_metadata_file'] ) || ! empty( $_FILES['cbp_metadata_file']['error'] ) ) {
			$this->redirect_with_result( false, 'Upload a valid CSV or XLSX file.', array() );
		}

		$file      = $_FILES['cbp_metadata_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
			$this->redirect_with_result( false, 'Only CSV and XLSX files are supported.', array() );
		}

		$rows = 'csv' === $extension
			? $this->parse_csv_file( $file['tmp_name'] )
			: $this->parse_xlsx_file( $file['tmp_name'] );

		if ( is_wp_error( $rows ) ) {
			$this->redirect_with_result( false, $rows->get_error_message(), array() );
		}

		$result_rows   = array();
		$updated_count = 0;
		$meta_keys     = $this->get_meta_keys();

		foreach ( $rows as $index => $row ) {
			$row_number       = $index + 2;
			$url              = trim( (string) ( $row['url'] ?? '' ) );
			$meta_title       = trim( (string) ( $row['meta_title'] ?? '' ) );
			$meta_description = trim( (string) ( $row['meta_description'] ?? '' ) );

			if ( '' === $url ) {
				$result_rows[] = $this->build_row_result( $row_number, $url, 'Skipped', 'Missing URL.', $meta_title, $meta_description );
				continue;
			}

			$resolution = $this->resolve_post_id_from_input_url( $url );
			if ( ! empty( $resolution['error'] ) ) {
				$result_rows[] = $this->build_row_result( $row_number, $url, 'Skipped', $resolution['error'], $meta_title, $meta_description );
				continue;
			}

			$post_id = (int) ( $resolution['post_id'] ?? 0 );
			if ( ! $post_id ) {
				$result_rows[] = $this->build_row_result( $row_number, $url, 'Skipped', 'No matching WordPress post or page found for URL.', $meta_title, $meta_description );
				continue;
			}

			$changes = array();

			if ( $update_title ) {
				if ( '' === $meta_title ) {
					$changes[] = 'Meta Title missing.';
				} else {
					$changes[] = sprintf( 'Meta Title => "%s"', $meta_title );
				}
			}

			if ( $update_description ) {
				if ( '' === $meta_description ) {
					$changes[] = 'Meta Description missing.';
				} else {
					$changes[] = sprintf( 'Meta Description => "%s"', $meta_description );
				}
			}

			$has_title_update       = $update_title && '' !== $meta_title;
			$has_description_update = $update_description && '' !== $meta_description;

			$matched_url = wp_make_link_relative( get_permalink( $post_id ) );
			if ( '' === $matched_url ) {
				$matched_url = '/';
			}

			if ( ! $has_title_update && ! $has_description_update ) {
				$result_rows[] = $this->build_row_result( $row_number, $url, 'Skipped', implode( ' ', $changes ), $meta_title, $meta_description, $matched_url );
				continue;
			}

			if ( $dry_run ) {
				$result_rows[] = $this->build_row_result( $row_number, $url, 'Dry run', implode( ' ', $changes ), $meta_title, $meta_description, $matched_url );
				continue;
			}

			if ( $has_title_update ) {
				update_post_meta( $post_id, $meta_keys['title'], $meta_title );
			}

			if ( $has_description_update ) {
				update_post_meta( $post_id, $meta_keys['description'], $meta_description );
			}

			clean_post_cache( $post_id );

			++$updated_count;
			$result_rows[] = $this->build_row_result( $row_number, $url, 'Updated', implode( ' ', $changes ), $meta_title, $meta_description, $matched_url );
		}

		$message = $dry_run
			? sprintf( 'Dry run complete. Reviewed %d row(s).', count( $rows ) )
			: sprintf( 'Update complete. Updated %d row(s).', $updated_count );

		$this->redirect_with_result( true, $message, $result_rows );
	}

	/**
	 * Parses a CSV file and returns its rows as an array.
	 *
	 * @param string $file_path Path to the CSV file.
	 * @return array|WP_Error   Array of rows or WP_Error on failure.
	 */
	private function parse_csv_file( string $file_path ) {
		$handle = fopen( $file_path, 'r' );
		if ( false === $handle ) {
			return new WP_Error( 'cbp_csv_open_failed', 'Could not open the CSV file.' );
		}

		$headers = fgetcsv( $handle, 0, ',', '"', '\\' );
		if ( false === $headers ) {
			fclose( $handle );
			return new WP_Error( 'cbp_csv_headers_missing', 'The CSV file is empty.' );
		}

		$normalized_headers = $this->normalize_headers( $headers );
		$mapped_headers     = $this->map_headers( $normalized_headers );
		if ( is_wp_error( $mapped_headers ) ) {
			fclose( $handle );
			return $mapped_headers;
		}

		$rows = array();
		while ( true ) {
			$data = fgetcsv( $handle, 0, ',', '"', '\\' );
			if ( false === $data ) {
				break;
			}
			$rows[] = $this->extract_row_from_columns( $mapped_headers, $data );
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Parses an XLSX file and returns its rows as an array.
	 *
	 * @param string $file_path Path to the XLSX file.
	 * @return array|WP_Error   Array of rows or WP_Error on failure.
	 */
	private function parse_xlsx_file( string $file_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'cbp_ziparchive_missing', 'XLSX support requires the PHP ZipArchive extension.' );
		}

		$zip = new ZipArchive();
		if ( $zip->open( $file_path ) !== true ) {
			return new WP_Error( 'cbp_xlsx_open_failed', 'Could not open the XLSX file.' );
		}

		$shared_strings     = array();
		$shared_strings_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $shared_strings_xml ) {
			$shared_strings = $this->parse_xlsx_shared_strings( $shared_strings_xml );
		}

		$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();

		if ( false === $sheet_xml ) {
			return new WP_Error( 'cbp_xlsx_sheet_missing', 'Could not find the first worksheet in the XLSX file.' );
		}

		$sheet      = simplexml_load_string( $sheet_xml );
		$sheet_rows = $sheet ? $sheet->xpath( '/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]' ) : false;
		if ( ! $sheet || false === $sheet_rows ) {
			return new WP_Error( 'cbp_xlsx_invalid', 'The XLSX worksheet could not be parsed.' );
		}

		$rows       = array();
		$header_row = null;

		foreach ( $sheet_rows as $row ) {
			$row_values = array();
			$cells      = $row->xpath( './*[local-name()="c"]' );
			if ( false === $cells ) {
				continue;
			}

			foreach ( $cells as $cell ) {
				$reference                   = (string) $cell['r'];
				$column_index                = $this->column_reference_to_index( $reference );
				$row_values[ $column_index ] = $this->extract_xlsx_cell_value( $cell, $shared_strings );
			}

			if ( null === $header_row ) {
				ksort( $row_values );
				$header_row = array_values( $row_values );
				continue;
			}

			$rows[] = $row_values;
		}

		if ( null === $header_row ) {
			return new WP_Error( 'cbp_xlsx_headers_missing', 'The XLSX file is empty.' );
		}

		$normalized_headers = $this->normalize_headers( $header_row );
		$mapped_headers     = $this->map_headers( $normalized_headers );
		if ( is_wp_error( $mapped_headers ) ) {
			return $mapped_headers;
		}

		$normalized_rows = array();
		foreach ( $rows as $row_values ) {
			$normalized_rows[] = $this->extract_row_from_columns( $mapped_headers, $row_values );
		}

		return $normalized_rows;
	}

	/**
	 * Normalize header values by trimming and converting to lowercase.
	 *
	 * @param array $headers The header values to normalize.
	 * @return array The normalized header values.
	 */
	private function normalize_headers( array $headers ): array {
		return array_map(
			static function ( $header ): string {
				$header = (string) $header;
				$header = preg_replace( '/^\xEF\xBB\xBF/', '', $header );
				return strtolower( trim( $header ) );
			},
			$headers
		);
	}
	/**
	 * Resolve a post ID from a given input URL or path.
	 *
	 * Attempts to find the post ID by matching the input as a URL or path, including candidate URLs and paths.
	 * Returns an array with 'post_id' (int) and 'error' (string|null).
	 *
	 * @param string $input The input URL or path to resolve.
	 * @return array{post_id:int,error:string|null} Array with post ID and error message if any.
	 */
	private function resolve_post_id_from_input_url( string $input ): array {
		$normalized_input = html_entity_decode( trim( $input ), ENT_QUOTES, 'UTF-8' );
		if ( '' === $normalized_input ) {
			return array(
				'post_id' => 0,
				'error'   => 'Missing URL.',
			);
		}

		$candidate_urls = $this->build_candidate_urls( $normalized_input );
		foreach ( $candidate_urls as $candidate_url ) {
			$post_id = url_to_postid( $candidate_url );
			if ( $post_id ) {
				return array(
					'post_id' => (int) $post_id,
					'error'   => null,
				);
			}
		}

		$candidate_paths = $this->build_candidate_paths( $normalized_input );
		$matches         = array();

		foreach ( $candidate_paths as $candidate_path ) {
			$path_match = $this->match_post_by_path( $candidate_path );
			if ( $path_match ) {
				$matches[ $path_match ] = $path_match;
			}

			foreach ( $this->match_posts_by_slug_from_path( $candidate_path ) as $slug_match ) {
				$matches[ $slug_match ] = $slug_match;
			}
		}

		$matches = array_values( $matches );
		if ( count( $matches ) > 1 ) {
			$labels = array();
			foreach ( $matches as $match_id ) {
				$labels[] = sprintf( '#%d %s', $match_id, get_the_title( $match_id ) );
			}

			return array(
				'post_id' => 0,
				'error'   => sprintf( 'Ambiguous URL match. Candidates: %s', implode( ', ', $labels ) ),
			);
		}

		if ( count( $matches ) === 1 ) {
			return array(
				'post_id' => (int) $matches[0],
				'error'   => null,
			);
		}

		return array(
			'post_id' => 0,
			'error'   => null,
		);
	}

	/**
	 * Builds an array of candidate URLs from the given input string.
	 *
	 * @param string $input The input string to build candidate URLs from.
	 * @return array Array of candidate URLs.
	 */
	private function build_candidate_urls( string $input ): array {
		$candidate_paths = $this->build_candidate_paths( $input );
		$urls            = array();

		if ( $this->is_absolute_url( $input ) ) {
			$urls[] = $this->normalize_absolute_url( $input );
		}

		foreach ( $candidate_paths as $candidate_path ) {
			$urls[] = home_url( $candidate_path );
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * Builds an array of candidate paths from the given input string.
	 *
	 * @param string $input The input string to build candidate paths from.
	 * @return array Array of candidate paths.
	 */
	private function build_candidate_paths( string $input ): array {
		$path = $this->extract_normalized_path( $input );
		if ( null === $path ) {
			return array();
		}

		$paths = array( $path );
		if ( '/' !== $path ) {
			$trimmed_path = untrailingslashit( $path );
			$paths[]      = $trimmed_path;
			$paths[]      = trailingslashit( $trimmed_path );
		}

		return array_values( array_unique( array_filter( $paths ) ) );
	}

	/**
	 * Extracts and normalizes the path from the given input string.
	 *
	 * @param string $input The input string to extract the path from.
	 * @return string|null The normalized path, or null if input is empty or invalid.
	 */
	private function extract_normalized_path( string $input ): ?string {
		$value = trim( $input );
		if ( '' === $value ) {
			return null;
		}

		if ( ! $this->is_absolute_url( $value ) && strpos( $value, '/' ) !== 0 ) {
			$value = '/' . $value;
		}

		$parts = wp_parse_url( $value );
		if ( false === $parts ) {
			return null;
		}

		$path = isset( $parts['path'] ) ? rawurldecode( (string) $parts['path'] ) : '/';
		$path = preg_replace( '#/+#', '/', $path );
		if ( ! is_string( $path ) || '' === $path ) {
			return '/';
		}

		if ( '/' !== $path ) {
			$path = '/' . ltrim( $path, '/' );
		}

		return $path;
	}

	/**
	 * Determines if the given value is an absolute URL.
	 *
	 * @param string $value The value to check.
	 * @return bool True if the value is an absolute URL, false otherwise.
	 */
	private function is_absolute_url( string $value ): bool {
		return (bool) preg_match( '#^https?://#i', $value );
	}

	/**
	 * Normalizes an absolute URL by ensuring scheme and host match the site, and path is normalized.
	 *
	 * @param string $url The URL to normalize.
	 * @return string The normalized absolute URL.
	 */
	private function normalize_absolute_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( false === $parts || empty( $parts['host'] ) ) {
			return $url;
		}

		$path            = isset( $parts['path'] ) ? $this->extract_normalized_path( $url ) : '/';
		$scheme_home_url = wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
		$scheme          = $scheme_home_url ? $scheme_home_url : ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' );
		$host            = $parts['host'];
		$site_host       = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		if ( $site_host ) {
			$normalized_host      = preg_replace( '/^www\./i', '', $host );
			$normalized_site_host = preg_replace( '/^www\./i', '', $site_host );
			if ( $normalized_host === $normalized_site_host ) {
				$host = $site_host;
			}
		}

		return $scheme . '://' . $host . ( $path ?? '/' );
	}

	/**
	 * Attempts to find a post by its path.
	 *
	 * @param string $path The path to match against posts.
	 * @return int The post ID if found, or 0 if not found.
	 */
	private function match_post_by_path( string $path ): int {
		$trimmed_path = trim( $path, '/' );
		if ( '' === $trimmed_path ) {
			return 0;
		}

		$public_post_types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$match             = get_page_by_path( $trimmed_path, OBJECT, $public_post_types );

		return $match instanceof WP_Post ? (int) $match->ID : 0;
	}

	/**
	 * Attempts to find posts by the slug extracted from the given path.
	 *
	 * @param string $path The path to extract the slug from.
	 * @return array Array of post IDs matching the slug.
	 */
	private function match_posts_by_slug_from_path( string $path ): array {
		$trimmed_path = trim( $path, '/' );
		if ( '' === $trimmed_path ) {
			return array();
		}

		$segments = array_values( array_filter( explode( '/', $trimmed_path ) ) );
		$slug     = end( $segments );
		if ( ! $slug ) {
			return array();
		}

		$posts = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);

		return array_map( 'intval', $posts );
	}

	/**
	 * Maps header names to their corresponding field keys using supported aliases.
	 *
	 * @param array $headers The array of header names from the input file.
	 * @return array|WP_Error Mapped header keys or WP_Error on failure.
	 */
	private function map_headers( array $headers ) {
		$aliases = array(
			'url'              => array( 'url' ),
			'meta_title'       => array( 'meta title', 'title', 'meta_title' ),
			'meta_description' => array( 'meta description', 'description', 'meta_description', 'meta desc' ),
		);

		$mapped = array();
		foreach ( $aliases as $field => $supported_headers ) {
			foreach ( $headers as $index => $header ) {
				if ( in_array( $header, $supported_headers, true ) ) {
					$mapped[ $field ] = $index;
					break;
				}
			}
		}

		if ( ! isset( $mapped['url'] ) ) {
			return new WP_Error( 'cbp_missing_url_header', 'Missing required URL column.' );
		}

		if ( ! isset( $mapped['meta_title'] ) && ! isset( $mapped['meta_description'] ) ) {
			return new WP_Error( 'cbp_missing_meta_headers', 'Include at least one Meta Title or Meta Description column.' );
		}

		return $mapped;
	}

	/**
	 * Extracts a row's values based on mapped header indices.
	 *
	 * @param array $mapped_headers Associative array mapping field names to column indices.
	 * @param array $row The row data as an array.
	 * @return array Associative array with 'url', 'meta_title', and 'meta_description' keys.
	 */
	private function extract_row_from_columns( array $mapped_headers, array $row ): array {
		return array(
			'url'              => isset( $mapped_headers['url'], $row[ $mapped_headers['url'] ] ) ? (string) $row[ $mapped_headers['url'] ] : '',
			'meta_title'       => isset( $mapped_headers['meta_title'], $row[ $mapped_headers['meta_title'] ] ) ? (string) $row[ $mapped_headers['meta_title'] ] : '',
			'meta_description' => isset( $mapped_headers['meta_description'], $row[ $mapped_headers['meta_description'] ] ) ? (string) $row[ $mapped_headers['meta_description'] ] : '',
		);
	}

	/**
	 * Parses the sharedStrings.xml content from an XLSX file and returns an array of shared strings.
	 *
	 * @param string $shared_strings_xml The XML content of sharedStrings.xml.
	 * @return array Array of shared strings extracted from the XML.
	 */
	private function parse_xlsx_shared_strings( string $shared_strings_xml ): array {
		$document = simplexml_load_string( $shared_strings_xml );
		if ( ! $document ) {
			return array();
		}

		$items = $document->xpath( '/*[local-name()="sst"]/*[local-name()="si"]' );
		if ( false === $items ) {
			return array();
		}

		$strings = array();
		foreach ( $items as $item ) {
			$text_nodes = $item->xpath( './/*[local-name()="t"]' );
			if ( false === $text_nodes ) {
				$strings[] = '';
				continue;
			}

			$value = '';
			foreach ( $text_nodes as $text_node ) {
				$value .= (string) $text_node;
			}

			$strings[] = $value;
		}

		return $strings;
	}

	/**
	 * Extracts the value from an XLSX cell, handling shared strings and inline strings.
	 *
	 * @param SimpleXMLElement $cell The cell element from the XLSX sheet.
	 * @param array            $shared_strings Array of shared strings from the XLSX file.
	 * @return string The extracted cell value as a string.
	 */
	private function extract_xlsx_cell_value( SimpleXMLElement $cell, array $shared_strings ): string {
		$type        = (string) $cell['t'];
		$value_nodes = $cell->xpath( './*[local-name()="v"]' );
		$value       = ! empty( $value_nodes ) ? (string) $value_nodes[0] : '';

		if ( 's' === $type ) {
			$index = (int) $value;
			return $shared_strings[ $index ] ?? '';
		}

		if ( 'inlineStr' === $type ) {
			$inline_text = $cell->xpath( './*[local-name()="is"]//*[local-name()="t"]' );
			if ( ! empty( $inline_text ) ) {
				return (string) $inline_text[0];
			}
		}

		return $value;
	}

	/**
	 * Converts a column reference (e.g., 'A', 'B', 'AA') to a zero-based index.
	 *
	 * @param string $reference The column reference string.
	 * @return int The zero-based column index.
	 */
	private function column_reference_to_index( string $reference ): int {
		$letters = preg_replace( '/[^A-Z]/', '', strtoupper( $reference ) );
		$index   = 0;

		for ( $i = 0, $length = strlen( $letters ); $i < $length; $i++ ) {
			$index = ( $index * 26 ) + ( ord( $letters[ $i ] ) - 64 );
		}

		return max( 0, $index - 1 );
	}

	/**
	 * Builds a result array for a processed row.
	 *
	 * @param int    $row_number The row number.
	 * @param string $url        The URL processed.
	 * @param string $status     The status of the operation.
	 * @param string $message    The message for the row.
	 * @return array             The result array for the row.
	 */
	private function build_row_result( int $row_number, string $url, string $status, string $message, string $meta_title = '', string $meta_description = '', string $matched_url = '' ): array {
		return array(
			'row'              => $row_number,
			'url'              => $url,
			'status'           => $status,
			'message'          => $message,
			'meta_title'       => $meta_title,
			'meta_description' => $meta_description,
			'matched_url'      => $matched_url,
		);
	}

	/**
	 * Redirects to the plugin page with a result stored in a transient.
	 *
	 * @param bool   $success Indicates if the operation was successful.
	 * @param string $message The message to display.
	 * @param array  $rows    The result rows to store.
	 * @return void
	 */
	private function redirect_with_result( bool $success, string $message, array $rows ): void {
		set_transient(
			'cbp_update_metadata_result',
			array(
				'success' => $success,
				'message' => $message,
				'rows'    => $rows,
			),
			MINUTE_IN_SECONDS * 10
		);

		wp_safe_redirect( admin_url( 'tools.php?page=cbp-update-metadata' ) );
		exit;
	}
}

new CBP_Update_Metadata_Plugin();
