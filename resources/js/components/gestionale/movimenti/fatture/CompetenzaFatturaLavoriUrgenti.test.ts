// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 688, il pannello della competenza della fattura registrata dopo sulla voce di un piano d'urgenza: un modo nuovo, «Lavori ordinati il …», con le
 * stesse parole del piano, al posto di «Spesa deliberata il …» con una frase sotto. Il giorno è lo stesso dei due estremi (`competenza_dal` uguale a
 * `competenza_al`), come la delibera: cambia la parola, non il dato.
 *
 * - Il modo c'è solo quando una voce della fattura sta in un piano d'urgenza (`lavoriUrgenti`); senza, i modi restano tre (`CompetenzaFattura.test.ts`).
 * - Con il giorno del piano scritto, il pannello si apre su «Lavori ordinati il …» e dice da quale piano viene.
 * - Sceglierlo scrive il giorno del piano nei due estremi; cambiato a mano, il pannello dice il giorno del piano accanto.
 * - Senza una competenza, sulla straordinaria il pannello dice che il conguaglio dell'opera usa il giorno del piano, non che il piano si fermerà.
 *
 * Che cosa non copre: la pagina che lo propone (`PianiRateNewUrgenzaEDelibera.test.ts`); il server (`UrgenzaSulleVociTest`).
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import CompetenzaFattura from './CompetenzaFattura.vue';

const LAVORI = { piano: 'Colonna montante 2026 — rate', giorno: '2026-03-02' };
const monta = (props: Record<string, unknown>) => {
    const w = mount(CompetenzaFattura, {
        props: {
            dal: '', al: '', gestioneStraordinaria: true, lavoriUrgenti: LAVORI, ...props,
            'onUpdate:dal': (v: string) => w.setProps({ dal: v }), 'onUpdate:al': (v: string) => w.setProps({ al: v }),
        },
    });
    return w;
};
const scelto = (w: ReturnType<typeof monta>) => w.findAll('[role="radio"]').find((b) => b.attributes('aria-checked') === 'true')?.text() ?? '';

describe('CompetenzaFattura — «Lavori ordinati il …» (Coda 688)', () => {
    test('con il giorno del piano scritto, il pannello è su «Lavori ordinati il …» e dice da quale piano viene', () => {
        const w = monta({ dal: '2026-03-02', al: '2026-03-02' });
        const scelte = w.findAll('[role="radio"]').map((b) => b.text().replace(/\s+/g, ' ').trim());
        expect(scelte).toHaveLength(4);
        expect(scelte[2]).toContain('Lavori ordinati il …');
        expect(scelte[2]).toContain('Lavori urgenti senza delibera (art. 1135 c.c.): paga chi era proprietario il giorno in cui sono stati ordinati.');
        expect(scelte[3]).toContain('Spesa deliberata il …');
        expect(scelto(w)).toContain('Lavori ordinati il …');
        expect(w.get('[data-testid="competenza-lavori-urgenti"]').text().replace(/\s+/g, ' '))
            .toBe('Lavori ordinati il 02/03/2026, dal piano d\'urgenza «Colonna montante 2026 — rate». Lo cambi se la fattura dice altro.');
        expect(w.text()).not.toContain('userà la data della sua delibera');
    });

    test('sceglierlo scrive il giorno del piano nei due estremi; un altro giorno scritto a mano, e il pannello dice quello del piano', async () => {
        const w = monta({});
        expect(scelto(w)).toContain('Nessuna');
        await w.findAll('[role="radio"]')[2].trigger('click');
        expect(w.props('dal')).toBe('2026-03-02');
        expect(w.props('al')).toBe('2026-03-02');
        expect(scelto(w)).toContain('Lavori ordinati il …');

        await w.get('[data-testid="giorno-dei-lavori-della-fattura"]').setValue('2026-03-04');
        expect(w.props('dal')).toBe('2026-03-04');
        expect(w.props('al')).toBe('2026-03-04');
        expect(scelto(w)).toContain('Lavori ordinati il …');
        expect(w.get('[data-testid="competenza-lavori-urgenti"]').text().replace(/\s+/g, ' '))
            .toBe('Lavori ordinati il 04/03/2026. Il piano d\'urgenza «Colonna montante 2026 — rate» dice lavori ordinati il 02/03/2026.');
    });

    test('senza una competenza, sulla straordinaria: il conguaglio dell\'opera usa il giorno del piano, non si ferma', () => {
        const testo = monta({}).text().replace(/\s+/g, ' ');
        expect(testo).toContain('Senza una competenza qui, il conguaglio dell\'opera usa il giorno del piano d\'urgenza «Colonna montante 2026 — rate»: lavori ordinati il 02/03/2026.');
        expect(testo).not.toContain('e senza nemmeno quella si fermerà');
    });

    test('senza il piano d\'urgenza un giorno solo resta «Spesa deliberata il …», come prima', () => {
        const w = monta({ dal: '2026-03-02', al: '2026-03-02', lavoriUrgenti: null });
        expect(w.findAll('[role="radio"]')).toHaveLength(3);
        expect(scelto(w)).toContain('Spesa deliberata il …');
        expect(w.find('[data-testid="competenza-lavori-urgenti"]').exists()).toBe(false);
    });
});
