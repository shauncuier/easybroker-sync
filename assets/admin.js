/* global jQuery, EBS_Admin */
( function ( $ ) {
	'use strict';

	// Render per-post error lines below the result element (capped at 10).
	function showErrorList( $anchor, errors ) {
		var $p = $anchor.closest( 'p' );
		$p.next( '.ebs-bulk-errors' ).remove();
		if ( ! errors || ! errors.length ) {
			return;
		}
		var $errs = $( '<ul class="ebs-bulk-errors"/>' ).insertAfter( $p );
		$.each( errors.slice( 0, 10 ), function ( i, msg ) {
			$( '<li/>' ).text( msg ).appendTo( $errs );
		} );
		if ( errors.length > 10 ) {
			$( '<li/>' ).text( '… +' + ( errors.length - 10 ) + ' more — see Sync Log.' ).appendTo( $errs );
		}
	}

	function post( action, $result, $btn ) {
		var original = $btn ? $btn.text() : '';
		if ( $btn ) {
			$btn.prop( 'disabled', true );
		}
		$result.removeClass( 'is-ok is-error' ).text( EBS_Admin.strings.working );
		showErrorList( $result, [] );

		$.post( EBS_Admin.ajaxUrl, {
			action: action,
			nonce: EBS_Admin.nonce
		} )
			.done( function ( res ) {
				if ( res && res.success ) {
					$result.addClass( 'is-ok' ).text( res.data.message || EBS_Admin.strings.done );
				} else {
					var msg = res && res.data && res.data.message ? res.data.message : EBS_Admin.strings.failed;
					$result.addClass( 'is-error' ).text( msg );
				}
				showErrorList( $result, res && res.data && res.data.errors );
			} )
			.fail( function () {
				$result.addClass( 'is-error' ).text( EBS_Admin.strings.failed );
			} )
			.always( function () {
				if ( $btn ) {
					$btn.prop( 'disabled', false ).text( original );
				}
			} );
	}

	$( function () {
		$( '#ebs-test-connection' ).on( 'click', function () {
			post( 'ebs_test_connection', $( '#ebs-test-result' ), $( this ) );
		} );

		$( '.ebs-action' ).on( 'click', function () {
			var action = $( this ).data( 'action' );
			post( action, $( '#ebs-action-result' ), $( this ) );
		} );

		// Houzez bulk sync: link pass, then batched pushes until none remain.
		$( '#ebs-houzez-sync' ).on( 'click', function () {
			var $btn = $( this );
			var $res = $( '#ebs-houzez-result' );
			var totals = { linked: 0, ok: 0, fail: 0 };
			var errList = [];
			var maxRounds = 60;

			function round( first ) {
				if ( maxRounds-- <= 0 ) {
					$res.addClass( 'is-error' ).text( 'Stopped: too many rounds. Check the Sync Log.' );
					$btn.prop( 'disabled', false );
					return;
				}
				$.post( EBS_Admin.ajaxUrl, {
					action: 'ebs_houzez_bulk',
					nonce: EBS_Admin.nonce,
					first: first ? 1 : 0
				} )
					.done( function ( r ) {
						if ( ! r || ! r.success ) {
							$res.addClass( 'is-error' ).text( ( r && r.data && r.data.message ) || EBS_Admin.strings.failed );
							$btn.prop( 'disabled', false );
							return;
						}
						totals.linked += r.data.linked;
						totals.ok += r.data.ok;
						totals.fail += r.data.fail;
						if ( r.data.errors && r.data.errors.length ) {
							errList = errList.concat( r.data.errors );
						}
						if ( r.data.remaining > 0 ) {
							$res.text( 'Linked ' + totals.linked + ', pushed ' + totals.ok + ', errors ' + totals.fail + ' — ' + r.data.remaining + ' remaining…' );
							round( false );
						} else {
							$res.removeClass( 'is-error' ).addClass( totals.fail ? 'is-error' : 'is-ok' )
								.text( 'Done. Linked ' + totals.linked + ', pushed ' + totals.ok + ', errors ' + totals.fail + ( totals.fail ? ':' : '.' ) );
							showErrorList( $res, errList );
							$btn.prop( 'disabled', false );
						}
					} )
					.fail( function () {
						$res.addClass( 'is-error' ).text( EBS_Admin.strings.failed );
						$btn.prop( 'disabled', false );
					} );
			}

			$btn.prop( 'disabled', true );
			$res.removeClass( 'is-ok is-error' ).text( EBS_Admin.strings.working );
			showErrorList( $res, [] );
			round( true );
		} );

		// Manual per-post push (editor).
		$( '#ebs-push-now' ).on( 'click', function () {
			var $btn = $( this );
			var $res = $( '#ebs-push-now-result' );
			var original = $btn.text();
			$btn.prop( 'disabled', true );
			$res.removeClass( 'is-ok is-error' ).text( EBS_Admin.strings.working );
			$.post( EBS_Admin.ajaxUrl, {
				action: 'ebs_push_one',
				nonce: EBS_Admin.nonce,
				post_id: $btn.data( 'post-id' )
			} )
				.done( function ( r ) {
					if ( r && r.success ) {
						$res.addClass( 'is-ok' ).text( r.data.message || EBS_Admin.strings.done );
					} else {
						$res.addClass( 'is-error' ).text( ( r && r.data && r.data.message ) || EBS_Admin.strings.failed );
					}
				} )
				.fail( function () {
					$res.addClass( 'is-error' ).text( EBS_Admin.strings.failed );
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( original );
				} );
		} );

		// Location picker (property editor).
		function runLocationSearch() {
			var q = $.trim( $( '#ebs-loc-query' ).val() || '' );
			var $status = $( '#ebs-loc-status' );
			var $results = $( '#ebs-loc-results' ).empty();
			if ( ! q ) {
				return;
			}
			$status.removeClass( 'is-ok is-error' ).text( EBS_Admin.strings.working );
			$.post( EBS_Admin.ajaxUrl, {
				action: 'ebs_location_search',
				nonce: EBS_Admin.nonce,
				query: q
			} )
				.done( function ( res ) {
					if ( res && res.success && res.data.locations && res.data.locations.length ) {
						$status.removeClass( 'is-error' ).addClass( 'is-ok' ).text( res.data.locations.length + ' found' );
						$.each( res.data.locations, function ( i, name ) {
							$( '<li/>' )
								.append( $( '<a href="#" class="ebs-loc-pick"/>' ).text( name ) )
								.appendTo( $results );
						} );
					} else {
						var msg = res && res.data && res.data.message ? res.data.message : 'No locations found.';
						$status.removeClass( 'is-ok' ).addClass( 'is-error' ).text( msg );
					}
				} )
				.fail( function () {
					$status.addClass( 'is-error' ).text( EBS_Admin.strings.failed );
				} );
		}

		// EasyBroker listing picker (manual match in the property editor).
		function runEbListingSearch() {
			var q = $.trim( $( '#ebs-eb-filter' ).val() || '' );
			var $status = $( '#ebs-eb-status' );
			var $results = $( '#ebs-eb-results' ).empty();
			$status.removeClass( 'is-ok is-error' ).text( EBS_Admin.strings.working );
			$.post( EBS_Admin.ajaxUrl, {
				action: 'ebs_eb_listings',
				nonce: EBS_Admin.nonce,
				query: q
			} )
				.done( function ( res ) {
					if ( res && res.success && res.data.listings && res.data.listings.length ) {
						$status.removeClass( 'is-error' ).addClass( 'is-ok' ).text( res.data.listings.length + ' found' );
						$.each( res.data.listings, function ( i, item ) {
							$( '<li/>' )
								.append(
									$( '<a href="#" class="ebs-eb-pick"/>' )
										.attr( 'data-id', item.id )
										.text( item.id + ' — ' + ( item.title || '(untitled)' ) )
								)
								.appendTo( $results );
						} );
					} else {
						var msg = res && res.data && res.data.message ? res.data.message : 'No listings found.';
						$status.removeClass( 'is-ok' ).addClass( 'is-error' ).text( msg );
					}
				} )
				.fail( function () {
					$status.addClass( 'is-error' ).text( EBS_Admin.strings.failed );
				} );
		}

		$( '#ebs-eb-load' ).on( 'click', runEbListingSearch );
		$( '#ebs-eb-filter' ).on( 'keydown', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault();
				runEbListingSearch();
			}
		} );
		$( document ).on( 'click', '.ebs-eb-pick', function ( e ) {
			e.preventDefault();
			$( '#ebs_eb_public_id' ).val( $( this ).attr( 'data-id' ) );
			$( '#ebs-eb-results' ).empty();
			$( '#ebs-eb-status' ).removeClass( 'is-error' ).addClass( 'is-ok' ).text( EBS_Admin.strings.done );
		} );

		$( '#ebs-loc-search' ).on( 'click', runLocationSearch );
		$( '#ebs-loc-query' ).on( 'keydown', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault();
				runLocationSearch();
			}
		} );
		$( document ).on( 'click', '.ebs-loc-pick', function ( e ) {
			e.preventDefault();
			$( '#ebs_location_name' ).val( $( this ).text() );
			$( '#ebs-loc-results' ).empty();
			$( '#ebs-loc-status' ).removeClass( 'is-error' ).addClass( 'is-ok' ).text( EBS_Admin.strings.done );
		} );
	} );
} )( jQuery );
