// @vitest-environment jsdom

/**
 * `useGiroFlash` (1.11.0-beta.37, giro di verifica della Fase 1-bis, L-R4): la pagina dei titolari resta montata fra le
 * azioni della scheda dello storico, e il messaggio di una seconda azione deve comparire con il suo timer.
 *
 * **Cosa resta scoperto**: la pagina vera (`AnagraficheList.vue`) non si monta qui, per le sue dipendenze; si prova il
 * contatore con l'`Alert` vero, nello stesso schema (`:key` sul contatore). L'evento `inertia:success` è quello che il
 * router di Inertia 3 emette sul documento a ogni visita riuscita.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import Alert from '@/components/Alert.vue';
import { useGiroFlash } from './useGiroFlash';

const Pagina = defineComponent({
    props: { messaggio: { type: String, required: true }, tipo: { type: String, default: 'success' } },
    setup(props) {
        const giro = useGiroFlash();

        return () => h(Alert, { key: giro.value, message: props.messaggio, type: props.tipo as any });
    },
});

const visitaRiuscita = async () => {
    document.dispatchEvent(new CustomEvent('inertia:success', { detail: { page: {} } }));
    await nextTick();
};

describe('useGiroFlash — il messaggio di una seconda azione nella stessa visita', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    test('un messaggio diverso, dopo che il primo si è chiuso da solo, compare', async () => {
        const w = mount(Pagina, { props: { messaggio: 'Conguaglio annullato.' } });
        await nextTick();
        expect(w.text()).toContain('Conguaglio annullato.');
        vi.advanceTimersByTime(6001);
        await nextTick();
        expect(w.text()).not.toContain('Conguaglio annullato.');

        await w.setProps({ messaggio: 'Passaggio annullato.', tipo: 'warning' });
        await visitaRiuscita();
        expect(w.text()).toContain('Passaggio annullato.');
        // Un avviso non eredita il timer del successo di prima: resta.
        vi.advanceTimersByTime(60000);
        await nextTick();
        expect(w.text()).toContain('Passaggio annullato.');
    });

    test('lo stesso messaggio, parola per parola, dopo sei secondi compare di nuovo (due locazioni annullate a cascata)', async () => {
        const w = mount(Pagina, { props: { messaggio: 'Passaggio annullato. Le righe di titolarità scritte dal passaggio sono tornate come prima.' } });
        await nextTick();
        expect(w.text()).toContain('Passaggio annullato.');
        vi.advanceTimersByTime(6001);
        await nextTick();
        expect(w.text()).toBe('');

        await visitaRiuscita();
        expect(w.text()).toContain('Passaggio annullato.');
    });
});
