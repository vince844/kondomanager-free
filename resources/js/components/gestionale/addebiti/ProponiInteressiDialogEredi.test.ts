// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisioni 489, 493 e 494 (4) nella finestra della proposta degli interessi: la riga del debito ereditato con il badge «ereditato» e la sua frase; la riga
 * del defunto ferma al decesso con la sua (D4 della prova della Coda 663); accanto al defunto che cosa fa la registrazione; «Cambia» su una riga, solo in
 * meno, con la frase delle conseguenze, «Perché cambi le cifre» obbligatorio, il pulsante con la cifra che si registra, e il corpo con le cifre scritte e
 * il perché. H6 della beta.12: il collegamento al piano dei conti della gestione.
 *
 * Che cosa non copre: le cifre (le fa il server, `InteressiSulDebitoEreditatoTest.php`); il tema scuro e il telefono (a video).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;

const Finestra = (await import('./ProponiInteressiDialog.vue')).default;

const tratto = (dal: string, al: string, giorni: number, capitale: number, esatti: number, causa: string) => ({ dal, al, giorni, capitale, tasso: '2,00 %', fonte: 'art. 12', divisore: 365, esatti, causa });
const proposta = (gestioni?: unknown) => ({
    alla: '2026-12-31',
    persone: [
        { anagrafica_id: 1, nome: 'Ettore Fabbri', totale: 329, crediti_sulle_rate: 0, anticipi: 0, quote_di_inquilino: 0,
            dopo_il_decesso: 'Registrati, gli interessi di Ettore Fabbri fino al decesso passano a Elena e Marco per quota, come quelli già addebitati prima della successione.',
            righe: [{ chiave: '1|10|5', rata_id: 10, immobile_id: 5, unita: 'U1', rata: 'Rata 1 — Preventivo 2026', piano: 'Preventivo 2026', gestione_id: 2, gestione: 'Ordinaria 2026',
                scadenza: '2026-04-30', dal: '2026-05-01', al: '2026-11-16', gia_registrati_al: null, interessi: 329, fermo: null, ereditata: null,
                fino_al_decesso: 'Fino al 16/11/2026: dal 17/11/2026 il debito è passato a Elena e Marco.', tratti: [tratto('2026-05-01', '2026-11-16', 200, 30000, 32877, 'scadenza')] }] },
        { anagrafica_id: 3, nome: 'Marco', totale: 37, crediti_sulle_rate: 0, anticipi: 0, quote_di_inquilino: 0,
            righe: [{ chiave: '3|10|5|s7', rata_id: 10, immobile_id: 5, unita: 'U1', rata: 'Rata 1 — Preventivo 2026', piano: 'Preventivo 2026', gestione_id: 2, gestione: 'Ordinaria 2026',
                scadenza: '2026-04-30', dal: '2026-11-17', al: '2026-12-31', gia_registrati_al: null, interessi: 37, fermo: null,
                ereditata: { subentro_id: 7, da_anagrafica_id: 1, da: 'Ettore Fabbri', percentuale: '50', decesso: '2026-11-17', modo: 'al_decesso', parte: 15000, accessorio: false },
                frase_ereditata: 'Di Ettore Fabbri, passata a te per il 50 % il 17/11/2026: interessi su € 150,00 dal 17/11/2026 al 31/12/2026.',
                tratti: [tratto('2026-11-17', '2026-12-31', 45, 15000, 3699, 'decesso')] }] },
    ],
    totale: 366,
    gestioni: gestioni ?? [{ gestione_id: 2, gestione: 'Ordinaria 2026', scrive: { gestione_id: 2, esercizio_id: 1, nome: 'Ordinaria 2026 (Esercizio 2026)', piano_conto_id: 9 }, perche: null,
        voci: [{ id: 40, nome: 'Interessi di mora' }], voce_proposta: 40 }],
    regole: { righe: [], di_oggi: null, avviso: null },
    frase_domanda_giudiziale: 'Dopo la domanda giudiziale il tasso è un altro.',
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; });
const corpo = () => document.body;
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const monta = async (dati = proposta()) => {
    axiosGet.mockResolvedValue({ data: dati });
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, oggi: '2026-12-31', persone: [1] } });
    await flushPromises();

    return w;
};
const registra = () => corpo().querySelector<HTMLButtonElement>('[data-testid="registra-interessi"]')!;
const righe = () => [...corpo().querySelectorAll<HTMLElement>('[data-testid="riga-interessi"]')];
const scrivi = async (el: HTMLInputElement, testo: string) => { el.value = testo; el.dispatchEvent(new Event('input')); await flushPromises(); };

