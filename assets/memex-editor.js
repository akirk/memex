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
} )( window.wp );
