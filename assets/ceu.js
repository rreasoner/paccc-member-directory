/* PACCC Approved CEU Courses — client-side filtering (CEU amount + provider)
   with pagination that operates on the filtered set. */
( function () {
	'use strict';

	function init( root ) {
		var amountSel = root.querySelector( '.paccc-ceu-filter-amount' );
		var providerSel = root.querySelector( '.paccc-ceu-filter-provider' );
		var cards = Array.prototype.slice.call( root.querySelectorAll( '.paccc-ceu-card' ) );
		var status = root.querySelector( '.paccc-ceu-status' );
		var empty = root.querySelector( '.paccc-ceu-empty' );
		var pager = root.querySelector( '.paccc-ceu-pagination' );
		var total = cards.length;
		var perPage = parseInt( root.getAttribute( 'data-per-page' ), 10 );
		if ( isNaN( perPage ) || perPage < 0 ) {
			perPage = 10;
		}
		var page = 1;

		function matched() {
			var amount = amountSel ? amountSel.value : '';
			var provider = providerSel ? providerSel.value : '';
			return cards.filter( function ( card ) {
				var okAmount = ! amount || card.getAttribute( 'data-amount' ) === amount;
				var okProvider = ! provider || card.getAttribute( 'data-provider' ) === provider;
				return okAmount && okProvider;
			} );
		}

		function pageNumbers( current, last ) {
			// 1 … (current-1) current (current+1) … last
			var out = [];
			var add = function ( n ) {
				if ( out.indexOf( n ) === -1 ) {
					out.push( n );
				}
			};
			add( 1 );
			for ( var n = current - 1; n <= current + 1; n++ ) {
				if ( n >= 1 && n <= last ) {
					add( n );
				}
			}
			add( last );
			out.sort( function ( a, b ) {
				return a - b;
			} );
			// insert ellipsis markers
			var withGaps = [];
			for ( var i = 0; i < out.length; i++ ) {
				if ( i > 0 && out[ i ] - out[ i - 1 ] > 1 ) {
					withGaps.push( '…' );
				}
				withGaps.push( out[ i ] );
			}
			return withGaps;
		}

		function renderPager( totalPages ) {
			if ( ! pager ) {
				return;
			}
			if ( perPage === 0 || totalPages <= 1 ) {
				pager.hidden = true;
				pager.innerHTML = '';
				return;
			}
			pager.hidden = false;
			var html = '';
			html += '<button type="button" class="paccc-ceu-page-btn paccc-ceu-page-prev" data-page="' + ( page - 1 ) + '"' + ( page <= 1 ? ' disabled' : '' ) + '>&laquo; Prev</button>';
			pageNumbers( page, totalPages ).forEach( function ( n ) {
				if ( n === '…' ) {
					html += '<span class="paccc-ceu-page-gap">…</span>';
				} else {
					html += '<button type="button" class="paccc-ceu-page-btn paccc-ceu-page-num' + ( n === page ? ' is-current" aria-current="page"' : '"' ) + ' data-page="' + n + '">' + n + '</button>';
				}
			} );
			html += '<button type="button" class="paccc-ceu-page-btn paccc-ceu-page-next" data-page="' + ( page + 1 ) + '"' + ( page >= totalPages ? ' disabled' : '' ) + '>Next &raquo;</button>';
			pager.innerHTML = html;
		}

		function apply() {
			var list = matched();
			var count = list.length;
			var totalPages = perPage > 0 ? Math.max( 1, Math.ceil( count / perPage ) ) : 1;
			if ( page > totalPages ) {
				page = totalPages;
			}
			if ( page < 1 ) {
				page = 1;
			}

			var start = perPage > 0 ? ( page - 1 ) * perPage : 0;
			var end = perPage > 0 ? start + perPage : count;

			// Hide everything, then reveal the current page slice of the matched set.
			cards.forEach( function ( card ) {
				card.hidden = true;
			} );
			list.forEach( function ( card, i ) {
				card.hidden = ! ( i >= start && i < end );
			} );

			if ( empty ) {
				empty.hidden = count !== 0;
			}

			if ( status ) {
				if ( count === 0 ) {
					status.textContent = '';
				} else if ( perPage > 0 && count > perPage ) {
					status.textContent = 'Showing ' + ( start + 1 ) + '–' + Math.min( end, count ) + ' of ' + count + ' CEUs.';
				} else if ( count === total ) {
					status.textContent = 'Showing all ' + total + ' CEU' + ( total === 1 ? '' : 's' ) + '.';
				} else {
					status.textContent = 'Showing ' + count + ' CEU' + ( count === 1 ? '' : 's' ) + '.';
				}
			}

			renderPager( totalPages );
		}

		function goTo( n ) {
			page = n;
			apply();
			// Keep the top of the list in view when paging.
			var top = root.getBoundingClientRect().top + window.pageYOffset - 20;
			window.scrollTo( { top: top, behavior: 'smooth' } );
		}

		if ( amountSel ) {
			amountSel.addEventListener( 'change', function () {
				page = 1;
				apply();
			} );
		}
		if ( providerSel ) {
			providerSel.addEventListener( 'change', function () {
				page = 1;
				apply();
			} );
		}
		if ( pager ) {
			pager.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.paccc-ceu-page-btn' );
				if ( ! btn || btn.disabled ) {
					return;
				}
				var n = parseInt( btn.getAttribute( 'data-page' ), 10 );
				if ( ! isNaN( n ) ) {
					goTo( n );
				}
			} );
		}

		apply();
	}

	function ready() {
		var roots = document.querySelectorAll( '.paccc-ceu-directory' );
		Array.prototype.forEach.call( roots, init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )();
