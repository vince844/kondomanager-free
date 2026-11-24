// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I voti nella linguetta «Assemblee» della scheda della persona, con il rendiconto e il riparto votati a parte (Coda 750 B2): il voto si legge per
 * votazione, e dove la riga mostra il punto il rendiconto e il riparto sono due righe («Punto 1, il rendiconto · Contrario»), il riparto favorevole va
 * nella riga dei favorevoli («Favorevole ai punti 2, 3 e 5 e al riparto del punto 1»). Il comproprietario entrato insieme a chi rappresenta l'unità è
 * «Presente, senza voto» (Coda 750 B3).
 *
 * Che cosa non copre: il server (`AssembleeDellaPersonaTest.php`, `DueVotiNelloStessoPuntoTest.php`); le card e i filtri (`AnagraficheAssemblee.test.ts`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import type { PersonaInAssemblea } from '@/lib/assemblee/persona';
import ElencoAssembleeDellaPersona from './ElencoAssembleeDellaPersona.vue';

vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const dicembre: PersonaInAssemblea = { id: 7, condominio_id: 3, condominio: 'Via Roma 12', data: '15/12/2026', anno: 2026, per_lei: { parola: 'Può impugnare', tono: 'ambra' },
    nome: 'Ordinaria del 15/12/2026', verbale: '42', convocazione: 'Per PEC · Consegnata il 05/12/2026', in_sala: 'Presente di persona', per_delega_da: null,
    favorevole_ai_punti: [2, 3, 5], favorevole_alle_parti: [{ punto: 1, oggetto: 'riparto' }],
    altri_voti: [
        { punto: 1, oggetto: 'rendiconto', voto: 'Contrario', testo: 'Rendiconto 2025 e stato di riparto', dichiarazioni: [] },
        { punto: 4, oggetto: null, voto: 'Astenuto', testo: 'Portone', dichiarazioni: [] },
    ],
    puo_impugnare_fino_al: '14/01/2027', impugnare_dopo_la_consegna: false, sulle_sue_cose: [] };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; vi.restoreAllMocks(); });

async function monta(assemblee: PersonaInAssemblea[]) {
    w = mount(ElencoAssembleeDellaPersona, { attachTo: document.body, props: { assemblee } });
    await flushPromises();
}
const testo = (x: Element | null) => (x?.textContent ?? '').replace(/\s+/g, ' ').trim();

describe('i voti per votazione', () => {
    test('il rendiconto contrario e il riparto favorevole dello stesso punto: due righe diverse, ognuna con il suo oggetto', async () => {
        await monta([dicembre]);
        const voti = [...document.body.querySelectorAll('[data-testid="voto-della-persona"]')].map((x) => testo(x));
        expect(voti).toEqual(['Punto 1, il rendiconto · Contrario · Rendiconto 2025 e stato di riparto', 'Punto 4 · Astenuto · Portone']);
        expect(testo(document.body.querySelector('[data-testid="favorevoli"]'))).toBe('Favorevole ai punti 2, 3 e 5 e al riparto del punto 1');
    });

    test('le due righe dello stesso punto hanno chiavi diverse: Vue non le confonde', async () => {
        const avvisi = vi.spyOn(console, 'warn').mockImplementation(() => {});
        await monta([{ ...dicembre, favorevole_alle_parti: [], altri_voti: [
            { punto: 1, oggetto: 'rendiconto', voto: 'Contrario', testo: null, dichiarazioni: [] },
            { punto: 1, oggetto: 'riparto', voto: 'Assente', testo: null, dichiarazioni: [] },
        ] }]);
        expect([...document.body.querySelectorAll('[data-testid="voto-della-persona"]')].map((x) => testo(x)))
            .toEqual(['Punto 1, il rendiconto · Contrario', 'Punto 1, il riparto · Assente']);
        expect(avvisi.mock.calls.flat().join(' ')).not.toContain('Duplicate keys');
    });

    test('solo il riparto a favore: «Favorevole al riparto del punto 1»; chi è entrato insieme a chi rappresenta l\'unità è «Presente, senza voto»', async () => {
        await monta([{ ...dicembre, in_sala: 'Presente, senza voto', favorevole_ai_punti: [], altri_voti: [] }]);
        expect(testo(document.body.querySelector('[data-testid="favorevoli"]'))).toBe('Favorevole al riparto del punto 1');
        expect(testo(document.body.querySelector('[data-testid="assemblea-aperta"]'))).toContain('In salaPresente, senza voto');
    });
});
