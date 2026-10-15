<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local state.
use Memex\DailyNote;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$memex_title          = DailyNote::today();
$memex_inline_capture = current_user_can( 'edit_posts' );
include __DIR__ . '/_header.php';
?>
<article id="selected-note" class="memex-note memex-note-with-calendar" aria-labelledby="selected-note-heading" data-ai-assistant-important>
	<?php
	$memex_calendar_date = DailyNote::today();
	include __DIR__ . '/_daily-calendar.php';
	?>
	<header class="memex-note-header">
		<h1 id="selected-note-heading">
			<?php echo esc_html( $memex_title ); ?>
			<a class="memex-badge memex-badge-daily" href="<?php echo esc_url( home_url( '/memex/tag/daily' ) ); ?>"><?php esc_html_e( 'daily', 'memex' ); ?></a>
		</h1>
	</header>
	<section id="selected-note-content" class="memex-note-body" aria-label="<?php esc_attr_e( 'Selected note content', 'memex' ); ?>" data-ai-assistant-important>
		<?php if ( $memex_inline_capture ) : ?>
			<?php include __DIR__ . '/_quick-capture-form.php'; ?>
		<?php endif; ?>
	</section>
</article>
<?php include __DIR__ . '/_footer.php'; ?>
