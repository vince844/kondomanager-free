// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra dell'accordo di rientro: «autorizzato dall'assemblea» senza una risposta già scelta (il pulsante resta spento finché non si
 * sceglie), le rate proposte con il residuo del giorno dell'accordo, la richiesta con le rate scelte e gli importi come li scrive chi lavora,
 * la lettura arrivata dopo un altro cambio della data che si scarta, la chiusura con la data e la nota.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(Object.entries(parametri).map(([k, v]) => [k, String(v)])).toString()}`;

const Finestra = (await import('./AccordoRientroDialog.vue')).default;

const possibile = (n: number, residuo: number) => ({ rata_id: n, immobile_id: 1, numero_rata: n, piano: 'Preventivo 2026', unita: 'Interno 7', scadenza: '2026-01-31', residuo });
const risposta = (extra: Record<string, unknown> = {}) => ({ data: { persona: { id: 1, nome: 'Giulia' }, data: '2026-06-26', oggi: '2026-06-26',
    rate: [possibile(1, 75000), possibile(2, 75000)], accordi: [], ...extra } });

let w: VueWrapper | null = null;
const monta = () => {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, persona: { id: 1, nome: 'Giulia' }, oggi: '2026-06-26' } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();
const corpo = () => document.body;

describe('la finestra dell\'accordo', () => {
    test('«autorizzato dall\'assemblea» non ha una risposta già scelta: senza la scelta non si registra', async () => {
        axiosGet.mockResolvedValue(risposta());
        monta();
        await flushPromises();
        const radio = Array.from(corpo().querySelectorAll<HTMLInputElement>('input[name="autorizzato"]'));
        expect(radio).toHaveLength(2);
        expect(radio.some((r) => r.checked)).toBe(false);
        expect(pulito(corpo().textContent ?? '')).toContain('Se l\'assemblea non ha ancora deliberato è «no», con una nota');

        // Le rate proposte, con il residuo di quel giorno come importo.
        const rate = corpo().querySelectorAll('[data-testid="rata-possibile"]');
        expect(rate).toHaveLength(2);
        expect(pulito(rate[0].textContent ?? '')).toContain('residuo € 750,00');
        (rate[0].querySelector('button[role="checkbox"]') as HTMLElement).click();
        await flushPromises();
        // Il calendario: una rata il 15/12, tutto il debito riconosciuto.
        const numero = corpo().querySelector<HTMLInputElement>('[data-testid="numero-rate"]')!;
        numero.value = '1';
        numero.dispatchEvent(new Event('input'));
        const prima = corpo().querySelector<HTMLInputElement>('[data-testid="prima-rata"]')!;
        prima.value = '2026-12-15';
        prima.dispatchEvent(new Event('input'));
        await flushPromises();
        const registra = corpo().querySelector<HTMLButtonElement>('[data-testid="registra-accordo"]')!;
        expect(registra.disabled).toBe(true);

        radio[1].click();
        await flushPromises();
        expect(registra.disabled).toBe(false);
        axiosPost.mockResolvedValueOnce({ data: { id: 9, messaggio: 'Accordo registrato.' } });
        axiosGet.mockResolvedValue(risposta());
        registra.click();
        await flushPromises();
        const [indirizzo, dati] = axiosPost.mock.calls[0] as [string, FormData];
        expect(indirizzo).toContain('gestionale.accordi-rientro.store');
        expect(dati.get('autorizzato_assemblea')).toBe('0');
        expect(dati.get('rate[0][rata_id]')).toBe('1');
        expect(dati.get('rate[0][importo]')).toBe('750,00');
        expect(dati.get('rate[1][rata_id]')).toBeNull();
        expect(dati.get('fino_al')).toBeNull();
        expect(dati.get('scadenze[0][data]')).toBe('2026-12-15');
        expect(dati.get('scadenze[0][importo]')).toBe('750,00');
        // Decisione 450: di serie, sulla parte della rata fuori dall'accordo gli interessi continuano.
        expect(dati.get('interessi_parte_fuori')).toBe('continuano');
        expect(pulito(corpo().textContent ?? '')).toContain('Accordo registrato.');
    });

    test('una lettura arrivata dopo un altro cambio della data dell\'accordo si scarta', async () => {
        let rispondiPrima: (v: unknown) => void = () => {};
        axiosGet.mockResolvedValueOnce(risposta());
        monta();
        await flushPromises();
        axiosGet.mockImplementationOnce(() => new Promise((r) => { rispondiPrima = r; }));
        axiosGet.mockResolvedValueOnce(risposta({ data: '2026-05-10', rate: [possibile(1, 75000)] }));
        const data = corpo().querySelector<HTMLInputElement>('[data-testid="data-accordo"]')!;
        data.value = '2026-06-01';
        data.dispatchEvent(new Event('input'));
        await flushPromises();
        data.value = '2026-05-10';
        data.dispatchEvent(new Event('input'));
        await flushPromises();
        rispondiPrima(risposta({ data: '2026-06-01', rate: [possibile(1, 75000), possibile(2, 75000), possibile(3, 75000)] }));
        await flushPromises();
        expect(corpo().querySelectorAll('[data-testid="rata-possibile"]')).toHaveLength(1);
    });

    test('un accordo in corso si chiude con la data e la nota; uno chiuso lo dice', async () => {
        const accordo = { id: 7, data_accordo: '2026-06-26', fino_al: '2026-12-15', riconosciuti: 150000, oggi: 150000, invariato: true, fino_al_passato: false, versati_oltre: 0,
            autorizzato_assemblea: false, data_autorizzazione: null, verbale_autorizzazione: null, nota: 'Assemblea convocata', chiuso_il: null, nota_chiusura: null,
            in_corso: true, documento: null, rate: [{ rata_id: 1, immobile_id: 1, riconosciuto: 75000, unita: 'Interno 7', piano: 'Preventivo 2026', numero_rata: 1, scadenza: '2026-01-31' }] };
        axiosGet.mockResolvedValue(risposta({ accordi: [accordo] }));
        monta();
        await flushPromises();
        const testo = pulito(corpo().querySelector('[data-testid="accordo"]')?.textContent ?? '');
        expect(testo).toContain('residuo invariato dal 26/06/2026');
        expect(testo).toContain('Non autorizzato dall\'assemblea.');
        corpo().querySelector<HTMLButtonElement>('[data-testid="apri-chiusura"]')!.click();
        await flushPromises();
        axiosPost.mockResolvedValueOnce({ data: { messaggio: 'Accordo chiuso.' } });
        axiosGet.mockResolvedValue(risposta({ accordi: [{ ...accordo, chiuso_il: '2026-06-26', nota_chiusura: 'Rate non versate', in_corso: false }] }));
        corpo().querySelector<HTMLButtonElement>('[data-testid="chiudi"]')!.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][1]).toEqual({ chiuso_il: '2026-06-26', nota_chiusura: null });
        expect(pulito(corpo().querySelector('[data-testid="chiuso"]')?.textContent ?? '')).toBe('Chiuso il 26/06/2026: Rate non versate');
    });

    test('sulla parte della rata fuori dall\'accordo: di serie «gli interessi continuano», con la «i» e un esempio; la scelta parte con l\'accordo e la scheda la dice (decisione 450)', async () => {
        const accordo = { id: 7, data_accordo: '2026-06-26', fino_al: '2026-12-15', riconosciuti: 150000, oggi: 150000, invariato: true, fino_al_passato: false, versati_oltre: 0,
            autorizzato_assemblea: false, data_autorizzazione: null, verbale_autorizzazione: null, nota: null, chiuso_il: null, nota_chiusura: null,
            interessi_parte_fuori: 'sospesi', in_corso: true, documento: null,
            rate: [{ rata_id: 1, immobile_id: 1, riconosciuto: 75000, unita: 'Interno 7', piano: 'Preventivo 2026', numero_rata: 1, scadenza: '2026-01-31' }] };
        axiosGet.mockResolvedValue(risposta({ accordi: [accordo] }));
        monta();
        await flushPromises();
        // La scheda dell'accordo registrato dice la sua scelta.
        expect(pulito(corpo().querySelector('[data-testid="parte-fuori-scelta"]')?.textContent ?? '')).toBe('Sulla parte della rata fuori dall\'accordo gli interessi '
            + 'restano sospesi finché l\'accordo regge.');

        // Il modulo: il menu con il di serie, la «i» con l'esempio.
        const menu = corpo().querySelector('[data-testid="parte-fuori"] .v-select') as HTMLElement;
        expect(pulito(menu.querySelector('.vs__selected')?.textContent ?? '')).toBe('gli interessi continuano');
        expect(corpo().querySelector('[data-testid="info-parte-fuori"]')?.getAttribute('aria-label')).toContain('Per esempio: la rata ha € 200,00 da versare e l\'accordo ne copre € 150,00.');
        menu.querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
        await flushPromises();
        const voci = [...menu.querySelectorAll('.vs__dropdown-option')];
        expect(voci.map((o) => pulito(o.textContent ?? ''))).toEqual(['gli interessi continuano', 'restano sospesi finché l\'accordo regge']);
        (voci[1] as HTMLElement).click();
        await flushPromises();

        (corpo().querySelectorAll('[data-testid="rata-possibile"]')[0].querySelector('button[role="checkbox"]') as HTMLElement).click();
        await flushPromises();
        const numero = corpo().querySelector<HTMLInputElement>('[data-testid="numero-rate"]')!;
        numero.value = '1';
        numero.dispatchEvent(new Event('input'));
        const prima = corpo().querySelector<HTMLInputElement>('[data-testid="prima-rata"]')!;
        prima.value = '2026-12-15';
        prima.dispatchEvent(new Event('input'));
        (corpo().querySelectorAll<HTMLInputElement>('input[name="autorizzato"]')[1]).click();
        await flushPromises();
        axiosPost.mockResolvedValueOnce({ data: { id: 9, messaggio: 'Accordo registrato.' } });
        corpo().querySelector<HTMLButtonElement>('[data-testid="registra-accordo"]')!.click();
        await flushPromises();
        expect((axiosPost.mock.calls[0] as [string, FormData])[1].get('interessi_parte_fuori')).toBe('sospesi');
    });

    test('dalla scheda di un accordo in corso la scelta sulla parte fuori si cambia: il menu con la «i», «Salva la scelta», e il registro la dice', async () => {
        const accordo = { id: 7, data_accordo: '2026-06-26', fino_al: '2026-12-15', riconosciuti: 150000, oggi: 150000, invariato: true, fino_al_passato: false, versati_oltre: 0,
            autorizzato_assemblea: false, data_autorizzazione: null, verbale_autorizzazione: null, nota: null, chiuso_il: null, nota_chiusura: null,
            interessi_parte_fuori: 'continuano', registro: [], in_corso: true, documento: null,
            rate: [{ rata_id: 1, immobile_id: 1, riconosciuto: 75000, unita: 'Interno 7', piano: 'Preventivo 2026', numero_rata: 1, scadenza: '2026-01-31' }] };
        axiosGet.mockResolvedValue(risposta({ accordi: [accordo] }));
        monta();
        await flushPromises();
        corpo().querySelector<HTMLButtonElement>('[data-testid="apri-parte-fuori"]')!.click();
        await flushPromises();
        const modulo = corpo().querySelector('[data-testid="modulo-parte-fuori"]') as HTMLElement;
        expect(modulo.querySelector('[data-testid="info-cambia-parte-fuori"]')?.getAttribute('aria-label')).toContain('Per esempio: la rata ha € 200,00 da versare');
        const menu = modulo.querySelector('.v-select') as HTMLElement;
        expect(pulito(menu.querySelector('.vs__selected')?.textContent ?? '')).toBe('gli interessi continuano');
        const salva = modulo.querySelector<HTMLButtonElement>('[data-testid="salva-parte-fuori"]')!;
        // La scelta uguale a quella di oggi non si manda.
        expect(salva.disabled).toBe(true);
        menu.querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
        await flushPromises();
        (menu.querySelectorAll('.vs__dropdown-option')[1] as HTMLElement).click();
        await flushPromises();
        expect(salva.disabled).toBe(false);

        axiosPost.mockResolvedValueOnce({ data: { messaggio: 'Scelta cambiata.' } });
        axiosGet.mockResolvedValue(risposta({ accordi: [{ ...accordo, interessi_parte_fuori: 'sospesi',
            registro: ['Il 26/06/2026 alle 10:00 Mario ha cambiato la scelta sulla parte della rata fuori dall\'accordo: da «gli interessi continuano» a «restano sospesi finché l\'accordo regge».'] }] }));
        salva.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][0]).toContain('gestionale.accordi-rientro.parte-fuori');
        expect(axiosPost.mock.calls[0][0]).toContain('accordo=7');
        expect(axiosPost.mock.calls[0][1]).toEqual({ interessi_parte_fuori: 'sospesi' });
        expect(pulito(corpo().textContent ?? '')).toContain('Scelta cambiata.');
        expect(pulito(corpo().querySelector('[data-testid="parte-fuori-scelta"]')?.textContent ?? '')).toBe('Sulla parte della rata fuori dall\'accordo gli interessi '
            + 'restano sospesi finché l\'accordo regge.');
        expect(pulito(corpo().querySelector('[data-testid="registro-accordo"]')?.textContent ?? '')).toBe('Il 26/06/2026 alle 10:00 Mario ha cambiato la scelta sulla parte '
            + 'della rata fuori dall\'accordo: da «gli interessi continuano» a «restano sospesi finché l\'accordo regge».');
    });

    test('un accordo chiuso non offre il cambio della scelta', async () => {
        const accordo = { id: 7, data_accordo: '2026-06-26', fino_al: '2026-12-15', riconosciuti: 150000, oggi: 150000, invariato: true, fino_al_passato: false, versati_oltre: 0,
            autorizzato_assemblea: false, data_autorizzazione: null, verbale_autorizzazione: null, nota: null, chiuso_il: '2026-06-26', nota_chiusura: null,
            interessi_parte_fuori: 'continuano', registro: [], in_corso: false, documento: null,
            rate: [{ rata_id: 1, immobile_id: 1, riconosciuto: 75000, unita: 'Interno 7', piano: 'Preventivo 2026', numero_rata: 1, scadenza: '2026-01-31' }] };
        axiosGet.mockResolvedValue(risposta({ accordi: [accordo] }));
        monta();
        await flushPromises();
        expect(corpo().querySelector('[data-testid="apri-parte-fuori"]')).toBeNull();
    });
});

// Fase 1-bis della 1.12.0-beta.11 (lezione 7 della 1.11.0-beta.9): il componente che usa `v-select` importa da sé lo stile di vue-select. Senza, il
// menu ha lo stile solo perché lo importano le pagine vicine che montano la finestra.
describe('AccordoRientroDialog — lo stile del menu', () => {
    test('importa lo stile di vue-select da sé', async () => {
        const { readFileSync } = await import('node:fs');
        const { resolve } = await import('node:path');
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/gestionale/accordi/AccordoRientroDialog.vue'), 'utf8');

        expect(sorgente).toContain("from 'vue-select'");
        expect(sorgente).toContain("import 'vue-select/dist/vue-select.css';");
    });
});
