// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Versioni di prima» nella pagina di modifica del documento (1.12.0-beta.13, §9 del registro delle modifiche): ogni file sostituito con il giorno, chi l'ha
 * sostituito, la grandezza all'italiana, l'impronta breve e «Scarica» verso la rotta della versione; la frase del vuoto; nessuna scritta sotto i 12 pixel,
 * nessuna pillola.
 *
 * Che cosa non copre: lo scaricamento vero (lo prova `VersioniDeiDocumentiTest` dalla rotta); la pagina intera e il tema scuro a video.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => true }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) =>
    `/${nome}?${new URLSearchParams(Object.entries(p ?? {}).map(([k, v]) => [k, String(v)])).toString()}`;

const VersioniDiPrima = (await import('./VersioniDiPrima.vue')).default;
const { grandezza, improntaBreve } = await import('@/lib/documenti/versioniDiPrima');

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

const versione = (id: number, altro: Record<string, unknown> = {}) => ({
    id, nome: 'Verbale 12-2026.pdf', sostituita_il: '16/12/2026 09:12', sostituita_da: 'Andrea Costa', grandezza: 1258291,
    impronta: '3f2a9c1b04de' + 'a'.repeat(52), ...altro,
});

describe('le parole e le cifre', () => {
    test('la grandezza come la riga del registro, all\'italiana', () => {
        expect(grandezza(820)).toBe('820 byte');
        expect(grandezza(2048)).toBe('2 KB');
        expect(grandezza(1258291)).toBe('1,2 MB');
    });

    test('l\'impronta breve: i primi dodici caratteri', () => {
        expect(improntaBreve('3f2a9c1b04de' + 'a'.repeat(52))).toBe('3f2a9c1b04de…');
    });
});

describe('la sezione', () => {
    test('una riga per versione, con il giorno, chi, la grandezza, l\'impronta e «Scarica» verso la rotta della versione', () => {
        w = mount(VersioniDiPrima, { props: { versioni: [versione(7), versione(5, { sostituita_da: null, grandezza: 2048 })], documentoId: 3 } });
        const righe = w.findAll('[data-testid="versione"]');
        expect(righe).toHaveLength(2);
        expect(righe[0].text()).toContain('Sostituita il 16/12/2026 09:12 da Andrea Costa · 1,2 MB');
        expect(righe[0].text()).toContain('Impronta 3f2a9c1b04de…');
        expect(righe[0].find('a').attributes('href')).toBe('/admin.documenti.versioni.download?documento=3&versione=7');
        expect(righe[0].find('a').text()).toBe('Scarica');
        expect(righe[1].text()).toContain('chi l\'ha sostituita non è più fra gli utenti');
        expect(w.text()).toContain('Versioni di prima');
    });

    test('nelle schede della persona, del fornitore e dell\'unità (decisione 559): la loro rotta, con i loro parametri', () => {
        w = mount(VersioniDiPrima, { props: { versioni: [versione(7)], documentoId: 3, rotta: 'gestionale.immobili.documenti.versioni.download',
            parametri: { condominio: 4, immobile: 12 } } });

        expect(w.find('a').attributes('href')).toBe('/admin.gestionale.immobili.documenti.versioni.download?condominio=4&immobile=12&documento=3&versione=7');
    });

    test('senza versioni, la frase del vuoto', () => {
        w = mount(VersioniDiPrima, { props: { versioni: [], documentoId: 3 } });
        expect(w.find('[data-testid="versioni-vuoto"]').text()).toBe('Il file non è mai stato sostituito.');
        expect(w.findAll('[data-testid="versione"]')).toHaveLength(0);
    });

    test('nessuna scritta sotto i 12 pixel, nessuna pillola', () => {
        const sorgente = readFileSync(resolve(__dirname, 'VersioniDiPrima.vue'), 'utf8');
        expect(sorgente).not.toMatch(/text-\[(\d|10|11)px\]/);
        expect(sorgente).not.toContain('rounded-full');
    });
});
