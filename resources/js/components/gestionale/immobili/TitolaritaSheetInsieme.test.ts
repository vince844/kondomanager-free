// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * 1.12.0-beta.14, tappa 7 (decisione 705) — lo storico dell'unità con i passaggi registrati insieme.
 *
 * Due persone che escono con lo stesso atto sono due passaggi nel registro, ma nello storico una scheda sola: il tipo, la data, «Cecilia Angeli (50 %) e Marta
 * Acciaioli (50 %) → Luca Toscani (100 %)», il badge «2 passaggi insieme», e sotto una riga per persona con il suo conguaglio e il suo «Annulla il conguaglio»
 * (Cecilia e Luca possono aver regolato fra loro, Marta no). Il passaggio si annulla tutto insieme, con un motivo solo: «Annulla i passaggi» apre la finestra
 * che dice che si annullano insieme, l'ultimo per primo, e che se uno non si può annullare non se ne annulla nessuno. La forma dei dati è quella del §1.9
 * di `tappa7_progetto.md`: i campi di oggi restano sul primo, e `insieme.passaggi` ha una voce per persona (`id`, `uscente`, `quota`, `conguaglio`). Se il server
 * dice che l'insieme non si annulla (`annullabile.si` falso), il perché si legge con un clic, come per un passaggio solo.
 *
 * Il passaggio da solo resta com'era (la scheda, «Annulla il passaggio…»).
 *
 * Cosa NON copre: il server (`Beta14PiuVenditoriAnnullamentoTest`); l'invio vero (si guarda la richiesta a `router.delete`).
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import TitolaritaSheet from './TitolaritaSheet.vue';

(globalThis as any).route = (n: string, p?: Record<string, unknown>) => `/${n}?${new URLSearchParams(Object.entries(p ?? {}).map(([k, v]) => [k, String(v)])).toString()}`;

const conguaglio = (extra: Record<string, unknown> = {}) => ({
    stato: 'proposto', importo: 2049, importo_formattato: '€ 20,49', applicato: false, nota: null, nota_annullamento: null, annullato_il: null, ...extra,
});

/** Il passaggio di Cecilia (id 7) e quello di Marta (id 8), registrati insieme a favore di Luca Toscani. */
function insieme(extra: Record<string, unknown> = {}): any {
    return {
        id: 7, tipo_passaggio: 'vendita', sottotipo: null, decorrenza: '2026-11-16', decorrenza_a_parole: '16 novembre 2026', registrato_il: '23 novembre 2026',
        uscente: 'Cecilia Angeli', entrante: 'Luca Toscani', estremi_titolo: 'Rogito del notaio Verdi', copia_autentica_il: null, copia_autentica_a_parole: null,
        copia_autentica_attesa: false, documento_url: null, pertinenze: [],
        conguaglio: conguaglio(),
        obbligati: ['Luca Toscani risponde in solido con Cecilia Angeli e Marta Acciaioli per i contributi dell\'esercizio 2026 e 2025.'], ordinaria: null, nota: null,
        annullato: false, annullato_il: null, annullato_da: null, nota_annullamento: null,
        annullabile: { si: true, motivo: null, avvisi: [], effetti: ['Cecilia Angeli e Marta Acciaioli tornano titolari al 50 % senza data di fine; Luca Toscani esce dall\'unità.', 'Si tolgono le 4 righe di conguaglio in saldi.'] },
        insieme: {
            passaggi: [
                { id: 7, uscente: 'Cecilia Angeli', quota: 50, conguaglio: conguaglio() },
                { id: 8, uscente: 'Marta Acciaioli', quota: 50, conguaglio: conguaglio() },
            ],
        },
        ...extra,
    };
}

const solo: any = () => ({ ...insieme(), insieme: null, uscente: 'Venditore Ugo', entrante: 'Acquirente Elsa' });

const passa = { template: '<div><slot /></div>' };

