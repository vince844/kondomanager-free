// @vitest-environment jsdom

/**
 * «Chi ha avuto questa unità» — l'annullamento di un passaggio (1.11.0-beta.37, decisione 27).
 *
 * Il server dice se un passaggio si annulla e perché no (`annullabile`, la stessa regola di
 * `AnnullaPassaggioAction::motivoBlocco`); il pannello non ricalcola niente. Tre stati: annullabile (il comando, la nota
 * obbligatoria, gli avvisi prima della conferma), non annullabile (il perché si legge con un clic: un comando che sparisce
 * senza spiegazioni è la lezione della beta.35), annullato (resta in elenco, senza comandi).
 *
 * **Cosa resta scoperto**: l'invio vero della richiesta (lo prova `AnnullaPassaggioTest` lato server) e lo scorrimento
 * del pannello laterale, che qui è uno stub.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import TitolaritaSheet from './TitolaritaSheet.vue';

(globalThis as any).route = (n: string) => `/${n}`;

// `any`: il pannello riceve un `PassaggioRegistrato`, qui costruito per pezzi.
function passaggio(extra: Record<string, unknown> = {}): any {
    return {
        id: 7, tipo_passaggio: 'vendita', sottotipo: null, decorrenza: '2026-05-01', decorrenza_a_parole: '1 maggio 2026', registrato_il: '28 settembre 2026',
        uscente: 'Venditore Ugo', entrante: 'Acquirente Elsa', estremi_titolo: null, copia_autentica_il: null, copia_autentica_a_parole: null,
        copia_autentica_attesa: false, documento_url: null, pertinenze: [],
        conguaglio: { stato: 'proposto', importo: 548, importo_formattato: '€ 5,48', applicato: false, nota: null, nota_annullamento: null, annullato_il: null },
        obbligati: ['Venditore Ugo resta obbligato per le rate già emesse.'], nota: null,
        annullato: false, annullato_il: null, annullato_da: null, nota_annullamento: null,
        annullabile: { si: true, motivo: null, avvisi: [], effetti: ['Le righe di titolarità scritte dal passaggio tornano come prima.'] },
        ...extra,
    };
}

function monta(p: any, storico: Record<string, unknown> = {}) {
    return mount(TitolaritaSheet, {
        props: {
            open: true, unita: 'Interno 1', condominioId: 18, immobileId: 24,
            storico: { passaggi: 1, periodi_chiusi: 0, righe: [], gruppi: [{ diritto: 'Proprietà', righe: [] }], subentri: [p], ...storico },
        },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
                Accordion: { template: '<div><slot /></div>' }, AccordionItem: { template: '<div><slot /></div>' },
                AccordionTrigger: { template: '<div><slot /></div>' }, AccordionContent: { template: '<div><slot /></div>' },
                BadgeRuolo: true,
                Input: { props: ['modelValue'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
}

describe('TitolaritaSheet — annullare un passaggio', () => {
    test('annullabile: il comando apre la nota, gli avvisi si leggono prima, e senza dieci caratteri non si conferma', async () => {
        const w = monta(passaggio({ annullabile: { si: true, motivo: null, avvisi: ['Il piano «Conguaglio luglio» è stato generato o ricalcolato dopo il passaggio: ricalcolalo di nuovo.'], effetti: ['Le righe di titolarità scritte dal passaggio tornano come prima.', 'Le quote di 8 rate passate a Acquirente Elsa tornano a Venditore Ugo, con le regole di prima.'] } }));
        const apri = w.findAll('button').find((b) => b.text() === 'Annulla il passaggio…');
        expect(apri).toBeDefined();
        await apri!.trigger('click');

        expect(w.text()).toContain('Il piano «Conguaglio luglio» è stato generato o ricalcolato dopo il passaggio');
        // Fase 1-bis A13: che cosa torna come prima lo dice il server, passaggio per passaggio; niente frase fissa.
        expect(w.text()).toContain('Le quote di 8 rate passate a Acquirente Elsa tornano a Venditore Ugo');
        expect(w.text()).not.toContain('il conguaglio tornano come prima');
        const conferma = () => w.findAll('button').find((b) => b.text().includes('Annulla il passaggio') && b.attributes('type') === 'submit')!;
        expect(conferma().attributes('disabled')).toBeDefined();

        const input = w.findAll('input').find((i) => (i.attributes('placeholder') ?? '').startsWith('Perché lo annulli'))!;
        await input.setValue('corto');
        expect(conferma().attributes('disabled')).toBeDefined();
        await input.setValue('Data del rogito sbagliata');
        expect(conferma().attributes('disabled')).toBeUndefined();
    });

    test('non annullabile: niente comando, e il perché si legge con un clic', async () => {
        const motivo = 'Dopo questo, su Interno 1, c\'è il passaggio del 1 settembre 2026 a Compratrice Bice: si annulla prima quello, poi questo.';
        const w = monta(passaggio({ annullabile: { si: false, motivo, avvisi: [] } }));
        expect(w.findAll('button').some((b) => b.text() === 'Annulla il passaggio…')).toBe(false);
        expect(w.text()).not.toContain(motivo);

        await w.findAll('button').find((b) => b.text().includes('non consentito'))!.trigger('click');
        expect(w.text()).toContain(motivo);
    });

    test('annullato: resta in elenco con la data, chi l\'ha annullato e la nota, senza comandi né vademecum, e non conta fra i passaggi registrati', () => {
        const w = monta(passaggio({ annullato: true, annullato_il: '28 settembre 2026', annullato_da: 'Amministratrice Ada', nota_annullamento: 'Data del rogito sbagliata', annullabile: { si: false, motivo: null, avvisi: [], effetti: [] } }));
        const testo = w.text();
        expect(testo).toContain('Annullato il 28 settembre 2026 da Amministratrice Ada: «Data del rogito sbagliata».');
        // Fase 1-bis A13 e A17: nessuna promessa fissa su rate e conguaglio, e il sottotitolo non nega il passaggio in elenco.
        expect(testo).not.toContain('sono tornati come prima');
        expect(testo).toContain('Nessun passaggio in vigore. Uno annullato, qui sotto.');
        expect(testo).not.toContain('Nessun passaggio registrato');
        expect(testo).not.toContain('Annulla il passaggio');
        expect(testo).not.toContain('annulla il conguaglio');
        expect(testo).not.toContain('Chi resta obbligato');
        expect(testo).not.toContain('Un passaggio registrato.');
    });

    test('unità rimasta senza titolari (un inizio locazione annullato): il passaggio annullato si vede, e niente dettaglio tecnico vuoto', () => {
        const w = monta(passaggio({ annullato: true, annullato_il: '28 settembre 2026', annullato_da: 'Amministratrice Ada', nota_annullamento: 'Data del rogito sbagliata', annullabile: { si: false, motivo: null, avvisi: [], effetti: [] } }),
            { passaggi: 0, gruppi: [], righe: [] });
        const testo = w.text();
        expect(testo).toContain('Nessun titolare registrato su questa unità.');
        expect(testo).toContain('Annullato il 28 settembre 2026 da Amministratrice Ada: «Data del rogito sbagliata».');
        expect(testo).not.toContain('Dettaglio tecnico');
    });

    test('rifiutato dal server perché lo stato è cambiato: lo storico ricaricato dice «non consentito», e il perché si apre da solo', async () => {
        let opzioni: Record<string, any> = {};
        const spia = vi.spyOn(router, 'delete').mockImplementation(((_url: string, o: Record<string, any>) => { opzioni = o; }) as any);
        const motivo = 'Dopo il passaggio è stata emessa a Acquirente Elsa la rata 5 del piano «Preventivo 2026».';
        const w = monta(passaggio());
        await w.findAll('button').find((b) => b.text() === 'Annulla il passaggio…')!.trigger('click');
        await w.findAll('input').find((i) => (i.attributes('placeholder') ?? '').startsWith('Perché lo annulli'))!.setValue('Data del rogito sbagliata');
        await w.find('form').trigger('submit');
        expect(spia).toHaveBeenCalledOnce();

        // Il redirect del 422 ricarica le props prima di onError (Inertia): il passaggio non è più annullabile.
        await w.setProps({ storico: { passaggi: 1, righe: [], gruppi: [{ diritto: 'Proprietà', righe: [] }], subentri: [passaggio({ annullabile: { si: false, motivo, avvisi: [], effetti: [] } })] } });
        opzioni.onError({ passaggio: motivo });
        await w.vm.$nextTick();
        expect(w.text()).toContain(motivo);
        spia.mockRestore();
    });
});

describe('TitolaritaSheet — la vendita o donazione con riserva d\'usufrutto (beta.38)', () => {
    // Testi T8: «o donazione», come il tipo di base — la donazione della nuda proprietà è il caso più frequente.
    test('lo storico la chiama con il suo nome, non «Vendita o donazione · riserva_usufrutto»', () => {
        const testo = monta(passaggio({ sottotipo: 'riserva_usufrutto' })).text();
        expect(testo).toContain('Vendita o donazione con riserva d\'usufrutto');
        expect(testo).not.toContain('riserva_usufrutto');
    });

    test('una vendita senza riserva resta «Vendita o donazione»', () => {
        const testo = monta(passaggio()).text();
        expect(testo).toContain('Vendita o donazione');
        expect(testo).not.toContain('riserva');
    });

    // Testi T7: la riga toccata dal passaggio si chiama come il passaggio, con la stessa funzione.
    function riga(subentro: Record<string, unknown>, id = 31): any {
        return {
            id, anagrafica: { id: 5, nome: 'Venditore Ugo', codice_fiscale: null }, tipologia: 'usufruttuario', diritto: 'Usufrutto', quota: 100, attivo: true,
            data_inizio: '2026-05-01', data_fine: null, in_corso: true, futuro: false, periodo: 'dal 1 maggio 2026 · in corso', durata: '4 mesi', note: null,
            subentro: { tipo_passaggio: 'vendita', sottotipo: null, decorrenza: '2026-05-01', estremi_titolo: 'atto notaio Verdi, rep. 777', copia_autentica_il: null,
                ruolo_nel_passaggio: 'continuazione', documento_url: null, nota_conguaglio: null, ...subentro },
        };
    }

    test('le righe toccate dalla riserva hanno il nome del passaggio, non «vendita o donazione»', () => {
        const testo = monta(passaggio(), { subentri: [], gruppi: [{ diritto: 'Usufrutto', righe: [riga({ sottotipo: 'riserva_usufrutto' })] }] }).text();
        expect(testo).toContain('Vendita o donazione con riserva d\'usufrutto, atto notaio Verdi, rep. 777');
        expect(testo).not.toContain('riserva_usufrutto');
    });

    test('la riga di una vendita senza riserva dice «Vendita o donazione», quella di una costituzione «Usufrutto · costituzione», come i loro passaggi', () => {
        const testo = monta(passaggio(), { subentri: [], gruppi: [{ diritto: 'Proprietà', righe: [riga({}), riga({ tipo_passaggio: 'usufrutto', sottotipo: 'costituzione', estremi_titolo: 'rep. 9' }, 32)] }] }).text();
        expect(testo).toContain('Vendita o donazione, atto notaio Verdi, rep. 777');
        expect(testo).toContain('Usufrutto · costituzione, rep. 9');
        expect(testo).not.toContain('riserva');
    });
});
