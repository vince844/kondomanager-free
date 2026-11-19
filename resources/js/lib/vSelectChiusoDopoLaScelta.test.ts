// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * I menu `v-select` si chiudono dopo la scelta (`vSelectChiusoDopoLaScelta.ts`): il menu della tabella millesimale nella finestra «Nuova voce di spesa o
 * capitolo», visto aperto dopo la scelta nella beta.12, e quello della visibilità della comunicazione (H2). `vue-select` invertiva il menu dopo una scelta:
 * con il campo senza il cursore, o con una seconda scelta, lo riapriva.
 *
 * Che cosa non copre: il clic vero nel browser dentro la finestra (a video); la ragione per cui, nella finestra del piano dei conti, il campo perde il
 * cursore prima della scelta (qualunque sia, il menu ora si chiude lo stesso); i menu con `close-on-select` spento, che restano come sono.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import vSelect from 'vue-select';
import { chiudiDopoLaScelta } from './vSelectChiusoDopoLaScelta';

interface Menu { open: boolean; select: (o: unknown) => void; searchEl: HTMLInputElement }

const TABELLE = [{ id: 1, nome: 'Generale' }, { id: 2, nome: 'Scale' }];
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const monta = () => {
    w = mount(vSelect as never, { attachTo: document.body, props: { options: TABELLE, label: 'nome', reduce: (t: { id: number }) => t.id } });

    return w.vm as unknown as Menu;
};

describe('il menu si chiude dopo la scelta', () => {
    test('una seconda scelta, con il campo senza il cursore, non lo riapre', () => {
        const menu = monta();
        menu.open = true;
        menu.select(TABELLE[0]);
        expect(menu.open).toBe(false);
        // La seconda scelta (il ridisegno della pagina, la finestra che sposta il cursore): con l'inversione di `vue-select` il menu tornava aperto.
        menu.select(TABELLE[1]);
        expect(menu.open).toBe(false);
    });

    test('la scelta con il menu già chiuso lo lascia chiuso', () => {
        const menu = monta();
        menu.open = false;
        menu.select(TABELLE[1]);
        expect(menu.open).toBe(false);
        expect(w!.emitted('update:modelValue')!.at(-1)![0]).toBe(2);
    });

    test('la correzione si mette una volta sola, e l\'applicazione la carica prima delle pagine', () => {
        const prima = (vSelect as unknown as { methods: Record<string, unknown> }).methods.onAfterSelect;
        chiudiDopoLaScelta();
        expect((vSelect as unknown as { methods: Record<string, unknown> }).methods.onAfterSelect).toBe(prima);
        expect(readFileSync(join(__dirname, '..', 'app.ts'), 'utf8')).toContain("import './lib/vSelectChiusoDopoLaScelta';");
    });
});
