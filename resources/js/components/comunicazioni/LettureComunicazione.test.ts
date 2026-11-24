// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il riquadro «Letture» della scheda di una comunicazione, nel pannello (Coda 594, decisione 311).
 *
 * Riceve la prop `letture` così come la manda il server e non fa conti suoi: mostra il riassunto, l'elenco persona
 * per persona con lo stato, il filtro «Chi manca» e, con molti destinatari, le pagine (25 righe per pagina).
 * Senza nessuna delle due forme attiva dice in una riga come si attivano, invece di una tabella vuota.
 *
 * I nomi che il test cerca:
 * - `[data-riquadro="letture"]`, il contenitore;
 * - `[data-letture="come-attivarle"]`, `[data-letture="nessun-destinatario"]`, `[data-letture="aperture-spente"]`,
 *   `[data-letture="nessuno-manca"]`;
 * - `[data-letture="riassunto"]`, con dentro `[data-letture="riassunto-aperte"]`,
 *   `[data-letture="riassunto-prese-visione"]` e `[data-letture="riassunto-senza-accesso"]`;
 * - `[data-lettura-riga]`, una per persona, con `data-stato`;
 * - `button[data-filtro="chi-manca"]`, con `aria-pressed`;
 * - `[data-letture="pagine"]`, con `button[data-letture="pagina-precedente"]`, `[data-letture="pagina"]` e
 *   `button[data-letture="pagina-successiva"]`.
 *
 * `trans()` restituisce la chiave seguita dai valori passati.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string, valori?: Record<string, unknown>) => (valori ? `${chiave} ${JSON.stringify(valori)}` : chiave),
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const LettureComunicazione = (await import('./LettureComunicazione.vue')).default;

type Riga = { anagrafica_id: number; nome: string; stato: string; aperta: { il: string; alle: string } | null; presa_visione: { il: string; alle: string } | null; manca: boolean };

const ANNA: Riga = { anagrafica_id: 1, nome: 'Anna Collaboratrice', stato: 'non_aperta', aperta: null, presa_visione: null, manca: true };
const GIULIA: Riga = { anagrafica_id: 2, nome: 'Giulia Senzaccount', stato: 'senza_accesso', aperta: null, presa_visione: null, manca: true };
const MARIO: Riga = { anagrafica_id: 3, nome: 'Mario Rossi', stato: 'presa_visione', aperta: { il: '11/11/2026', alle: '10:00' }, presa_visione: { il: '11/11/2026', alle: '10:05' }, manca: false };
const PAOLO: Riga = { anagrafica_id: 4, nome: 'Paolo Neri', stato: 'aperta', aperta: { il: '10/11/2026', alle: '18:30' }, presa_visione: null, manca: true };

function letture(campi: Record<string, unknown> = {}) {
    return {
        registra_aperture: true,
        chiedi_presa_visione: true,
        attive: true,
        riassunto: { destinatari: 4, senza_accesso: 1, aperte: 2, prese_visione: 1 },
        righe: [ANNA, GIULIA, MARIO, PAOLO],
        ...campi,
    };
}

function monta(campi: Record<string, unknown> = {}) {
    return mount(LettureComunicazione, {
        props: { letture: letture(campi) } as never,
        global: { mocks: { route: (n: string) => `/${n}` } },
    });
}

const righe = (w: ReturnType<typeof monta>) => w.findAll('[data-lettura-riga]');
const nomi = (w: ReturnType<typeof monta>) => righe(w).map((r) => ['Anna Collaboratrice', 'Giulia Senzaccount', 'Mario Rossi', 'Paolo Neri'].find((n) => r.text().includes(n)));
const filtro = (w: ReturnType<typeof monta>) => w.find('button[data-filtro="chi-manca"]');

/** Sessanta persone: le prime venti hanno aperto, le altre mancano. */
function sessanta(): Riga[] {
    return Array.from({ length: 60 }, (_, i) => ({
        anagrafica_id: 100 + i,
        nome: `Persona ${String(i + 1).padStart(2, '0')}`,
        stato: i < 20 ? 'aperta' : 'non_aperta',
        aperta: i < 20 ? { il: '11/11/2026', alle: '10:00' } : null,
        presa_visione: null,
        manca: i >= 20,
    }));
}

describe('LettureComunicazione — senza nessuna delle due forme attiva', () => {
    test('dice in una riga come si attivano, senza riassunto e senza elenco', () => {
        const w = monta({ registra_aperture: false, chiedi_presa_visione: false, attive: false, riassunto: null, righe: [] });

        expect(w.find('[data-riquadro="letture"]').exists()).toBe(true);
        expect(w.find('[data-letture="come-attivarle"]').text()).toContain('comunicazioni.readings.how_to_enable');
        expect(w.find('[data-letture="riassunto"]').exists()).toBe(false);
        expect(righe(w)).toHaveLength(0);
        expect(filtro(w).exists()).toBe(false);
    });

    test('con una forma attiva la riga non c\'è', () => {
        expect(monta().find('[data-letture="come-attivarle"]').exists()).toBe(false);
    });
});

