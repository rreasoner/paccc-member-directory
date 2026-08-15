/* PACCC Approved CEU Courses — client-side filtering by CEU amount + provider. */
( function () {
	'use strict';

	function init( root ) {
		var amountSel = root.querySelector( '.paccc-ceu-filter-amount' );
		var providerSel = root.querySelector( '.paccc-ceu-filter-provider' );
		var cards = Array.prototype.slice.call( root.querySelectorAll( '.paccc-ceu-card' ) );
		var status = root.querySelector( '.paccc-ceu-status' );
		var empty = root.querySelector( '.paccc-ceu-empty' );
		var total = cards.length;

		function apply() {
			var amount = amountSel ? amountSel.value : '';
			var provider = providerSel ? providerSel.value : '';
			var shown = 0;

			cards.forEach( function ( card ) {
				var okAmount = ! amount || card.getAttribute( 'data-amount' ) === amount;
				var okProvider = ! provider || card.getAttribute( 'data-provider' ) === provider;
				var visible = okAmount && okProvider;
				card.hidden = ! visible;
				if ( visible ) {
					shown++;
				}
			} );

			if ( empty ) {
				empty.hidden = shown !== 0;
			}

			if ( status ) {
				if ( shown === total ) {
					status.textContent = 'Showing all ' + total + ' CEU' + ( total === 1 ? '' : 's' ) + '.';
				} else {
					status.textContent = 'Showing ' + shown + ' of ' + total + ' CEUs.';
				}
			}
		}

		if ( amountSel ) {
			amountSel.addEventListener( 'change', apply );
		}
		if ( providerSel ) {
			providerSel.addEventListener( 'change', apply );
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
