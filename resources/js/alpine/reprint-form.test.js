import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { reprintForm } from './reprint-form';

const ENDPOINT = '/inbound/cetak-label/reprint';
const REDIRECT = '/inbound/cetak-label';
const CONTEXT = 'inventory.label-overprint';

/** A form element Alpine can hand to `send()` through `this.$el`. */
function formElement() {
	const element = document.createElement('form');

	element.append(
		Object.assign(document.createElement('input'), { name: 'lot_ids[]', value: '7' }),
		Object.assign(document.createElement('input'), { name: 'copies', value: '3' }),
	);

	return element;
}

/**
 * The component under test with its `window` dependencies faked.
 *
 * `window.location.assign` and `window.pin` are the only two things the form
 * reaches for outside itself, and both are replaced rather than stubbed on the
 * object so that a test which forgets to fake one fails loudly instead of
 * quietly navigating a jsdom window.
 */
function mount({ responses, pin } = {}) {
	const assign = vi.fn();
	const element = formElement();

	delete window.pin;
	window.pin = pin;

	const state = reprintForm({ endpoint: ENDPOINT, redirectTo: REDIRECT, context: CONTEXT });
	state.$el = element;

	const queue = [...(responses ?? [])];
	const calls = [];

	global.fetch = vi.fn(async (url, init) => {
		calls.push({ url, init });

		return queue.length > 1 ? queue.shift() : queue[0];
	});

	return { state, assign, calls, element };
}

function jsonResponse(status, payload) {
	return {
		ok: status >= 200 && status < 300,
		status,
		json: async () => payload,
	};
}

beforeEach(() => {
	vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
	vi.unstubAllGlobals();
	delete window.pin;
});

const LOT_IDS = [7, 8, 9];

