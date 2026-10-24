import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { reprintForm } from './reprint-form';

const ENDPOINT = '/inbound/cetak-label/reprint';
const REDIRECT = '/inbound/cetak-label';

/** A form element Alpine can hand to `send()` through `this.$el`. */
function formElement() {
	const element = document.createElement('form');

	element.append(
		Object.assign(document.createElement('input'), { name: 'lot_ids[]', value: '7' }),
		Object.assign(document.createElement('input'), { name: 'copies', value: '3' }),
	);

	return element;
}

function jsonResponse(status, payload) {
	return {
		ok: status >= 200 && status < 300,
		status,
		json: async () => payload,
	};
}

/**
 * The component under test with its `window` dependencies faked.
 *
 * `window.location.assign` is the only thing the form reaches for outside
 * itself, and it is replaced so that a successful run does not try to navigate
 * the jsdom window.
 */
function mount({ responses } = {}) {
	const assign = vi.fn();
	const element = formElement();
	const state = reprintForm({ endpoint: ENDPOINT, redirectTo: REDIRECT });
	state.$el = element;

	window.location.assign = assign;

	const queue = [...(responses ?? [])];
	const calls = [];

	global.fetch = vi.fn(async (url, init) => {
		calls.push({ url, init });

		return queue.length > 1 ? queue.shift() : queue[0];
	});

	return { state, assign, calls };
}

beforeEach(() => {
	vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
	vi.unstubAllGlobals();
});

const LOT_IDS = [7, 8, 9];

function makeForm(overrides = {}) {
	return { ...reprintForm({ endpoint: ENDPOINT, redirectTo: REDIRECT }, LOT_IDS), ...overrides };
}

/**
 * Checkbox header ditulis lewat `$refs` untuk `indeterminate`, jadi butuh objek
 * yang benar-benar punya properti itu.
 */
function makeHeader() {
	return { checked: false, indeterminate: false };
}

describe('reprintForm lot selection', () => {
	it('selects every lot on the page', () => {
		const form = makeForm();

		form.toggleAll();

		expect(form.selected).toEqual(LOT_IDS);
		expect(form.allSelected()).toBe(true);
		expect(form.someSelected()).toBe(false);
	});

	it('clears the selection when everything is already selected', () => {
		const form = makeForm({ selected: [...LOT_IDS] });

		form.toggleAll();

		expect(form.selected).toEqual([]);
	});

	it('reports a partial selection as indeterminate', () => {
		const form = makeForm({ selected: [8] });

		expect(form.someSelected()).toBe(true);
		expect(form.allSelected()).toBe(false);
	});

	it('never reports all selected when no lot is on the page', () => {
		const form = reprintForm({ endpoint: ENDPOINT, redirectTo: REDIRECT }, []);

		expect(form.allSelected()).toBe(false);

		form.toggleAll();

		expect(form.selected).toEqual([]);
	});

	it('writes indeterminate onto the header checkbox', () => {
		const form = makeForm({ $refs: { selectAll: makeHeader() }, $watch: vi.fn() });

		form.init();
		form.selected = [7];
		form.syncSelectAll();

		expect(form.$refs.selectAll.indeterminate).toBe(true);
	});

	it('tolerates a render without the header checkbox', () => {
		const form = makeForm({ $refs: {}, $watch: vi.fn() });

		expect(() => form.init()).not.toThrow();
	});

	it('keeps the submit button dead until a lot is chosen', () => {
		expect(makeForm().canSubmit()).toBe(false);
		expect(makeForm({ selected: [7] }).canSubmit()).toBe(true);
	});

	it('keeps the submit button dead while a request is in flight', () => {
		expect(makeForm({ selected: [7], busy: true }).canSubmit()).toBe(false);
	});

	it('keeps the chosen lots after the server refuses the request', async () => {
		const { state } = mount({ responses: [jsonResponse(422, { errors: { copies: ['Melebihi batas.'] } })] });
		state.selected = [7];

		await state.run();

		// Kalau centangan ikut hilang, operator harus mencari ulang lot yang tadi
		// dipegang hanya karena batasnya berubah di detik terakhir.
		expect(state.selected).toEqual([7]);
	});
});