describe('la proposta con il debito ereditato', () => {
    test('la riga ereditata ha il badge e la sua frase; la riga del defunto dice perché si ferma; accanto al defunto la frase della registrazione', async () => {
        await monta();
        const [ettore, marco] = righe();
        expect(ettore.querySelector('[data-testid="badge-ereditata"]')).toBeNull();
        expect(pulito(ettore.querySelector('[data-testid="fino-al-decesso"]')?.textContent)).toBe('Fino al 16/11/2026: dal 17/11/2026 il debito è passato a Elena e Marco.');
        expect(pulito(marco.querySelector('[data-testid="badge-ereditata"]')?.textContent)).toBe('ereditato');
        expect(marco.querySelector('[data-testid="badge-ereditata"]')!.className).toContain('rounded-md');
        expect(pulito(marco.querySelector('[data-testid="frase-ereditata"]')?.textContent)).toBe('Di Ettore Fabbri, passata a te per il 50 % il 17/11/2026: interessi su € 150,00 dal 17/11/2026 al 31/12/2026.');
        expect(pulito(corpo().querySelector('[data-testid="dopo-il-decesso"]')?.textContent)).toContain('passano a Elena e Marco per quota');
        marco.querySelector<HTMLButtonElement>('[data-testid="apri-tratti"]')!.click();
        await flushPromises();
        expect(pulito(corpo().querySelector('[data-testid="tratti"] li')?.textContent)).toContain('(dal giorno del decesso, il debito è passato a te)');
    });

    test('«Cambia» in meno: la frase delle conseguenze, il perché obbligatorio, il pulsante con la cifra che si registra, il corpo con la cifra e il perché', async () => {
        await monta();
        axiosPost.mockResolvedValue({ data: { messaggio: 'Interessi registrati: 2 addebiti, € 3,59.' } });
        expect(pulito(registra().textContent)).toBe('Registra € 3,66');
        righe()[1].querySelector<HTMLButtonElement>('[data-testid="cambia"]')!.click();
        await flushPromises();
        const campo = righe()[1].querySelector<HTMLInputElement>('[data-testid="cifra-cambiata"]')!;
        expect(campo.value).toBe('0,37');
        await scrivi(campo, '0,30');
        expect(pulito(righe()[1].querySelector('[data-testid="conseguenze"]')?.textContent)).toBe('I giorni fino al 31/12/2026 restano registrati: la prossima proposta '
            + 'riparte dal 01/01/2027, e i € 0,07 di differenza non si chiedono più. Se invece togli la riga, quei giorni tornano nella prossima proposta.');
        expect(pulito(registra().textContent)).toBe('Registra € 3,59');
        // Senza il perché il pulsante è spento, e la frase lo dice.
        expect(registra().disabled).toBe(true);
        expect(pulito(corpo().querySelector('[data-testid="errore-motivo"]')?.textContent)).toBe('Scrivi perché cambi le cifre, in almeno 5 caratteri: resta scritto nell\'addebito.');
        const motivo = corpo().querySelector<HTMLTextAreaElement>('#perche-cambi-le-cifre')!;
        motivo.value = 'Accordo con l\'erede';
        motivo.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(registra().disabled).toBe(false);
        registra().click();
        await flushPromises();
        const inviato = axiosPost.mock.calls[0][1];
        // Il totale visto resta quello proposto: il server rifà la proposta e la confronta.
        expect(inviato.atteso).toBe(366);
        expect(inviato.importi).toEqual({ '3|10|5|s7': '0,30' });
        expect(inviato.motivo).toBe('Accordo con l\'erede');
        expect(inviato.righe).toEqual(['1|10|5', '3|10|5|s7']);
    });

    test('più della proposta o zero: la frase sotto la riga, e il pulsante spento', async () => {
        await monta();
        righe()[1].querySelector<HTMLButtonElement>('[data-testid="cambia"]')!.click();
        await flushPromises();
        const campo = righe()[1].querySelector<HTMLInputElement>('[data-testid="cifra-cambiata"]')!;
        await scrivi(campo, '0,38');
        expect(pulito(righe()[1].querySelector('[data-testid="errore-cifra"]')?.textContent)).toBe('Più della proposta no: è il massimo che le regole del condominio danno. Per un tasso diverso cambia la regola in «Recupero dei crediti».');
        expect(registra().disabled).toBe(true);
        await scrivi(campo, '0');
        expect(pulito(righe()[1].querySelector('[data-testid="errore-cifra"]')?.textContent)).toBe('Per non chiedere niente, togli la riga.');
        // «Lascia» torna alla cifra proposta: niente da cambiare, niente perché.
        righe()[1].querySelector<HTMLButtonElement>('[data-testid="lascia-la-cifra"]')!.click();
        await flushPromises();
        expect(registra().disabled).toBe(false);
        expect(corpo().querySelector('[data-testid="perche-cambi"]')).toBeNull();
    });

    test('H6: senza una voce di entrata il collegamento apre il piano dei conti della gestione, non l\'elenco dei piani', async () => {
        await monta(proposta([{ gestione_id: 2, gestione: 'Ordinaria 2026', scrive: { gestione_id: 2, esercizio_id: 1, nome: 'Ordinaria 2026 (Esercizio 2026)', piano_conto_id: 9 },
            perche: null, voci: [], voce_proposta: null }]));
        const link = corpo().querySelector<HTMLAnchorElement>('[data-testid="vai-al-piano-dei-conti"]')!;
        // Con il codice di prima: «gestionale.esercizi.piani-conti.index».
        expect(link.getAttribute('href')).toContain('gestionale.esercizi.piani-conti.show');
        expect(link.getAttribute('href')).toContain('pianoConto=9');
    });
});
