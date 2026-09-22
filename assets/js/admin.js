/* global wcruAdmin */
( function() {
	'use strict';
	var input = document.getElementById( 'wcru-user-ids' );
	var addButton = document.getElementById( 'wcru-add-users' );
	var feedback = document.getElementById( 'wcru-entry-feedback' );
	var list = document.getElementById( 'wcru-restricted-list' );

	function request( action, data ) {
		var params = new URLSearchParams( data || {} );
		params.set( 'action', action );
		params.set( 'nonce', wcruAdmin.nonce );
		return fetch( wcruAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: params.toString()
		} ).then( function( response ) {
			if ( ! response.ok ) { throw new Error( 'Request failed' ); }
			return response.json();
		} );
	}
	function cell( value ) { var td = document.createElement( 'td' ); td.textContent = value || ''; return td; }
	function removeIcon() {
		var namespace = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( namespace, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' ); svg.setAttribute( 'width', '20' ); svg.setAttribute( 'height', '20' );
		svg.setAttribute( 'fill', 'none' ); svg.setAttribute( 'stroke', 'red' ); svg.setAttribute( 'stroke-width', '3' ); svg.setAttribute( 'stroke-linecap', 'round' ); svg.setAttribute( 'aria-hidden', 'true' );
		[ [ '18', '6', '6', '18' ], [ '6', '6', '18', '18' ] ].forEach( function( coordinates ) { var line = document.createElementNS( namespace, 'line' ); line.setAttribute( 'x1', coordinates[ 0 ] ); line.setAttribute( 'y1', coordinates[ 1 ] ); line.setAttribute( 'x2', coordinates[ 2 ] ); line.setAttribute( 'y2', coordinates[ 3 ] ); svg.appendChild( line ); } );
		return svg;
	}
	function addRow( user ) {
		if ( list.querySelector( '[data-user-id="' + user.id + '"]' ) ) { return; }
		var tr = document.createElement( 'tr' ); tr.dataset.userId = user.id;
		tr.append( cell( user.id ), cell( user.username ), cell( user.email ) );
		var actions = document.createElement( 'td' ); var remove = document.createElement( 'button' );
		remove.type = 'button'; remove.className = 'wcru-remove button-link-delete'; remove.setAttribute( 'aria-label', 'Remove customer restriction' ); remove.appendChild( removeIcon() ); actions.appendChild( remove ); tr.appendChild( actions ); list.appendChild( tr );
	}
	function showFeedback( message, isError ) {
		feedback.textContent = message || '';
		feedback.classList.toggle( 'wcru-error', !! isError );
	}
	addButton.addEventListener( 'click', function() {
		var ids = input.value.trim();
		if ( ! ids ) {
			showFeedback( 'Enter at least one user ID.', true );
			return;
		}
		addButton.disabled = true;
		showFeedback( 'Adding users...' );
		request( 'wcru_add_users', { user_ids: ids } ).then( function( response ) {
			if ( ! response.success ) {
				throw new Error( response.data && response.data.message ? response.data.message : 'Unable to add users.' );
			}
			response.data.users.forEach( addRow );
			input.value = '';
			var invalid = response.data.invalid || [];
			showFeedback( invalid.length ? 'These user IDs were not found: ' + invalid.join( ', ' ) : 'Users added.' , !! invalid.length );
		} ).catch( function( error ) {
			showFeedback( error.message, true );
		} ).then( function() {
			addButton.disabled = false;
		} );
	} );
	list.addEventListener( 'click', function( event ) { var button = event.target.closest( '.wcru-remove' ); if ( ! button ) { return; } var row = button.closest( 'tr' ); request( 'wcru_remove_customer', { user_id: row.dataset.userId } ).then( function( response ) { if ( response.success ) { row.remove(); } } ); } );
}() );
