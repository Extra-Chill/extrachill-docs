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

add_action( 'init', 'extrachill_docs_register_last_reviewed_meta' );

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