describe('LettureComunicazione — il riassunto', () => {
    test('con tutte e due le forme dice le aperture, le prese visione e chi è senza accesso, sul totale dei destinatari', () => {
        const w = monta();
        const aperte = w.find('[data-letture="riassunto"] [data-letture="riassunto-aperte"]');
        const prese = w.find('[data-letture="riassunto"] [data-letture="riassunto-prese-visione"]');
        const senza = w.find('[data-letture="riassunto"] [data-letture="riassunto-senza-accesso"]');

        expect(aperte.text()).toContain('comunicazioni.readings.summary_opened');
        expect(aperte.text()).toContain('"count":2');
        expect(aperte.text()).toContain('"total":4');
        expect(prese.text()).toContain('comunicazioni.readings.summary_acknowledged');
        expect(prese.text()).toContain('"count":1');
        expect(prese.text()).toContain('"total":4');
        expect(senza.text()).toContain('comunicazioni.readings.summary_no_access');
        expect(senza.text()).toContain('"count":1');
    });

    test('il riquadro ha il titolo e dice che non ha valore legale', () => {
        const testo = monta().find('[data-riquadro="letture"]').text();

        expect(testo).toContain('comunicazioni.readings.title');
        expect(testo).toContain('comunicazioni.readings.no_legal_value');
    });

    test('con la sola presa visione non parla di aperture', () => {
        const w = monta({ registra_aperture: false, riassunto: { destinatari: 4, senza_accesso: 0, aperte: 0, prese_visione: 1 } });

        expect(w.find('[data-letture="riassunto-aperte"]').exists()).toBe(false);
        expect(w.find('[data-letture="riassunto-prese-visione"]').exists()).toBe(true);
        expect(w.find('[data-letture="riassunto-senza-accesso"]').exists()).toBe(false);
        expect(w.find('[data-letture="aperture-spente"]').exists()).toBe(false);
    });

    test('con le sole aperture non parla di prese visione', () => {
        const w = monta({ chiedi_presa_visione: false, riassunto: { destinatari: 4, senza_accesso: 1, aperte: 2, prese_visione: 0 } });

        expect(w.find('[data-letture="riassunto-aperte"]').exists()).toBe(true);
        expect(w.find('[data-letture="riassunto-prese-visione"]').exists()).toBe(false);
    });

    test('le aperture registrate prima restano scritte anche con l\'impostazione spenta, e il riquadro lo dice', () => {
        const w = monta({ registra_aperture: false, chiedi_presa_visione: false, riassunto: { destinatari: 4, senza_accesso: 1, aperte: 2, prese_visione: 0 } });

        expect(w.find('[data-letture="riassunto-aperte"]').text()).toContain('"count":2');
        expect(w.find('[data-letture="aperture-spente"]').text()).toContain('comunicazioni.readings.tracking_off_note');
    });

    test('le prese visione date prima restano scritte anche se la spunta è stata tolta', () => {
        const w = monta({ chiedi_presa_visione: false, riassunto: { destinatari: 4, senza_accesso: 1, aperte: 2, prese_visione: 1 } });

        expect(w.find('[data-letture="riassunto-prese-visione"]').text()).toContain('"count":1');
    });

    test('con l\'impostazione accesa la nota delle aperture spente non c\'è', () => {
        expect(monta().find('[data-letture="aperture-spente"]').exists()).toBe(false);
    });

    test('una comunicazione senza destinatari lo dice, senza elenco e senza filtro', () => {
        const w = monta({ riassunto: { destinatari: 0, senza_accesso: 0, aperte: 0, prese_visione: 0 }, righe: [] });

        expect(w.find('[data-letture="nessun-destinatario"]').text()).toContain('comunicazioni.readings.no_recipients');
        expect(righe(w)).toHaveLength(0);
        expect(filtro(w).exists()).toBe(false);
        expect(w.find('[data-letture="come-attivarle"]').exists()).toBe(false);
    });
});