function monta(p: any, storico: Record<string, unknown> = {}) {
    return mount(TitolaritaSheet, {
        props: {
            open: true, unita: 'Int. 1A', condominioId: 18, immobileId: 24,
            storico: { passaggi: 1, periodi_chiusi: 0, righe: [], gruppi: [{ diritto: 'Proprietà', righe: [] }], subentri: [p], ...storico },
        },
        global: {
            stubs: {
                Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa,
                Accordion: passa, AccordionItem: passa, AccordionTrigger: passa, AccordionContent: passa,
                // La finestra si legge solo quando è aperta.
                Dialog: { props: ['open'], template: '<div v-if="open"><slot /></div>' }, DialogContent: passa, DialogHeader: passa, DialogTitle: passa, DialogDescription: passa, DialogFooter: passa,
                BadgeRuolo: true,
                Input: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
            },
        },
    });
}

const testo = (w: ReturnType<typeof monta>) => w.text().replace(/\s+/g, ' ').replace(/ /g, ' ');
const bottone = (w: ReturnType<typeof monta>, t: string) => w.findAll('button').find((b) => b.text().replace(/\s+/g, ' ') === t);

describe('TitolaritaSheet — i passaggi registrati insieme (tappa 7)', () => {
    test('una scheda sola, con il badge «2 passaggi insieme» e le parti con le quote', () => {
        const w = monta(insieme());

        expect(w.findAll('ol > li')).toHaveLength(1);
        expect(w.find('[data-testid="badge-insieme"]').text()).toBe('2 passaggi insieme');
        expect(w.find('[data-testid="badge-insieme"]').classes()).toContain('rounded-md');
        expect(testo(w)).toContain('Vendita o donazione · dal 16 novembre 2026');
        expect(testo(w)).toContain('Cecilia Angeli (50 %) e Marta Acciaioli (50 %) → Luca Toscani (100 %) · Rogito del notaio Verdi');
        expect(testo(w)).toContain('registrato il 23 novembre 2026');
    });

    test('una riga per persona con il suo conguaglio, e «Chi resta obbligato» una volta sola', () => {
        const w = monta(insieme());
        const righe = w.findAll('[data-testid="conguaglio-persona"]');

        expect(righe).toHaveLength(2);
        expect(righe[0].text().replace(/\s+/g, ' ')).toContain('Conguaglio di Cecilia Angeli: € 20,49 a suo credito, a debito di Luca Toscani');
        expect(righe[1].text().replace(/\s+/g, ' ')).toContain('Conguaglio di Marta Acciaioli: € 20,49 a suo credito, a debito di Luca Toscani');
        expect(testo(w).split('Chi resta obbligato')).toHaveLength(2);
    });

    test('un conguaglio che si rovescia, regolato fra le parti, non scritto o annullato si legge per persona', () => {
        const w = monta(insieme({ insieme: { passaggi: [
            { id: 7, uscente: 'Cecilia Angeli', quota: 50, conguaglio: conguaglio({ importo: -548, importo_formattato: '€ 5,48' }) },
            { id: 8, uscente: 'Marta Acciaioli', quota: 50, conguaglio: conguaglio({ stato: 'rinunciato', nota: 'regolato nel rogito' }) },
        ] } }));

        expect(testo(w)).toContain('Conguaglio di Cecilia Angeli: € 5,48 a suo debito, a credito di Luca Toscani');
        expect(testo(w)).toContain('Conguaglio di Marta Acciaioli: regolato fra le parti («regolato nel rogito»)');
    });

    test('«Annulla il conguaglio» è di ciascuna: apre la nota, e la richiesta è per il passaggio di quella persona', async () => {
        const spia = vi.spyOn(router, 'delete').mockImplementation((() => {}) as any);
        const w = monta(insieme());
        const apri = w.findAll('[data-testid="conguaglio-persona"]')[1].findAll('button').find((b) => b.text().startsWith('Annulla il conguaglio'))!;
        await apri.trigger('click');
        await w.findAll('input').find((i) => (i.attributes('placeholder') ?? '').startsWith('Come hanno regolato'))!.setValue('Marta ha regolato nel rogito');
        await w.find('form').trigger('submit');

        expect(spia).toHaveBeenCalledOnce();
        expect(String(spia.mock.calls[0][0])).toContain('subentro=8');
        expect(String(spia.mock.calls[0][0])).toContain('annulla-conguaglio');
        // Quello di Cecilia resta com'è: il suo comando non si è aperto.
        expect(w.findAll('form')).toHaveLength(1);
        spia.mockRestore();
    });

    test('un conguaglio già assorbito da un piano non ha il comando', () => {
        const w = monta(insieme({ insieme: { passaggi: [
            { id: 7, uscente: 'Cecilia Angeli', quota: 50, conguaglio: conguaglio({ applicato: true }) },
            { id: 8, uscente: 'Marta Acciaioli', quota: 50, conguaglio: conguaglio() },
        ] } }));
        const righe = w.findAll('[data-testid="conguaglio-persona"]');

        expect(righe[0].findAll('button')).toHaveLength(0);
        expect(righe[1].findAll('button')).toHaveLength(1);
    });

    test('«Annulla i passaggi» apre la finestra: si annullano insieme, l\'ultimo registrato per primo, e il riepilogo dice quanti', async () => {
        const w = monta(insieme());

        expect(testo(w)).not.toContain('Annulla i 2 passaggi registrati insieme');
        expect(bottone(w, 'Annulla il passaggio…')).toBeUndefined();
        await bottone(w, 'Annulla i passaggi')!.trigger('click');

        expect(testo(w)).toContain('Annulla i 2 passaggi registrati insieme');
        expect(testo(w)).toContain('Cecilia Angeli e Marta Acciaioli tornano titolari al 50 % senza data di fine; Luca Toscani esce dall\'unità.');
        expect(testo(w)).toContain('Si annullano insieme, l\'ultimo registrato per primo. Se uno non si può annullare, non se ne annulla nessuno, e il messaggio dice di chi e perché.');
        expect(w.find('[data-testid="riepilogo-annulla"]').text().replace(/\s+/g, ' ')).toBe('Si annullano 2 passaggi.');
        expect(bottone(w, 'Chiudi')).toBeDefined();
    });

    test('la finestra chiede il motivo (dieci caratteri), e la richiesta annulla l\'insieme dal primo, con il motivo', async () => {
        const spia = vi.spyOn(router, 'delete').mockImplementation((() => {}) as any);
        const w = monta(insieme());
        await bottone(w, 'Annulla i passaggi')!.trigger('click');
        const conferma = () => w.findAll('button').filter((b) => b.text() === 'Annulla i passaggi' && b.attributes('type') === 'submit')[0];

        expect(conferma().attributes('disabled')).toBeDefined();
        const campo = w.findAll('input').find((i) => (i.attributes('placeholder') ?? '').startsWith('Perché annulli'))!;
        await campo.setValue('corto');
        expect(conferma().attributes('disabled')).toBeDefined();
        await campo.setValue('Atto registrato con la data sbagliata: lo rifaccio.');
        expect(conferma().attributes('disabled')).toBeUndefined();
        await w.find('form[data-testid="form-annulla-insieme"]').trigger('submit');

        expect(spia).toHaveBeenCalledOnce();
        expect(String(spia.mock.calls[0][0])).toContain('subentro=7');
        expect(String(spia.mock.calls[0][0])).toContain('annulla');
        expect(String(spia.mock.calls[0][0])).not.toContain('annulla-conguaglio');
        spia.mockRestore();
    });

    test('un rifiuto del server («La parte di Marta Acciaioli: …») resta nella finestra', async () => {
        let opzioni: Record<string, any> = {};
        const spia = vi.spyOn(router, 'delete').mockImplementation(((_u: string, o: Record<string, any>) => { opzioni = o; }) as any);
        const w = monta(insieme());
        await bottone(w, 'Annulla i passaggi')!.trigger('click');
        await w.findAll('input').find((i) => (i.attributes('placeholder') ?? '').startsWith('Perché annulli'))!.setValue('Atto registrato con la data sbagliata');
        await w.find('form[data-testid="form-annulla-insieme"]').trigger('submit');
        opzioni.onError({ passaggio: 'La parte di Marta Acciaioli: un piano ha già assorbito il suo conguaglio.' });
        await w.vm.$nextTick();

        expect(testo(w)).toContain('Annulla i 2 passaggi registrati insieme');
        expect(testo(w)).toContain('La parte di Marta Acciaioli: un piano ha già assorbito il suo conguaglio.');
        spia.mockRestore();
    });

    test('se l\'insieme non si annulla, niente finestra: il perché si legge con un clic', async () => {
        const motivo = 'Dopo questi, su Int. 1A, c\'è il passaggio del 1 dicembre 2026 a Sara Neri: si annulla prima quello.';
        const w = monta(insieme({ annullabile: { si: false, motivo, avvisi: [], effetti: [] } }));

        expect(bottone(w, 'Annulla i passaggi')).toBeUndefined();
        expect(testo(w)).not.toContain(motivo);
        await w.findAll('button').find((b) => b.text().includes('non consentito'))!.trigger('click');
        expect(testo(w)).toContain(motivo);
        expect(testo(w)).toContain('Annulla i passaggi — non consentito: perché?');
    });

    test('annullato insieme: resta in elenco, annullato, senza comandi', () => {
        const w = monta(insieme({ annullato: true, annullato_il: '24 novembre 2026', annullato_da: 'Amministratrice Ada', nota_annullamento: 'Data sbagliata', annullabile: { si: false, motivo: null, avvisi: [], effetti: [] } }));

        expect(testo(w)).toContain('Annullato il 24 novembre 2026 da Amministratrice Ada: «Data sbagliata».');
        expect(testo(w)).toContain('2 passaggi insieme');
        expect(testo(w)).not.toContain('Annulla i passaggi');
        expect(testo(w)).not.toContain('Annulla il conguaglio');
    });

    test('chi entra con la sua quota dopo l\'atto (`quota_entrante`), non con la somma delle parti: i tre fratelli, Tina passa dal 33,33 % al 100 %', () => {
        const w = monta(insieme({ entrante: 'Tina Angeli', insieme: { quota_entrante: 100, passaggi: [
            { id: 7, uscente: 'Cecilia Angeli', quota: 33.34, conguaglio: conguaglio(), fermate: [] },
            { id: 8, uscente: 'Marta Acciaioli', quota: 33.33, conguaglio: conguaglio(), fermate: [] },
        ] } }));

        expect(testo(w)).toContain('Cecilia Angeli (33,34 %) e Marta Acciaioli (33,33 %) → Tina Angeli (100 %)');
        expect(testo(w)).not.toContain('Tina Angeli (66,67 %)');
    });

    test('senza `quota_entrante` (uno storico di prima) chi entra ha la somma delle parti', () => {
        const w = monta(insieme());

        expect(testo(w)).toContain('Cecilia Angeli (50 %) e Marta Acciaioli (50 %) → Luca Toscani (100 %)');
    });

    test('le fermate dopo «Scrivi» si leggono nella riga di ciascuna persona, una volta sola', () => {
        const fermataUgo = 'Il conguaglio scritto alla fine locazione di Ines del 24 gennaio 2026 non è passato con questo passaggio: € 142,94 dei giorni dal 1 aprile 2026 sono rimasti a carico di Ugo.';
        const fermataAnna = 'Il conguaglio scritto alla fine locazione di Ines del 24 gennaio 2026 non è passato con questo passaggio: € 142,93 dei giorni dal 1 aprile 2026 sono rimasti a carico di Anna.';
        const w = monta(insieme({ fermate: [], insieme: { quota_entrante: 100, passaggi: [
            { id: 7, uscente: 'Cecilia Angeli', quota: 50, conguaglio: conguaglio(), fermate: [fermataUgo] },
            { id: 8, uscente: 'Marta Acciaioli', quota: 50, conguaglio: conguaglio(), fermate: [fermataAnna] },
        ] } }));
        const righe = w.findAll('[data-testid="conguaglio-persona"]');

        expect(righe[0].text().replace(/\s+/g, ' ')).toContain('€ 142,94 dei giorni dal 1 aprile 2026 sono rimasti a carico di Ugo.');
        expect(righe[1].text().replace(/\s+/g, ' ')).toContain('€ 142,93 dei giorni dal 1 aprile 2026 sono rimasti a carico di Anna.');
        expect(testo(w).split('€ 142,94 dei giorni')).toHaveLength(2);
        expect(testo(w).split('€ 142,93 dei giorni')).toHaveLength(2);
    });

    test('il passaggio da solo resta com\'era: nessun badge, «Annulla il passaggio…», il conguaglio sulla riga di sempre', () => {
        const w = monta(solo());

        expect(w.find('[data-testid="badge-insieme"]').exists()).toBe(false);
        expect(w.find('[data-testid="conguaglio-persona"]').exists()).toBe(false);
        expect(bottone(w, 'Annulla il passaggio…')).toBeDefined();
        expect(testo(w)).toContain('Venditore Ugo → Acquirente Elsa');
        expect(testo(w)).toContain('Conguaglio scritto nei saldi');
    });
});

