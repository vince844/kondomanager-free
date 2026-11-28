// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra «Incolla da un foglio di calcolo»: l'anteprima dice ciò che è stato capito, e
 * «Applica» consegna **solo** le righe riconosciute. Una riga da guardare non entra nell'elenco
 * in silenzio: resta nel riquadro arancio, con il suo motivo. E dove il foglio si legge in due
 * modi la finestra chiede, senza mostrare righe finché non ha la risposta.
 */

import { afterEach, describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import IncollaMillesimi from './IncollaMillesimi.vue';

const unita = [
    { id: 1, nome: 'Appartamento 1', particella_catasto: '658', subalterno_catasto: '10', palazzina: { name: 'Palazzina B' } },
    { id: 2, nome: 'Appartamento 2', particella_catasto: '658', subalterno_catasto: '11', palazzina: null },
    { id: 3, nome: 'Appartamento 1', particella_catasto: '651', subalterno_catasto: '10', palazzina: { name: 'Palazzina A' } },
];

const nel = <T extends Element>(selettore: string) => document.body.querySelector<T>(`[data-test="${selettore}"]`);
const testoDi = (selettore: string) => (nel(selettore)?.textContent ?? '').replace(/\s+/g, ' ').trim();

async function apriEIncolla(testo: string, props: Record<string, unknown> = {}) {
    const w = mount(IncollaMillesimi, { props: { open: true, unita, inTabella: [1, 2], adesso: { 1: '90.00', 2: null }, ...props }, attachTo: document.body });
    await nextTick();
    const area = nel<HTMLTextAreaElement>('incolla-testo')!;
    area.value = testo;
    area.dispatchEvent(new Event('input'));
    await nextTick();

    return w;
}

async function premi(selettore: string) {
    nel<HTMLButtonElement>(selettore)!.click();
    await nextTick();
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('l\'anteprima', () => {
    test('senza niente di riconosciuto «Applica» resta spento, e la riga sta nel riquadro con il motivo', async () => {
        await apriEIncolla('Appartamento 9\t10');

        expect(nel<HTMLButtonElement>('incolla-applica')!.disabled).toBe(true);
        expect(testoDi('incolla-da-guardare')).toContain('Una riga da guardare');
        expect(testoDi('incolla-da-guardare')).toContain('Riga 1 · Appartamento 9: nessuna unità con questo nome');
        expect(nel('incolla-riconosciute')).toBeNull();
    });

    test('il riepilogo sta nel piede, accanto ai pulsanti: «2 riconosciute · 1 da guardare»', async () => {
        await apriEIncolla('Unità\tMillesimi\nAppartamento 1\t94,91\n658/11\t95,97\nAppartamento 9\t10');

        expect(testoDi('incolla-riepilogo')).toBe('2 riconosciute · 1 da guardare');
        expect(nel('incolla-riepilogo')!.parentElement!.contains(nel('incolla-applica'))).toBe(true);
    });

    test('la colonna «Adesso» mostra il valore che l\'unità ha nella griglia: scritto, vuoto, o non ancora in tabella', async () => {
        await apriEIncolla('Appartamento 1\t94,91\n658/11\t95,97\n651/10\t50', {});

        const righe = Array.from(nel('incolla-riconosciute')!.querySelectorAll('tbody tr')).map((tr) =>
            Array.from(tr.querySelectorAll('td')).map((td) => td.textContent!.trim()));

        expect(nel('incolla-riconosciute')!.querySelector('thead')!.textContent).toContain('Adesso');
        expect(righe).toEqual([
            ['1', 'Appartamento 1', 'Appartamento 1 · Palazzina B', '90.00', '94.91'],
            ['2', '658/11', 'Appartamento 2', 'vuoto', '95.97'],
            ['3', '651/10', 'Appartamento 1 · Palazzina A', '—', '50'],
        ]);
    });

    test('un nome che è in due palazzine si legge sull\'unità già in tabella', async () => {
        await apriEIncolla('Appartamento 1\t94,91');

        expect(testoDi('incolla-riconosciute')).toContain('Appartamento 1 · Palazzina B');
    });

    test('niente scritte sotto i 12 px e nessun carattere a spaziatura fissa, nemmeno nella casella del testo', async () => {
        await apriEIncolla('Unità\tMillesimi\nAppartamento 1\t94,91\nAppartamento 9\t10');

        const classi = Array.from(nel('incolla-finestra')!.querySelectorAll('*')).map((e) => e.getAttribute('class') ?? '').join(' ');
        expect(classi).not.toMatch(/font-mono/);
        expect(classi).not.toMatch(/text-\[(\d|1[01])px\]/);
    });

    test('la finestra è ancorata in alto e il contenuto scorre dentro', async () => {
        await apriEIncolla('Appartamento 1\t94,91');

        const classi = nel('incolla-finestra')!.getAttribute('class')!;
        expect(classi).toContain('top-[5vh]');
        expect(classi).toContain('translate-y-0');
        expect(classi).toContain('max-h-[90vh]');
    });
});

describe('le due domande', () => {
    test('con più di due colonne piene la finestra chiede quale sono i valori, e prima non mostra righe', async () => {
        await apriEIncolla('Unità\tProprietà\tScale\nAppartamento 1\t94,91\t120\n658/11\t95,97\t80');

        expect(testoDi('incolla-scegli-colonna')).toContain('quale sono i valori');
        expect(testoDi('incolla-scegli-colonna')).toContain('«Proprietà»');
        expect(testoDi('incolla-scegli-colonna')).toContain('«Scale»');
        expect(nel('incolla-riconosciute')).toBeNull();
        expect(nel<HTMLButtonElement>('incolla-applica')!.disabled).toBe(true);

        await premi('incolla-colonna-2');

        expect(nel('incolla-scegli-colonna')).toBeNull();
        expect(testoDi('incolla-riconosciute')).toContain('120');
        expect(testoDi('incolla-riconosciute')).not.toContain('94.91');
    });

    test('«1.250» senza altri indizi: la finestra mostra le due letture e applica quella scelta', async () => {
        const w = await apriEIncolla('Appartamento 1\t1.250\n658/11\t250');

        expect(testoDi('incolla-scegli-convenzione')).toContain('«1.250»');
        expect(testoDi('incolla-convenzione-virgola')).toMatch(/^Delle migliaia\s*si legge 1250$/);
        expect(testoDi('incolla-convenzione-punto')).toMatch(/^Dei decimali\s*si legge 1\.25$/);

        await premi('incolla-convenzione-punto');
        await premi('incolla-applica');

        expect(w.emitted('applica')).toEqual([[[{ immobileId: 1, valore: '1.25' }, { immobileId: 2, valore: '250' }]]]);
    });

    test('cambiato il testo incollato, la risposta data al foglio di prima non vale più', async () => {
        await apriEIncolla('Appartamento 1\t1.250\n658/11\t250');
        await premi('incolla-convenzione-punto');
        expect(nel('incolla-scegli-convenzione')).toBeNull();

        const area = nel<HTMLTextAreaElement>('incolla-testo')!;
        area.value = 'Appartamento 1\t2.500\n658/11\t250';
        area.dispatchEvent(new Event('input'));
        await nextTick();

        expect(nel('incolla-scegli-convenzione')).not.toBeNull();
    });
});

describe('«Applica»', () => {
    test('consegna solo le righe riconosciute e chiude la finestra', async () => {
        const w = await apriEIncolla('Appartamento 1\t94,91\n658/11\t95,97\nAppartamento 9\t10');

        await premi('incolla-applica');

        expect(w.emitted('applica')).toEqual([[[{ immobileId: 1, valore: '94.91' }, { immobileId: 2, valore: '95.97' }]]]);
        expect(w.emitted('update:open')).toEqual([[false]]);
    });
});
