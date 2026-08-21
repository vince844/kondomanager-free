// @vitest-environment jsdom

/**
 * beta.48 — La schermata degli scoperti deve saper mostrare TRE forme, non una.
 *
 * Fino alla beta.47 uno scoperto era sempre la stessa cosa: un'unità senza soggetto attivo a
 * cui addebitare la quota. La tabella lo dava per scontato — mostrava `immobile_nome`, il badge
 * del ruolo atteso e un collegamento a `/immobili/{id}`.
 *
 * Correggendo i due scarti silenziosi del motore (coda ⑨ e la tabella senza millesimi) sono
 * nate due forme in cui **`immobile_id` è `null`**, perché non riguardano un'unità ma un
 * capitolo o una tabella. Con il tracciato vecchio producevano «Immobile #» seguito dal vuoto,
 * un badge vuoto e un collegamento a `/immobili/null`.
 *
 * Ed è la parte che rende questo test necessario invece che diligente: la correzione **non ha
 * introdotto** quelle righe, le ha rese **comuni**. `conto_senza_tabella` esisteva dalla
 * beta.33 e si vedeva quasi mai, perché il ramo con importo forzato dal piano — cioè tutti i
 * piani rate — non ci arrivava. Da adesso ci arriva sempre. È la lezione del «perimetro di
 * raggiungibilità» della beta.46 applicata a una schermata invece che a un motore.
 *
 * L'altra metà del contratto sta in `tests/Feature/Riparto/ScartoSilenziosoOverrideTest.php`,
 * che fissa quali scoperti il motore produce. I due lati vanno letti insieme, come nella
 * beta.35.
 *
 * ## Cosa questo file NON copre
 *
 * - Il flusso di conferma (nota minima di dieci caratteri, evento `procedi`): è preesistente e
 *   non è stato toccato dalla beta.48.
 * - Il rendering dentro `PianiRateShow.vue` e `PianiRateNew.vue`, che sono i due chiamanti: qui
 *   si monta il solo componente.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// `usePermission` legge il ruolo dalle props di pagina per costruire il prefisso dei percorsi.
// Senza un utente amministratore i collegamenti uscirebbero sotto `/user/`, che è un'altra cosa.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({
        props: { auth: { user: { roles: ['amministratore'], permissions: [] } } },
    }),
}));

const ScopertoWarning = (await import('./ScopertoWarning.vue')).default;

/** La quota orfana storica: l'unità c'è, il soggetto no. `motivo` assente per costruzione. */
function quotaOrfana(overrides: Record<string, unknown> = {}) {
    return {
        immobile_id: 12,
        immobile_nome: 'Interno 3',
        conto_id: 5,
        conto_nome: 'Spese generali',
        importo: 90000,
        ruolo_richiesto: 'inquilino',
        ...overrides,
    };
}

/** Il capitolo del piano senza nessuna tabella collegata — coda ⑨. */
function capitoloSenzaTabella(overrides: Record<string, unknown> = {}) {
    return {
        immobile_id: null,
        immobile_nome: null,
        conto_id: 7,
        conto_nome: 'Manutenzione idraulica',
        tabella_id: null,
        tabella_nome: null,
        importo: 2474160,
        ruolo_richiesto: null,
        motivo: 'conto_senza_tabella',
        ...overrides,
    };
}

/** La tabella collegata ma senza millesimi utilizzabili. */
function tabellaSenzaMillesimi(overrides: Record<string, unknown> = {}) {
    return {
        immobile_id: null,
        immobile_nome: null,
        conto_id: 9,
        conto_nome: 'Cura del giardino',
        tabella_id: 84,
        tabella_nome: 'Millesimi giardino',
        importo: 80000,
        ruolo_richiesto: null,
        motivo: 'tabella_senza_millesimi',
        ...overrides,
    };
}

/**
 * La quarta forma, aggiunta dalla beta.63: le tabelle collegate al capitolo non arrivano al 100%.
 *
 * Non riguarda né un'unità né una tabella — **manca una tabella**, e quale sia lo sa solo
 * l'amministratore. `immobile_id`, `tabella_id` e `ruolo_richiesto` arrivano tutti `null`.
 */
function coefficientiSottoIlCento(overrides: Record<string, unknown> = {}) {
    return {
        immobile_id: null,
        immobile_nome: null,
        conto_id: 11,
        conto_nome: 'Rifacimento lastrico solare',
        tabella_id: null,
        tabella_nome: null,
        importo: 600030,
        ruolo_richiesto: null,
        motivo: 'coefficienti_sotto_il_cento',
        ...overrides,
    };
}

function monta(scoperti: Record<string, unknown>[]) {
    return mount(ScopertoWarning, { props: { scoperti: scoperti as never } });
}

