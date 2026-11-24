// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 26 (1.11.0-beta.35) — il pannello della competenza su una fattura pregressa.
 *
 * Con una parte non coperta dai saldi iniziali il periodo è obbligatorio e si chiude prima dell'esercizio: il pannello lo
 * annuncia con la data d'inizio dell'esercizio, prima dell'invio (la regola vera sta in `StoreFatturaRequest`). Senza parte
 * scoperta resta un invito. Il testo generale dice che la competenza guida le rate in ogni piano rate straordinario, anche su
 * gestione ordinaria — fino alla beta.34 diceva «solo su una gestione straordinaria», e dalla decisione 26 era falso.
 *
 * Cosa NON copre: il controllo prima dell'invio (sta in `FatturaRegisterNew.test.ts`) e l'arrivo dell'importo della
 * pregressa dall'import XML.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import CompetenzaFattura from './CompetenzaFattura.vue';

const monta = (props: Record<string, unknown>) => mount(CompetenzaFattura, { props: { dal: '', al: '', ...props } });

describe('CompetenzaFattura — la pregressa e il suo periodo (decisione 26)', () => {
    test('pregressa con una parte non coperta: il periodo è obbligatorio, e si dice fino a quando', () => {
        const testo = monta({ pregressa: true, periodoObbligatorio: true, inizioEsercizio: '2026-01-01' }).text();
        expect(testo).toContain('il periodo in cui il costo è maturato è obbligatorio');
        expect(testo).toContain('si chiude prima del 01/01/2026');
    });

    test('pregressa tutta coperta: solo un invito, niente obbligo', () => {
        const testo = monta({ pregressa: true, periodoObbligatorio: false }).text();
        expect(testo).not.toContain('è obbligatorio');
        expect(testo).toContain('se una parte non è coperta dai saldi iniziali');
    });

    test('il testo generale non dice più «solo su una gestione straordinaria»', () => {
        const testo = monta({}).text();
        expect(testo).toContain('anche su una gestione ordinaria');
        expect(testo).not.toContain('Guida le rate solo se');
    });

    test('ogni scelta ha la sua spiegazione dentro il pulsante, senza un secondo elenco che sembri altre scelte; niente scritte sotto i 12 pixel', async () => {
        const w = monta({});
        expect(w.get('[data-testid="competenza-a-che-serve"]').text()).toContain('A che cosa serve: dice a chi tocca la spesa se l\'unità cambia proprietario.');
        const scelte = w.findAll('[role="radio"]').map((b) => b.text().replace(/\s+/g, ' ').trim());
        expect(scelte).toHaveLength(3);
        expect(scelte[0]).toContain('Nessuna');
        expect(scelte[0]).toContain('Il caso normale');
        expect(scelte[1]).toContain('il riscaldamento da ottobre a marzo');
        expect(scelte[2]).toContain('il giorno della delibera');
        // Prima uno schema sotto i pulsanti ripeteva i nomi delle scelte, e sembrava un secondo elenco da cliccare (prova a video, 20/11).
        expect(w.find('[data-testid="competenza-spiegazione"] li').exists()).toBe(false);
        expect(w.text()).toContain('in un piano approvato non si cambia più');
        const { readFileSync } = await import('node:fs');
        const { resolve } = await import('node:path');
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/gestionale/movimenti/fatture/CompetenzaFattura.vue'), 'utf8');
        expect(sorgente).not.toMatch(/text-\[(9|10|10\.5|11)px\]/);
    });
});

/*
 * 1.12.0-beta.14, tappa 6, Coda 754 (4): con «Usa fondo riserva» la parte non coperta della pregressa la copre il fondo, non un piano rate. Il periodo
 * resta obbligatorio (risposta di chi coordina); la frase dice il vero.
 */
describe('CompetenzaFattura — la pregressa coperta dal fondo', () => {
    test('con il fondo: «Quella parte la copre il fondo «Fondo di riserva».», non il piano rate; il periodo resta obbligatorio', () => {
        const testo = monta({ pregressa: true, periodoObbligatorio: true, inizioEsercizio: '2026-01-01', fondoCheCopre: 'Fondo di riserva' }).text().replace(/\s+/g, ' ');
        expect(testo).toContain('il periodo in cui il costo è maturato è obbligatorio');
        expect(testo).toContain('Quella parte la copre il fondo «Fondo di riserva».');
        expect(testo).not.toContain('Quella parte finisce in un piano rate');
        expect(testo).toContain('Dopo non si potrà aggiungere: una pregressa si storna, non si modifica.');
    });

    test('controprova — senza il fondo la frase dice ancora il piano rate', () => {
        const testo = monta({ pregressa: true, periodoObbligatorio: true, inizioEsercizio: '2026-01-01' }).text().replace(/\s+/g, ' ');
        expect(testo).toContain('Quella parte finisce in un piano rate');
    });
});
