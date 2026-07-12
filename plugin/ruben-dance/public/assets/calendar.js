/**
 * `[rd_calendar]` shortcode: FullCalendar instance fed by `GET /rd/v1/lessons`
 * (spec F2). Design (design/screens.html #3d/#4d): a day-list agenda
 * (`listWeek`) is the "Týden" view on a phone-sized viewport, a 7-column
 * event grid (`dayGridWeek`) is "Týden" everywhere wider, and "Měsíc" is
 * always FullCalendar's own `dayGridMonth` — the plugin's own Week/Month
 * pill toolbar (public/templates/calendar.php's `.rd-cal__view-toggle`)
 * drives `calendar.changeView()` instead of FullCalendar's built-in
 * `headerToolbar` buttons, which is disabled (`headerToolbar: false`) so
 * front-calendar.css can theme one set of chrome instead of two. Style/
 * location filters re-fetch the same endpoint; clicking an event opens the
 * course detail page (`event.url`, FullCalendar's own click-to-navigate
 * behavior for events that carry a `url`) in every view, since a custom
 * `eventContent` only replaces an event's *inner* markup — the click
 * handler stays bound to FullCalendar's own event wrapper element.
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
		var rangeLabel = document.getElementById( 'rd-cal-range-label' );
		var viewButtons = document.querySelectorAll( '[data-rd-cal-view]' );
		var navButtons = document.querySelectorAll( '[data-rd-cal-nav]' );

		var isMobile = window.innerWidth < ( rdCalendarL10n.mobileBreakpoint || 768 );

		var calendar = new FullCalendar.Calendar( container, {
			initialView: isMobile ? viewNameFor( 'week' ) : viewNameFor( 'month' ),
			locale: rdCalendarL10n.locale || 'en',
			headerToolbar: false,
			height: 'auto',
			// Design's chips always show a full "18:00", never FullCalendar's
			// default omitted-zero-minute "18".
			eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
			events: fetchEvents,
			// Desktop week grid (#4d): "PO 14" combined into the column
			// header, so the per-cell date-number badge daygrid normally
			// draws is redundant there — hidden by
			// `.rd-cal-view--dayGridWeek .fc-daygrid-day-top` in
			// front-calendar.css, scoped by the view-name class `datesSet`
			// keeps on the container. Month view keeps FullCalendar's own
			// header (only the week views are in the design), so this
			// callback falls back to the default text there.
			dayHeaderContent: dayHeaderContent,
			// Mobile day-list (#3d): each day's separator row reads
			// "weekday-abbr + day-number" (e.g. "po 10"), the day grouping
			// the design shows down the left of the phone week list. Both go
			// in the main list-day text (`listDayFormat`) rather than split
			// across FullCalendar's main/side pair — this bundle omits the
			// side-text span when the day row carries no navLink, so the side
			// format alone never renders. The redundant far-right side text
			// (the full date) is suppressed via `listDaySideFormat: false`.
			listDayFormat: { weekday: 'short', day: 'numeric' },
			listDaySideFormat: false,
			eventClick: function ( info ) {
				if ( info.event.url ) {
					info.jsEvent.preventDefault();
					window.location.href = info.event.url;
				}
			},
			eventClassNames: function ( arg ) {
				return eventClassNames( arg.event );
			},
			eventContent: renderEventContent,
			eventDidMount: function ( info ) {
				if ( 'cancelled' === info.event.extendedProps.status ) {
					info.el.setAttribute( 'title', rdCalendarL10n.cancelledLabel || 'Cancelled' );
				}
			},
			datesSet: function ( info ) {
				if ( rangeLabel ) {
					rangeLabel.textContent = info.view.title;
				}

				updateViewToggle( info.view.type );

				container.classList.remove( 'rd-cal-view--listWeek', 'rd-cal-view--dayGridWeek', 'rd-cal-view--dayGridMonth' );
				container.classList.add( 'rd-cal-view--' + info.view.type );
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

		viewButtons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				calendar.changeView( viewNameFor( button.getAttribute( 'data-rd-cal-view' ) ) );
			} );
		} );

		navButtons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( 'prev' === button.getAttribute( 'data-rd-cal-nav' ) ) {
					calendar.prev();
				} else {
					calendar.next();
				}
			} );
		} );

		/**
		 * Map the toolbar's Week/Month toggle to a concrete FullCalendar view
		 * name for the current viewport.
		 *
		 * @param {string} mode 'week' or 'month'.
		 * @return {string}
		 */
		function viewNameFor( mode ) {
			if ( 'month' === mode ) {
				return 'dayGridMonth';
			}

			return isMobile ? 'listWeek' : 'dayGridWeek';
		}

		/**
		 * Reflect FullCalendar's actual current view back onto the Week/Month
		 * pill toggle — it can change for reasons other than a toggle click
		 * (e.g. `calendar.prev()/.next()` never change the view, but a future
		 * viewport-resize handler might).
		 *
		 * @param {string} viewType FullCalendar's `view.type` (e.g. 'listWeek', 'dayGridWeek', 'dayGridMonth').
		 */
		function updateViewToggle( viewType ) {
			var mode = 'dayGridMonth' === viewType ? 'month' : 'week';

			viewButtons.forEach( function ( button ) {
				var isActive = button.getAttribute( 'data-rd-cal-view' ) === mode;
				button.classList.toggle( 'is-active', isActive );
				button.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );
			} );
		}

		/**
		 * FullCalendar `events` callback: fetch the REST feed for the
		 * currently visible date range and active filters, map each row to a
		 * FullCalendar event, and drop cancelled lessons entirely when the
		 * admin setting is "hidden" (kept, but visually struck through via
		 * `rd-chip--cancelled`, when the setting is "strikethrough").
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
									type: row.type,
								},
							};
						} );

					successCallback( events );
				} )
				.catch( failureCallback );
		}
	} );

	/**
	 * Event chip classes (design/screens.html #3d/#4d + design/README.md's
	 * calendar-chip token pair): `.rd-chip` (public/assets/rd-design.css)
	 * plus one variant. Cancelled always wins over workshop/style (a
	 * cancelled workshop still reads as cancelled first); workshop's dashed
	 * chip wins over the coral/yellow style variant otherwise.
	 *
	 * @param {Object} event FullCalendar event object.
	 * @return {string[]}
	 */
	function eventClassNames( event ) {
		var props = event.extendedProps;

		if ( 'cancelled' === props.status ) {
			return [ 'rd-chip', 'rd-chip--cancelled' ];
		}

		if ( 'workshop' === props.type ) {
			return [ 'rd-chip', 'rd-chip--workshop' ];
		}

		return [ 'rd-chip', styleVariant( props.style ) ];
	}

	/**
	 * Map a dance-style slug to one of the two calendar chip background
	 * variants. design/README.md defines exactly two chip tokens —
	 * `--rd-chip-salsa` (coral) and `--rd-chip-alt` (yellow) — and the
	 * design mockups (#3d/#4d) paint the salsa lessons coral and every other
	 * style yellow. Matched on the slug *prefix* so language/level variants
	 * of the taxonomy slug ("salsa", "salsa-en", ...) stay coral too; a
	 * lesson with no style term at all falls in the yellow "other" bucket.
	 *
	 * @param {string} style Dance-style taxonomy slug (`row.style`; may be empty).
	 * @return {string} 'rd-chip--coral' or 'rd-chip--yellow'.
	 */
	function styleVariant( style ) {
		return 0 === ( style || '' ).indexOf( 'salsa' ) ? 'rd-chip--coral' : 'rd-chip--yellow';
	}

	/**
	 * Custom chip content for every view (month/week/list): bold time +
	 * course title + location (design's event chip rule: "čas tučně + název
	 * + lokalita"), workshop's ◆ marker, and a cancelled label. Returning a
	 * DOM node here replaces FullCalendar's own event inner markup in every
	 * view uniformly, so front-calendar.css only has to style one set of
	 * `.rd-chip__*` classes instead of each view's own default structure.
	 *
	 * @param {Object} arg FullCalendar `eventContent` render hook argument.
	 * @return {{domNodes: Node[]}}
	 */
	function renderEventContent( arg ) {
		var props = arg.event.extendedProps;
		var isCancelled = 'cancelled' === props.status;
		var isWorkshop = 'workshop' === props.type;

		var wrap = document.createElement( 'div' );
		wrap.className = 'rd-chip__inner';

		var time = document.createElement( 'strong' );
		time.className = 'rd-chip__time';
		time.textContent = arg.timeText;
		wrap.appendChild( time );

		var title = document.createElement( 'span' );
		title.className = 'rd-chip__title';
		title.textContent = ( isWorkshop ? '◆ ' : '' ) + arg.event.title;
		wrap.appendChild( title );

		if ( props.location ) {
			var location = document.createElement( 'span' );
			location.className = 'rd-chip__location';
			location.textContent = props.location;
			wrap.appendChild( location );
		}

		if ( isCancelled ) {
			var status = document.createElement( 'span' );
			status.className = 'rd-chip__status';
			status.textContent = rdCalendarL10n.cancelledLabel || 'Cancelled';
			wrap.appendChild( status );
		}

		return { domNodes: [ wrap ] };
	}

	/**
	 * Column header content for the daygrid views. Desktop week (#4d) gets
	 * a custom "PO 14" (small uppercase weekday + bold date) pairing built
	 * from `Intl.DateTimeFormat` (via `toLocaleDateString`) rather than a
	 * hardcoded weekday-name table, so it follows `rdCalendarL10n.locale`
	 * automatically; month view (not covered by the design mockups) keeps
	 * FullCalendar's own default header text.
	 *
	 * @param {Object} arg FullCalendar `dayHeaderContent` render hook argument.
	 * @return {{domNodes: Node[]}|string}
	 */
	function dayHeaderContent( arg ) {
		if ( 'dayGridWeek' !== arg.view.type ) {
			return arg.text;
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'rd-cal-daygrid-head';

		var dow = document.createElement( 'span' );
		dow.className = 'rd-cal-daygrid-head__dow';
		dow.textContent = arg.date.toLocaleDateString( rdCalendarL10n.locale, { weekday: 'short' } ).toUpperCase();
		wrap.appendChild( dow );

		var day = document.createElement( 'strong' );
		day.className = 'rd-cal-daygrid-head__day';
		day.textContent = String( arg.date.getDate() );
		wrap.appendChild( day );

		return { domNodes: [ wrap ] };
	}
} )();