describe('la quota orfana continua a comportarsi come prima', () => {
    test('nomina l\'unità, mostra il ruolo atteso e porta alle anagrafiche', () => {
        const w = monta([quotaOrfana()]);

        expect(w.text()).toContain('Interno 3');
        expect(w.text()).toContain('inquilino');
        expect(w.html()).toContain('/admin/gestionale/:condominio/immobili/12');
    });
});

describe('il capitolo senza tabella', () => {
    test('non scrive mai «Immobile #», che era il testo prodotto da immobile_id nullo', () => {
        const w = monta([capitoloSenzaTabella()]);

        expect(w.text()).not.toContain('Immobile #');
    });

    test('nomina il capitolo e dice cosa fare, perché è la riga che toglie la telefonata', () => {
        const w = monta([capitoloSenzaTabella()]);

        expect(w.text()).toContain('Manutenzione idraulica');
        expect(w.text()).toContain('Nessuna tabella millesimale collegata al capitolo');
        expect(w.text()).toContain('Collega una tabella millesimale a questa voce di spesa');
    });

    test('non offre nessun collegamento, invece di offrirne uno che porta altrove', () => {
        const w = monta([capitoloSenzaTabella()]);

        // Il percorso per collegare una tabella a un capitolo richiede esercizio e piano dei
        // conti, che il payload non porta. Su un dato che non c'è non si indovina.
        expect(w.html()).not.toContain('/immobili/');
        expect(w.html()).not.toContain('piani-conti');
    });
});

describe('la tabella senza millesimi', () => {
    test('nomina la tabella e porta alla pagina dei suoi millesimi', () => {
        const w = monta([tabellaSenzaMillesimi()]);

        expect(w.text()).toContain('Millesimi giardino');
        expect(w.text()).toContain('Assegna gli immobili alla tabella e inserisci i millesimi');
        expect(w.html()).toContain('/admin/gestionale/:condominio/tabelle/84/quote');
    });

    test('se il nome della tabella non arriva, lo dice senza inventarlo', () => {
        const w = monta([tabellaSenzaMillesimi({ tabella_nome: null })]);

        expect(w.text()).toContain('La tabella collegata non ha millesimi utilizzabili');
        expect(w.text()).not.toContain('«»');
    });
});

describe('i coefficienti che non arrivano al cento', () => {
    test('dice che manca percentuale, non che mancano le anagrafiche', () => {
        // ⚠️ **È il difetto che questo blocco esiste per prendere.** Senza un ramo suo la forma
        // cadeva nel caso storico — quello della quota orfana — e la riga consigliava di
        // «censire le anagrafiche mancanti su questa unità»: un consiglio che non c'entra
        // niente, su un'unità che non esiste, che manda a cercare un difetto dove non c'è.
        const w = monta([coefficientiSottoIlCento()]);

        expect(w.text()).toContain('Le tabelle collegate al capitolo non arrivano al 100%');
        expect(w.text()).toContain('Collega la tabella che manca');
        expect(w.text()).not.toContain('Censisci le anagrafiche mancanti');
        expect(w.text()).not.toContain('Unità senza soggetto');
    });

    // ⚠️ **Solo il test qui sopra morde davvero.** Provato togliendo il ramo dal componente: il
    // resto passa lo stesso, perché il nome del capitolo è una colonna a sé e il caso storico
    // non costruisce comunque un collegamento su `immobile_id` nullo. Il test qui sotto resta
    // perché presidia comportamento visibile all'utente — nessun link morto, l'importo mostrato
    // e non un trattino — non perché provi il ramo.
    test('non offre un collegamento che porterebbe altrove, e mostra l\'importo scoperto', () => {
        const w = monta([coefficientiSottoIlCento()]);

        // `immobile_id` è nullo: un pulsante «Anagrafiche» punterebbe a `/immobili/null`.
        expect(w.html()).not.toContain('/immobili/null');
        expect(w.html()).not.toContain('/tabelle/null');

        // A differenza del millesimo non compilato, qui l'importo **è** calcolabile: è la fetta
        // che i coefficienti non dichiarano. Va mostrata, non sostituita da un trattino.
        expect(w.text()).toContain('6.000,30');
    });
});

describe('le tre forme insieme', () => {
    test('il totale le somma tutte e tre', () => {
        const w = monta([quotaOrfana(), capitoloSenzaTabella(), tabellaSenzaMillesimi()]);

        // € 900,00 + € 24.741,60 + € 800,00 = € 26.441,60
        expect(w.text()).toContain('26.441,60');
    });

    test('il titolo parla di importi non ripartibili, non di sole quote di unità', () => {
        const w = monta([capitoloSenzaTabella()]);

        // Con `immobile_id` nullo la vecchia formula («quota non assegnabile») descriveva
        // una cosa che non è: qui non c'è nessuna quota di nessuno.
        expect(w.text()).toContain('importo non ripartibile');
    });
});
