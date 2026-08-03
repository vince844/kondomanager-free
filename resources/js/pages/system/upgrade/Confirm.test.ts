// @vitest-environment jsdom

/**
 * La schermata di conferma dell'aggiornamento del database.
 *
 * Questi test esistono per un difetto preciso, quello della beta.31: partendo da una
 * 1.9.x il pulsante «Avvia aggiornamento» restava disabilitato per sempre, e nessun
 * aggiornamento poteva partire. Il sistema riporta su questa schermata a ogni accesso
 * finché l'aggiornamento non è completato, quindi l'amministratore restava murato fuori
 * dal proprio gestionale.
 *
 * La causa non stava nella logica ma nel *template*: la casella di conferma che sblocca
 * il pulsante era renderizzata dentro `v-if="canBackup"`, cioè proprio nel ramo che con
 * una 1.9.x non esiste. Un test sulla sola proprietà calcolata sarebbe passato anche col
 * codice rotto — per questo qui il componente viene montato davvero.
 *
 * La nota della beta.31 dichiarava questo come limite noto: «il progetto non ha un runner
 * di test JavaScript […] l'adozione di Vitest è rimandata a dopo il rilascio». Vitest è
 * arrivato con la beta.35, e il limite si chiude qui.
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import Confirm from './Confirm.vue';

// Ziggy espone `route()` come globale dell'applicazione: nel template Vue lo risolve
// sul contesto del componente, quindi non basta assegnarlo su `globalThis`.
const mocks = { route: (name: string) => `/${name}` };

/**
 * Il componente monta l'intero kit UI (Card, Alert, Switch, Checkbox…). Qui interessa
 * solo il pulsante primario, quindi i wrapper vengono sostituiti da stub trasparenti che
 * conservano l'unica cosa che conta: `disabled` che arriva fino a un <button> vero.
 *
 * Gli stub NON toccano `v-if`/`v-else`: la struttura condizionale del template — che è
 * dove viveva il difetto — resta quella reale.
 */
const stubs = {
    Card: { template: '<div><slot /></div>' },
    CardHeader: { template: '<div><slot /></div>' },
    CardTitle: { template: '<div><slot /></div>' },
    CardDescription: { template: '<div><slot /></div>' },
    CardContent: { template: '<div><slot /></div>' },
    CardFooter: { template: '<div><slot /></div>' },
    Alert: { template: '<div><slot /></div>' },
    AlertTitle: { template: '<div><slot /></div>' },
    AlertDescription: { template: '<div><slot /></div>' },
    Badge: { template: '<span><slot /></span>' },
    Link: { template: '<a><slot /></a>' },
    Button: {
        props: ['disabled'],
        template: '<button :disabled="disabled"><slot /></button>',
    },
    Switch: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
    },
    Checkbox: {
        props: ['modelValue'],
        emits: ['update:modelValue'],
        template: '<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" />',
    },
};

function render(props: Record<string, unknown>) {
    return mount(Confirm, {
        props: {
            currentVersion: '1.9.1',
            newVersion: '1.10.0-beta.35',
            needsUpgrade: true,
            canBackup: false,
            errors: {},
            ...props,
        },
        global: { stubs, mocks },
    });
}

const primaryButton = (wrapper: ReturnType<typeof render>) => wrapper.get('button');

describe('aggiornamento da una versione senza infrastruttura di backup (1.9.x)', () => {
    /**
     * L'invariante che conta più di tutte, gemella di quella già presidiata lato server
     * in `PreUpgradeBackupTest`: l'uscita deve esistere sempre.
     */
    test('il pulsante «Avvia aggiornamento» è attivo senza dover confermare nulla', () => {
        const wrapper = render({ canBackup: false });

        expect(primaryButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    test('la pagina chiede una copia del database dal pannello hosting', () => {
        const wrapper = render({ canBackup: false });

        expect(wrapper.text()).toContain('fai una copia del database');
    });

    /**
     * Il cuore della regressione: con `canBackup` falso la casella di conferma non è
     * renderizzata affatto. Finché il pulsante dipendeva da lei, dipendeva da un valore
     * che nessuno poteva più cambiare.
     */
    test('la casella «confermo di procedere senza backup» non è nemmeno renderizzata', () => {
        const wrapper = render({ canBackup: false });

        expect(wrapper.text()).not.toContain('senza');
        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(0);
    });
});

describe('aggiornamento da una 1.10 o successiva (backup disponibile)', () => {
    test('il backup è attivo di default e si può procedere', () => {
        const wrapper = render({ canBackup: true, currentVersion: '1.10.0' });

        expect(primaryButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    /**
     * L'attrito voluto: chi rinuncia al backup deve dirlo esplicitamente. È il
     * comportamento che la correzione della beta.31 non doveva perdere per strada.
     */
    test('disattivando il backup il pulsante si blocca finché non si conferma', async () => {
        const wrapper = render({ canBackup: true, currentVersion: '1.10.0' });
        const [backupSwitch] = wrapper.findAll('input[type="checkbox"]');

        await backupSwitch.setValue(false);

        expect(primaryButton(wrapper).attributes('disabled')).toBeDefined();

        const checkboxes = wrapper.findAll('input[type="checkbox"]');
        expect(checkboxes).toHaveLength(2);
        await checkboxes[1].setValue(true);

        expect(primaryButton(wrapper).attributes('disabled')).toBeUndefined();
    });
});

describe('quando non c è nulla da aggiornare', () => {
    test('il pulsante primario lascia il posto al ritorno in dashboard', () => {
        const wrapper = render({ needsUpgrade: false, canBackup: true });

        expect(wrapper.text()).toContain('Non ci sono aggiornamenti del database pendenti');
        expect(wrapper.text()).not.toContain('Avvia aggiornamento');
    });
});
