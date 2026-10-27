// @vitest-environment jsdom

/**
 * Coda 306 — le etichette dello stato nelle tabelle dell'amministratore e nelle tendine dei moduli.
 *
 * La colonna dello stato di segnalazioni e comunicazioni dice la pubblicazione da `is_published` e
 * `is_approved` insieme («In attesa di approvazione», «Bozza», «Pubblicata») e, a parte, «Privata»
 * da `is_private`: una in moderazione si distingue da una bozza, e una privata pubblicata porta le
 * due indicazioni. Le stesse parole valgono per la tendina `is_published` dei moduli e per i
 * documenti, che la privacy non la salvano proprio.
 *
 * Qui si disegnano le celle vere (`columns.ts`) in un contenitore finto. Le traduzioni non sono
 * caricate, quindi `trans()` restituisce la chiave: si leggono le chiavi.
 *
 * Cosa resta scoperto: il colore, che si guarda a video.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import type { VNode } from 'vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { template: '<a><slot /></a>' },
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const { columns: colonneSegnalazioni } = await import('@/components/segnalazioni/columns');
const { columns: colonneComunicazioni } = await import('@/components/comunicazioni/columns');
const { columns: colonneDocumenti } = await import('@/components/documenti/columns');
const costantiSegnalazioni = await import('@/lib/segnalazioni/constants');
const costantiComunicazioni = await import('@/lib/comunicazioni/constants');
const costantiDocumenti = await import('@/lib/documenti/constants');

function testoDi(nodo: unknown): string {
    const w = mount({ render: () => nodo as VNode });
    return w.text().replace(/\s+/g, ' ').trim();
}

function nodoPubblicazione(colonne: unknown[], oggetto: Record<string, unknown>): unknown {
    const colonna = (colonne as Array<{ accessorKey?: string; cell?: unknown }>).find((c) => c.accessorKey === 'is_published');
    expect(colonna, 'la tabella non ha più la colonna della pubblicazione').toBeDefined();

    const riga = { original: oggetto, getValue: (k: string) => oggetto[k] };
    return (colonna!.cell as (ctx: unknown) => unknown)({ row: riga });
}

function cellaPubblicazione(colonne: unknown[], oggetto: Record<string, unknown>): string {
    return testoDi(nodoPubblicazione(colonne, oggetto));
}

/** Il colore del pallino: verde pubblicata, ambra bozza, grigio in attesa (lo stesso della scheda). */
function colorePallino(colonne: unknown[], oggetto: Record<string, unknown>): string {
    const w = mount({ render: () => nodoPubblicazione(colonne, oggetto) as VNode });
    return w.find('span.rounded-full').classes().join(' ');
}

describe.each([
    ['segnalazioni', colonneSegnalazioni],
    ['comunicazioni', colonneComunicazioni],
] as const)('la colonna dello stato delle %s', (entita, colonne) => {
    test('una non approvata dice «In attesa di approvazione», non «Privata»', () => {
        const t = cellaPubblicazione(colonne, { id: 1, is_published: false, is_approved: false, is_private: false });

        expect(t).toContain(`${entita}.publication.pending`);
        expect(t).not.toContain(`${entita}.visibility.private`);
        expect(t).not.toContain(`${entita}.privacy.private`);
    });

    test('una approvata ma non pubblicata dice «Bozza»', () => {
        const t = cellaPubblicazione(colonne, { id: 1, is_published: 0, is_approved: 1, is_private: 0 });

        expect(t).toContain(`${entita}.publication.draft`);
    });

    test('una privata pubblicata dice «Pubblicata» e, a parte, «Privata»', () => {
        const t = cellaPubblicazione(colonne, { id: 1, is_published: true, is_approved: true, is_private: true });

        expect(t).toContain(`${entita}.publication.published`);
        expect(t).toContain(`${entita}.privacy.private`);
        expect(t).not.toContain(`${entita}.visibility.public`);
    });

    test('il pallino segue lo stato: grigio in attesa, ambra bozza, verde pubblicata', () => {
        expect(colorePallino(colonne, { id: 1, is_published: true, is_approved: false, is_private: false })).toContain('bg-slate-400');
        expect(colorePallino(colonne, { id: 1, is_published: false, is_approved: true, is_private: false })).toContain('bg-amber-500');
        expect(colorePallino(colonne, { id: 1, is_published: true, is_approved: true, is_private: false })).toContain('bg-emerald-500');
    });

    test('una pubblicata non privata non porta l\'indicazione «Privata»', () => {
        const t = cellaPubblicazione(colonne, { id: 1, is_published: 1, is_approved: 1, is_private: 0 });

        expect(t).toContain(`${entita}.publication.published`);
        expect(t).not.toContain(`${entita}.privacy.private`);
    });
});

describe('la colonna dello stato dei documenti', () => {
    test('un documento non pubblicato dice «Bozza», mai «Privato»', () => {
        const t = cellaPubblicazione(colonneDocumenti, { id: 1, is_published: false, is_approved: true, categorie: [], condomini: { full: [] } });

        expect(t).toContain('documenti.publication.draft');
        expect(t).not.toContain('documenti.visibility.private');
    });

    test('un documento pubblicato dice «Pubblicato»', () => {
        const t = cellaPubblicazione(colonneDocumenti, { id: 1, is_published: true, is_approved: true, categorie: [], condomini: { full: [] } });

        expect(t).toContain('documenti.publication.published');
        expect(t).not.toContain('documenti.visibility.public');
    });
});

describe('la tendina «is_published» dei moduli', () => {
    test.each([
        ['segnalazioni', costantiSegnalazioni.publishedConstants],
        ['comunicazioni', costantiComunicazioni.publishedConstants],
        ['documenti', costantiDocumenti.publishedConstants],
    ] as const)('%s: le due voci sono «Bozza» e la pubblicazione, non «Privata/Pubblica»', (entita, costanti) => {
        const etichette = Object.fromEntries(costanti.map((c) => [String(c.value), c.label]));

        expect(etichette).toEqual({
            false: `${entita}.publication.draft`,
            true: `${entita}.publication.published`,
        });
    });
});
