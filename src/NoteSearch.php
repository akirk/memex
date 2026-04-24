<?php
/**
 * REST search handler that makes memex_note findable from Gutenberg's link UI.
 *
 * Gutenberg's link picker calls `/wp/v2/search?type=post&subtype=any`, which
 * is served by `WP_REST_Post_Search_Handler`. That handler's constructor
 * populates `$this->subtypes` from `get_post_types(['public' => true, 'show_in_rest' => true])`,
 * so a `public => false` CPT is invisible to the link picker.
 *
 * We subclass the default handler, call parent so all "normal" public post
 * types remain searchable, then splice `memex_note` in.
 */

namespace Memex;

class NoteSearch extends \WP_REST_Post_Search_Handler {
	public function __construct() {
		parent::__construct();
		if ( ! in_array( CPT::POST_TYPE, $this->subtypes, true ) ) {
			$this->subtypes[] = CPT::POST_TYPE;
		}
	}
}