/**
 * Coda 786: la testa dello storico conta i passaggi, non le schede. Un insieme di due persone è una scheda e due passaggi: «Un passaggio registrato.» sopra
 * una scheda che dice «2 passaggi insieme» e che la registrazione aveva chiamato «2 passaggi registrati insieme» non quadrava.
 */
describe('TitolaritaSheet — la testa dello storico con i passaggi registrati insieme (Coda 786)', () => {
    const sottotitolo = (w: ReturnType<typeof monta>) => testo(w).split('Int. 1A · ')[1].split('Passaggi registrati')[0].split('Chi ha')[0].trim();
    const insiemeDi3 = (extra: Record<string, unknown> = {}) => insieme({ insieme: { passaggi: [
        { id: 7, uscente: 'Cecilia Angeli', quota: 30, conguaglio: conguaglio() },
        { id: 8, uscente: 'Marta Acciaioli', quota: 30, conguaglio: conguaglio() },
        { id: 9, uscente: 'Tina Angeli', quota: 40, conguaglio: conguaglio() },
    ] }, ...extra });

    test('un insieme di due persone: «2 passaggi registrati insieme.»', () => {
        expect(sottotitolo(monta(insieme()))).toContain('2 passaggi registrati insieme.');
        expect(sottotitolo(monta(insieme()))).not.toContain('Un passaggio registrato');
    });

    test('un insieme di tre persone: «3 passaggi registrati insieme.»', () => {
        expect(sottotitolo(monta(insiemeDi3()))).toContain('3 passaggi registrati insieme.');
    });

    test('passaggi da soli e un insieme: «3 passaggi registrati, 2 insieme.»', () => {
        const altro = { ...solo(), id: 3 };
        const w = monta(insieme(), { subentri: [insieme(), altro] });

        expect(sottotitolo(w)).toContain('3 passaggi registrati, 2 insieme.');
    });

    test('un passaggio da solo resta «Un passaggio registrato.», e più passaggi da soli «N passaggi registrati.»', () => {
        expect(sottotitolo(monta(solo()))).toContain('Un passaggio registrato.');
        expect(sottotitolo(monta(solo(), { subentri: [solo(), { ...solo(), id: 3 }] }))).toContain('2 passaggi registrati.');
    });

    test('un insieme annullato si conta per i suoi passaggi: «2 annullati insieme, qui sotto.»', () => {
        const annullato = insieme({ annullato: true, annullato_il: '24 novembre 2026', annullato_da: 'Amministratrice Ada', nota_annullamento: 'Data sbagliata' });
        const w = monta(annullato, { subentri: [annullato, { ...solo(), id: 3 }] });

        expect(sottotitolo(w)).toContain('Un passaggio registrato. 2 annullati insieme, qui sotto.');
    });

    test('un insieme annullato e nient\'altro: «Nessun passaggio in vigore. 2 annullati insieme, qui sotto.»', () => {
        const annullato = insieme({ annullato: true, annullato_il: '24 novembre 2026', annullato_da: 'Amministratrice Ada', nota_annullamento: 'Data sbagliata' });

        expect(sottotitolo(monta(annullato))).toContain('Nessun passaggio in vigore. 2 annullati insieme, qui sotto.');
    });

    test('annullati da soli e un insieme annullato: «3 annullati, 2 insieme, qui sotto.»', () => {
        const annullato = insieme({ annullato: true, annullato_il: '24 novembre 2026', annullato_da: 'Amministratrice Ada', nota_annullamento: 'Data sbagliata' });
        const w = monta(annullato, { subentri: [annullato, { ...solo(), id: 3, annullato: true, annullato_il: '24 novembre 2026', annullato_da: 'Ada', nota_annullamento: 'Errore' }] });

        expect(sottotitolo(w)).toContain('Nessun passaggio in vigore. 3 annullati, 2 insieme, qui sotto.');
    });
});
