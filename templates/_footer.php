<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
?>
			<footer class="memex-footer">
				<?php include __DIR__ . '/_offline-panel.php'; ?>
			</footer>
		</main>
	</div>
	<?php wp_app_body_close(); ?>
</body>
</html>
