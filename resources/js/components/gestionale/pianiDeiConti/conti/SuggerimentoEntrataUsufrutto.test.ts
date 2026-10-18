// @vitest-environment jsdom

/**
 * Cosa resta scoperto: il testo in lingua (lo legge `Beta52UsufruttoSuggerimentoTest.php`); il resto dei modali; come il riquadro appare nel tema
 * scuro e alle larghezze del telefono (qui si prova che ha le classi del tema scuro, non che i colori leggono bene: si guarda a video).
 *
 * L'art. 984 c.c. nei tre punti dove si scrivono le percentuali di una voce: il modale della nuova voce, quello della modifica e quello
 * della tabella collegata. Il suggerimento compare solo con il tipo «Entrata» (una spesa non cambia, e non c'è nessun questionario),
 * sparisce se si torna a «Spesa», non c'è dove le percentuali non ci sono (un capitolo; una voce ripartita su più tabelle, per la quale
 * il testo sta nel modale della tabella collegata).
 *
 * Il testo nomina i campi «Proprietario %» e «Usufruttuario %»: dove compare il suggerimento ci sono anche quei campi, con quel nome. In tutti e tre
 * i modali le etichette sono le chiavi `labels` (`owner_percent`, `tenant_percent`, `usufructuary_percent`), che `lang/` traduce: qui `trans` risponde
 * con la chiave, quindi il test vede la chiave dove il modale in italiano scrive il testo, e le etichette in inglese e in portoghese combaciano con il
 * testo del suggerimento (`Beta52UsufruttoSuggerimentoTest.php` confronta il testo con le stesse chiavi, lingua per lingua).
 *
 * Il modale della tabella collegata chiama la percentuale della tabella «della voce»: vale per una spesa e per un'entrata.
 *
 * Si montano i componenti veri con il dialogo vero (il contenuto va in `document.body`, come in `ModalModificaConto.test.ts`); la
 * traduzione risponde con la chiave, quindi il test prova che il suggerimento è nella pagina e sotto quale chiave.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, reactive } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: (dati: Record<string, unknown>) =>
        reactive({ ...dati, errors: {}, processing: false, clearErrors: vi.fn(), setError: vi.fn(), reset: vi.fn(), put: vi.fn(), post: vi.fn(), transform() { return this; } }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
vi.mock('vue-select', () => ({ default: { name: 'VSelectFinto', template: '<div />' } }));
vi.mock('@/composables/useCapitoliConti', () => ({
    useCapitoliConti: () => ({ capitoli: { value: [] }, isLoading: { value: false }, fetchCapitoliConti: vi.fn(), reset: vi.fn() }),
}));
vi.mock('@/composables/useTabelle', () => ({
    useTabelle: () => ({ tabelle: { value: [] }, isLoading: { value: false }, fetchTabelle: vi.fn() }),
}));

const CHIAVE = 'gestionale.list_pages.piani_conti.show.new_entry_modal.help.income_usufruct';
const ETICHETTE = 'gestionale.list_pages.piani_conti.show.new_entry_modal.labels.';

const SuggerimentoEntrataUsufrutto = (await import('./SuggerimentoEntrataUsufrutto.vue')).default;
const ModalNuovoConto = (await import('./ModalNuovoConto.vue')).default;
const ModalModificaConto = (await import('./ModalModificaConto.vue')).default;
const ModalAssociaTabella = (await import('./ModalAssociaTabella.vue')).default;

/** Il testo del dialogo: il contenuto è teletrasportato in `document.body`. */
const testoDelDialogo = () => document.body.textContent ?? '';

async function lasciaAssestare() {
    await nextTick();
    await new Promise((r) => setTimeout(r, 0));
}

