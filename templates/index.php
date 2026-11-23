<?php
/** The application supplies content; wp-app owns the document and bootstrap. */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
\WpApp\Pwa::render_shell( 'memex' );
