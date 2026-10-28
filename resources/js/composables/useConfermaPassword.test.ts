/**
 * Coda 404 — una chiamata che riceve 423 apre la finestra della conferma della password e, dopo la
 * conferma, rifà da sola la stessa chiamata.
 *
 * Le rotte sensibili (la 2FA, la prova della posta) rispondono 423 in JSON quando la password non è
 * stata ridigitata da poco. La Coda 261 portava la pagina alla schermata `password.confirm`, e la modifica
 * scritta nel modulo si perdeva; dalla Coda 404 la conferma si dà in una finestra sulla stessa pagina
 * (`FinestraConfermaPassword.vue`) e la chiamata riparte da sola, con gli stessi dati. La suite PHP vede il
 * 423, non che cosa ne fa la pagina: per questo il passaggio ha un test suo.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';

const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({ router: { visit } }));

(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const { annullaConferma, confermaPasswordAperta, confermaPoiRiprova, confermaRiuscita, richiediConferma, STATO_CONFERMA_PASSWORD } =
    await import('./useConfermaPassword');

describe('confermaPoiRiprova', () => {
    beforeEach(() => {
        visit.mockClear();
        annullaConferma();
    });

    test('Coda 404 — un 423 apre la finestra, ferma chi chiama e non lascia la pagina', () => {
        const riprova = vi.fn();

        expect(STATO_CONFERMA_PASSWORD).toBe(423);
        expect(confermaPoiRiprova(423, riprova)).toBe(true);

        expect(confermaPasswordAperta.value).toBe(true);
        expect(visit).not.toHaveBeenCalled();
        expect(riprova).not.toHaveBeenCalled();
    });

    test('Coda 404 — dopo la conferma la stessa chiamata riparte una volta sola, e la finestra si chiude', () => {
        const riprova = vi.fn();
        confermaPoiRiprova(423, riprova);

        confermaRiuscita();

        expect(riprova).toHaveBeenCalledTimes(1);
        expect(confermaPasswordAperta.value).toBe(false);

        confermaRiuscita();

        expect(riprova).toHaveBeenCalledTimes(1);
    });

    test('Coda 404 — con «Annulla» la chiamata non riparte, nemmeno se la conferma arriva dopo', () => {
        const riprova = vi.fn();
        confermaPoiRiprova(423, riprova);

        annullaConferma();
        confermaRiuscita();

        expect(confermaPasswordAperta.value).toBe(false);
        expect(riprova).not.toHaveBeenCalled();
    });

    test('Coda 404 — gli altri errori restano a chi chiama, e la finestra non si apre', () => {
        const riprova = vi.fn();

        for (const stato of [401, 403, 409, 419, 422, 429, 500, undefined, null]) {
            expect(confermaPoiRiprova(stato, riprova)).toBe(false);
        }

        expect(confermaPasswordAperta.value).toBe(false);
        expect(riprova).not.toHaveBeenCalled();
        expect(visit).not.toHaveBeenCalled();
    });

    test('Coda 404 — se la chiamata rifatta riceve di nuovo il 423 la finestra si riapre', () => {
        const chiamata = vi.fn(() => confermaPoiRiprova(423, chiamata));
        confermaPoiRiprova(423, chiamata);

        confermaRiuscita();

        expect(chiamata).toHaveBeenCalledTimes(1);
        expect(confermaPasswordAperta.value).toBe(true);

        // La conferma successiva rifà ancora la stessa chiamata, e solo quella.
        annullaConferma();
        expect(chiamata).toHaveBeenCalledTimes(1);
    });

    test('Coda 404 — con due richieste ferme insieme la finestra è una sola e dopo la conferma ripartono tutte e due, nell\'ordine', () => {
        const ordine: string[] = [];
        richiediConferma(() => ordine.push('prima'));
        richiediConferma(() => ordine.push('seconda'));

        expect(confermaPasswordAperta.value).toBe(true);
        confermaRiuscita();

        expect(ordine).toEqual(['prima', 'seconda']);
        expect(confermaPasswordAperta.value).toBe(false);
    });

    test('Coda 404 — «Annulla» avvisa ogni richiesta ferma, che deve finire; la conferma non avvisa nessuno', () => {
        const prima = { riprova: vi.fn(), annullata: vi.fn() };
        const seconda = { riprova: vi.fn(), annullata: vi.fn() };
        richiediConferma(prima.riprova, prima.annullata);
        richiediConferma(seconda.riprova, seconda.annullata);

        annullaConferma();

        expect(prima.annullata).toHaveBeenCalledTimes(1);
        expect(seconda.annullata).toHaveBeenCalledTimes(1);
        expect(prima.riprova).not.toHaveBeenCalled();
        expect(seconda.riprova).not.toHaveBeenCalled();
        expect(confermaPasswordAperta.value).toBe(false);

        const terza = { riprova: vi.fn(), annullata: vi.fn() };
        richiediConferma(terza.riprova, terza.annullata);
        confermaRiuscita();

        expect(terza.riprova).toHaveBeenCalledTimes(1);
        expect(terza.annullata).not.toHaveBeenCalled();
    });

    test('Coda 404 — confermaPoiRiprova avvisa con «Annulla» chi lo ha chiesto, e non con la conferma; senza avviso resta com\'era', () => {
        const riprova = vi.fn();
        const annullata = vi.fn();
        confermaPoiRiprova(423, riprova, annullata);

        annullaConferma();

        expect(annullata).toHaveBeenCalledTimes(1);
        expect(riprova).not.toHaveBeenCalled();

        confermaPoiRiprova(423, riprova, annullata);
        confermaRiuscita();

        expect(riprova).toHaveBeenCalledTimes(1);
        expect(annullata).toHaveBeenCalledTimes(1);

        confermaPoiRiprova(423, riprova);
        expect(() => annullaConferma()).not.toThrow();
        expect(confermaPasswordAperta.value).toBe(false);
    });

    test('Coda 404 — una richiesta che si ritira (la visita è stata interrotta) non riparte, e la finestra si chiude se era l\'ultima', () => {
        const interrotta = { riprova: vi.fn(), annullata: vi.fn() };
        const viva = { riprova: vi.fn(), annullata: vi.fn() };
        const ritiraLaPrima = richiediConferma(interrotta.riprova, interrotta.annullata);
        const ritiraLaSeconda = richiediConferma(viva.riprova, viva.annullata);

        ritiraLaPrima();
        expect(confermaPasswordAperta.value).toBe(true);

        ritiraLaSeconda();
        expect(confermaPasswordAperta.value).toBe(false);

        confermaRiuscita();
        annullaConferma();

        expect(interrotta.riprova).not.toHaveBeenCalled();
        expect(viva.riprova).not.toHaveBeenCalled();
        // Chi si ritira da sé non viene avvisato: lo sa già.
        expect(interrotta.annullata).not.toHaveBeenCalled();
        expect(viva.annullata).not.toHaveBeenCalled();
    });
});
