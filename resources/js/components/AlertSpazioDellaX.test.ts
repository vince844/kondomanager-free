// @vitest-environment jsdom

/**
 * Il riquadro dei messaggi della pagina (`Alert.vue`) lascia a destra lo spazio della X di chiusura.
 *
 * Il riquadro dichiara `pr-10` per la X, che è in posizione assoluta in alto a destra; le varianti per tipo aggiungevano `px-4`, e la fusione delle classi del
 * componente di base tiene l'ultima delle due: il margine destro tornava di 16 px e un messaggio lungo (il fermo di «Ricalcola» sulla voce negativa, visto a video
 * il 17/10/2026) finiva sotto la X. Si legge la classe che arriva al riquadro, per ogni tipo.
 *
 * Cosa resta scoperto: la resa a video (jsdom non applica il CSS); il messaggio che va a capo.
 */

import { describe, expect, test } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Alert from '@/components/Alert.vue';

describe('lo spazio della X', () => {
    test.each(['success', 'error', 'warning', 'info'] as const)('il riquadro di tipo «%s» tiene il margine destro della X', async (type) => {
        const w = mount(Alert, { props: { message: 'Un messaggio lungo, che arriva fino al bordo destro del riquadro', type } });
        await flushPromises();
        const classi = w.find('button[aria-label="Chiudi avviso"]').element.parentElement!.className.split(/\s+/);

        expect(classi).toContain('pr-10');
        expect(classi).not.toContain('px-4');
        w.unmount();
    });
});
