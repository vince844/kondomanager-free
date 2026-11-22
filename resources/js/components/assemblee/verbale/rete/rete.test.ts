// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I pezzi del verbale senza rete, con le parole delle scene 3 e 4 della bozza approvata dell'editor:
 *
 * - la scritta del salvataggio nei suoi stati, con «Rientra» solo per la sessione scaduta;
 * - il lavoro ritrovato, con «Vedi che cosa è partito»;
 * - le due versioni: l'avviso con chi e quando, «Confronta le due versioni» affiancate in sola lettura (la finestra ancorata in alto, il riepilogo nel piede),
 *   «Tieni questa» su ognuna e «Uniscile»; il testo di un utente resta testo;
 * - l'avviso del browser prima dell'editor: la finestra privata, la nota leggera, l'indirizzo senza https;
 * - gli standard: nessun `v-html`, niente scritte sotto i 12 pixel, ogni colore con il suo tema scuro.
 *
 * Che cosa non copre: come la pagina li monta (la pagina del verbale); i 375 pixel (la prova a video).
 */
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { h, nextTick } from 'vue';
import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import type { EsitoDiUnGesto } from '@/lib/senzaRete/coda';
import type { VoceInCoda } from '@/lib/senzaRete/deposito';
import { type DueVersioniDelTesto, fraseDelLavoroRitrovato, scrittaDelSalvataggio } from '@/lib/senzaRete/verbale';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Stato = (await import('./StatoDelSalvataggio.vue')).default;
const Ritrovato = (await import('./LavoroRitrovatoDelVerbale.vue')).default;
const DueVersioni = (await import('./DueVersioniDelVerbale.vue')).default;
const Avviso = (await import('./AvvisoDelBrowserDelVerbale.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    document.body.innerHTML = '';
});
const campo = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;
const testo = (id: string) => (campo(id)?.textContent ?? '').replace(/\s+/g, ' ').trim();
const doc = (t: string): NodoDelVerbale => ({ type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: t }] }] });
const base = { stato: 'in_linea' as const, serverFermo: false, daMandare: false, inAttesa: 0, salvatoAlle: null, partite: null };

describe('la scritta del salvataggio', () => {
    test('i quattro stati della bozza, ognuno con il suo colore e il tema scuro; «Rientra» solo per la sessione scaduta', async () => {
        const stati = [
            [scrittaDelSalvataggio({ ...base, salvatoAlle: '2026-12-15T19:14:00Z' }), 'Salvato alle 20:14 · tutto sul server'],
            [scrittaDelSalvataggio({ ...base, daMandare: true }), 'Salvataggio… · pochi secondi'],
            [scrittaDelSalvataggio({ ...base, stato: 'senza_rete', inAttesa: 3 }), 'Non salvato: senza connessione. Lo tengo qui, nel browser. · 3 modifiche in attesa'],
            [scrittaDelSalvataggio({ ...base, partite: { quante: 3, alle: '2026-12-15T19:19:00Z' } }), 'Di nuovo in linea: salvato alle 20:19 · le 3 modifiche sono partite'],
        ] as const;
        for (const [scritta, parole] of stati) {
            w?.unmount();
            w = mount(Stato, { attachTo: document.body, props: { scritta } });
            expect(testo('stato-del-salvataggio')).toBe(parole);
            expect(campo('stato-del-salvataggio')!.className).toMatch(/dark:text-/);
            expect(campo('salvataggio-rientra')).toBeNull();
        }
        w?.unmount();
        w = mount(Stato, { attachTo: document.body, props: { scritta: scrittaDelSalvataggio({ ...base, stato: 'sessione_scaduta', inAttesa: 1 }) } });
        campo('salvataggio-rientra')!.click();
        expect(w.emitted('rientra')).toHaveLength(1);
        expect(campo('stato-del-salvataggio')!.getAttribute('role')).toBe('status');
    });
});

