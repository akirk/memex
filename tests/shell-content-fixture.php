<?php
// Render the real static content template for browser integration tests.
define( 'ABSPATH', '/tmp/' );
function home_url( $path ) {
	return 'http://placeholder' . $path; }
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) {
	return esc_html( $text ); }
function esc_url( $text ) {
	return esc_attr( $text ); }
function esc_html_e( $text, $domain ) {
	echo esc_html( $text ); }
function esc_attr_e( $text, $domain ) {
	echo esc_attr( $text ); }
require dirname( __DIR__ ) . '/templates/_shell-content.php';
