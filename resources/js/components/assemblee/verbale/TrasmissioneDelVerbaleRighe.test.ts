// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La riga di chi riceve il verbale (la prova a video del 22/11/2026, notte: «Luca Verdi · Int. 3 | assente a tutti i punti | Raccomandata | [Consegnata il
 * 21/11/2026]punti 1–3: fino al 21/12/2026», il badge attaccato al testo e le colonne sparse):
 *
 * - dal portatile in su colonne con la loro intestazione e le larghezze giuste, le stesse per l'intestazione e per ogni riga: chi (nome e unità), perché
 *   lo riceve, il mezzo, lo stato (il badge, con il giorno sotto), «Può impugnare» («punti 3, 4 e 6, fino al 14/01/2027»), il «⋯»;
 * - nel «⋯» i gesti della riga: «Registra la consegna» per chi è partito e non ha ancora ricevuto, «Togli dalla lista» per chi è proposto e non ancora
 *   partito;
 * - al telefono una scheda per persona, con «Può impugnare» detto per esteso.
 *
 * Che cosa non copre: il conto dei trenta giorni (il server, `TerminiDellImpugnazioneTest.php`); le finestre della consegna e di «Togli».
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { ROTTE, registrato } from './verbaleDiProva';
import TrasmissioneDelVerbale from './TrasmissioneDelVerbale.vue';
import type { VerbaleDellaPagina } from '@/types/verbale';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia }));
vi.mock('axios', () => ({ default: { post: vi.fn() } }));
vi.mock('vue-select', async () => {
    const { defineComponent, h } = await import('vue');
    return { default: defineComponent({ name: 'VSelect', props: ['modelValue', 'options'], setup: () => () => h('div') }) };
});
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
beforeEach(() => { inertia.post.mockReset(); inertia.reload.mockReset(); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

/** Galli Franco ha ricevuto la PEC; Rossi Mario ha la raccomandata partita e non ancora consegnata; Bianchi Anna è proposta e non ancora partita. */
function conTreRighe(): VerbaleDellaPagina {
    const v = registrato();
    const [galli, rossi, romano] = v.trasmissione!.persone;
    const partita = { ...rossi, invio: { id: 502, canale: 'lettera', mezzo: 'raccomandata', stato: 'spedita', esito: null, esito_parola: null, esito_il: null, ricevuta_il: null,
        spedito: true, errore: null, creato_il: '2026-12-15T20:38:00Z' } };
    const bianchi = { ...rossi, anagrafica_id: 16, nome: 'Bianchi Anna', unita: 'Int. 14', invio: null, perche: 'in sala dalle 19:20: assente ai punti 3 e 4', punti_assente: [3, 4], voti: [],
        termini: [{ punti: [3, 4], perche: 'assente', dal: null, fino_al: null, da_consegnare: true }] };

    return { ...v, trasmissione: { ...v.trasmissione!, persone: [galli, partita, bianchi, romano], quanti: 3 } };
}

async function monta(v: VerbaleDellaPagina = conTreRighe()) {
    w = mount(TrasmissioneDelVerbale, { attachTo: document.body, props: { verbale: v, rotte: ROTTE, puoGestire: true } });
    await flushPromises();
}
const el = (sel: string) => document.body.querySelector(sel) as HTMLElement;
const testo = (x: Element | null) => (x?.textContent ?? '').replace(/\s+/g, ' ').trim();
const riga = (id: number) => el(`[data-testid="persona-della-trasmissione"][data-anagrafica="${id}"]`);
/** Le colonne della griglia dal portatile in su: la classe `lg:grid-cols-[…]`. */
const colonne = (x: Element) => x.className.split(' ').find((c) => c.startsWith('lg:grid-cols-['));

/** Apre un menu a tendina del programma dalla tastiera (Invio sul pulsante): il contenuto arriva nel body. */
async function apriMenu(trigger: HTMLElement) {
    trigger.focus();
    trigger.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
    await flushPromises();
    await new Promise((r) => setTimeout(r, 30));
    await flushPromises();
}

describe('la riga di chi riceve il verbale', () => {
    test('l\'intestazione dice le colonne; ogni riga ha le stesse colonne, con le larghezze giuste', async () => {
        await monta();
        const testa = el('[data-testid="intestazione-trasmissione"]');
        expect([...testa.children].map((c) => testa && c.textContent?.trim())).toEqual(['Chi', 'Perché lo riceve', 'Mezzo', 'Stato', 'Può impugnare', 'Azioni']);
        expect(testa.className.split(' ')).toEqual(expect.arrayContaining(['hidden', 'lg:grid']));
        expect(colonne(testa)).toBeTruthy();
        for (const id of [20, 3, 16]) expect(colonne(riga(id)), String(id)).toBe(colonne(testa));
    });

    test('chi, perché, il mezzo, lo stato con il badge staccato dal giorno, «Può impugnare» con la virgola', async () => {
        await monta();
        const galli = riga(20);
        expect([...galli.querySelector('[data-colonna="chi"]')!.children].map((x) => testo(x))).toEqual(['Galli Franco', 'Int. 20']);
        expect(testo(galli.querySelector('[data-colonna="perche"]'))).toBe('assente a tutti i punti');
        expect(testo(galli.querySelector('[data-colonna="mezzo"]'))).toBe('PEC');
        const stato = galli.querySelector('[data-colonna="stato"]')!;
        expect(testo(stato.querySelector('[data-testid="badge-stato"]'))).toBe('Consegnata');
        expect([...stato.children].map((x) => testo(x))).toEqual(['Consegnata', 'il 15/12/2026']);
        expect(stato.querySelector('[data-testid="badge-stato"]')!.parentElement!.className.split(' ')).toContain('flex-col');
        expect(testo(galli.querySelector('[data-colonna="impugnare"] [data-testid="trenta-giorni"]'))).toBe('punti 3, 4 e 6, fino al 14/01/2027');
        expect(testo(riga(16).querySelector('[data-testid="trenta-giorni"]'))).toBe('punti 3 e 4, dalla consegna');
    });

    test('il «⋯» ha i gesti della riga: «Registra la consegna» per chi è partito, «Togli dalla lista» per chi non è ancora partito; senza gesti non c\'è', async () => {
        await monta();
        expect(riga(20).querySelector('[data-testid="menu-della-persona"]')).toBeNull();
        expect(riga(3).querySelector('[data-testid="registra-la-consegna"]')).toBeNull();
        await apriMenu(riga(3).querySelector('[data-testid="menu-della-persona"]') as HTMLElement);
        expect(el('[data-testid="voce-registra-la-consegna"]')).not.toBeNull();
        expect(document.body.querySelector('[data-testid="voce-togli"]')).toBeNull();
        el('[data-testid="voce-registra-la-consegna"]').click();
        await flushPromises();
        // La finestra della consegna, aperta sulla riga di Rossi Mario.
        expect(el('[data-testid="registra-consegna"]')).not.toBeNull();
        expect(testo(document.body)).toContain('Rossi Mario · Int. 3, box 21 · Raccomandata');
        w!.unmount();
        document.body.innerHTML = '';
        await monta();
        await apriMenu(riga(16).querySelector('[data-testid="menu-della-persona"]') as HTMLElement);
        el('[data-testid="voce-togli"]').click();
        await flushPromises();
        expect(el('[data-testid="finestra-togli"]')).not.toBeNull();
        // L'esempio del perché senza genere (rilievo C59).
        expect(el('[data-testid="perche-togli"]').getAttribute('placeholder'))
            .toBe('Per esempio: la persona è di nuovo in sala dalle 19:40, prima del voto del punto 4, e in sala non è stata segnata.');
    });

    test('al telefono una scheda per persona: il bordo e gli angoli sulla riga, «Può impugnare» detto per esteso; dal portatile in su la tabella', async () => {
        await monta();
        const galli = riga(20);
        expect(galli.className.split(' ')).toEqual(expect.arrayContaining(['rounded-lg', 'border', 'lg:rounded-none', 'lg:border-0']));
        const etichetta = galli.querySelector('[data-colonna="impugnare"] [data-testid="etichetta-al-telefono"]')!;
        expect(testo(etichetta)).toBe('Può impugnare');
        expect(etichetta.className.split(' ')).toContain('lg:hidden');
    });
});

describe('il rendiconto e il riparto votati a parte (Coda 750 B2)', () => {
    /**
     * Il punto 1 votato due volte: Rossi Mario era in sala al rendiconto (favorevole) e fuori al riparto; Romano Ida contraria al rendiconto e favorevole al
     * riparto. I gruppi di «Chi riceve» dicono «Mai in sala» (rilievo C59).
     */
    function conIlRiparto(): VerbaleDellaPagina {
        const v = registrato();
        const [galli, rossi, romano] = v.trasmissione!.persone;
        const voce = (oggetto: 'rendiconto' | 'riparto', votazione: number, delibera: number) => ({ votazione_id: votazione, delibera_id: delibera, numero: 1, oggetto });
        const fuori = { ...rossi, perche: 'fuori dalle 19:02: assente al riparto del punto 1', punti_assente: [1], voti: {},
            voti_per_votazione: [{ ...voce('rendiconto', 101, 71), voto: 'favorevole' }, { ...voce('riparto', 102, 72), voto: null }],
            termini: [{ punti: [1], perche: 'assente', dal: null, fino_al: null, da_consegnare: true, delibere: [72], voci: [voce('riparto', 102, 72)] }] };
        const contraria = { ...romano, voti: {}, voti_per_votazione: [{ ...voce('rendiconto', 101, 71), voto: 'contrario' }, { ...voce('riparto', 102, 72), voto: 'favorevole' }],
            termini: [{ punti: [1], perche: 'contrario', dal: '2026-12-15', fino_al: '2027-01-14', da_consegnare: false, delibere: [71], voci: [voce('rendiconto', 101, 71)] }] };
        const termini = { delibere: [
            { delibera_id: 71, punto: 1, votazione: 1, oggetto: 'rendiconto', esito: 'approvata', deliberata_il: '2026-12-15',
                chi: [{ anagrafica_id: 13, nome: 'Romano Ida', perche: 'contrario', dal: '2026-12-15', fino_al: '2027-01-14' }], per_tutti: '2027-01-14' },
            { delibera_id: 72, punto: 1, votazione: 2, oggetto: 'riparto', esito: 'approvata', deliberata_il: '2026-12-15',
                chi: [{ anagrafica_id: 3, nome: 'Rossi Mario', perche: 'assente', dal: null, fino_al: null }], per_tutti: null },
        ], per_tutti: null };

        return { ...v, trasmissione: { ...v.trasmissione!, persone: [galli, fuori, contraria] as never }, termini: termini as never };
    }

    test('«Può impugnare» dice quale delle due votazioni: il riparto dalla consegna, il rendiconto favorevole senza termine', async () => {
        await monta(conIlRiparto());
        const righe = [...riga(3).querySelectorAll('[data-testid="trenta-giorni"] > span')].map((x) => testo(x));
        expect(righe).toEqual(['il riparto del punto 1, dalla consegna', 'il rendiconto del punto 1, favorevole, nessun termine']);
        expect(testo(riga(3).querySelector('[data-colonna="perche"]'))).toBe('fuori dalle 19:02: assente al riparto del punto 1');
    });

    test('«Chi c\'era»: la riga del rendiconto con il suo oggetto; i gruppi con le parole senza genere', async () => {
        await monta(conIlRiparto());
        expect(testo(el('[data-testid="chi-cera"]'))).toContain('Punto 1, il rendiconto · Romano Ida (contrario) · fino al 14/01/2027');
        expect(testo(el('[data-gruppo="mai_entrati"]'))).toContain('Mai in sala · 1');
    });
});