async function scegliTipo(tipo: 'spesa' | 'entrata') {
    const radio = document.querySelector(`input#${tipo}`) as HTMLInputElement;
    radio.click();
    await lasciaAssestare();
}

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('SuggerimentoEntrataUsufrutto — il riquadro', () => {
    test('è una nota (`role="note"`), non un avviso: un lettore di schermo non lo annuncia come urgente', () => {
        const riquadro = mount(SuggerimentoEntrataUsufrutto).find('div');

        expect(riquadro.attributes('role')).toBe('note');
        expect(riquadro.text()).toContain(CHIAVE);
    });

    test('ha le classi del tema scuro per il bordo, lo sfondo e il testo (come appare nel tema scuro si guarda a video)', () => {
        const classi = mount(SuggerimentoEntrataUsufrutto).find('div').attributes('class') ?? '';

        expect(classi).toMatch(/\bdark:border-/);
        expect(classi).toMatch(/\bdark:bg-/);
        expect(classi).toMatch(/\bdark:text-/);
    });
});

describe('ModalNuovoConto — il suggerimento sull\'art. 984 c.c.', () => {
    const monta = () =>
        mount(ModalNuovoConto, { props: { show: true, condominioId: 1, esercizioId: 1, pianoContoId: 1, fornitori: [] }, attachTo: document.body });

    test('con «Spesa» non c\'è; scegliendo «Entrata» compare; tornando a «Spesa» sparisce', async () => {
        monta();
        await lasciaAssestare();
        expect(testoDelDialogo()).not.toContain(CHIAVE);

        await scegliTipo('entrata');
        expect(testoDelDialogo()).toContain(CHIAVE);

        await scegliTipo('spesa');
        expect(testoDelDialogo()).not.toContain(CHIAVE);
    });

    test('dove compare il suggerimento ci sono i campi che il testo nomina, con le etichette tradotte (`owner_percent`, `tenant_percent`, `usufructuary_percent`)', async () => {
        monta();
        await lasciaAssestare();
        await scegliTipo('entrata');

        expect(testoDelDialogo()).toContain(CHIAVE);
        expect(testoDelDialogo()).toContain(ETICHETTE + 'owner_percent');
        expect(testoDelDialogo()).toContain(ETICHETTE + 'tenant_percent');
        expect(testoDelDialogo()).toContain(ETICHETTE + 'usufructuary_percent');
        // Nessuna etichetta in italiano scritta a mano: in inglese o in portoghese il testo e i campi direbbero cose diverse.
        expect(testoDelDialogo()).not.toContain('Proprietario %');
        expect(testoDelDialogo()).not.toContain('Usufruttuario %');
    });

    test('controprova — per un capitolo le percentuali non ci sono, e il suggerimento nemmeno', async () => {
        monta();
        await lasciaAssestare();
        await scegliTipo('entrata');
        (document.querySelector('button#isCapitolo') as HTMLButtonElement).click();
        await lasciaAssestare();

        expect(testoDelDialogo()).not.toContain(ETICHETTE + 'owner_percent');
        expect(testoDelDialogo()).not.toContain(CHIAVE);
    });
});

describe('ModalModificaConto — il suggerimento sull\'art. 984 c.c.', () => {
    const voce = (extra: Record<string, unknown> = {}) => ({
        id: 7, nome: 'Affitto antenna', tipo: 'entrata', parent_id: null, is_capitolo: false, importo: '€ 240,00', importo_raw: 24000, budget_originale_raw: 24000, speso_raw: 0,
        tabelle_millesimali: [{ tabella_id: 3, ripartizioni: [{ soggetto: 'proprietario', percentuale: 100 }] }], sottoconti: [], ...extra,
    });
    const monta = (conto: Record<string, unknown>) =>
        mount(ModalModificaConto, { props: { show: true, conto: conto as never, condominioId: 1, esercizioId: 1, pianoContoId: 1, tabelle: [], fornitori: [] }, attachTo: document.body });

    test('una voce di entrata ha il suggerimento; una di spesa no', async () => {
        monta(voce());
        await lasciaAssestare();
        expect(testoDelDialogo()).toContain(CHIAVE);

        document.body.innerHTML = '';
        monta(voce({ tipo: 'spesa' }));
        await lasciaAssestare();
        expect(testoDelDialogo()).not.toContain(CHIAVE);
    });

    test('dove compare il suggerimento ci sono i campi che il testo nomina (le etichette `owner_percent` e `usufructuary_percent`)', async () => {
        monta(voce());
        await lasciaAssestare();

        expect(testoDelDialogo()).toContain(CHIAVE);
        expect(testoDelDialogo()).toContain(ETICHETTE + 'owner_percent');
        expect(testoDelDialogo()).toContain(ETICHETTE + 'usufructuary_percent');
    });

    test('cambiando il tipo nel modale il suggerimento segue', async () => {
        monta(voce());
        await lasciaAssestare();
        await scegliTipo('spesa');
        expect(testoDelDialogo()).not.toContain(CHIAVE);

        await scegliTipo('entrata');
        expect(testoDelDialogo()).toContain(CHIAVE);
    });

    test('controprova — una voce ripartita su più tabelle non ha le percentuali qui: il suggerimento sta nel modale della tabella collegata', async () => {
        monta(voce({ tabelle_millesimali: [{ tabella_id: 3, ripartizioni: [] }, { tabella_id: 4, ripartizioni: [] }] }));
        await lasciaAssestare();

        expect(testoDelDialogo()).toContain('Questa voce è ripartita su 2 tabelle millesimali');
        expect(testoDelDialogo()).not.toContain(CHIAVE);
    });

    test('controprova — un capitolo di entrata non ha percentuali, quindi nemmeno il suggerimento', async () => {
        monta(voce({ is_capitolo: true, tabelle_millesimali: [] }));
        await lasciaAssestare();

        expect(testoDelDialogo()).not.toContain(CHIAVE);
    });
});

