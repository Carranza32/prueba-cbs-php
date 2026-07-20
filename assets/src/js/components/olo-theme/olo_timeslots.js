
jQuery( function ( $ ) {
	const cfg   = window.oloTimeSlotsConfig || {};
	const REST  = ( cfg.restUrl || '/wp-json/northstaronlineordering/v1' ).replace( /\/$/, '' );
	const NONCE = cfg.nonce || '';


	let loadCtrl     = null;
	let reserveCtrl  = null;
	let reserveTimer = null;


	function $dateField()    { return $( 'input#olo_slot_date' ); }
	function $displayField() { return $( 'input#olo_slot_date_display' ); }
	function $slotField()    { return $( 'select#olo_time_slot' ); }

	function getSiteId() { return String( $dateField().data( 'site-id' ) || '' ); }
	function getAreaId() { return String( $dateField().data( 'area-id' ) || '' ); }

	// -------------------------------------------------------------------------
	// Notice area
	// -------------------------------------------------------------------------

	function showNotice( msg, type /* 'error'|'info' */ ) {
		var isError  = ( type === 'error' );
		var bgColor  = isError ? '#ffe0e0' : '#e0f0ff';
		var txtColor = isError ? '#c00'    : '#006';
		var border   = isError ? '#c00'    : '#006';

		let $n = $( '#olo-timeslot-notice' );
		if ( ! $n.length ) {
			$n = $( '<p id="olo-timeslot-notice" aria-live="polite" role="alert"></p>' )
				.insertAfter( $slotField().closest( '.form-row' ) );
		}
		$n.css( {
			display:       'block',
			margin:        '8px 0',
			padding:       '10px 14px',
			background:    bgColor,
			color:         txtColor,
			border:        '1px solid ' + border,
			borderRadius:  '3px',
			fontWeight:    'bold',
			fontSize:      '14px',
		} ).text( msg );
	}

	function clearNotice() {
		$( '#olo-timeslot-notice' ).css( 'display', 'none' ).text( '' );
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	function formatDate( yyyyMmDd ) {
		if ( ! yyyyMmDd ) { return ''; }
		const parts = yyyyMmDd.split( '-' ).map( Number );
		return new Date( parts[ 0 ], parts[ 1 ] - 1, parts[ 2 ] )
			.toLocaleDateString( undefined, { year: 'numeric', month: 'long', day: 'numeric' } );
	}

	/**
	 * Parse "{timeSlotId}|{slotTime}" — mirrors TimeSlotValueParser::parse().
	 *
	 * @param  {string} raw
	 * @return {{timeSlotId: string, slotTime: string}|null}
	 */
	function parseSlotValue( raw ) {
		if ( ! raw ) { return null; }
		const idx = raw.indexOf( '|' );
		if ( idx < 0 ) { return null; }
		const timeSlotId = raw.substring( 0, idx );
		const slotTime   = raw.substring( idx + 1 );
		if ( ! timeSlotId || ! slotTime ) { return null; }
		return { timeSlotId, slotTime };
	}

	function escAttr( str ) {
		return String( str )
			.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}



	/** Shared handler — re-queries value from the live element each time. */
	function handleSlotChange() {
		var raw     = $slotField().val();
		var dateVal = $dateField().val();
		if ( raw && dateVal ) {
			scheduleReserve( dateVal, raw );
		}
	}

	function bindSlotSelect() {
		// 1. Document-level delegation — survives DOM replacement and works
		//    when select2:select bubbles (Select2 / SelectWoo 4.x).
		$( document )
			.off( 'change.oloSlotDel select2:select.oloSlotDel', 'select#olo_time_slot' )
			.on(  'change.oloSlotDel select2:select.oloSlotDel', 'select#olo_time_slot', handleSlotChange );

		// 2. Direct binding on the element — catches native change events even
		//    when SelectWoo prevents bubbling.
		var $s = $slotField();
		if ( $s.length ) {
			$s.off( '.oloSlotDir' );
			$s.on( 'change.oloSlotDir select2:select.oloSlotDir', handleSlotChange );
		}
	}



	function restoreSessionSlot( loadedDate ) {
		if ( ! cfg.selectedSlot ) { return; }
		if ( cfg.selectedDate && cfg.selectedDate !== loadedDate ) { return; }

		var $s   = $slotField();
		var safe = cfg.selectedSlot.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' );

		if ( $s.find( 'option[value="' + safe + '"]' ).length ) {
			$s.val( cfg.selectedSlot );

			// Tell SelectWoo to update its display without triggering our handler.
			if ( $s.data( 'select2' ) ) {
				$s.trigger( 'change.select2' );
			}
		}

		// Clear so a manual date change never restores the stale slot.
		cfg.selectedSlot = '';
		cfg.selectedDate = '';
	}

	// -------------------------------------------------------------------------
	// Load available slots
	// -------------------------------------------------------------------------

	async function loadSlots( keepNotice ) {
		const dateVal = $dateField().val();
		if ( ! dateVal ) { return; }

		$displayField().val( formatDate( dateVal ) );
		if ( ! keepNotice ) { clearNotice(); }

		if ( loadCtrl ) { loadCtrl.abort(); }
		loadCtrl = new AbortController();

		$slotField().prop( 'disabled', true ).html( '<option value="">Loading\u2026</option>' );

		const params = new URLSearchParams( {
			siteId: getSiteId(),
			areaId: getAreaId(),
			date:   dateVal,
		} );

		try {
			const res  = await fetch( REST + '/timeslots?' + params.toString(), {
				signal:  loadCtrl.signal,
				headers: { Accept: 'application/json' },
			} );
			const data = await res.json();

			if ( ! res.ok || ! data.success ) {
				$slotField().html( '<option value="">No time slots available</option>' ).prop( 'disabled', false );
				bindSlotSelect();
				return;
			}

			const options = data.options || {};
			let html = '';
			Object.entries( options ).forEach( ( [ val, label ] ) => {
				html += '<option value="' + escAttr( val ) + '">' + escHtml( String( label ) ) + '</option>';
			} );

			$slotField()
				.html( html || '<option value="">No time slots available</option>' )
				.prop( 'disabled', false );

			restoreSessionSlot( dateVal );

			// Re-bind directly to the freshly-populated select element.
			bindSlotSelect();

		} catch ( err ) {
			if ( err.name === 'AbortError' ) { return; }
			$slotField().html( '<option value="">Error loading time slots</option>' ).prop( 'disabled', false );
			bindSlotSelect();
		}
	}

	// -------------------------------------------------------------------------
	// Reserve selected slot
	// -------------------------------------------------------------------------

	async function reserveSlot( dateVal, raw ) {
		const parsed = parseSlotValue( raw );
		if ( ! parsed ) { return; }

		if ( reserveCtrl ) { reserveCtrl.abort(); }
		reserveCtrl = new AbortController();

		clearNotice();
		$slotField().prop( 'disabled', true );

		try {
			const res = await fetch( REST + '/timeslots/reserve', {
				method:  'POST',
				signal:  reserveCtrl.signal,
				headers: {
					'Content-Type': 'application/json',
					'Accept':       'application/json',
					'X-WP-Nonce':   NONCE,
				},
				body: JSON.stringify( {
					date:       dateVal,
					timeSlotId: parsed.timeSlotId,
					slotTime:   parsed.slotTime,
					siteId:     getSiteId(),
				} ),
			} );

			const data = await res.json();

			if ( ! res.ok || ! data.success ) {
				console.error( '[olo_timeslots] reserve failed', res.status, data );
				showNotice(
					( data && data.message ) || 'This time slot is no longer available. Please choose another.',
					'error'
				);
				$slotField().val( '' );
				if ( $slotField().data( 'select2' ) ) { $slotField().trigger( 'change' ); }
				loadSlots( true );
			} else {
				clearNotice();
			}

		} catch ( err ) {
			if ( err.name === 'AbortError' ) { return; }
			showNotice( 'Unable to reserve time slot. Please try again.', 'error' );
			$slotField().val( '' );
			if ( $slotField().data( 'select2' ) ) { $slotField().trigger( 'change' ); }
			loadSlots( true );
		} finally {
			$slotField().prop( 'disabled', false );
		}
	}

	/**
	 * Debounce rapid changes (keyboard navigation) so only one reserve request
	 * fires 400 ms after the last change.
	 */
	function scheduleReserve( dateVal, raw ) {
		clearTimeout( reserveTimer );
		reserveTimer = setTimeout( function () {
			reserveSlot( dateVal, raw );
		}, 400 );
	}

	// -------------------------------------------------------------------------
	// Event binding
	// -------------------------------------------------------------------------

	function bindEvents() {
		// Date field: delegation is fine — it's a plain <input type="date">.
		$( document )
			.off( 'change.oloDate', 'input#olo_slot_date' )
			.on(  'change.oloDate', 'input#olo_slot_date', function () {
				// Changing date invalidates any prior reservation.
				clearTimeout( reserveTimer );
				if ( reserveCtrl ) { reserveCtrl.abort(); }
				$slotField().val( '' );
				loadSlots();
			} );
			console.log( 'bindEvents' );

		// Slot field: bind directly to the element (not delegated) to work
		// correctly with WooCommerce's Select2 integration.
		bindSlotSelect();
	}

	// After WooCommerce replaces checkout fragments, rebind everything.
	$( document.body ).on( 'updated_checkout', function () {
		bindEvents();
	} );

	// Initial setup.
	console.log( 'olo_timeslots.js' );
	bindEvents();
	if ( $dateField().length ) {
		loadSlots();
	}
} );
