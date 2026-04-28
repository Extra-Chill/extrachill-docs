<?php
/**
 * Last Reviewed Date for ec_doc
 *
 * Tracks when each documentation article was last reviewed for accuracy.
 * Editors set the date manually; nothing is auto-backfilled.
 *
 * This module currently registers the `_ec_doc_last_reviewed` post meta
 * (string, Y-m-d) and exposes it to the REST API so the editor sidebar
 * and admin tooling can read/write it. The editor UI and admin list
 * column are layered on top in subsequent changes.
 *
 * @package ExtraChillDocs
 * @since 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta key for the last-reviewed date (string, Y-m-d).
 *
 * @since 0.5.0
 */
const EXTRACHILL_DOCS_LAST_REVIEWED_META_KEY = '_ec_doc_last_reviewed';

/**
 * Stale thresholds in days.
 *
 * @since 0.5.0
 */
const EXTRACHILL_DOCS_STALE_AMBER_DAYS = 90;
const EXTRACHILL_DOCS_STALE_RED_DAYS   = 180;

add_action( 'init', 'extrachill_docs_register_last_reviewed_meta' );
add_action( 'enqueue_block_editor_assets', 'extrachill_docs_enqueue_last_reviewed_sidebar' );
add_filter( 'manage_ec_doc_posts_columns', 'extrachill_docs_register_last_reviewed_column' );
add_action( 'manage_ec_doc_posts_custom_column', 'extrachill_docs_render_last_reviewed_column', 10, 2 );
add_filter( 'manage_edit-ec_doc_sortable_columns', 'extrachill_docs_register_last_reviewed_sortable' );
add_action( 'pre_get_posts', 'extrachill_docs_apply_last_reviewed_sort' );
add_action( 'admin_print_styles-edit.php', 'extrachill_docs_print_last_reviewed_admin_styles' );

/**
 * Registers `_ec_doc_last_reviewed` post meta on the ec_doc post type.
 *
 * REST-exposed so the editor sidebar can read/write it via the core
 * entity store. Stored as `string` in `Y-m-d` format. Empty string means
 * "never reviewed" (the default for existing docs — no auto-backfill).
 *
 * @since 0.5.0
 * @return void
 */
function extrachill_docs_register_last_reviewed_meta() {
	register_post_meta(
		'ec_doc',
		EXTRACHILL_DOCS_LAST_REVIEWED_META_KEY,
		array(
			'type'              => 'string',
			'description'       => __( 'Date the doc was last reviewed for accuracy (Y-m-d).', 'extrachill-docs' ),
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'extrachill_docs_sanitize_last_reviewed_date',
			'auth_callback'     => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}

/**
 * Sanitizes a Y-m-d date string. Returns '' for invalid input.
 *
 * @since 0.5.0
 * @param mixed $value Raw meta value.
 * @return string Sanitized Y-m-d date or empty string.
 */
function extrachill_docs_sanitize_last_reviewed_date( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return '';
	}

	$value = trim( $value );
	$date  = DateTime::createFromFormat( 'Y-m-d', $value );

	if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
		return '';
	}

	return $value;
}

/**
 * Enqueues the Gutenberg sidebar panel script on ec_doc edit screens.
 *
 * No build step: hand-written ES module that consumes WP globals.
 *
 * @since 0.5.0
 * @return void
 */
function extrachill_docs_enqueue_last_reviewed_sidebar() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'ec_doc' !== $screen->post_type ) {
		return;
	}

	$script_path = EXTRACHILL_DOCS_PLUGIN_DIR . 'assets/js/last-reviewed-sidebar.js';
	if ( ! file_exists( $script_path ) ) {
		return;
	}

	wp_enqueue_script(
		'extrachill-docs-last-reviewed-sidebar',
		EXTRACHILL_DOCS_PLUGIN_URL . 'assets/js/last-reviewed-sidebar.js',
		array(
			'wp-element',
			'wp-plugins',
			'wp-edit-post',
			'wp-components',
			'wp-data',
			'wp-i18n',
			'wp-compose',
		),
		filemtime( $script_path ),
		true
	);

	wp_set_script_translations( 'extrachill-docs-last-reviewed-sidebar', 'extrachill-docs' );
}

/**
 * Adds the "Last reviewed" column to the ec_doc list table.
 *
 * Inserted before the Date column for visual proximity to other dates.
 *
 * @since 0.5.0
 * @param array $columns Existing columns.
 * @return array Modified columns.
 */