describe('ModalAssociaTabella — il suggerimento sull\'art. 984 c.c.', () => {
    const monta = (conto: Record<string, unknown> | null, extra: Record<string, unknown> = {}) =>
        mount(ModalAssociaTabella, { props: { show: true, conto: conto as never, condominioId: 1, residuoDisponibile: 100, tabelleGiaAssociateIds: [], ...extra }, attachTo: document.body });

    test('su una voce di entrata compare, in creazione e in modifica dell\'associazione; su una voce di spesa no', async () => {
        monta({ id: 7, nome: 'Affitto antenna', tipo: 'entrata' });
        await lasciaAssestare();
        expect(testoDelDialogo()).toContain(CHIAVE);

        document.body.innerHTML = '';
        monta({ id: 7, nome: 'Affitto antenna', tipo: 'entrata' }, { tabellaEsistente: { id: 3, nome: 'Proprietà', coefficiente: 100, ripartizioni: [{ soggetto: 'proprietario', percentuale: 100 }] } });
        await lasciaAssestare();
        expect(testoDelDialogo()).toContain(CHIAVE);

        document.body.innerHTML = '';
        monta({ id: 8, nome: 'Spese generali', tipo: 'spesa' });
        await lasciaAssestare();
        expect(testoDelDialogo()).not.toContain(CHIAVE);
    });

    test('dove compare il suggerimento ci sono i campi che il testo nomina, con le etichette tradotte (`owner_percent`, `tenant_percent`, `usufructuary_percent`)', async () => {
        monta({ id: 7, nome: 'Affitto antenna', tipo: 'entrata' });
        await lasciaAssestare();

        expect(testoDelDialogo()).toContain(CHIAVE);
        expect(testoDelDialogo()).toContain(ETICHETTE + 'owner_percent');
        expect(testoDelDialogo()).toContain(ETICHETTE + 'tenant_percent');
        expect(testoDelDialogo()).toContain(ETICHETTE + 'usufructuary_percent');
        expect(testoDelDialogo()).not.toContain('Proprietario %');
        expect(testoDelDialogo()).not.toContain('Usufruttuario %');
    });

    test('la percentuale della tabella è «della voce», per una spesa come per un\'entrata', async () => {
        monta({ id: 7, nome: 'Affitto antenna', tipo: 'entrata' });
        await lasciaAssestare();
        expect(testoDelDialogo()).toContain('Percentuale della voce attribuita a questa tabella');
        expect(testoDelDialogo()).not.toContain('Percentuale della spesa');

        document.body.innerHTML = '';
        monta({ id: 8, nome: 'Spese generali', tipo: 'spesa' });
        await lasciaAssestare();
        expect(testoDelDialogo()).toContain('Percentuale della voce attribuita a questa tabella');
    });
});