describe('reprintForm.submit', () => {
	it('sends the form with the endpoint it was given', async () => {
		const { state, calls } = mount({ responses: [jsonResponse(200, {})] });

		await state.submit({ preventDefault: vi.fn() });

		expect(calls[0].url).toBe(ENDPOINT);
		expect(calls[0].init.method).toBe('POST');
	});

	it('asks the server to answer with JSON rather than a redirect', async () => {
		const { state, calls } = mount({ responses: [jsonResponse(200, {})] });

		await state.submit({ preventDefault: vi.fn() });

		// Tanpa header ini Laravel menjawab 302 ke halaman sebelumnya, `fetch`
		// mengikutinya sampai ke HTML, dan pesan penolakan tidak pernah sampai
		// ke komponen ini.
		expect(calls[0].init.headers.Accept).toBe('application/json');
		expect(calls[0].init.headers['X-Requested-With']).toBe('XMLHttpRequest');
	});

	it('goes to the queue when the reprint was accepted', async () => {
		const { state, assign } = mount({ responses: [jsonResponse(200, {})] });

		await state.submit({ preventDefault: vi.fn() });

		expect(assign).toHaveBeenCalledWith(REDIRECT);
	});

	it('prevents the browser submitting the form as well', async () => {
		const preventDefault = vi.fn();
		const { state } = mount({ responses: [jsonResponse(200, {})] });

		await state.submit({ preventDefault });

		expect(preventDefault).toHaveBeenCalled();
	});
});

describe('reprintForm when the server refuses the request', () => {
	it('shows the first validation message, not a generic one', async () => {
		const { state } = mount({
			responses: [
				jsonResponse(422, {
					errors: { copies: ['CN01-HW-001-U09 sudah punya 4 dari 4 label.'] },
				}),
			],
		});

		await state.submit({ preventDefault: vi.fn() });

		expect(state.error).toBe('CN01-HW-001-U09 sudah punya 4 dari 4 label.');
	});

	it('says which lot is over the limit', async () => {
		const { state } = mount({
			responses: [jsonResponse(422, { errors: { copies: ['CN01-HW-001-U09 sudah punya 4 dari 4 label.'] } })],
		});

		await state.submit({ preventDefault: vi.fn() });

		expect(state.error).toContain('CN01-HW-001-U09');
	});

	it('falls back to a generic message when the response carries no errors', async () => {
		const { state } = mount({ responses: [jsonResponse(422, {})] });

		await state.submit({ preventDefault: vi.fn() });

		expect(state.error).not.toBe('');
	});

	it('does not navigate when the request was refused', async () => {
		const { state, assign } = mount({ responses: [jsonResponse(422, { errors: { copies: ['Penuh.'] } })] });

		await state.submit({ preventDefault: vi.fn() });

		expect(assign).not.toHaveBeenCalled();
	});

	it('clears the previous error on the next attempt', async () => {
		const { state } = mount({
			responses: [jsonResponse(422, { errors: { copies: ['Penuh.'] } }), jsonResponse(200, {})],
		});

		await state.run();
		expect(state.error).toBe('Penuh.');

		await state.run();
		expect(state.error).toBe('');
	});
});

describe('reprintForm when the server cannot be reached', () => {
	it('says the network is the problem, not the form', async () => {
		global.fetch = vi.fn(async () => {
			throw new TypeError('Failed to fetch');
		});

		const state = reprintForm({ endpoint: ENDPOINT, redirectTo: REDIRECT });
		state.$el = formElement();

		await state.run();

		expect(state.error).toContain('Tidak bisa menghubungi server');
	});

	it('clears the busy flag so the form can be used again', async () => {
		global.fetch = vi.fn(async () => {
			throw new TypeError('Failed to fetch');
		});

		const state = reprintForm({ endpoint: ENDPOINT, redirectTo: REDIRECT });
		state.$el = formElement();

		await state.run();

		expect(state.busy).toBe(false);
	});

	it('does not start a second request while one is still in flight', async () => {
		const { state, calls } = mount({ responses: [jsonResponse(200, {})] });
		state.busy = true;

		await state.run();

		expect(calls).toHaveLength(0);
	});
});