describe('LettureComunicazione — l\'elenco persona per persona', () => {
    test('una riga per persona, nell\'ordine ricevuto, con il suo stato', () => {
        const w = monta();

        expect(nomi(w)).toEqual(['Anna Collaboratrice', 'Giulia Senzaccount', 'Mario Rossi', 'Paolo Neri']);
        expect(righe(w).map((r) => r.attributes('data-stato'))).toEqual(['non_aperta', 'senza_accesso', 'presa_visione', 'aperta']);
    });

    test('ogni stato ha le sue parole, con il giorno e l\'ora dove ci sono', () => {
        const [anna, giulia, mario, paolo] = righe(monta());

        expect(anna.text()).toContain('comunicazioni.readings.status_not_opened');
        expect(giulia.text()).toContain('comunicazioni.readings.status_no_access');
        expect(mario.text()).toContain('comunicazioni.readings.status_acknowledged');
        expect(mario.text()).toContain('11/11/2026');
        expect(mario.text()).toContain('10:05');
        expect(paolo.text()).toContain('comunicazioni.readings.status_opened');
        expect(paolo.text()).toContain('10/11/2026');
        expect(paolo.text()).toContain('18:30');
    });

    test('chi non ha premuto il pulsante, con le aperture non registrate, «non ha confermato»', () => {
        const w = monta({ registra_aperture: false, righe: [{ ...ANNA, stato: 'non_confermata' }] });

        expect(righe(w)[0].text()).toContain('comunicazioni.readings.status_not_acknowledged');
        expect(righe(w)[0].text()).not.toContain('comunicazioni.readings.status_not_opened');
    });
});

describe('LettureComunicazione — «Chi manca»', () => {
    test('il filtro parte spento e mostra tutti', () => {
        const w = monta();

        expect(filtro(w).text()).toContain('comunicazioni.readings.filter_missing');
        expect(filtro(w).attributes('aria-pressed')).toBe('false');
        expect(righe(w)).toHaveLength(4);
    });

    test('acceso mostra solo chi manca, come lo dice il server; spento di nuovo, tutti', async () => {
        const w = monta();

        await filtro(w).trigger('click');
        expect(filtro(w).attributes('aria-pressed')).toBe('true');
        expect(nomi(w)).toEqual(['Anna Collaboratrice', 'Giulia Senzaccount', 'Paolo Neri']);

        await filtro(w).trigger('click');
        expect(filtro(w).attributes('aria-pressed')).toBe('false');
        expect(righe(w)).toHaveLength(4);
    });

    test('se non manca nessuno, acceso lo dice', async () => {
        const w = monta({ righe: [MARIO] });

        await filtro(w).trigger('click');

        expect(righe(w)).toHaveLength(0);
        expect(w.find('[data-letture="nessuno-manca"]').text()).toContain('comunicazioni.readings.nobody_missing');
    });
});

describe('LettureComunicazione — con molti destinatari va a pagine', () => {
    const tanti = { riassunto: { destinatari: 60, senza_accesso: 0, aperte: 20, prese_visione: 0 }, righe: sessanta() };

    test('fino a venticinque righe non ci sono pagine', () => {
        expect(monta().find('[data-letture="pagine"]').exists()).toBe(false);
        expect(monta({ righe: sessanta().slice(0, 25) }).find('[data-letture="pagine"]').exists()).toBe(false);
    });

    test('con sessanta persone mostra le prime venticinque e dice a che pagina si è', () => {
        const w = monta(tanti);

        expect(righe(w)).toHaveLength(25);
        expect(righe(w)[0].text()).toContain('Persona 01');
        expect(righe(w)[24].text()).toContain('Persona 25');
        expect(w.find('[data-letture="pagina"]').text()).toContain('comunicazioni.readings.page');
        expect(w.find('[data-letture="pagina"]').text()).toContain('"page":1');
        expect(w.find('[data-letture="pagina"]').text()).toContain('"pages":3');
        expect(w.find('button[data-letture="pagina-precedente"]').attributes('disabled')).toBeDefined();
        expect(w.find('button[data-letture="pagina-successiva"]').attributes('disabled')).toBeUndefined();
    });

    test('avanti e indietro fra le pagine', async () => {
        const w = monta(tanti);

        await w.find('button[data-letture="pagina-successiva"]').trigger('click');
        expect(righe(w)).toHaveLength(25);
        expect(righe(w)[0].text()).toContain('Persona 26');

        await w.find('button[data-letture="pagina-successiva"]').trigger('click');
        expect(righe(w)).toHaveLength(10);
        expect(righe(w)[9].text()).toContain('Persona 60');
        expect(w.find('[data-letture="pagina"]').text()).toContain('"page":3');
        expect(w.find('button[data-letture="pagina-successiva"]').attributes('disabled')).toBeDefined();

        await w.find('button[data-letture="pagina-precedente"]').trigger('click');
        expect(righe(w)[0].text()).toContain('Persona 26');
    });

    test('accendere «Chi manca» riporta alla prima pagina e conta le pagine di chi manca', async () => {
        const w = monta(tanti);
        await w.find('button[data-letture="pagina-successiva"]').trigger('click');
        await w.find('button[data-letture="pagina-successiva"]').trigger('click');

        await filtro(w).trigger('click');

        // Mancano in quaranta: due pagine, e si riparte dalla prima.
        expect(righe(w)).toHaveLength(25);
        expect(righe(w)[0].text()).toContain('Persona 21');
        expect(w.find('[data-letture="pagina"]').text()).toContain('"page":1');
        expect(w.find('[data-letture="pagina"]').text()).toContain('"pages":2');
    });
});
