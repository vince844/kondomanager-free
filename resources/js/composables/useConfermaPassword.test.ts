/**
 * Coda 261 — una chiamata che riceve 423 porta alla pagina della conferma della password.
 *
 * Le rotte sensibili (la 2FA, la prova della posta) rispondono 423 in JSON quando la password non è
 * stata ridigitata da poco. Le pagine che le chiamano con `fetch` o `axios` guardavano solo se la
 * risposta era buona: il pulsante non faceva niente, senza messaggio. La suite PHP vede il 423, non
 * che cosa ne fa la pagina: per questo il passaggio ha un test suo.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';

const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({ router: { visit } }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { portaAllaConfermaSeServe, STATO_CONFERMA_PASSWORD } = await import('./useConfermaPassword');

describe('portaAllaConfermaSeServe', () => {
    beforeEach(() => visit.mockClear());

    test('Coda 261 — un 423 porta alla pagina della conferma e ferma chi chiama', () => {
        expect(STATO_CONFERMA_PASSWORD).toBe(423);
        expect(portaAllaConfermaSeServe(423)).toBe(true);
        expect(visit).toHaveBeenCalledWith('/password.confirm');
    });

    test('Coda 261 — gli altri errori restano a chi chiama', () => {
        for (const stato of [401, 403, 409, 419, 422, 500, undefined, null]) {
            expect(portaAllaConfermaSeServe(stato)).toBe(false);
        }
        expect(visit).not.toHaveBeenCalled();
    });
});