describe('il lavoro ritrovato', () => {
    test('le frasi della scena 4 e «Vedi che cosa è partito»; chiudi', async () => {
        const voce = (parte: string, il: string): VoceInCoda => ({ gesto: parte + il, ambito: 'verbale:12:5', ordine: 1, sessione: 'x', tipo: 'testo', base: 0, orologio: il,
            segnato_il: il, dati: { verbale_id: 7, parte, documento: doc('a') } });
        const modifiche = [{ parte: 'punto:31', il: '2026-12-15T19:16:00Z' }, { parte: 'punto:31', il: '2026-12-15T19:18:00Z' }];
        const esiti = [{ gesto: 'g', tipo: 'testo', esito: 'applicato', voce: voce('punto:31', '2026-12-15T19:18:00Z') }] as EsitoDiUnGesto[];
        const lavoro = { modifiche, finito: true, arrivate_alle: '2026-12-15T19:31:00Z', esiti };
        const punti = [{ punto_id: 31, numero: 4 }];
        w = mount(Ritrovato, { attachTo: document.body, props: { ritrovato: { ...lavoro, frasi: fraseDelLavoroRitrovato(lavoro, punti) }, punti } });
        expect(testo('verbale-ritrovato-titolo')).toBe('Ho ritrovato 2 modifiche che non erano partite, e le ho mandate.');
        expect([...document.body.querySelectorAll('[data-testid="verbale-ritrovato-riga"]')].map((x) => x.textContent)).toEqual([
            'Del 15/12/2026, dalle 20:16 alle 20:18: due correzioni alla discussione del punto 4.', 'Arrivate alle 20:31.']);
        expect(campo('verbale-che-cosa-e-partito')).toBeNull();
        campo('verbale-vedi-che-cosa-e-partito')!.click();
        await nextTick();
        expect(testo('verbale-che-cosa-e-partito')).toBe('20:18 · La discussione del punto 4: arrivata');
        campo('verbale-ritrovato-chiudi')!.click();
        expect(w.emitted('chiudi')).toHaveLength(1);
    });
});

describe('le due versioni', () => {
    const due: DueVersioniDelTesto = {
        parte: 'punto:31', nome: 'La discussione del punto 4', versione_id: 41,
        arrivata: { documento: doc('Dal portatile: <script>alert(1)</script>'), dispositivo: 'computer di Andrea Costa#11aa22bb', il: '2026-12-15T19:18:00Z', daQui: true },
        adesso: { documento: doc('Dal tablet'), dispositivo: 'tablet di Marco Galli#ab12cd34', il: '2026-12-15T19:24:00Z', daQui: false },
        frase: 'La discussione del punto 4 è stata cambiata anche dal tablet di Marco Galli, alle 20:24. Non sovrascrivo niente: le due versioni restano, e scegli tu '
            + 'quale tenere, o le unisci.',
    };

    test('l\'avviso, poi «Confronta le due versioni» affiancate in sola lettura; «Tieni questa» su ognuna; «Uniscile»; il testo resta testo', async () => {
        w = mount(DueVersioni, { attachTo: document.body, props: { due } });
        expect(testo('due-versioni-frase')).toBe(due.frase);
        expect(campo('finestra-confronto')).toBeNull();
        campo('confronta-le-due-versioni')!.click();
        await nextTick();
        await nextTick();
        const finestra = campo('finestra-confronto')!;
        expect(finestra.className).toContain('top-[5vh]');
        expect(finestra.className).toContain('translate-y-0');
        expect(testo('versione-arrivata')).toContain('Da questo computer, alle 20:18');
        expect(testo('versione-arrivata')).toContain('Dal portatile: <script>alert(1)</script>');
        expect(testo('versione-adesso')).toContain('Dal tablet di Marco Galli, alle 20:24');
        expect(document.body.querySelector('[data-testid="versione-arrivata"] script')).toBeNull();
        expect(testo('riepilogo-confronto')).toBe('Quella che non tieni resta fra le versioni: niente si perde.');
        expect(campo('piede-confronto')!.contains(campo('uniscile'))).toBe(true);

        campo('tieni-arrivata')!.click();
        campo('tieni-adesso')!.click();
        expect(w.emitted('scegli')).toEqual([['questa', 41], ['quella', 41]]);
        campo('uniscile')!.click();
        expect(w.emitted('uniscile')).toEqual([[due]]);
    });

    test('il testo in sola lettura lo disegna chi monta il pezzo, se vuole; senza il permesso di scegliere, solo il confronto', async () => {
        w = mount(DueVersioni, { attachTo: document.body, props: { due, puoScegliere: false },
            slots: { testo: (p: { documento: NodoDelVerbale }) => h('em', { 'data-testid': 'testo-di-chi-monta' }, String(p.documento.content?.length)) } });
        campo('confronta-le-due-versioni')!.click();
        await nextTick();
        await nextTick();
        expect(document.body.querySelectorAll('[data-testid="testo-di-chi-monta"]')).toHaveLength(2);
        expect(campo('tieni-arrivata')).toBeNull();
        expect(campo('uniscile')).toBeNull();
    });
});

