<?php
/**
 * Compact side-by-side diffs for the revision list in the in-app editor.
 */

namespace Memex;

class RevisionDiff {
	/**
	 * Like wp_text_diff() but with a compact renderer, the diff title
	 * removed, and the revision ID in the header row.
	 *
	 * @param string $old         Text of the revision.
	 * @param string $new         Text of the current note.
	 * @param string $title_right Header for the current-note column.
	 * @param int    $revision_id Shown in the header row's left cell.
	 * @return string Table markup, or '' when nothing changed.
	 */
	public static function render( string $old, string $new, string $title_right, int $revision_id ): string {
		$renderer = new RevisionDiffRenderer();
		$diff     = $renderer->render(
			new \Text_Diff(
				explode( "\n", normalize_whitespace( $old ) ),
				explode( "\n", normalize_whitespace( $new ) )
			)
		);
		if ( ! $diff ) {
			return '';
		}

		return "<table class='diff is-split-view'>\n"
			. "<thead><tr class='diff-sub-title'>\n"
			. "\t<td class='memex-revision-diff-number'>" . $revision_id . "</td>\n"
			. "\t<th>" . esc_html( $title_right ) . "</th>\n"
			. "</tr></thead>\n"
			. "<tbody>\n" . $diff . "\n</tbody>\n"
			. '</table>';
	}
}
