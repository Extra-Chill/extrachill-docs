<?php
declare(strict_types=1);
/**
 * Abilities Registration
 *
 * Registers the extrachill-docs ability category and loads all ability files.
 * Each file registers its own abilities on the wp_abilities_api_init hook.
 *
 * @package ExtraChillDocs
 * @since 0.5.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_docs_register_ability_category' );

/**
 * Register the docs ability category.
 */
function extrachill_docs_register_ability_category(): void {
	wp_register_ability_category(
		'extrachill-docs',
		array(
			'label'       => __( 'Extra Chill Docs', 'extrachill-docs' ),
			'description' => __( 'Documentation site metadata and content sync abilities.', 'extrachill-docs' ),
		)
	);
}

// Load ability files — each self-registers on wp_abilities_api_init.
require_once __DIR__ . '/docs-info.php';
require_once __DIR__ . '/docs-sync.php';
