<?php
declare(strict_types=1);
/**
 * Docs Info Ability
 *
 * Public read-only ability returning docs site metadata: site info,
 * about page content, post types with taxonomy breakdowns, and pages.
 *
 * @package ExtraChillDocs
 * @since 0.5.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_docs_register_docs_info_ability' );

/**
 * Register the docs-info ability.
 */
function extrachill_docs_register_docs_info_ability(): void {
	wp_register_ability(
		'extrachill/docs-info',
		array(
			'label'               => __( 'Docs Info', 'extrachill-docs' ),
			'description'         => __( 'Returns documentation site metadata including site info, about page, post types, taxonomies, and published pages.', 'extrachill-docs' ),
			'category'            => 'extrachill-docs',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'site'         => array(
						'type'        => 'object',
						'description' => __( 'Site identity: blog_id, domain, path, name, url.', 'extrachill-docs' ),
					),
					'generated_at' => array(
						'type'        => 'string',
						'description' => __( 'ISO 8601 timestamp of when the payload was generated.', 'extrachill-docs' ),
					),
					'about'        => array(
						'type'        => 'object',
						'description' => __( 'About page from the main site.', 'extrachill-docs' ),
					),
					'post_types'   => array(
						'type'        => 'object',
						'description' => __( 'Public post types with taxonomy and term breakdowns.', 'extrachill-docs' ),
					),
					'pages'        => array(
						'type'        => 'array',
						'description' => __( 'Published pages with title and URL.', 'extrachill-docs' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_docs_ability_docs_info',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for the docs-info ability.
 *
 * Delegates to helper functions that mirror the original REST route handler
 * in extrachill-api/inc/routes/docs/docs-info.php.
 *
 * @param array $input Unused — no input required.
 * @return array|WP_Error Docs metadata payload.
 */
function extrachill_docs_ability_docs_info( array $input ) {
	$site = get_site();

	$post_types = extrachill_docs_ability_collect_post_types();
	$pages      = extrachill_docs_ability_collect_pages();
	$about      = extrachill_docs_ability_collect_about();

	if ( is_wp_error( $about ) ) {
		return $about;
	}

	return array(
		'site'         => array(
			'blog_id' => get_current_blog_id(),
			'domain'  => isset( $site->domain ) ? $site->domain : '',
			'path'    => isset( $site->path ) ? $site->path : '/',
			'name'    => get_bloginfo( 'name' ),
			'url'     => home_url( '/' ),
		),
		'generated_at' => gmdate( 'c' ),
		'about'        => $about,
		'post_types'   => $post_types,
		'pages'        => $pages,
	);
}

/**
 * Loads About page content from the main site (blog ID 1).
 *
 * @return array|WP_Error
 */
function extrachill_docs_ability_collect_about() {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
	if ( ! $main_blog_id ) {
		return new WP_Error( 'about_not_found', 'Main site blog ID not available.', array( 'status' => 500 ) );
	}

	try {
		switch_to_blog( $main_blog_id );

		$about = get_page_by_path( 'about' );

		if ( ! $about || 'publish' !== $about->post_status ) {
			return new WP_Error( 'about_not_found', 'About page not found on main site.', array( 'status' => 500 ) );
		}

		return array(
			'id'      => (int) $about->ID,
			'slug'    => 'about',
			'title'   => get_the_title( $about ),
			'url'     => get_permalink( $about ),
			'content' => apply_filters( 'the_content', $about->post_content ),
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Collects published pages with title and URL.
 *
 * @return array
 */
function extrachill_docs_ability_collect_pages(): array {
	$pages = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$data = array();

	foreach ( $pages as $page ) {
		$data[] = array(
			'title' => get_the_title( $page ),
			'url'   => get_permalink( $page ),
		);
	}

	return $data;
}

/**
 * Collects post type metadata including taxonomies, counts, and term usage.
 *
 * @return array
 */
function extrachill_docs_ability_collect_post_types(): array {
	$public_post_types = get_post_types( array( 'public' => true ), 'objects' );
	$data              = array();

	global $wpdb;

	foreach ( $public_post_types as $post_type => $object ) {
		$counts          = wp_count_posts( $post_type );
		$published_count = isset( $counts->publish ) ? (int) $counts->publish : 0;

		$tax_data   = array();
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );

		foreach ( $taxonomies as $tax_obj ) {
			$total_terms = (int) wp_count_terms(
				array(
					'taxonomy'   => $tax_obj->name,
					'hide_empty' => false,
				)
			);

			$terms_with_counts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT t.term_id, t.slug, t.name, COUNT(p.ID) AS post_count
					 FROM {$wpdb->terms} t
					 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
					 INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
					 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
					 WHERE p.post_type = %s AND p.post_status = 'publish'
					 GROUP BY t.term_id, t.slug, t.name
					 HAVING post_count > 0",
					$tax_obj->name,
					$post_type
				),
				ARRAY_A
			);

			$assigned_term_count = is_array( $terms_with_counts ) ? count( $terms_with_counts ) : 0;

			$terms = array();

			foreach ( $terms_with_counts as $term_row ) {
				$terms[] = array(
					'slug'  => $term_row['slug'],
					'name'  => $term_row['name'],
					'count' => (int) $term_row['post_count'],
				);
			}

			$tax_data[] = array(
				'slug'                => $tax_obj->name,
				'label'               => $tax_obj->label,
				'hierarchical'        => (bool) $tax_obj->hierarchical,
				'public'              => (bool) $tax_obj->public,
				'total_term_count'    => $total_terms,
				'assigned_term_count' => $assigned_term_count,
				'terms'               => $terms,
			);
		}

		$data[ $post_type ] = array(
			'slug'          => $post_type,
			'label'         => $object->label,
			'public'        => (bool) $object->public,
			'has_archive'   => (bool) $object->has_archive,
			'hierarchical'  => (bool) $object->hierarchical,
			'supports'      => is_array( $object->supports ) ? array_values( $object->supports ) : array(),
			'publish_count' => $published_count,
			'taxonomies'    => $tax_data,
		);
	}

	return $data;
}
