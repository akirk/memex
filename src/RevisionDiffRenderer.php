<?php
/**
 * Table diff renderer for the revision list in the in-app editor.
 *
 * WordPress' renderer keeps 10,000 lines of context around every change,
 * which means a revision diff is the whole note. We keep one line before
 * and after each change and mark where lines were skipped between hunks.
 */

namespace Memex;

if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
	require_once ABSPATH . WPINC . '/wp-diff.php';
}

class RevisionDiffRenderer extends \WP_Text_Diff_Renderer_Table {
	public $_leading_context_lines  = 1;
	public $_trailing_context_lines = 1;

	private bool $first_block = true;

	/**
	 * Text_Diff_Renderer only starts a new block when more unchanged lines
	 * than fit in the context were skipped, so every block after the first
	 * follows a gap. Mark it with a row so the hunks don't read as adjacent.
	 *
	 * @param string $header Unused block header from Text_Diff_Renderer.
	 * @return string
	 */
	public function _startBlock( $header ) {
		if ( $this->first_block ) {
			$this->first_block = false;
			return '';
		}
		$colspan = $this->_show_split_view ? 2 : 1;
		return "<tr class='diff-skipped'><td colspan='" . $colspan . "' aria-label='" . esc_attr__( 'Unchanged lines skipped', 'memex' ) . "'>&#8943;</td></tr>\n";
	}

	/*
	 * Plain cells: the parent adds dashicons and screen-reader prefixes that
	 * don't fit the editor's sidebar. The colour of the cell says it all.
	 */

	/**
	 * @param string $line HTML-escaped line.
	 * @return string
	 */
	public function addedLine( $line ) {
		return "<td class='diff-addedline'>{$line}</td>";
	}

	/**
	 * @param string $line HTML-escaped line.
	 * @return string
	 */
	public function deletedLine( $line ) {
		return "<td class='diff-deletedline'>{$line}</td>";
	}

	/**
	 * @param string $line HTML-escaped line.
	 * @return string
	 */
	public function contextLine( $line ) {
		return "<td class='diff-context'>{$line}</td>";
	}
}
