<?php
declare(strict_types=1);
/**
 * Docs Sync Ability
 *
 * Syncs a documentation post (ec_doc) from a Markdown source file.
 * Converts Markdown to HTML, adds header IDs for TOC anchoring,
 * resolves internal .md links to ec_doc permalinks, and upserts
 * the post with platform taxonomy assignment.
 *
 * Permission: edit_posts (matches the original REST route).
 *
 * @package ExtraChillDocs
 * @since 0.5.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_docs_register_docs_sync_ability' );

/**
 * Register the docs-sync ability.
 */
function extrachill_docs_register_docs_sync_ability(): void {
	wp_register_ability(
		'extrachill/docs-sync',
		array(
			'label'               => __( 'Docs Sync', 'extrachill-docs' ),
			'description'         => __( 'Syncs a documentation post from a Markdown source file. Converts to HTML, adds TOC anchors, resolves internal links, and upserts the ec_doc post.', 'extrachill-docs' ),
			'category'            => 'extrachill-docs',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'source_file'   => array(
						'type'        => 'string',
						'description' => __( 'Relative path of the source Markdown file.', 'extrachill-docs' ),
					),
					'title'         => array(
						'type'        => 'string',
						'description' => __( 'Document title.', 'extrachill-docs' ),
					),
					'content'       => array(
						'type'        => 'string',
						'description' => __( 'Raw Markdown content to sync.', 'extrachill-docs' ),
					),
					'platform_slug' => array(
						'type'        => 'string',
						'description' => __( 'Platform taxonomy slug (e.g. "kimaki", "datamachine").', 'extrachill-docs' ),
					),
					'slug'          => array(
						'type'        => 'string',
						'description' => __( 'Post slug for the ec_doc post.', 'extrachill-docs' ),
					),
					'filesize'      => array(
						'type'        => 'integer',
						'description' => __( 'File size in bytes for change tracking.', 'extrachill-docs' ),
					),
					'timestamp'     => array(
						'type'        => 'string',
						'description' => __( 'ISO 8601 timestamp of the source file.', 'extrachill-docs' ),
					),
					'force'         => array(
						'type'        => 'boolean',
						'description' => __( 'Force sync even if content hash matches.', 'extrachill-docs' ),
						'default'     => false,
					),
				),
				'required'   => array( 'source_file', 'title', 'content', 'platform_slug', 'slug', 'filesize', 'timestamp' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the sync succeeded.', 'extrachill-docs' ),
					),
					'action'  => array(
						'type'        => 'string',
						'description' => __( 'One of: created, updated, skipped.', 'extrachill-docs' ),
					),
					'id'      => array(
						'type'        => 'integer',
						'description' => __( 'Post ID of the synced ec_doc.', 'extrachill-docs' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_docs_ability_docs_sync',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for the docs-sync ability.
 *
 * Mirrors ExtraChill_Docs_Sync_Controller::sync_doc() from extrachill-api
 * but operates as a standalone ability without a REST request object.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error Sync result with success, action, and post ID.
 */
function extrachill_docs_ability_docs_sync( array $input ) {
	$docs_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'docs' ) : null;
	if ( ! $docs_blog_id || get_current_blog_id() !== $docs_blog_id ) {
		return new WP_Error(
			'invalid_site',
			'Documentation sync is only allowed on the docs site.',
			array( 'status' => 400 )
		);
	}

	if ( ! post_type_exists( 'ec_doc' ) ) {
		return new WP_Error(
			'missing_post_type',
			'The ec_doc post type is not registered on this site.',
			array( 'status' => 500 )
		);
	}

	$source_file   = (string) $input['source_file'];
	$title         = (string) $input['title'];
	$content       = (string) $input['content'];
	$platform_slug = (string) $input['platform_slug'];
	$slug          = (string) $input['slug'];
	$filesize      = (int) $input['filesize'];
	$timestamp     = (string) $input['timestamp'];
	$force         = isset( $input['force'] ) ? (bool) $input['force'] : false;

	// Convert Markdown to HTML.
	$html_content = extrachill_docs_sync_markdown_to_html( $content );

	// Add IDs to h2 headers for TOC anchor linking.
	$html_content = extrachill_docs_sync_add_header_ids( $html_content );

	// Resolve internal .md links to ec_doc permalinks.
	$html_content = extrachill_docs_sync_resolve_internal_links( $html_content );

	// Calculate hash to detect changes.
	$hash = hash( 'sha256', $content . $title . $platform_slug );

	// Find existing post by source_file meta.
	$existing_post = extrachill_docs_sync_get_post_by_source_file( $source_file );

	if ( $existing_post ) {
		$stored_hash = get_post_meta( $existing_post->ID, '_sync_hash', true );
		if ( ! $force && $stored_hash === $hash ) {
			return array(
				'success' => true,
				'action'  => 'skipped',
				'id'      => $existing_post->ID,
			);
		}
		$post_id = $existing_post->ID;
		$action  = 'updated';
	} else {
		$post_id = 0;
		$action  = 'created';
	}

	// Ensure platform taxonomy term exists.
	$term_id = extrachill_docs_sync_ensure_platform_term( $platform_slug );
	if ( is_wp_error( $term_id ) ) {
		return $term_id;
	}

	// Insert or update.
	$post_data = array(
		'ID'           => $post_id,
		'post_title'   => $title,
		'post_content' => $html_content,
		'post_name'    => $slug,
		'post_status'  => 'publish',
		'post_type'    => 'ec_doc',
		'meta_input'   => array(
			'_source_file'    => $source_file,
			'_sync_hash'      => $hash,
			'_sync_timestamp' => $timestamp,
			'_sync_filesize'  => $filesize,
		),
	);

	$id = wp_insert_post( $post_data, true );

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	wp_set_object_terms( $id, array( $term_id ), 'ec_doc_platform' );

	return array(
		'success' => true,
		'action'  => $action,
		'id'      => $id,
	);
}

/**
 * Convert Markdown content to HTML.
 *
 * Uses League\CommonMark if available (loaded via the plugin's Composer
 * autoloader), otherwise falls back to a minimal conversion.
 *
 * @param string $markdown Raw Markdown.
 * @return string HTML content.
 */
function extrachill_docs_sync_markdown_to_html( string $markdown ): string {
	$autoload = EXTRACHILL_DOCS_PLUGIN_DIR . 'vendor/autoload.php';
	if ( file_exists( $autoload ) ) {
		require_once $autoload;
	}

	if ( class_exists( \League\CommonMark\CommonMarkConverter::class ) ) {
		$converter = new \League\CommonMark\CommonMarkConverter();
		return $converter->convert( $markdown )->getContent();
	}

	// Minimal fallback — wrap in paragraphs.
	return wpautop( $markdown );
}

/**
 * Add IDs to h2 headers for TOC anchor linking.
 *
 * @param string $html HTML content.
 * @return string HTML with IDs added to h2 headers.
 */
function extrachill_docs_sync_add_header_ids( string $html ): string {
	$used_ids = array();

	return (string) preg_replace_callback(
		'/<(h2)([^>]*)>(.*?)<\/h2>/i',
		function ( array $matches ) use ( &$used_ids ): string {
			$attrs = $matches[2];
			$text  = $matches[3];

			// Skip if already has an id.
			if ( preg_match( '/id=["\']/', $attrs ) ) {
				return $matches[0];
			}

			$slug    = sanitize_title( wp_strip_all_tags( $text ) );
			$base_id = 'toc-' . $slug;
			$id      = $base_id;

			$counter = 2;
			while ( in_array( $id, $used_ids, true ) ) {
				$id = $base_id . '-' . $counter;
				$counter++;
			}
			$used_ids[] = $id;

			return sprintf( '<h2%s id="%s">%s</h2>', $attrs, esc_attr( $id ), $text );
		},
		$html
	);
}

/**
 * Resolve internal .md links to ec_doc permalinks.
 *
 * @param string $html HTML content with potential .md links.
 * @return string HTML with resolved internal links.
 */
function extrachill_docs_sync_resolve_internal_links( string $html ): string {
	return (string) preg_replace_callback(
		'/<a\s+([^>]*?)href=["\']([^"\']+\.md)["\']([^>]*)>/i',
		function ( array $matches ): string {
			$before_href = $matches[1];
			$href        = $matches[2];
			$after_href  = $matches[3];

			$source_file = ltrim( $href, '/' );
			$post        = extrachill_docs_sync_get_post_by_source_file( $source_file );

			if ( $post ) {
				$permalink = get_permalink( $post->ID );
				return sprintf( '<a %shref="%s"%s>', $before_href, esc_url( $permalink ), $after_href );
			}

			return $matches[0];
		},
		$html
	);
}

/**
 * Find an ec_doc post by its _source_file meta value.
 *
 * @param string $source_file Source file path.
 * @return WP_Post|null
 */
function extrachill_docs_sync_get_post_by_source_file( string $source_file ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'ec_doc',
			'post_status'    => 'any',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_source_file',
					'value' => $source_file,
				),
			),
			'posts_per_page' => 1,
		)
	);

	return $query->have_posts() ? $query->posts[0] : null;
}

/**
 * Ensure a platform taxonomy term exists, creating it if necessary.
 *
 * @param string $slug Platform slug.
 * @return int|WP_Error Term ID on success, WP_Error on failure.
 */
function extrachill_docs_sync_ensure_platform_term( string $slug ) {
	$term = get_term_by( 'slug', $slug, 'ec_doc_platform' );
	if ( $term ) {
		return $term->term_id;
	}

	$name   = ucwords( str_replace( '-', ' ', $slug ) );
	$result = wp_insert_term( $name, 'ec_doc_platform', array( 'slug' => $slug ) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $result['term_id'];
}
