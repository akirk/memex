<?php
/**
 * Edit redirect: /memex/edit/{slug} → wp-admin Gutenberg editor for the note.
 */

use Memex\CPT;
use Memex\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$slug = wp_app_get_route_var( 'slug' );
$post = null;
if ( $slug ) {
	$q    = get_posts(
		array(
			'post_type'      => CPT::POST_TYPE,
			'name'           => $slug,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 1,
		)
	);
	$post = $q ? $q[0] : null;
	if ( ! $post ) {
		$id = Links::resolve( rawurldecode( $slug ) );
		if ( $id ) {
			$post = get_post( $id );
		}
	}
}

if ( ! $post ) {
	wp_safe_redirect( home_url( '/memex/' ) );
	exit;
}
wp_safe_redirect( admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) );
exit;
