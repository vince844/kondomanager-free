/**
 * Coda 261 — le quattro chiamate della pagina della 2FA, a conferma scaduta, portano alla pagina
 * della conferma della password invece di fallire in silenzio.
 *
 * La pagina si apre solo con la password ridigitata da poco, ma resta aperta: dopo quindici minuti
 * le chiamate ricevono un 423. Il composable guardava solo `response.ok` e scriveva l'errore nella
 * console. Un errore diverso (il codice sbagliato alla conferma) resta un errore della pagina.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';

const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { visit },
    usePage: () => ({ props: { csrf_token: 'gettone' } }),
}));

vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { useTwoFactorAuth } = await import('./useTwoFactorAuth');

function risposta(stato: number, corpo: Record<string, unknown> = {}) {
    return { ok: stato >= 200 && stato < 300, status: stato, statusText: '', json: async () => corpo };
}

describe('useTwoFactorAuth', () => {
    beforeEach(() => {
        visit.mockClear();
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    test.each([
        ['enable', (c: ReturnType<typeof useTwoFactorAuth>) => c.enable()],
        ['confirm', (c: ReturnType<typeof useTwoFactorAuth>) => { c.passcode.value = '123456'; return c.confirm(); }],
        ['regenerateRecoveryCodes', (c: ReturnType<typeof useTwoFactorAuth>) => c.regenerateRecoveryCodes()],
        ['disable', (c: ReturnType<typeof useTwoFactorAuth>) => c.disable()],
    ])('Coda 261 — %s con il 423 porta alla conferma della password', async (_nome, chiama) => {
        globalThis.fetch = vi.fn(async () => risposta(423, { message: 'conferma' })) as unknown as typeof fetch;
        const c = useTwoFactorAuth(true, ['codice']);

        await chiama(c);

        expect(visit).toHaveBeenCalledWith('/password.confirm');
        // Niente è cambiato nella pagina: la 2FA risulta ancora attiva, i codici sono quelli di prima.
        expect(c.confirmed.value).toBe(true);
        expect(c.recoveryCodesList.value).toEqual(['codice']);
    });

    test('Coda 261 — un codice sbagliato alla conferma resta un errore della pagina, senza andare altrove', async () => {
        globalThis.fetch = vi.fn(async () => risposta(422, { message: 'codice non valido' })) as unknown as typeof fetch;
        const c = useTwoFactorAuth(false, []);
        c.passcode.value = '123456';

        await c.confirm();

        expect(visit).not.toHaveBeenCalled();
        expect(c.error.value).toBe('codice non valido');
    });
});