function extrachill_docs_register_last_reviewed_column( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		if ( 'date' === $key ) {
			$new['ec_doc_last_reviewed'] = __( 'Last reviewed', 'extrachill-docs' );
		}
		$new[ $key ] = $label;
	}

	// Fallback: append if Date column was absent.
	if ( ! isset( $new['ec_doc_last_reviewed'] ) ) {
		$new['ec_doc_last_reviewed'] = __( 'Last reviewed', 'extrachill-docs' );
	}

	return $new;
}

/**
 * Renders the Last reviewed column cell.
 *
 * Color hints:
 *  - never reviewed → muted "Never"
 *  - >180 days      → red
 *  - >90 days       → amber
 *  - otherwise      → plain
 *
 * @since 0.5.0
 * @param string $column  Column slug.
 * @param int    $post_id Post ID.
 * @return void
 */
function extrachill_docs_render_last_reviewed_column( $column, $post_id ) {
	if ( 'ec_doc_last_reviewed' !== $column ) {
		return;
	}

	$value = get_post_meta( $post_id, EXTRACHILL_DOCS_LAST_REVIEWED_META_KEY, true );

	if ( '' === $value ) {
		echo '<span class="ec-doc-reviewed ec-doc-reviewed--never">' . esc_html__( 'Never', 'extrachill-docs' ) . '</span>';
		return;
	}

	$reviewed = DateTime::createFromFormat( 'Y-m-d', $value );
	if ( ! $reviewed ) {
		echo '<span class="ec-doc-reviewed ec-doc-reviewed--never">' . esc_html__( 'Never', 'extrachill-docs' ) . '</span>';
		return;
	}

	$now      = new DateTime( 'now', $reviewed->getTimezone() );
	$age_days = (int) $now->diff( $reviewed )->days;

	$class = 'ec-doc-reviewed';
	if ( $age_days > EXTRACHILL_DOCS_STALE_RED_DAYS ) {
		$class .= ' ec-doc-reviewed--red';
	} elseif ( $age_days > EXTRACHILL_DOCS_STALE_AMBER_DAYS ) {
		$class .= ' ec-doc-reviewed--amber';
	}

	$display = mysql2date( get_option( 'date_format' ), $value . ' 00:00:00' );

	printf(
		'<span class="%1$s" title="%2$s">%3$s</span>',
		esc_attr( $class ),
		esc_attr(
			sprintf(
				/* translators: %d: age in days */
				_n( '%d day ago', '%d days ago', $age_days, 'extrachill-docs' ),
				$age_days
			)
		),
		esc_html( $display )
	);
}

/**
 * Marks the Last reviewed column as sortable.
 *
 * @since 0.5.0
 * @param array $columns Sortable columns.
 * @return array
 */
function extrachill_docs_register_last_reviewed_sortable( $columns ) {
	$columns['ec_doc_last_reviewed'] = 'ec_doc_last_reviewed';
	return $columns;
}

/**
 * Applies a meta-based sort when the user clicks the column header.
 *
 * Uses meta_value (string compare) since dates are zero-padded Y-m-d
 * which sorts lexicographically the same as chronologically. Posts
 * without the meta sort to the bottom on ASC and top on DESC, which
 * matches WordPress's default LEFT JOIN behavior.
 *
 * @since 0.5.0
 * @param WP_Query $query Current query.
 * @return void
 */
function extrachill_docs_apply_last_reviewed_sort( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( 'ec_doc_last_reviewed' !== $query->get( 'orderby' ) ) {
		return;
	}

	$query->set( 'meta_key', EXTRACHILL_DOCS_LAST_REVIEWED_META_KEY );
	$query->set( 'orderby', 'meta_value' );
}

/**
 * Prints minimal admin CSS for the Last reviewed column.
 *
 * Inlined so we don't add a new stylesheet for a few selectors.
 *
 * @since 0.5.0
 * @return void
 */
function extrachill_docs_print_last_reviewed_admin_styles() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit-ec_doc' !== $screen->id ) {
		return;
	}

	?>
	<style>
		.column-ec_doc_last_reviewed { width: 12em; }
		.ec-doc-reviewed--never { color: #757575; font-style: italic; }
		.ec-doc-reviewed--amber { color: #b26200; font-weight: 600; }
		.ec-doc-reviewed--red { color: #b32d2e; font-weight: 600; }
	</style>
	<?php
}
