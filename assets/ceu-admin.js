/* PACCC CEU — provider logo picker on the Providers taxonomy screens. */
( function ( $ ) {
	'use strict';

	var frame;

	function field( el ) {
		return $( el ).closest( '.paccc-ceu-logo-field' );
	}

	$( document ).on( 'click', '.paccc-ceu-logo-select', function ( e ) {
		e.preventDefault();
		var $field = field( this );

		frame = wp.media( {
			title: 'Select provider logo',
			button: { text: 'Use this logo' },
			library: { type: 'image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var url = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
			$field.find( '.paccc-ceu-logo-id' ).val( att.id );
			$field.find( '.paccc-ceu-logo-preview' ).html(
				'<img src="' + url + '" alt="" style="max-height:80px;width:auto;display:block;margin-bottom:6px;" />'
			);
			$field.find( '.paccc-ceu-logo-remove' ).show();
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.paccc-ceu-logo-remove', function ( e ) {
		e.preventDefault();
		var $field = field( this );
		$field.find( '.paccc-ceu-logo-id' ).val( '' );
		$field.find( '.paccc-ceu-logo-preview' ).empty();
		$( this ).hide();
	} );

	// The "Add Provider" form clears itself via AJAX after a term is added;
	// reset our custom preview along with it.
	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		if ( settings.data && settings.data.indexOf( 'action=add-tag' ) !== -1 ) {
			var $form = $( '#addtag' );
			$form.find( '.paccc-ceu-logo-id' ).val( '' );
			$form.find( '.paccc-ceu-logo-preview' ).empty();
			$form.find( '.paccc-ceu-logo-remove' ).hide();
		}
	} );
} )( jQuery );