function makeForm(overrides = {}) {
	return { ...reprintForm({ endpoint: ENDPOINT, redirectTo: REDIRECT, context: CONTEXT }, LOT_IDS), ...overrides };
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
		const form = reprintForm({ endpoint: ENDPOINT, redirectTo: REDIRECT, context: CONTEXT }, []);

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

	it('keeps the chosen lots after the owner cancels the PIN dialog', () => {
		const form = makeForm({ selected: [7] });
		form.reset();

		// Token yang gagal dicoba ikut dibuang, tapi pilihan lot tidak: kalau
		// ikut hilang, operator harus mencari ulang lot yang tadi dipegang.
		expect(form.token).toBe('');
		expect(form.selected).toEqual([7]);
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

		// A 422 is what opens the dialog. Without these headers Laravel answers
		// 302 back to the previous page, `fetch` follows it to HTML, and the
		// rejection is never seen by this component.
		expect(calls[0].init.headers.Accept).toBe('application/json');
		expect(calls[0].init.headers['X-Requested-With']).toBe('XMLHttpRequest');
	});

	it('goes to the queue when the reprint was accepted', async () => {
		const assign = vi.fn();
		const { state } = mount({ responses: [jsonResponse(200, {})] });
		window.location.assign = assign;

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

describe('reprintForm and the Owner PIN', () => {
	it('opens the dialog when the server says the reprint needs approval', async () => {
		const request = vi.fn(async () => ({ token: 'grant-1' }));
		const { state, calls } = mount({
			pin: { request },
			responses: [
				jsonResponse(422, { errors: { pin_token: ['Sudah punya 4 dari 4 label.'] } }),
				jsonResponse(200, {}),
			],
		});

		await state.submit({ preventDefault: vi.fn() });

		expect(request).toHaveBeenCalledTimes(1);
		expect(request.mock.calls[0][0].context).toBe(CONTEXT);
		expect(calls).toHaveLength(2);
	});

	it('tells the operator which lot is the problem', async () => {
		const request = vi.fn(async () => ({ token: 'grant-1' }));
		const { state } = mount({
			pin: { request },
			responses: [
				jsonResponse(422, { errors: { pin_token: ['CN01-HW-001-U09 sudah punya 4 dari 4 label.'] } }),
				jsonResponse(200, {}),
			],
		});

		await state.submit({ preventDefault: vi.fn() });

		expect(request.mock.calls[0][0].description).toContain('CN01-HW-001-U09');
	});

	it('sends the token it was given', async () => {
		const request = vi.fn(async () => ({ token: 'grant-1' }));
		const { state, calls } = mount({
			pin: { request },
			responses: [
				jsonResponse(422, { errors: { pin_token: ['Perlu PIN.'] } }),
				jsonResponse(200, {}),
			],
		});

		await state.submit({ preventDefault: vi.fn() });

		expect(calls[1].init.body.get('pin_token')).toBe('grant-1');
	});

	it('does not send an empty token when no approval is needed', async () => {
		// An empty `pin_token` would trip `required` with the wrong message, and
		// what the operator needs to read at that point is "ask for a PIN".
		const { state, calls } = mount({ responses: [jsonResponse(200, {})] });

		await state.submit({ preventDefault: vi.fn() });

		expect(calls[0].init.body.has('pin_token')).toBe(false);
	});

	it('opens the dialog at most once, however often the server refuses', async () => {
		// A wrong PIN, or one that expired, answers 422 again. Reopening the
		// dialog on that would put a PIN prompt in front of whoever else is at
		// the terminal, over and over, with no way out.
		const request = vi.fn(async () => ({ token: 'grant-1' }));
		const { state } = mount({
			pin: { request },
			responses: [jsonResponse(422, { errors: { pin_token: ['PIN Owner salah.'] } })],
		});

		await state.submit({ preventDefault: vi.fn() });

		expect(request).toHaveBeenCalledTimes(1);
		expect(state.error).toContain('PIN Owner salah');
	});

	it('reports the refusal when the operator cancels the dialog', async () => {
		const request = vi.fn(async () => null);
		const { state, calls } = mount({
			pin: { request },
			responses: [jsonResponse(422, { errors: { pin_token: ['Sudah punya 4 dari 4 label.'] } })],
		});

		await state.submit({ preventDefault: vi.fn() });

		expect(calls).toHaveLength(1);
		expect(request).toHaveBeenCalledTimes(1);
		expect(state.error).toContain('4 dari 4');
	});

	it('lets a cancelled dialog be asked for again', async () => {
		const request = vi.fn(async () => null);
		const { state, calls } = mount({
			pin: { request },
			responses: [jsonResponse(422, { errors: { pin_token: ['Perlu PIN.'] } })],
		});

		await state.submit({ preventDefault: vi.fn() });
		await state.submit({ preventDefault: vi.fn() });

		expect(request).toHaveBeenCalledTimes(2);
		expect(calls).toHaveLength(2);
	});

	it('shows the reason without a dialog when it is not about the PIN', async () => {
		const request = vi.fn();
		const { state } = mount({
			pin: { request },
			responses: [jsonResponse(422, { errors: { reason: ['Alasan tidak dikenal.'] } })],
		});

		await state.submit({ preventDefault: vi.fn() });

		expect(request).not.toHaveBeenCalled();
		expect(state.error).toBe('Alasan tidak dikenal.');
	});
});

describe('reprintForm when the server cannot be reached', () => {
	it('says the network is the problem, not the form', async () => {
		global.fetch = vi.fn(async () => {
			throw new TypeError('Failed to fetch');
		});

		const state = reprintForm({ endpoint: ENDPOINT, redirectTo: REDIRECT, context: CONTEXT });
		state.$el = formElement();

		await state.run();

		// A dead network and a refused PIN send the operator to two different
		// places to fix something, so the message has to say which one.
		expect(state.error).toContain('Tidak bisa menghubungi server');
	});

	it('clears the busy flag so the form can be used again', async () => {
		global.fetch = vi.fn(async () => {
			throw new TypeError('Failed to fetch');
		});

		const state = reprintForm({ endpoint: ENDPOINT, redirectTo: REDIRECT, context: CONTEXT });
		state.$el = formElement();

		await state.run();

		expect(state.busy).toBe(false);
	});
});