describe('l\'avviso del browser prima dell\'editor', () => {
    test('la finestra privata: la finestra con «Copia l\'indirizzo» e «Apri lo stesso», ancorata in alto', async () => {
        w = mount(Avviso, { attachTo: document.body, props: { tenuta: 'non_tiene', indirizzo: 'https://studio.example/il-verbale' } });
        await nextTick();
        expect(campo('verbale-avviso-finestra-privata')!.className).toContain('top-[5vh]');
        expect(document.body.textContent).toContain('Questo browser non tiene il testo del verbale');
        campo('verbale-apri-lo-stesso')!.click();
        expect(w.emitted('apri-lo-stesso')).toHaveLength(1);
        expect(w.emitted('update:open')).toEqual([[false]]);
    });

    test('il browser che tiene senza promettere: la nota; quello che tiene: niente; senza https: la nota dell\'indirizzo', () => {
        w = mount(Avviso, { attachTo: document.body, props: { tenuta: 'non_promette' } });
        expect(campo('verbale-nota-del-browser')).not.toBeNull();
        w.unmount();
        w = mount(Avviso, { attachTo: document.body, props: { tenuta: 'tiene' } });
        expect(campo('verbale-nota-del-browser')).toBeNull();
        expect(campo('verbale-avviso-finestra-privata')).toBeNull();
        w.unmount();
        w = mount(Avviso, { attachTo: document.body, props: { tenuta: 'tiene', sicuro: false } });
        expect(testo('verbale-nota-indirizzo')).toContain('Su questo indirizzo (senza https) il verbale non si riapre senza rete.');
    });
});

describe('gli standard dei pezzi', () => {
    test('nessun v-html, niente scritte sotto i 12 pixel, ogni colore di sfondo con il suo tema scuro', () => {
        const cartella = join(process.cwd(), 'resources/js/components/assemblee/verbale/rete');
        for (const nome of readdirSync(cartella).filter((f) => f.endsWith('.vue'))) {
            const sorgente = readFileSync(join(cartella, nome), 'utf8');
            expect(sorgente, nome).not.toContain('v-html');
            expect(sorgente, nome).not.toMatch(/text-\[(\d|1[01])(\.\d+)?px\]|text-\[0\.[0-6]/);
            const classi = sorgente.split(/[\s"'`]+/);
            for (const classe of classi) {
                const m = /^((?:[a-z-]+:)*)bg-(?:amber|sky|violet|rose|emerald|slate)-50$/.exec(classe);
                if (!m || m[1].startsWith('dark:')) continue;
                expect(classi.some((c) => c.startsWith(`dark:${m[1]}bg-`)), `${nome}: ${classe} senza il tema scuro`).toBe(true);
            }
        }
    });
});
