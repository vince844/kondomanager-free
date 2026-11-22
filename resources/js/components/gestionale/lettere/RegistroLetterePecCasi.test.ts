// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La lettera per PEC nella scheda «Corrispondenza», i casi che contano (1.12.0-beta.13):
 *
 * - la PEC con l'avviso di mancata consegna: il badge rosso, la frase del server, «Manda di nuovo per PEC» e «Stampala per la posta»;
 * - «Stampala per la posta» chiede il PDF della lettera rifatta con le cifre di oggi, per quella riga;
 * - «Allega la prova» non c'è sulla riga della PEC (la prova della PEC è la ricevuta, nella sua zona); c'è sulla riga della stampa;
 * - la prova della consegna scritta a mano si dice «prova della consegna», l'avviso di mancata consegna si dice così;
 * - l'evento dell'avviso di mancata consegna, con le parole del server.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn(), post: vi.fn(), delete: vi.fn() }, usePage: () => ({ props: { errors: {} } }) }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
const apri = vi.fn();
vi.mock('@/lib/gestionale/incassi/ricevute', async (originale) => ({ ...(await originale<object>()), apriPdfInUnaScheda: (...a: unknown[]) => apri(...a) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}/${p.invio ?? ''}/${p.documento ?? ''}`;

const RegistroLettere = (await import('./RegistroLettere.vue')).default;

const FRASE = 'Non consegnata: l\'avviso di mancata consegna del gestore (Gestore PEC di prova), del 21/03 alle 11:30, dice che la PEC non è arrivata nella casella di ugo@pec.it.';
const rigaPec = (extra: Record<string, unknown> = {}) => ({
    id: 40, canale: 'pec', formato: null, destinatario: 'ugo@pec.it', stato: 'inviata', quando: '2026-03-20 11:00', errore: null, spedizione: null, spedibile: false,
    rimandabile: false,
    pec: { badge: 'Non consegnata', frase: FRASE, provata: false, mancata_consegna: true, ricevibile: true, ripetibile: true, stampabile: true,
        invio: { giorno: '2026-03-20', ora: '11:00' } },
    ...extra,
});
const lettera = (righe: unknown[], extra: Record<string, unknown> = {}) => ({
    lettera: 'l-40', tipo: 'diffida', emessa_il: '2026-03-20', ora: '11:00', emesso_da: 'Andrea Costa', totale: 18000, residuo_oggi: 18000, alla: '2026-03-20', termine: 15,
    termine_dalla_consegna: null, stato: 'non_partita', righe, pdf: null, prove: [], eventi: [], ...extra,
});

let w: VueWrapper | null = null;
const monta = (lettere: unknown[]) => {
    w = mount(RegistroLettere, { attachTo: document.body, props: { condominioId: 3, lettere: lettere as any, oggi: '2026-03-21' } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; axiosPost.mockReset(); apri.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('la PEC con l\'avviso di mancata consegna', () => {
    test('il badge rosso, la frase del server, «Manda di nuovo per PEC» e «Stampala per la posta»', () => {
        monta([lettera([rigaPec()])]);
        const badge = w!.get('[data-testid="badge-pec-40"]');
        expect(badge.text()).toBe('Non consegnata');
        expect(badge.classes()).toContain('rounded-md');
        expect(badge.classes()).toContain('text-red-800');
        expect(pulito(w!.text())).toContain(FRASE);
        expect(w!.find('[data-testid="pec-di-nuovo-40"]').exists()).toBe(true);
        expect(w!.get('[data-testid="stampa-per-la-posta-40"]').text()).toBe('Stampala per la posta');
    });

    test('«Stampala per la posta» chiede il PDF della lettera rifatta, per quella riga', async () => {
        monta([lettera([rigaPec()])]);
        apri.mockResolvedValueOnce(undefined);
        await w!.get('[data-testid="stampa-per-la-posta-40"]').trigger('click');
        await flushPromises();
        expect(apri).toHaveBeenCalledTimes(1);
        axiosPost.mockResolvedValueOnce({ data: new Blob(['%PDF']), headers: {} });
        await (apri.mock.calls[0][0] as () => Promise<unknown>)();
        expect(axiosPost.mock.calls.at(-1)?.[0]).toBe('/gestionale.lettere.pec-non-partite.stampa//');
        expect(axiosPost.mock.calls.at(-1)?.[1]).toEqual({ invii: [40] });
    });

    test('la zona della ricevuta dice che si trascina anche l\'avviso di mancata consegna', () => {
        monta([lettera([rigaPec({ pec: { ...rigaPec().pec, badge: 'Partita', mancata_consegna: false, ripetibile: false, stampabile: false } })])]);
        expect(pulito(w!.text())).toContain('Anche l\'avviso di mancata consegna: il programma lo registra, e la lettera non conta come arrivata.');
    });
});

describe('le prove e gli eventi', () => {
    test('«Allega la prova» non c\'è sulla riga della PEC; c\'è sulla riga della stampa', () => {
        const stampa = { id: 41, canale: 'stampa', formato: 'stampa', destinatario: null, stato: 'stampata', quando: '2026-03-20 11:05', errore: null, spedizione: null,
            spedibile: true, rimandabile: false, pec: null };
        monta([lettera([rigaPec(), stampa])]);
        expect(w!.find('[data-testid="allega-prova-40"]').exists()).toBe(false);
        expect(w!.find('[data-testid="allega-prova-41"]').exists()).toBe(true);
    });

    test('la prova della consegna scritta a mano e l\'avviso di mancata consegna si dicono per quello che sono', () => {
        monta([lettera([rigaPec()], { prove: [
            { id: 7, invio_id: 40, nome: 'foto.jpg', aggiunta_il: '2026-03-21', rimovibile: false, della_consegna: true, consegna_a_mano: true, mancata_consegna: false },
            { id: 8, invio_id: 40, nome: 'mancata.eml', aggiunta_il: '2026-03-21', rimovibile: false, della_consegna: true, consegna_a_mano: false, mancata_consegna: true },
        ], eventi: [{ evento: 'mancata_consegna', il: '2026-03-21T10:00:00+00:00', giorno: '2026-03-21', da_nome: 'Andrea Costa', parole: 'non consegnata: avviso del 21/03 alle 11:30' }] })]);
        const t = pulito(w!.text());
        const prove = w!.findAll('li').filter((li) => li.text().includes('non si toglie')).map((li) => pulito(li.text()));
        expect(prove.find((x) => x.startsWith('foto.jpg'))).toContain('prova della consegna · non si toglie');
        expect(prove.find((x) => x.startsWith('mancata.eml'))).toContain('avviso di mancata consegna · non si toglie');
        expect(t).toContain('avviso di mancata consegna caricato il 21/03/2026, da Andrea Costa (non consegnata: avviso del 21/03 alle 11:30)');
    });
});
