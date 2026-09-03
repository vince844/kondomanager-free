// @vitest-environment jsdom

/**
 * ⚠️ **Questo file nasce il 03/09/2026 perché non esisteva.**
 *
 * `usePuliziaErrori` è usato da tre pagine — la registrazione di una fattura passiva e le
 * due schede fornitore — e non aveva **nessun test proprio**: era coperto solo di rimbalzo
 * dai test della pagina delle fatture, e le due schede fornitore non hanno test affatto.
 * Modificarlo significava quindi cambiare il comportamento di due pagine senza che una sola
 * asserzione se ne accorgesse. La regola imparata in questa beta — *una guardia che nessun
 * test fa fallire non è protetta* — vale anche per un composable condiviso.
 */

import { describe, expect, test, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { usePuliziaErrori } from './usePuliziaErrori';

/**
 * Un finto `useForm` con la sola superficie che il composable tocca.
 *
 * ⚠️ `errors` è **reattivo** e `data()` restituisce i dati **vivi**, come fa Inertia
 * davvero (`set(carry, key, get(this, key))`): un doppio inerte renderebbe il test più
 * gentile della realtà, che è il modo in cui oggi un difetto è passato per mezza giornata.
 */
function finoForm(dati: Record<string, unknown>) {
    const stato = reactive({ dati, errors: {} as Record<string, string> });

    return {
        get errors() { return stato.errors; },
        data: () => stato.dati,
        clearErrors: vi.fn((...campi: string[]) => {
            if (!campi.length) { stato.errors = {}; return; }
            for (const campo of campi) delete stato.errors[campo];
        }),
        setError: (chiavi: Record<string, string>) => { Object.assign(stato.errors, chiavi); },
        stato,
    };
}

describe('un campo corretto perde il suo errore', () => {
    test('cambiando il valore di un campo in errore, l\'errore viene pulito', async () => {
        const form = finoForm({ email: '' });
        usePuliziaErrori(form);

        form.setError({ email: 'L\'email è obbligatoria.' });
        await nextTick();      // primo scatto: fotografa

        form.stato.dati.email = 'a@b.it';
        await nextTick();

        expect(form.clearErrors).toHaveBeenCalledWith('email');
    });

    test('toccando un campo DIVERSO, l\'errore non si tocca', async () => {
        const form = finoForm({ email: '', nome: '' });
        usePuliziaErrori(form);

        form.setError({ email: 'L\'email è obbligatoria.' });
        await nextTick();

        form.stato.dati.nome = 'Mario';
        await nextTick();

        expect(form.clearErrors).not.toHaveBeenCalled();
    });
});

describe('quando cambiano le chiavi, l\'istantanea non è confrontabile', () => {
    // ⚠️ È la guardia aggiunta col reperto 16. Un percorso come `righe.1.conto_id` non
    // nomina una voce: nomina la voce che in quel momento sta in seconda posizione. Quando
    // le voci scalano, chi possiede gli errori li rinumera — e il valore vecchio di una
    // chiave non ha più niente a che vedere con quello nuovo. Confrontarli comunque faceva
    // sparire un errore ancora valido nello stesso istante in cui era stato messo a posto.
    test('rinumerando le chiavi, l\'errore rinumerato non viene cancellato', async () => {
        // ⚠️ Lo scenario deve essere quello vero: l'errore sta sulla **seconda** voce e si
        // cancella la **prima**, così la chiave passa da `righe.1` a `righe.0`. Il primo
        // scenario che avevo scritto teneva la stessa chiave prima e dopo — e con la stessa
        // chiave non c'è nessuna rinumerazione da proteggere: il test falliva sul prodotto
        // corretto, cioè misurava un'altra cosa.
        const form = finoForm({ righe: [{ conto_id: 7 }, { conto_id: null }] });
        usePuliziaErrori(form);

        form.setError({ 'righe.1.conto_id': 'Obbligatorio.' });
        await nextTick();

        // La prima voce viene tolta e il chiamante rinumera: `righe.1` diventa `righe.0`.
        (form.stato.dati.righe as unknown[]).splice(0, 1);
        delete form.stato.errors['righe.1.conto_id'];
        form.setError({ 'righe.0.conto_id': 'Obbligatorio.' });
        await nextTick();

        expect(form.stato.errors['righe.0.conto_id'], 'l\'errore deve sopravvivere').toBeTruthy();
        expect(form.clearErrors, 'e la pulizia non deve intervenire').not.toHaveBeenCalled();
    });

    // ⚠️ **È QUESTO il caso che protegge la guardia, e il precedente NON bastava.**
    // Con un errore solo, la chiave nuova (`righe.0`) non era fra le vecchie (`righe.1`) e
    // il filtro `campo in precedente` la scartava da sé: il test restava verde anche
    // togliendo la guardia, cioè misurava qualcosa che il codice faceva già. Serve che la
    // chiave nuova sia **anche** una chiave vecchia, con un valore diverso: è il caso con
    // due errori su righe diverse, quello trovato dal critico avversariale.
    test('con due errori, la chiave rinumerata che era già in errore non viene cancellata', async () => {
        const form = finoForm({ righe: [{ descrizione: 'prima' }, { descrizione: '' }] });
        usePuliziaErrori(form);

        form.setError({
            'righe.0.descrizione': 'Obbligatoria.',
            'righe.1.descrizione': 'Obbligatoria.',
        });
        await nextTick();

        // Tolta la prima voce, il chiamante rinumera: resta il solo `righe.0.descrizione`,
        // che però adesso vale '' dove prima valeva 'prima'.
        (form.stato.dati.righe as unknown[]).splice(0, 1);
        form.stato.errors = { 'righe.0.descrizione': 'Obbligatoria.' };
        await nextTick();

        expect(form.stato.errors['righe.0.descrizione'], 'l\'errore della voce rimasta deve restare').toBeTruthy();
        expect(form.clearErrors).not.toHaveBeenCalled();
    });

    test('arrivando errori nuovi dal server, non ne viene pulito nessuno', async () => {
        const form = finoForm({ email: '', nome: '' });
        usePuliziaErrori(form);

        form.setError({ email: 'Sbagliata.' });
        await nextTick();

        form.setError({ nome: 'Obbligatorio.' });
        await nextTick();

        expect(form.clearErrors).not.toHaveBeenCalled();
        expect(Object.keys(form.stato.errors).sort()).toEqual(['email', 'nome']);
    });
});

describe('il primo giro fotografa e basta', () => {
    // Senza questa guardia ogni errore appena arrivato dal server verrebbe pulito subito
    // dopo essere comparso: il modulo verrebbe rifiutato e a schermo non si vedrebbe niente.
    test('un errore appena arrivato non viene pulito nello stesso istante', async () => {
        const form = finoForm({ email: 'sbagliata' });
        usePuliziaErrori(form);

        form.setError({ email: 'Non è un indirizzo valido.' });
        await nextTick();
        await nextTick();

        expect(form.clearErrors).not.toHaveBeenCalled();
    });
});
