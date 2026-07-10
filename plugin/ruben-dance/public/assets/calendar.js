/**
 * `[rd_calendar]` shortcode: FullCalendar instance fed by `GET /rd/v1/lessons`
 * (spec F2). Month + week views, week view initially selected on a
 * phone-sized viewport, style/location filters re-fetch the same endpoint,
 * clicking an event opens the course detail page (`event.url`, FullCalendar's
 * own click-to-navigate behavior for events that carry a `url`).
 *
 * @package RubenDance
 */
/* global FullCalendar, rdCalendarL10n */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var container = document.getElementById( 'rd-calendar' );

		if ( ! container || 'undefined' === typeof FullCalendar || 'undefined' === typeof rdCalendarL10n ) {
			return;
		}

		var styleField = document.getElementById( 'rd-calendar-style' );
		var locationField = document.getElementById( 'rd-calendar-location' );

		var isMobile = window.innerWidth < ( rdCalendarL10n.mobileBreakpoint || 768 );

		var calendar = new FullCalendar.Calendar( container, {
			initialView: isMobile ? 'timeGridWeek' : 'dayGridMonth',
			locale: rdCalendarL10n.locale || 'en',
			headerToolbar: {
				left: 'prev,next today',
				center: 'title',
				right: 'dayGridMonth,timeGridWeek',
			},
			height: 'auto',
			events: fetchEvents,
			eventClick: function ( info ) {
				if ( info.event.url ) {
					info.jsEvent.preventDefault();
					window.location.href = info.event.url;
				}
			},
			eventDidMount: function ( info ) {
				if ( 'cancelled' === info.event.extendedProps.status ) {
					info.el.classList.add( 'rd-event-cancelled' );
					info.el.setAttribute( 'title', rdCalendarL10n.cancelledLabel || 'Cancelled' );
				}
			},
		} );

		calendar.render();

		if ( styleField ) {
			styleField.addEventListener( 'change', function () {
				calendar.refetchEvents();
			} );
		}

		if ( locationField ) {
			locationField.addEventListener( 'change', function () {
				calendar.refetchEvents();
			} );
		}

		/**
		 * FullCalendar `events` callback: fetch the REST feed for the
		 * currently visible date range and active filters, map each row to a
		 * FullCalendar event, and drop cancelled lessons entirely when the
		 * admin setting is "hidden" (kept, but visually struck through via
		 * `eventDidMount` above, when the setting is "strikethrough").
		 *
		 * @param {Object}   fetchInfo       FullCalendar range descriptor (start/end/startStr/endStr).
		 * @param {Function} successCallback Called with the mapped event array.
		 * @param {Function} failureCallback Called on a fetch error.
		 */
		function fetchEvents( fetchInfo, successCallback, failureCallback ) {
			var params = new URLSearchParams( {
				from: fetchInfo.startStr.slice( 0, 10 ),
				to: fetchInfo.endStr.slice( 0, 10 ),
				lang: rdCalendarL10n.lang || 'cs',
			} );

			if ( styleField && '0' !== styleField.value ) {
				params.set( 'style', styleField.value );
			}

			if ( locationField && '0' !== locationField.value ) {
				params.set( 'location', locationField.value );
			}

			fetch( rdCalendarL10n.restUrl + '?' + params.toString() )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'rd-calendar: request failed with status ' + response.status );
					}

					return response.json();
				} )
				.then( function ( rows ) {
					var hideCancelled = rdCalendarL10n.cancelledDisplay === rdCalendarL10n.cancelledHidden;

					var events = rows
						.filter( function ( row ) {
							return ! ( hideCancelled && 'cancelled' === row.status );
						} )
						.map( function ( row ) {
							return {
								id: String( row.id ),
								title: row.title,
								start: row.date + 'T' + row.start,
								end: row.date + 'T' + row.end,
								url: row.url,
								extendedProps: {
									status: row.status,
									style: row.style,
									location: row.location,
								},
							};
						} );

					successCallback( events );
				} )
				.catch( failureCallback );
		}
	} );
} )();
