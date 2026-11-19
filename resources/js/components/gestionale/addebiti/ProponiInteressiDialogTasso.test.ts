// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisioni 511 e 512 nella finestra della proposta degli interessi: accanto alla cifra di ogni riga il tasso usato («€ 0,37 · 2,00 %», o «2,00 % → 3,00 %» se
 * nel periodo cambia); «Cambia» con «Cifra» o «Tasso»: con il tasso la finestra rifà la riga sugli stessi giorni e tratti, mostra la cifra, solo in meno, e al
 * server manda il tasso scritto. L'etichetta «ereditato» è un badge `rounded-md`, non una pillola (decisione 399).
 *
 * Che cosa non copre: la cifra registrata (la rifà il server, `InteressiEreditatiCorrezioniTest.php`); il tema scuro e il telefono (a video).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { interessiAlTasso, tassoScritto, etichettaDelTasso, conLArticolo } from '@/lib/gestionale/addebiti/interessiDegliEredi';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Finestra = (await import('./ProponiInteressiDialog.vue')).default;

const NBSP = ' ';
const tratto = (dal: string, al: string, giorni: number, capitale: number, e4: number, causa: string) => ({
    dal, al, giorni, capitale, tasso: etichettaDelTasso(e4), tasso_e4: e4, fonte: 'art. 12', divisore: 365, esatti: 0, causa,
});
const proposta = () => ({
    alla: '2027-01-31',
    persone: [{ anagrafica_id: 3, nome: 'Marco', totale: 75, crediti_sulle_rate: 0, anticipi: 0, quote_di_inquilino: 0, righe: [
        { chiave: '3|10|5|s7', rata_id: 10, immobile_id: 5, unita: 'U1', rata: 'Rata 1 — Preventivo 2026', piano: 'Preventivo 2026', gestione_id: 2, gestione: 'Ordinaria 2026',
            scadenza: '2026-04-30', dal: '2026-11-17', al: '2027-01-31', gia_registrati_al: null, interessi: 75, fermo: null,
            ereditata: { subentro_id: 7, da_anagrafica_id: 1, da: 'Ettore Fabbri', percentuale: '50', decesso: '2026-11-17', modo: 'al_decesso', parte: 15000, accessorio: false },
            frase_ereditata: 'Di Ettore Fabbri, passata a te per il 50 % il 17/11/2026: interessi su € 150,00 dal 17/11/2026 al 31/01/2027.',
            tassi: [`2,00${NBSP}%`, `3,00${NBSP}%`],
            tratti: [tratto('2026-11-17', '2026-12-31', 45, 15000, 20000, 'decesso'), tratto('2027-01-01', '2027-01-31', 31, 15000, 30000, 'anno')] },
    ] }],
    totale: 75,
    gestioni: [{ gestione_id: 2, gestione: 'Ordinaria 2026', scrive: { gestione_id: 2, esercizio_id: 1, nome: 'Ordinaria 2026 (Esercizio 2026)', piano_conto_id: 9 }, perche: null,
        voci: [{ id: 40, nome: 'Interessi di mora' }], voce_proposta: 40 }],
    regole: { righe: [], di_oggi: null, avviso: null },
    frase_domanda_giudiziale: 'Dopo la domanda giudiziale il tasso è un altro.',
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; });
const corpo = () => document.body;
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const monta = async () => {
    axiosGet.mockResolvedValue({ data: proposta() });
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, oggi: '2027-01-31', persone: [3] } });
    await flushPromises();
};
const riga = () => corpo().querySelector<HTMLElement>('[data-testid="riga-interessi"]')!;
const scrivi = async (el: HTMLInputElement, testo: string) => { el.value = testo; el.dispatchEvent(new Event('input')); await flushPromises(); };

