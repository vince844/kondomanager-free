/**
 * Coda 404 — le quattro chiamate della pagina della 2FA, a conferma scaduta, aprono la finestra della
 * conferma della password e, dopo la conferma, rifanno la stessa chiamata.
 *
 * La pagina si apre solo con la password ridigitata da poco, ma resta aperta: dopo quindici minuti le chiamate
 * ricevono un 423. Con la Coda 261 la pagina lasciava il posto alla schermata della conferma, e la finestra
 * aperta e il codice a sei cifre digitato andavano persi; ora la finestra della conferma si apre sopra la
 * pagina e la chiamata riparte da sola, con lo stesso corpo. Un errore diverso (il codice sbagliato alla
 * conferma) resta un errore della pagina.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';

const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { visit },
    usePage: () => ({ props: { csrf_token: 'gettone' } }),
}));

vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { useTwoFactorAuth } = await import('./useTwoFactorAuth');
const { annullaConferma, confermaPasswordAperta, confermaRiuscita } = await import('./useConfermaPassword');

type Composable = ReturnType<typeof useTwoFactorAuth>;

function risposta(stato: number, corpo: Record<string, unknown> = {}) {
    return { ok: stato >= 200 && stato < 300, status: stato, statusText: '', json: async () => corpo };
}

const CONFERMA_RICHIESTA = { message: 'conferma', conferma_password: true };

// Ognuna delle quattro chiamate: come si fa partire, che cosa risponde il server a conferma data, che cosa cambia nella pagina.
const chiamate: [string, boolean, (c: Composable) => Promise<void>, Record<string, unknown>, (c: Composable) => void][] = [
    ['enable', false, (c) => c.enable(), { qrCode: '<svg />', secret: 'SEGRETO' }, (c) => {
        expect(c.qrCodeSvg.value).toBe('<svg />');
        expect(c.secretKey.value).toBe('SEGRETO');
    }],
    ['confirm', false, (c) => { c.passcode.value = '123456'; return c.confirm(); }, { status: 'ok', recovery_codes: ['nuovo-codice'] }, (c) => {
        expect(c.confirmed.value).toBe(true);
        expect(c.recoveryCodesList.value).toEqual(['nuovo-codice']);
        expect(c.passcode.value).toBe('');
    }],
    ['regenerateRecoveryCodes', true, (c) => c.regenerateRecoveryCodes(), { recovery_codes: ['rigenerato'] }, (c) => {
        expect(c.recoveryCodesList.value).toEqual(['rigenerato']);
    }],
    ['disable', true, (c) => c.disable(), {}, (c) => {
        expect(c.confirmed.value).toBe(false);
        expect(c.recoveryCodesList.value).toEqual([]);
    }],
];

describe('useTwoFactorAuth', () => {
    beforeEach(() => {
        visit.mockClear();
        annullaConferma();
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    test.each(chiamate)('Coda 404 — %s con il 423 apre la finestra della conferma, senza lasciare la pagina e senza cambiarla', async (_nome, iniziaConfermata, chiama) => {
        globalThis.fetch = vi.fn(async () => risposta(423, CONFERMA_RICHIESTA)) as unknown as typeof fetch;
        const c = useTwoFactorAuth(iniziaConfermata, ['codice']);

        await chiama(c);

        expect(confermaPasswordAperta.value).toBe(true);
        expect(visit).not.toHaveBeenCalled();
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        // Niente è cambiato nella pagina: la 2FA è com'era, i codici sono quelli di prima.
        expect(c.confirmed.value).toBe(iniziaConfermata);
        expect(c.recoveryCodesList.value).toEqual(['codice']);
    });

    test.each(chiamate)('Coda 404 — %s, dopo la conferma, rifà la stessa chiamata con lo stesso corpo', async (_nome, iniziaConfermata, chiama, rispostaGiusta, verifica) => {
        globalThis.fetch = vi.fn()
            .mockResolvedValueOnce(risposta(423, CONFERMA_RICHIESTA))
            .mockResolvedValueOnce(risposta(200, rispostaGiusta)) as unknown as typeof fetch;
        const c = useTwoFactorAuth(iniziaConfermata, ['codice']);
        await chiama(c);

        confermaRiuscita();
        await flushPromises();

        const [prima, dopo] = vi.mocked(globalThis.fetch).mock.calls;
        expect(vi.mocked(globalThis.fetch)).toHaveBeenCalledTimes(2);
        expect(dopo).toEqual(prima);
        expect(confermaPasswordAperta.value).toBe(false);
        verifica(c);
    });

    test('Coda 404 — con «Annulla» la chiamata non riparte, e il codice a sei cifre resta dov\'era', async () => {
        globalThis.fetch = vi.fn(async () => risposta(423, CONFERMA_RICHIESTA)) as unknown as typeof fetch;
        const c = useTwoFactorAuth(false, []);
        c.passcode.value = '123456';
        await c.confirm();

        annullaConferma();
        confermaRiuscita();
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
        expect(c.passcode.value).toBe('123456');
    });

    test('Coda 404 — con «Annulla» nella finestra della password la modale dell\'attivazione si chiude, senza QR vuoto', async () => {
        globalThis.fetch = vi.fn(async () => risposta(423, CONFERMA_RICHIESTA)) as unknown as typeof fetch;
        const c = useTwoFactorAuth(false, []);

        c.showModal.value = true;
        await flushPromises();
        expect(confermaPasswordAperta.value).toBe(true);
        expect(c.showModal.value).toBe(true);

        annullaConferma();
        await flushPromises();

        // Chi annulla la conferma rinuncia ad attivare la 2FA: non resta una modale senza QR da chiudere a mano.
        expect(c.showModal.value).toBe(false);
        expect(c.qrCodeSvg.value).toBe('');
        expect(confermaPasswordAperta.value).toBe(false);
        expect(globalThis.fetch).toHaveBeenCalledTimes(1);
    });

    test('Coda 404 — con la password giusta la modale dell\'attivazione resta aperta e mostra il QR', async () => {
        globalThis.fetch = vi.fn()
            .mockResolvedValueOnce(risposta(423, CONFERMA_RICHIESTA))
            .mockResolvedValueOnce(risposta(200, { qrCode: '<svg />', secret: 'SEGRETO' })) as unknown as typeof fetch;
        const c = useTwoFactorAuth(false, []);
        c.showModal.value = true;
        await flushPromises();

        confermaRiuscita();
        await flushPromises();

        expect(c.showModal.value).toBe(true);
        expect(c.qrCodeSvg.value).toBe('<svg />');
        expect(c.secretKey.value).toBe('SEGRETO');
    });

    test('Coda 404 — riaprendo la modale dopo «Annulla» la chiamata dell\'attivazione riparte da capo', async () => {
        globalThis.fetch = vi.fn()
            .mockResolvedValueOnce(risposta(423, CONFERMA_RICHIESTA))
            .mockResolvedValueOnce(risposta(200, { qrCode: '<svg />', secret: 'SEGRETO' })) as unknown as typeof fetch;
        const c = useTwoFactorAuth(false, []);
        c.showModal.value = true;
        await flushPromises();
        annullaConferma();
        await flushPromises();

        c.showModal.value = true;
        await flushPromises();

        expect(globalThis.fetch).toHaveBeenCalledTimes(2);
        expect(c.qrCodeSvg.value).toBe('<svg />');
        expect(c.secretKey.value).toBe('SEGRETO');
        expect(c.showModal.value).toBe(true);
    });

    test.each(chiamate.slice(1))('Coda 404 — %s con «Annulla» non tocca la modale: il codice e i pulsanti restano com\'erano', async (_nome, iniziaConfermata, chiama) => {
        globalThis.fetch = vi.fn(async () => risposta(423, CONFERMA_RICHIESTA)) as unknown as typeof fetch;
        const c = useTwoFactorAuth(iniziaConfermata, ['codice']);
        c.showModal.value = true;
        c.verifyStep.value = true;
        await flushPromises();
        await chiama(c);

        annullaConferma();
        await flushPromises();

        expect(c.showModal.value).toBe(true);
        expect(c.verifyStep.value).toBe(true);
        expect(c.recoveryCodesList.value).toEqual(['codice']);
    });

    test('Coda 404 — alla conferma rifatta un codice nel frattempo scaduto è un errore della pagina, come sempre', async () => {
        globalThis.fetch = vi.fn()
            .mockResolvedValueOnce(risposta(423, CONFERMA_RICHIESTA))
            .mockResolvedValueOnce(risposta(422, { message: 'codice non valido' })) as unknown as typeof fetch;
        const c = useTwoFactorAuth(false, []);
        c.passcode.value = '123456';
        await c.confirm();

        confermaRiuscita();
        await flushPromises();

        expect(c.error.value).toBe('codice non valido');
        expect(confermaPasswordAperta.value).toBe(false);
    });

    test('Coda 261 — un codice sbagliato alla conferma resta un errore della pagina, senza aprire la finestra della password', async () => {
        globalThis.fetch = vi.fn(async () => risposta(422, { message: 'codice non valido' })) as unknown as typeof fetch;
        const c = useTwoFactorAuth(false, []);
        c.passcode.value = '123456';

        await c.confirm();

        expect(visit).not.toHaveBeenCalled();
        expect(confermaPasswordAperta.value).toBe(false);
        expect(c.error.value).toBe('codice non valido');
    });
});
