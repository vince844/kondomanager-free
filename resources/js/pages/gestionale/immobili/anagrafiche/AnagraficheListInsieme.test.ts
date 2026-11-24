// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * 1.12.0-beta.14, tappa 7 (decisione 705) — il riquadro verde dopo «Registra passaggio» quando le persone che escono sono più d'una
 * (`AnagraficheList.vue`, chiave nuova `insieme` del flash `passaggio_registrato`: §1.9 di `tappa7_progetto.md`).
 *
 * Il riquadro dice «2 passaggi registrati insieme», la frase dell'insieme che manda il server («Cecilia Angeli e Marta Acciaioli risultano titolari fino al 15
 * novembre 2026 compreso. Luca Toscani dal 16 novembre 2026.»), il conguaglio scritto in saldi persona per persona e quello che arriva a chi entra
 * («€ 20,49 a credito di Cecilia Angeli, € 20,49 a credito di Marta Acciaioli, € 40,98 a debito di Luca Toscani»), le bozze passate, e le due azioni su chi
 * entra (l'estratto conto e la scheda). Per un passaggio da solo (`insieme` assente o nullo) il riquadro resta quello di sempre: «Passaggio registrato.».
 *
 * Cosa NON copre: il server che compone `insieme` e la frase; il modo scuro e le larghezze.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

const stato = vi.hoisted(() => ({ flash: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { flash: stato.flash, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const AnagraficheList = (await import('./AnagraficheList.vue')).default;

const BASE = {
    subentro_id: 7, frase: 'Cecilia Angeli e Marta Acciaioli risultano titolari fino al 15 novembre 2026 compreso. Luca Toscani dal 16 novembre 2026.',
    coppie: 4, conguaglio: '€ 40,98', conguaglio_rovesciato: false, riassegnate: 2,
    riassegnate_frase: 'Le 2 quote in bozza della rata del 5 dicembre 2026 sono passate a Luca Toscani.',
    rinuncia: false, non_scritto: false, documento: null, promemoria: null, avvisi: [], entrante: 'Luca Toscani',
    azioni: { estratto_conto: '/estratto/9', anagrafe: '/scheda/9' },
};

const INSIEME = [
    { nome: 'Cecilia Angeli', quota: 50, frase_conguaglio: '€ 20,49 a credito di Cecilia Angeli', estratto_conto: '/estratto/1' },
    { nome: 'Marta Acciaioli', quota: 50, frase_conguaglio: '€ 20,49 a credito di Marta Acciaioli', estratto_conto: '/estratto/2' },
];

function monta(registrato: Record<string, unknown>) {
    stato.flash = { passaggio_registrato: registrato };

    return shallowMount(AnagraficheList, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 7, nome: 'Int. 1A', interno: '1A', anagrafiche: [] } as never,
            storico: { passaggi: 1, periodi_chiusi: 0, righe: [], gruppi: [], subentri: [] } as never,
            oggi: '2026-11-23',
            prospettoOneri: null,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
}

const riquadro = (w: ReturnType<typeof monta>) => (w.find('[data-testid="passaggio-registrato"]').text() ?? '').replace(/\s+/g, ' ').replace(/ /g, ' ');

describe('AnagraficheList — il riquadro verde dopo i passaggi registrati insieme (tappa 7)', () => {
    test('dice «2 passaggi registrati insieme», con la frase dell\'insieme e le due azioni su chi entra', () => {
        const w = monta({ ...BASE, insieme: INSIEME });
        const t = riquadro(w);

        expect(t).toContain('2 passaggi registrati insieme.');
        expect(t).not.toContain('Passaggio registrato.');
        expect(t).toContain('Cecilia Angeli e Marta Acciaioli risultano titolari fino al 15 novembre 2026 compreso. Luca Toscani dal 16 novembre 2026.');
        expect(w.findAll('a').filter((a) => a.text().includes('Estratto conto di Luca Toscani'))).toHaveLength(1);
        expect(w.findAll('a').filter((a) => a.text().includes('Scheda di Luca Toscani'))).toHaveLength(1);
    });

    test('il conguaglio scritto in saldi, persona per persona e quello che arriva a chi entra', () => {
        const t = riquadro(monta({ ...BASE, insieme: INSIEME }));

        expect(t).toContain('Conguaglio scritto in saldi: € 20,49 a credito di Cecilia Angeli, € 20,49 a credito di Marta Acciaioli, € 40,98 a debito di Luca Toscani.');
        expect(t).toContain('Le 2 quote in bozza della rata del 5 dicembre 2026 sono passate a Luca Toscani.');
        expect(t).not.toContain('2 coppie');
    });

    test('con il conguaglio rovesciato chi entra è a credito', () => {
        const t = riquadro(monta({ ...BASE, conguaglio: '€ 5,48', conguaglio_rovesciato: true, insieme: INSIEME }));

        expect(t).toContain('€ 5,48 a credito di Luca Toscani.');
    });

    test('senza righe in saldi (le parti hanno regolato) il riquadro lo dice, e non elenca conguagli', () => {
        const t = riquadro(monta({ ...BASE, coppie: 0, rinuncia: true, conguaglio: null, riassegnate: 0, riassegnate_frase: null, insieme: INSIEME.map((i) => ({ ...i, frase_conguaglio: null })) }));

        expect(t).toContain('2 passaggi registrati insieme.');
        expect(t).toContain('Nessuna riga in saldi: hai indicato che il conguaglio è regolato fra le parti.');
        expect(t).not.toContain('Conguaglio scritto in saldi');
    });

    test('un passaggio da solo resta com\'era: «Passaggio registrato.» e le coppie a somma zero', () => {
        const t = riquadro(monta({ ...BASE, insieme: null, frase: 'Ugo risulterà titolare fino al 30 aprile 2026 compreso.' }));

        expect(t).toContain('Passaggio registrato.');
        expect(t).not.toContain('insieme');
        expect(t).toContain('Conguaglio scritto in saldi: 4 coppie a somma zero, € 40,98 a debito di chi entra.');
    });
});
