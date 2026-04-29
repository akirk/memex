/**
 * Memex — block-editor enhancements for the memex_note edit screen.
 *
 * Gutenberg's link picker is hardcoded around the `page` post type:
 *   - Initial suggestions filter to subtype=page (format-library).
 *   - "Create new" calls __experimentalCreatePageEntity, which is hardcoded
 *     to saveEntityRecord("postType", "page", ...) (editor package).
 *   - The button label "Create page: …" comes from a hardcoded __() call.
 *
 * On a memex_note we want all three to point at memex_note instead, so
 * authors can search/create sibling notes from the standard link UI.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.data || ! wp.domReady ) {
		return;
	}

	wp.domReady( function () {
		var data        = wp.data;
		var apiFetch    = wp.apiFetch;
		var addQueryArgs = wp.url && wp.url.addQueryArgs;
		var i18n        = wp.i18n;

		var BLOCK_EDITOR = 'core/block-editor';
		var CORE        = 'core';

		// Re-skin the "Create page: …" label to "Create note: …". The string is
		// emitted via __() in @wordpress/format-library with no text domain, so
		// merging into the default locale data swaps it everywhere in this
		// editor session.
		if ( i18n && i18n.setLocaleData ) {
			var overrides = {};
			overrides[ 'Create page: <mark>%s</mark>' ] = [ 'Create note: <mark>%s</mark>' ];
			overrides[ 'Create Page' ]                   = [ 'Create Note' ];
			i18n.setLocaleData( overrides, 'default' );
		}

		// Make saveEntityRecord('postType', 'memex_note') the create target for
		// the link picker. Returning the saved record lets format-library read
		// .id / .title.rendered / .link off it as it would for a page.
		var createMemexNote = function ( options ) {
			return data.dispatch( CORE ).saveEntityRecord(
				'postType',
				'memex_note',
				Object.assign( { status: 'draft' }, options || {} )
			);
		};

		// Wrap the default link-suggestion fetcher so memex_note results show
		// up alongside (and ahead of) the built-in subtype=page initial list.
		// Gutenberg passes searchOptions.subtype='page' for initial suggestions;
		// we ignore that for our extra query so notes always surface.
		var wrapFetchLinkSuggestions = function ( original ) {
			return function ( search, searchOptions, editorSettings ) {
				searchOptions = searchOptions || {};
				var perPage   = searchOptions.perPage || ( searchOptions.isInitialSuggestions ? 10 : 20 );

				var standard = original
					? original( search, searchOptions, editorSettings )
					: Promise.resolve( [] );

				var notes = apiFetch( {
					path: addQueryArgs( '/wp/v2/search', {
						search:   search,
						per_page: perPage,
						type:     'post',
						subtype:  'memex_note',
					} ),
				} ).then( function ( results ) {
					return results.map( function ( r ) {
						return {
							id:    r.id,
							url:   r.url,
							title: r.title || i18n.__( '(no title)', 'memex' ),
							type:  r.subtype || r.type,
							kind:  'post-type',
						};
					} );
				} ).catch( function () {
					return [];
				} );

				return Promise.all( [ notes, standard ] ).then( function ( parts ) {
					var seen = {};
					var out  = [];
					parts[ 0 ].concat( parts[ 1 ] ).forEach( function ( r ) {
						var key = r.type + ':' + r.id;
						if ( seen[ key ] ) return;
						seen[ key ] = true;
						out.push( r );
					} );
					return out.slice( 0, perPage );
				} );
			};
		};

		var applyOverrides = function () {
			var settings = data.select( BLOCK_EDITOR ).getSettings();
			if ( settings.__memexLinkOverridesApplied ) {
				return;
			}
			data.dispatch( BLOCK_EDITOR ).updateSettings( {
				__experimentalCreatePageEntity:    createMemexNote,
				__experimentalUserCanCreatePages:  true,
				__experimentalFetchLinkSuggestions: wrapFetchLinkSuggestions(
					settings.__experimentalFetchLinkSuggestions
				),
				__memexLinkOverridesApplied: true,
			} );
		};

		applyOverrides();

		// The editor sometimes resets settings after our initial run (e.g. when
		// the post entity finishes loading). Re-apply once if that happens.
		var unsubscribe = data.subscribe( function () {
			var settings = data.select( BLOCK_EDITOR ).getSettings();
			if ( ! settings.__memexLinkOverridesApplied ) {
				applyOverrides();
			}
		} );

		// Stop listening after a tick: the editor is initialised by then and
		// further updateSettings calls would loop the subscription.
		setTimeout( function () {
			if ( typeof unsubscribe === 'function' ) {
				unsubscribe();
			}
		}, 5000 );
	} );

	/* ─────────────────────────────────────────────────────────────────────
	 * Reminder block (memex/reminder)
	 *
	 * Static block — its rendered HTML lives in post_content, the canonical
	 * data lives in the block's JSON attributes. PHP-side reconcile() walks
	 * parsed blocks on save_post and upserts a `memex_reminder` CPT row per
	 * block, keyed by `blockId`.
	 * ───────────────────────────────────────────────────────────────────── */

	if ( wp.blocks && wp.element && wp.blockEditor && wp.components ) {
		var registerBlockType = wp.blocks.registerBlockType;
		var el                = wp.element.createElement;
		var Fragment          = wp.element.Fragment;
		var useBlockProps     = wp.blockEditor.useBlockProps;
		var TextControl       = wp.components.TextControl;
		var DateTimePicker    = wp.components.DateTimePicker;
		var __                = wp.i18n.__;

		var randomId = function () {
			return 'r' + Math.random().toString( 36 ).slice( 2, 10 );
		};

		// Display "YYYY-MM-DD HH:MM" from a DateTimePicker ISO-ish string
		// (which is local time, no offset, e.g. "2026-05-01T14:30:00").
		var formatDue = function ( iso ) {
			if ( ! iso ) {
				return '';
			}
			return iso.slice( 0, 16 ).replace( 'T', ' ' );
		};

		registerBlockType( 'memex/reminder', {
			apiVersion: 2,
			title:       __( 'Reminder', 'memex' ),
			description: __( 'A nudge by email when due.', 'memex' ),
			icon:        'bell',
			category:    'text',
			keywords:    [ 'reminder', 'remind', 'todo', 'alert' ],
			supports:    { html: false, multiple: true },
			attributes:  {
				blockId: { type: 'string', default: '' },
				due:     { type: 'string', default: '' },
				label:   { type: 'string', default: '' },
			},

			edit: function ( props ) {
				var attributes    = props.attributes;
				var setAttributes = props.setAttributes;

				if ( ! attributes.blockId ) {
					setAttributes( { blockId: randomId() } );
				}

				var blockProps = useBlockProps( {
					className: 'memex-reminder-inline is-editing',
				} );

				return el(
					'div',
					blockProps,
					el( TextControl, {
						label:       __( 'Remind me to…', 'memex' ),
						value:       attributes.label,
						placeholder: __( 'Buy milk', 'memex' ),
						onChange:    function ( value ) {
							setAttributes( { label: value } );
						},
					} ),
					el( DateTimePicker, {
						currentDate: attributes.due || null,
						is12Hour:    false,
						onChange:    function ( value ) {
							setAttributes( { due: value || '' } );
						},
					} )
				);
			},

			save: function ( props ) {
				var attributes = props.attributes;
				var blockProps = useBlockProps.save( {
					className: 'memex-reminder-inline',
				} );
				var due   = formatDue( attributes.due );
				var label = attributes.label || '';
				return el(
					'p',
					blockProps,
					el( 'span', { className: 'memex-reminder-inline-icon' }, '⏰' ),
					' ',
					due
						? el( 'span', { className: 'memex-reminder-inline-due' }, due )
						: null,
					due && label ? ' — ' : null,
					label
						? el( 'span', { className: 'memex-reminder-inline-label' }, label )
						: null
				);
			},
		} );
	}
} )( window.wp );
