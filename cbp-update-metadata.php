<?php // phpcs:ignore WodPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: CBP Update Metadata
 * Description: Upload CSV or XLSX files to update Yoast meta titles and descriptions by URL, with dry-run support.
 * Version: 1.0.0
 * Author: Chillibyte - DS
 */


defined( 'ABSPATH' ) || exit;

/**
 * Class CBP_Update_Metadata_Plugin
 *
 * Handles the admin interface and logic for updating Yoast meta titles and descriptions via CSV/XLSX upload.
 *
 * @package CBP_Update_Metadata
 */
class CBP_Update_Metadata_Plugin {

    private const NONCE_ACTION         = 'cbp_update_metadata_upload';
    private const META_TITLE_KEY       = '_yoast_wpseo_title';
    private const META_DESCRIPTION_KEY = '_yoast_wpseo_metadesc';

    /**
     * CBP_Update_Metadata_Plugin constructor.
     * Registers admin menu and upload handler actions.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
        add_action( 'admin_post_cbp_update_metadata', array( $this, 'handle_upload' ) );
    }

    /**
     * Registers the admin page for updating Yoast metadata.
     */
    public function register_admin_page(): void {
        add_management_page(
            'Update Yoast Metadata',
            'Update Yoast Metadata',
            'manage_options',
            'cbp-update-metadata',
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * Renders the admin page for uploading and updating Yoast metadata.
     */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'cbp-update-metadata' ) );
        }

        $result = get_transient( 'cbp_update_metadata_result' );
        if ( $result ) {
            delete_transient( 'cbp_update_metadata_result' );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Update Yoast Metadata', 'cbp-update-metadata' ); ?></h1>
            <p><?php echo esc_html__( 'Upload a CSV or XLSX file with columns for URL, Meta Title, and optionally Meta Description.', 'cbp-update-metadata' ); ?></p>

            <?php if ( $result ) : ?>
                <div class="notice notice-<?php echo esc_attr( $result['success'] ? 'success' : 'error' ); ?>">
                    <p><?php echo esc_html( $result['message'] ); ?></p>
                </div>

                <?php if ( ! empty( $result['rows'] ) ) : ?>
                    <table class="widefat striped" style="max-width: 1200px; margin-top: 1rem;">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__( 'Row', 'cbp-update-metadata' ); ?></th>
                                <th><?php echo esc_html__( 'URL', 'cbp-update-metadata' ); ?></th>
                                <th><?php echo esc_html__( 'Status', 'cbp-update-metadata' ); ?></th>
                                <th><?php echo esc_html__( 'Details', 'cbp-update-metadata' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $result['rows'] as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( (string) $row['row'] ); ?></td>
                                    <td><?php echo esc_html( $row['url'] ); ?></td>
                                    <td><?php echo esc_html( $row['status'] ); ?></td>
                                    <td><?php echo esc_html( $row['message'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>

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

        foreach ( $rows as $index => $row ) {
            $row_number       = $index + 2;
            $url              = trim( (string) ( $row['url'] ?? '' ) );
            $meta_title       = trim( (string) ( $row['meta_title'] ?? '' ) );
            $meta_description = trim( (string) ( $row['meta_description'] ?? '' ) );

            if ( '' === $url ) {
                $result_rows[] = $this->build_row_result( $row_number, $url, 'Skipped', 'Missing URL.' );
                continue;
            }

            $resolution = $this->resolve_post_id_from_input_url( $url );
            if ( ! empty( $resolution['error'] ) ) {
                $result_rows[] = $this->build_row_result( $row_number, $url, 'Skipped', $resolution['error'] );
                continue;
            }

            $post_id = (int) ( $resolution['post_id'] ?? 0 );
            if ( ! $post_id ) {
                $result_rows[] = $this->build_row_result( $row_number, $url, 'Skipped', 'No matching WordPress post or page found for URL.' );
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

            if ( ! $has_title_update && ! $has_description_update ) {
                $result_rows[] = $this->build_row_result( $row_number, $url, 'Skipped', implode( ' ', $changes ) );
                continue;
            }

            if ( $dry_run ) {
                $result_rows[] = $this->build_row_result( $row_number, $url, 'Dry run', implode( ' ', $changes ) );
                continue;
            }

            if ( $has_title_update ) {
                update_post_meta( $post_id, self::META_TITLE_KEY, $meta_title );
            }

            if ( $has_description_update ) {
                update_post_meta( $post_id, self::META_DESCRIPTION_KEY, $meta_description );
            }

            if ( function_exists( 'wpseo_replace_vars' ) ) {
                clean_post_cache( $post_id );
            }

            ++$updated_count;
            $result_rows[] = $this->build_row_result( $row_number, $url, 'Updated', implode( ' ', $changes ) );
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
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . '/wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $csv_content = $wp_filesystem->get_contents( $file_path );
        if ( false === $csv_content ) {
            return new WP_Error( 'cbp_csv_open_failed', 'Could not open the CSV file.' );
        }

        $lines = preg_split( '/\r\n|\r|\n/', $csv_content );
        if ( empty( $lines ) ) {
            return new WP_Error( 'cbp_csv_headers_missing', 'The CSV file is empty.' );
        }

        $headers            = str_getcsv( array_shift( $lines ) );
        $normalized_headers = $this->normalize_headers( $headers );
        $mapped_headers     = $this->map_headers( $normalized_headers );
        if ( is_wp_error( $mapped_headers ) ) {
            return $mapped_headers;
        }

        $rows = array();
        foreach ( $lines as $line ) {
            if ( '' === trim( $line ) ) {
                continue;
            }
            $data   = str_getcsv( $line );
            $rows[] = $this->extract_row_from_columns( $mapped_headers, $data );
        }

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
				return strtolower( trim( (string) $header ) );
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
				'post_status'      => 'any',
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
    private function build_row_result( int $row_number, string $url, string $status, string $message ): array {
        return array(
            'row'     => $row_number,
            'url'     => $url,
            'status'  => $status,
            'message' => $message,
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
