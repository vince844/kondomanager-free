// @vitest-environment jsdom

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import ModalImportaXml, { type FilePendente } from './ModalImportaXml.vue';

const ESITO = {
    documento: {
        tipo_documento: 'fattura',
        numero_documento: 'FT-XML-1',
        data_documento: '2026-06-10',
        data_scadenza: '2026-07-10',
        modalita_pagamento: 'bonifico',
        iban_fornitore: 'IT43X0100003245100000000001',
    },
    // 35 € + 22% = 42,70 €. Serve a presidiare la conversione euro→centesimi (sotto).
    righe: [{ descrizione: 'Servizio letto da XML', importo_imponibile: 35, aliquota_iva: 22 }],
    fornitore: {
        esito: 'trovato',
        candidati: [{ id: 10, ragione_sociale: 'Alfa Servizi Srl' }],
        letto_da_xml: { denominazione: 'ALFA SERVIZI SRL', partita_iva: '01234567897', codice_fiscale: null },
    },
    avvisi: { lotto_con_altri_documenti: 0, righe_non_quadrano_col_riepilogo: false, scarto_righe_riepilogo_cents: 0 },
} as any;

const ESITO_DA_CREARE = {
    ...ESITO,
    fornitore: { ...ESITO.fornitore, esito: 'non_trovato', candidati: [] },
} as any;

function voce(nome: string, stato: FilePendente['stato'], extra: Partial<FilePendente> = {}): FilePendente {
    return {
        file: new File(['<xml/>'], nome, { type: 'text/xml' }),
        stato,
        esito: stato === 'pronto' ? ESITO : null,
        erroreMessaggio: null,
        ...extra,
    };
}

// ⚠️ `attachTo` serve davvero: il componente usa `<Teleport to="body">`, quindi il
// suo markup non finisce dentro `wrapper.element`. Senza questo, ogni `wrapper.text()`
// tornerebbe vuoto e i test passerebbero misurando il nulla.
/**
 * Il testo con gli spazi unificatori (U+00A0) riportati a spazi normali. `euro()` passa
 * da `Intl.NumberFormat`, che fra simbolo e cifre mette U+00A0: senza questa
 * normalizzazione un'asserzione su «€ 42,70» fallisce pur essendo giusta, e si finisce
 * per scrivere il carattere invisibile dentro il test.
 */
function testo(wrapper: { text: () => string }): string {
    return wrapper.text().replace(/\u00a0/g, ' ');
}

function render(files: FilePendente[] = [], show = true, extra: Record<string, unknown> = {}) {
    return mount(ModalImportaXml, {
        props: { show, files, ...extra },
        attachTo: document.body,
        global: { stubs: { Teleport: true } },
    });
}

