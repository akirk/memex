<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
?>
			<footer class="memex-footer">
				<a href="<?php echo esc_url( home_url( '/memex/' ) ); ?>"><?php esc_html_e( 'Notes, offline work & sync', 'memex' ); ?></a>
			</footer>
		</main>
	</div>
	<?php wp_app_body_close(); ?>
</body>
</html>
