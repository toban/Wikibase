/**
 * @license GPL-2.0-or-later
 * @author H. Snater < mediawiki@snater.com >
 */
( function ( $, wb, QUnit ) {
	'use strict';

	/**
	 * @param {Object} [options]
	 * @return {jQuery}
	 */
	function createSitelinkview( options ) {
		options = $.extend( {
			entityIdPlainFormatter: 'i am an EntityIdPlainFormatter',
			allowedSiteIds: [ 'aawiki', 'enwiki' ],
			getSiteLinkRemover: function () {
				return {
					destroy: function () {},
					disable: function () {},
					enable: function () {}
				};
			}
		}, options );

		return $( '<div/>' )
			.addClass( 'test_sitelinkview' )
			.appendTo( $( 'body' ) )
			.sitelinkview( options );
	}

	QUnit.module( 'jquery.wikibase.sitelinkview', QUnit.newWbEnvironment( {
		config: {
			wbSiteDetails: {
				aawiki: {
					apiUrl: 'http://aa.wikipedia.org/w/api.php',
					name: 'Qafár af',
					pageUrl: 'http://aa.wikipedia.org/wiki/$1',
					shortName: 'Qafár af',
					languageCode: 'aa',
					id: 'aawiki',
					group: 'wikipedia'
				},
				enwiki: {
					apiUrl: 'http://en.wikipedia.org/w/api.php',
					name: 'English Wikipedia',
					pageUrl: 'http://en.wikipedia.org/wiki/$1',
					shortName: 'English',
					languageCode: 'en',
					id: 'enwiki',
					group: 'wikipedia'
				},
				dewiki: {
					apiUrl: 'http://de.wikipedia.org/w/api.php',
					name: 'Deutsche Wikipedia',
					pageUrl: 'http://de.wikipedia.org/wiki/$1',
					shortName: 'Deutsch',
					languageCode: 'de',
					id: 'dewiki',
					group: 'wikipedia'
				}
			}
		},
		teardown: function () {
			$( '.test_sitelinkview' ).each( function () {
				var $sitelinkview = $( this ),
					sitelinkview = $sitelinkview.data( 'sitelinkview' );

				if ( sitelinkview ) {
					sitelinkview.destroy();
				}

				$sitelinkview.remove();
			} );
		}
	} ) );

	QUnit.test( 'Create and destroy', function ( assert ) {
		assert.expect( 2 );
		var $sitelinkview = createSitelinkview(),
			sitelinkview = $sitelinkview.data( 'sitelinkview' );

		assert.ok(
			sitelinkview instanceof $.wikibase.sitelinkview,
			'Created widget.'
		);

		sitelinkview.destroy();

		assert.ok(
			$sitelinkview.data( 'sitelinkview' ) === undefined,
			'Destroyed widget.'
		);
	} );

	QUnit.test( 'Create and destroy with initial value', function ( assert ) {
		assert.expect( 2 );
		var siteLink = new wikibase.datamodel.SiteLink( 'enwiki', 'Main Page' ),
			$sitelinkview = createSitelinkview( {
				value: siteLink
			} ),
			sitelinkview = $sitelinkview.data( 'sitelinkview' );

		assert.ok(
			sitelinkview instanceof $.wikibase.sitelinkview,
			'Created widget.'
		);

		sitelinkview.destroy();

		assert.ok(
			$sitelinkview.data( 'sitelinkview' ) === undefined,
			'Destroyed widget.'
		);
	} );

	QUnit.test( 'startEditing() & stopEditing()', function ( assert ) {
		assert.expect( 4 );
		var $sitelinkview = createSitelinkview(),
			sitelinkview = $sitelinkview.data( 'sitelinkview' );

		$sitelinkview
		.on( 'sitelinkviewafterstartediting', function ( event ) {
			assert.ok(
				true,
				'Started edit mode.'
			);
		} )
		.on( 'sitelinkviewafterstopediting', function ( event, dropValue ) {
			assert.ok(
				true,
				'Stopped edit mode.'
			);
		} );

		sitelinkview.startEditing();
		sitelinkview.startEditing(); // should not trigger event
		sitelinkview.stopEditing( true );
		sitelinkview.stopEditing( true ); // should not trigger event
		sitelinkview.stopEditing(); // should not trigger event

		sitelinkview.startEditing();

		var siteselector = $sitelinkview.find( ':wikibase-siteselector' ).data( 'siteselector' ),
			$pagesuggester = $sitelinkview.find( ':wikibase-pagesuggester' );

		siteselector.setSelectedSite( wb.sites.getSite( 'aawiki' ) );

		sitelinkview.stopEditing(); // should not trigger event

		$pagesuggester.val( 'test' );

		sitelinkview.stopEditing();
	} );

	QUnit.test( 'startEditing(), stopEditing() with initial value', function ( assert ) {
		assert.expect( 5 );
		var siteLink = new wikibase.datamodel.SiteLink( 'enwiki', 'Main Page' ),
			$sitelinkview = createSitelinkview( {
				value: siteLink
			} ),
			sitelinkview = $sitelinkview.data( 'sitelinkview' );

		$sitelinkview
		.on( 'sitelinkviewafterstartediting', function ( event ) {
			assert.ok(
				true,
				'Started edit mode.'
			);
		} )
		.on( 'sitelinkviewafterstopediting', function ( event, dropValue ) {
			assert.ok(
				true,
				'Stopped edit mode.'
			);
		} );

		sitelinkview.startEditing();

		assert.ok(
			$sitelinkview.find( ':wikibase-siteselector' ).length === 0,
			'Did not create a site selector widget.'
		);

		sitelinkview.stopEditing( true );

		sitelinkview.startEditing();

		var $pagesuggester = $sitelinkview.find( ':wikibase-pagesuggester' );

		sitelinkview.stopEditing(); // should not trigger event (value unchanged)

		$pagesuggester.val( 'test' );

		sitelinkview.stopEditing();
	} );

	QUnit.test( 'value()', function ( assert ) {
		assert.expect( 2 );
		var $sitelinkview = createSitelinkview(),
			sitelinkview = $sitelinkview.data( 'sitelinkview' );

		assert.strictEqual(
			sitelinkview.value(),
			null,
			'Returning null when no value is set.'
		);

		var siteLink = new wikibase.datamodel.SiteLink( 'enwiki', 'Main Page' );

		$sitelinkview = createSitelinkview( {
			value: siteLink
		} );
		sitelinkview = $sitelinkview.data( 'sitelinkview' );

		assert.strictEqual(
			sitelinkview.value(),
			siteLink,
			'Returning SiteLink object when a valid value is set.'
		);
	} );

	QUnit.test( 'isEmpty()', function ( assert ) {
		assert.expect( 6 );
		var siteLink = new wikibase.datamodel.SiteLink( 'enwiki', 'Main Page' ),
			$sitelinkview = createSitelinkview(),
			sitelinkview = $sitelinkview.data( 'sitelinkview' );

		assert.ok(
			sitelinkview.isEmpty(),
			'isEmpty() returns TRUE when no site link is set and the widget is not in edit mode.'
		);

		sitelinkview.startEditing();

		assert.ok(
			sitelinkview.isEmpty(),
			'Verified isEmpty() returning TRUE when no site link is set, the widget is in edit '
			+ 'and input elements are empty.'
		);

		$sitelinkview.find( ':wikibase-siteselector' ).val( 'site' );

		assert.ok(
			!sitelinkview.isEmpty(),
			'Widget is not empty when the site selector is filled with input.'
		);

		$sitelinkview.find( ':wikibase-siteselector' ).val( '' );
		$sitelinkview.find( ':wikibase-pagesuggester' ).val( 'page' );

		assert.ok(
			!sitelinkview.isEmpty(),
			'Widget is not empty when the page suggester is filled with input.'
		);

		$sitelinkview = createSitelinkview( {
			value: siteLink
		} );
		sitelinkview = $sitelinkview.data( 'sitelinkview' );

		assert.ok(
			!sitelinkview.isEmpty(),
			'isEmpty() returns FALSE when a site link is set initially.'
		);

		sitelinkview.startEditing();
		$sitelinkview.find( ':wikibase-pagesuggester' ).val( '' );

		assert.ok(
			!sitelinkview.isEmpty(),
			'isEmpty() returns FALSE when a site link is set initially although the page suggester '
			+ ' input is cleared in edit mode.'
		);
	} );

	QUnit.test( 'setError()', function ( assert ) {
		var $sitelinkview = createSitelinkview(),
			sitelinkview = $sitelinkview.data( 'sitelinkview' );

		$sitelinkview
		.addClass( 'wb-error' )
		.on( 'sitelinkviewtoggleerror', function ( event, error ) {
			assert.ok(
				true,
				'Triggered toggleerror event.'
			);
		} );

		sitelinkview.setError();
	} );

}( jQuery, wikibase, QUnit ) );