describe('ModalImportaXml', () => {
    test('chiusa non mostra niente', () => {
        const wrapper = render([], false);
        expect(wrapper.text()).not.toContain('Trascina qui i file');
    });

    test('aperta mostra la dropzone', () => {
        const wrapper = render();
        expect(wrapper.text()).toContain('Trascina qui i file');
        expect(wrapper.find('input[type="file"][multiple]').exists()).toBe(true);
    });

    test('scegliendo dei file emette «aggiungi», non li legge da sé', async () => {
        // Il confine con la pagina: la modale raccoglie, la pagina legge. Chi tiene la
        // coda è la pagina, perché la modale di successo la usa dopo che questa è
        // chiusa — vedi il docblock del componente.
        const wrapper = render();
        const input = wrapper.find('input[type="file"][multiple]');
        const files = [new File(['<xml/>'], 'a.xml', { type: 'text/xml' })];
        Object.defineProperty(input.element, 'files', { value: files, configurable: true });

        await input.trigger('change');

        expect(wrapper.emitted('aggiungi')).toHaveLength(1);
    });

    test('un file in lettura si distingue da uno pronto e da uno in errore', () => {
        const wrapper = render([
            voce('lento.xml', 'in_corso'),
            voce('buono.xml', 'pronto'),
            voce('rotto.xml', 'errore', { erroreMessaggio: 'File XML malformato' }),
        ]);

        expect(wrapper.text()).toContain('Leggo il file...');
        expect(wrapper.text()).toContain('ALFA SERVIZI SRL');
        expect(wrapper.text()).toContain('File XML malformato');
        // Il nome del file resta visibile finché non c'è un esito da mostrare al suo posto.
        expect(wrapper.text()).toContain('lento.xml');
        expect(wrapper.text()).toContain('rotto.xml');
    });

    test('l\'importo stimato è in euro, non diviso per cento', () => {
        // ⚠️ `euro()` si aspetta CENTESIMI di default e le righe arrivano in EURO: senza
        // la conversione mostrava «€ 0,43» al posto di «€ 42,70». Trovato dal vivo nel
        // browser e non da un test, perché il caso a file singolo salta l'elenco.
        const wrapper = render([voce('buono.xml', 'pronto')]);
        expect(testo(wrapper)).toContain('€ 42,70');
    });

    test('solo un file pronto si può registrare', () => {
        const inCorso = render([voce('lento.xml', 'in_corso')]);
        expect(inCorso.findAll('button').some((b) => b.text() === 'Rivedi e registra')).toBe(false);

        const pronto = render([voce('buono.xml', 'pronto')]);
        expect(pronto.findAll('button').some((b) => b.text() === 'Rivedi e registra')).toBe(true);
    });

    test('«Rivedi e registra» emette «seleziona» con la voce giusta', async () => {
        const voci = [voce('a.xml', 'pronto'), voce('b.xml', 'pronto')];
        const wrapper = render(voci);

        const pulsanti = wrapper.findAll('button').filter((b) => b.text() === 'Rivedi e registra');
        await pulsanti[1].trigger('click');

        // ⚠️ `toBe` fallirebbe: quello che riemerge dall'evento è il proxy reattivo che
        // Vue ha costruito attorno alla prop, non l'oggetto originale. Si identifica la
        // voce dal file, che è ciò che conta.
        const selezionata = wrapper.emitted('seleziona')?.[0]?.[0] as FilePendente;
        expect(selezionata.file.name).toBe('b.xml');
    });

    test('il cestino emette «rimuovi» con la voce giusta', async () => {
        const voci = [voce('a.xml', 'pronto'), voce('b.xml', 'errore', { erroreMessaggio: 'rotto' })];
        const wrapper = render(voci);

        const cestino = wrapper.find('button[aria-label="Togli b.xml dall\'elenco"]');
        expect(cestino.exists()).toBe(true);
        await cestino.trigger('click');

        const rimossa = wrapper.emitted('rimuovi')?.[0]?.[0] as FilePendente;
        expect(rimossa.file.name).toBe('b.xml');
    });

    test('un fornitore non in anagrafica dice dove si crea, senza nascondersi in un tooltip', () => {
        // ⚠️ Non un tooltip, per la regola già scritta in casa (FatturaShow.vue, card
        // Allegati): «una riga sola sopra l'elenco, sempre visibile — non un tooltip che
        // si vede solo passandoci sopra col mouse, e mai su un touch screen».
        // «Fornitore da creare» da solo sembra un ostacolo, e manda l'amministratore
        // fuori dal flusso a cercarlo in anagrafica.
        const wrapper = render([voce('nuovo.xml', 'pronto', { esito: ESITO_DA_CREARE })]);

        expect(wrapper.text()).toContain('fornitore da creare');
        expect(testo(wrapper)).toContain('«Fornitore da creare» non ferma niente');
        expect(wrapper.text()).toContain('dal modulo di registrazione');
        expect(wrapper.text()).toContain('puoi crearlo da Fornitori');
    });

    test('il messaggio sta nel piede, fuori dall\'area che scorre', () => {
        // ⚠️ Il difetto che questo presidia si vede solo con molti file: dentro il
        // contenitore `overflow-y-auto` il messaggio finiva in fondo all'elenco, cioè
        // leggibile soltanto scorrendo fino in fondo — proprio quando non serve più.
        // Provato a video con 16 voci il 03/09/2026. Qui si verifica la struttura, che
        // è ciò che rende il messaggio indipendente da quanti file ci sono.
        const wrapper = render([voce('nuovo.xml', 'pronto', { esito: ESITO_DA_CREARE })]);

        const messaggio = wrapper.findAll('p').find((p) => /non ferma niente/.test(p.text()));
        expect(messaggio).toBeTruthy();

        const dentroLoScroller = messaggio!.element.closest('.overflow-y-auto');
        expect(dentroLoScroller).toBeNull();

        // ...e sta in un contenitore che NON scorre, fratello dell'area che scorre: è
        // questo che lo rende indipendente da quanti file ci sono nell'elenco.
        const scroller = wrapper.element.querySelector('.overflow-y-auto');
        const piede = messaggio!.element.closest('.shrink-0');
        expect(piede).not.toBeNull();
        expect(piede!.contains(scroller)).toBe(false);
    });

    test('con tutti i fornitori riconosciuti quella riga non compare', () => {
        const wrapper = render([voce('buono.xml', 'pronto')]);

        expect(wrapper.text()).toContain('fornitore riconosciuto');
        expect(wrapper.text()).not.toContain('non ferma niente');
    });

    test('senza codice fiscale del condominio avvisa che non può controllare l\'intestatario', () => {
        // ⚠️ **Avviso, non blocco** — deciso con Vincenzo il 03/09/2026. Senza codice
        // fiscale la guardia sul destinatario (ImportaFatturaXmlController) si salta
        // giustamente, ma prima si saltava IN SILENZIO: l'amministratore credeva di
        // avere una rete che non c'era. Il caso arriva dall'importatore Danea, che può
        // creare un condominio senza CF.
        const wrapper = render([], true, {
            condominioSenzaCodiceFiscale: true,
            urlAnagraficaCondominio: '/condomini/28/edit',
        });

        expect(wrapper.text()).toContain('Non posso controllare che le fatture siano di questo condominio');
        expect(wrapper.text()).toContain('manca il suo codice fiscale');
        // e dice dove si rimedia
        expect(wrapper.find('a[href="/condomini/28/edit"]').exists()).toBe(true);
    });

    test('l\'avviso sul codice fiscale non blocca il caricamento', () => {
        const wrapper = render([voce('buono.xml', 'pronto')], true, { condominioSenzaCodiceFiscale: true });

        // La dropzone c'è, e un file pronto resta registrabile: è un avviso, non un muro.
        expect(wrapper.find('input[type="file"][multiple]').exists()).toBe(true);
        expect(wrapper.findAll('button').some((b) => b.text() === 'Rivedi e registra')).toBe(true);
    });

    test('col codice fiscale a posto l\'avviso non compare', () => {
        const wrapper = render([voce('buono.xml', 'pronto')]);
        expect(wrapper.text()).not.toContain('Non posso controllare');
    });

    test('non c\'è nessun pulsante «Chiudi»: il piede è solo informazione', () => {
        // Tolto il 03/09/2026 su proposta di Vincenzo: qui non c'è niente da confermare
        // — si carica e si sceglie — e un pulsante che fa la stessa cosa della X rubava
        // attenzione alla nota. Le vie d'uscita restano due: la X ed Esc (sotto).
        const wrapper = render([voce('buono.xml', 'pronto')]);
        expect(wrapper.findAll('button').some((b) => b.text() === 'Chiudi')).toBe(false);
    });

    test('senza niente da segnalare il piede non compare affatto', () => {
        const wrapper = render([voce('buono.xml', 'pronto')]);
        // Nessun avviso e nessun fornitore da creare: non resta una fascia vuota.
        expect(wrapper.text()).not.toContain('non ferma niente');
        expect(wrapper.text()).not.toContain('Non posso controllare');
    });

    test('la X chiude la modale', async () => {
        const wrapper = render();

        // È il solo pulsante senza testo: l'icona X in alto a destra.
        const x = wrapper.findAll('button').find((b) => b.text().trim() === '');
        expect(x).toBeTruthy();
        await x!.trigger('click');

        expect(wrapper.emitted('update:show')?.[0]).toEqual([false]);
    });

    test('Esc chiude la modale, e smette di ascoltare quando è chiusa', async () => {
        // ⚠️ Esc è arrivato INSIEME alla rimozione del pulsante «Chiudi»: senza, le vie
        // d'uscita sarebbero scese a una sola, una X piccola in un angolo.
        const wrapper = render();
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(wrapper.emitted('update:show')?.[0]).toEqual([false]);

        // A modale chiusa l'ascoltatore è staccato: un Esc premuto altrove non emette
        // nulla (altrimenti resterebbe appeso a `window` per tutta la sessione).
        const chiusa = render([], false);
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(chiusa.emitted('update:show')).toBeUndefined();
    });
});
