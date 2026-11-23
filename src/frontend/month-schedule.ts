import { buildSlotsByDate, parseSlotValue } from './slot-parser';
import type { FrontendDateMap, FrontendSlot } from './types';

/** Canonical slot identifiers and their absolute Unix start times in seconds. */
type MonthTimes = Record< string, number >;

interface CalendarSeed {
	months: string[];
	slots: MonthTimes;
	previewMonths?: Record< string, MonthTimes >;
}

/** Combines the first rendered month with on-demand responses for the same block. */
export class MonthSchedule {
	readonly months: string[];
	readonly dates: FrontendDateMap;
	readonly timestamps = new Map< string, number >();
	readonly paged: boolean;
	private loaded = new Set< string >();
	private pending = new Map< string, Promise< void > >();
	private previewMonths?: Record< string, MonthTimes >;
	private form: HTMLFormElement;

	/**
	 * Reads this form's seed, or the radio inputs from an earlier cached page.
	 *
	 * @param form      Published form used to identify the block.
	 * @param container Embedded JSON or cached slot inputs.
	 */
	constructor( form: HTMLFormElement, container: HTMLElement ) {
		this.form = form;
		const json = container.querySelector(
			'script[type="application/json"]'
		)?.textContent;
		this.paged = !! json;
		if ( json ) {
			const seed: CalendarSeed = JSON.parse( json );
			this.months = seed.months;
			this.dates = new Map();
			this.previewMonths = seed.previewMonths;
			this.store( this.months[ 0 ], seed.slots );
		} else {
			this.dates = buildSlotsByDate(
				Array.from(
					container.querySelectorAll< HTMLInputElement >(
						'input[type="radio"]'
					)
				)
			);
			this.months = Array.from(
				new Set(
					Array.from( this.dates.keys(), ( date ) =>
						date.slice( 0, 7 )
					)
				)
			).sort();
			this.loaded = new Set( this.months );
		}
	}

	/**
	 * Returns whether this month is ready for synchronous navigation.
	 *
	 * @param month ISO calendar month.
	 * @return Whether this month is loaded.
	 */
	has( month: string ): boolean {
		return this.loaded.has( month );
	}

	/**
	 * Validates the complete response before making its times visible to the calendar.
	 *
	 * @param month Expected ISO calendar month.
	 * @param times Canonical slots mapped to Unix timestamps.
	 */
	private store( month: string, times: MonthTimes ): void {
		if ( ! times || typeof times !== 'object' ) {
			throw new Error( 'Invalid calendar month' );
		}
		const days: FrontendDateMap = new Map();
		for ( const [ value, timestamp ] of Object.entries( times ) ) {
			const { date, time, endTime } = parseSlotValue( value );
			if (
				date.slice( 0, 7 ) !== month ||
				! endTime ||
				! Number.isFinite( timestamp )
			) {
				throw new Error( 'Invalid appointment time' );
			}
			const slots: FrontendSlot[] = days.get( date ) || [];
			slots.push( { value, time, label: time + ' - ' + endTime } );
			days.set( date, slots );
		}
		for ( const [ date, slots ] of days ) {
			this.dates.set( date, slots );
			for ( const slot of slots ) {
				this.timestamps.set( slot.value, times[ slot.value ] );
			}
		}
		this.loaded.add( month );
	}

	/**
	 * Coalesces overlapping requests for a month and allows retry after a failure.
	 *
	 * @param month ISO month from the published index.
	 * @return Promise that resolves when all times are available.
	 */
	load( month: string ): Promise< void > {
		if ( this.has( month ) ) {
			return Promise.resolve();
		}
		let request = this.pending.get( month );
		if ( ! request ) {
			request = this.fetchMonth( month ).finally( () =>
				this.pending.delete( month )
			);
			this.pending.set( month, request );
		}
		return request;
	}

	/**
	 * Reads a published month with the page's cookies, or uses the authorized draft preview.
	 *
	 * @param month ISO month selected in this form.
	 */
	private async fetchMonth( month: string ): Promise< void > {
		if (
			this.previewMonths &&
			Object.prototype.hasOwnProperty.call( this.previewMonths, month )
		) {
			this.store( month, this.previewMonths[ month ] );
			return;
		}
		const body = new FormData();
		body.set( 'action', 'rrze_appointment_get_month' );
		body.set( 'month', month );
		body.set( 'post_id', this.form.dataset.postId || '' );
		body.set( 'block_id', this.form.dataset.blockId || '' );
		body.set( 'nonce', window.rrze_appointment?.nonce || '' );
		const response = await fetch(
			window.rrze_appointment?.ajaxUrl || '/wp-admin/admin-ajax.php',
			{
				method: 'POST',
				body,
				credentials: 'same-origin',
				mode: 'same-origin',
				cache: 'no-store',
			}
		);
		if ( ! response.ok ) {
			throw new Error( 'Calendar request failed' );
		}
		const result = await response.json();
		if ( result?.success !== true || result.data?.month !== month ) {
			throw new Error( 'Unexpected calendar month' );
		}
		this.store( month, result.data.slots );
	}
}