describe('il tasso accanto alla cifra e «Cambia» con il tasso', () => {
    test('decisione 511: la cifra con il tasso, e i tassi dei tratti quando cambiano; il badge «ereditato» non è una pillola', async () => {
        await monta();
        expect(pulito(riga().querySelector('[data-testid="cifra-riga"]')?.textContent)).toBe('€ 0,75 · 2,00 % → 3,00 %');
        const badge = riga().querySelector('[data-testid="badge-ereditata"]')!;
        expect(badge.className).toContain('rounded-md');
        expect(badge.className).not.toContain('rounded-full');
    });

    test('decisione 512: «Tasso» rifà la riga sugli stessi giorni e la dice; al server va il tasso scritto, non la cifra', async () => {
        await monta();
        riga().querySelector<HTMLButtonElement>('[data-testid="cambia"]')!.click();
        await flushPromises();
        riga().querySelector<HTMLButtonElement>('[data-testid="modo-tasso"]')!.click();
        await flushPromises();
        const campo = riga().querySelector<HTMLInputElement>('[data-testid="tasso-cambiato"]')!;
        // Con due tassi nei giorni della riga il campo è vuoto: nessuna cifra cambiata finché non lo scrivi.
        expect(campo.value).toBe('');
        expect(riga().querySelector('[data-testid="errore-cifra"]')).toBeNull();
        expect(riga().querySelector('[data-testid="cifra-dal-tasso"]')).toBeNull();
        await scrivi(campo, '1,00');
        // 15000 × 1 % × 76 / 365 = 31,23 → € 0,31.
        expect(pulito(riga().querySelector('[data-testid="cifra-dal-tasso"]')?.textContent)).toBe('Con l\'1,00 % sugli stessi giorni: € 0,31.');
        expect(pulito(corpo().querySelector('[data-testid="registra-interessi"]')?.textContent)).toBe('Registra € 0,31');
        const motivo = corpo().querySelector<HTMLTextAreaElement>('#perche-cambi-le-cifre')!;
        motivo.value = 'Accordo con l\'erede';
        motivo.dispatchEvent(new Event('input'));
        await flushPromises();
        axiosPost.mockResolvedValue({ data: { messaggio: 'Interessi registrati: 1 addebito, € 0,31.' } });
        corpo().querySelector<HTMLButtonElement>('[data-testid="registra-interessi"]')!.click();
        await flushPromises();
        const inviato = axiosPost.mock.calls[0][1];
        expect(inviato.tassi).toEqual({ '3|10|5|s7': '1,00' });
        expect(inviato.importi).toEqual({});
        expect(inviato.atteso).toBe(75);
    });

    test('solo in meno: un tasso più alto di quello delle regole in un tratto, o zero, non va, e il pulsante è spento', async () => {
        await monta();
        riga().querySelector<HTMLButtonElement>('[data-testid="cambia"]')!.click();
        await flushPromises();
        riga().querySelector<HTMLButtonElement>('[data-testid="modo-tasso"]')!.click();
        await flushPromises();
        const campo = riga().querySelector<HTMLInputElement>('[data-testid="tasso-cambiato"]')!;
        // Il 2,50 % è più basso del 3,00 % di gennaio ma più alto del 2,00 % di dicembre.
        await scrivi(campo, '2,50');
        expect(pulito(riga().querySelector('[data-testid="errore-cifra"]')?.textContent)).toBe('Più del tasso delle regole no: per questa riga è 2,00 %. Per un tasso diverso su tutte le righe cambia la regola in «Recupero dei crediti».');
        expect(corpo().querySelector<HTMLButtonElement>('[data-testid="registra-interessi"]')!.disabled).toBe(true);
        await scrivi(campo, '0');
        expect(pulito(riga().querySelector('[data-testid="errore-cifra"]')?.textContent)).toBe('Per non chiedere niente, togli la riga.');
    });

    test('il conto del tasso è quello del server: la somma esatta dei tratti, un arrotondamento per riga, il mezzo in su', () => {
        expect(tassoScritto('1,00')).toBe(10000);
        expect(tassoScritto('1,5 %')).toBe(15000);
        expect(tassoScritto('tanto')).toBeNull();
        expect(etichettaDelTasso(16000)).toBe(`1,60${NBSP}%`);
        expect(etichettaDelTasso(16050)).toBe(`1,605${NBSP}%`);
        expect(conLArticolo(10000)).toBe(`l'1,00${NBSP}%`);
        expect(conLArticolo(20000)).toBe(`il 2,00${NBSP}%`);
        // Le due righe della prova di Ettore: 15000 × 2 % × 45 / 365 = 36,99 → 37; 3279 × 1,60 % × 45 / 365 = 6,47 → 6 (R2 della seconda prova).
        expect(interessiAlTasso([{ capitale: 15000, giorni: 45, divisore: 365 }], 20000)).toBe(37);
        expect(interessiAlTasso([{ capitale: 3279, giorni: 45, divisore: 365 }], 16000)).toBe(6);
        // Un capitale grande non perde cifre: 9.000.000,00 € × 100 % × 366 / 366.
        expect(interessiAlTasso([{ capitale: 900_000_000, giorni: 366, divisore: 366 }], 1_000_000)).toBe(900_000_000);
    });
});
