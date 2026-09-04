// @vitest-environment jsdom

/**
 * ⚠️ **Questo file nasce il 03/09/2026 perché la finestra non aveva un solo test.**
 *
 * La rete costruita per il difetto del forum del 30/08 — «un campo validato senza un posto
 * dove comparire» — guarda `FornitoriNew.vue` e `FornitoriEdit.vue` e basta. Questa terza
 * porta sulla stessa parete, aperta nella beta.14, non la guardava nessuno: quando la natura
 * del percipiente è diventata obbligatoria, il pulsante sarebbe tornato da «Creo il
 * fornitore...» a «Crea e aggancia» **senza una parola**, e la guardia sarebbe restata verde.
 */

import { describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

const post = vi.fn();
vi.mock('axios', () => ({ default: { post: (...a: unknown[]) => post(...a) } }));

// `route()` è un globale di Ziggy: nei test non esiste.
(globalThis as any).route = (nome: string) => `/${nome}`;

import ModalCreaFornitoreDaXml from './ModalCreaFornitoreDaXml.vue';

function montaFinestra() {
    return mount(ModalCreaFornitoreDaXml, {
        props: {
            // ⚠️ **Chiusa, e aperta dopo.** Il precompilamento vive in un `watch` su `show`
            // senza `immediate`: montando la finestra già aperta i campi restano vuoti, il
            // pulsante nasce disabilitato (`!data.ragione_sociale`) e il clic non fa niente
            // — un test così sarebbe verde per non aver premuto nulla.
            show: false,
            lettoDaXml: { denominazione: 'IMPRESA LETTA DAL FILE SRL', partita_iva: '01234567897', codice_fiscale: null } as any,
            documento: { modalita_pagamento: 'bonifico', data_documento: '2026-06-10', data_scadenza: '2026-07-10' },
            ritenuta: { importo: 12800, aliquota: 4, tipo: 'RT01' } as any,
            baseImponibileCents: 320000,
        },
        global: {
            stubs: { 'v-select': true, Teleport: true },
        },
    });
}

/**
 * Preme «Crea e aggancia».
 *
 * ⚠️ Si passa dal **pulsante**, non da una chiamata diretta alla funzione: `<script setup>`
 * non espone le funzioni sul componente, quindi invocarle da fuori non fa niente — il test
 * resterebbe verde senza aver premuto nulla, che è come è nato questo file al primo tentativo.
 */
async function apri(w: ReturnType<typeof montaFinestra>) {
    await w.setProps({ show: true });
    await flushPromises();
}

/** Spunta «Il fornitore è soggetto a ritenuta d'acconto», che è ciò che accende l'obbligo. */
async function spuntaRitenuta(w: ReturnType<typeof montaFinestra>) {
    // ⚠️ Nella finestra ci sono due caselle — il regime forfetario e la ritenuta — e la
    // prima del DOM è quella sbagliata: si sceglie per l'etichetta che la contiene.
    const casella = w.findAll('input[type="checkbox"]')
        .find((c) => c.element.closest('label')?.textContent?.includes('ritenuta'));
    expect(casella, 'la casella «soggetto a ritenuta» non è stata trovata').toBeTruthy();
    await casella!.setValue(true);
    await w.vm.$nextTick();
}

async function premiCrea(w: ReturnType<typeof montaFinestra>) {
    const pulsante = w.findAll('button').find((b) => b.text().includes('Crea e aggancia'));
    expect(pulsante, 'il pulsante «Crea e aggancia» non è stato trovato').toBeTruthy();
    await pulsante!.trigger('click');
    await flushPromises();
}

/**
 * Fa rifiutare la prossima chiamata, nella forma che Laravel manda davvero con Accept: json.
 *
 * ⚠️ La promessa si crea **dentro** l'implementazione, non fuori: un `Promise.reject()`
 * costruito al momento del mock viene rifiutato subito, prima che qualcuno lo attenda, e
 * Node lo conta come «unhandled rejection». La suite restava verde ma stampava due errori
 * in coda — e una suite che stampa errori da ignorare insegna a ignorare gli errori.
 */
function faRifiutare(errori: Record<string, string[]>) {
    post.mockImplementationOnce(() => Promise.reject({ response: { status: 422, data: { errors: errori } } }));
}

describe('un rifiuto del server non può essere muto', () => {
    test('l\'errore sulla natura del percipiente compare sotto il campo', async () => {
        faRifiutare({
            natura_percipiente: ["Per un fornitore soggetto a ritenuta d'appalto serve la natura del percipiente."],
        });

        const w = montaFinestra();
        await apri(w);
        await spuntaRitenuta(w);
        await premiCrea(w);

        expect(w.text()).toContain('serve la natura del percipiente');
    });

    test('e un campo che questa finestra non stampa finisce comunque a schermo', async () => {
        // ⚠️ È la guardia strutturale: l'elenco dei campi validati cresce dal server, quello
        // dei campi stampati qui dentro no. Ciò che non trova posto sotto a un campo deve
        // comparire in alto — meno preciso, ma non invisibile.
        faRifiutare({
            giorni_scadenza: ['Il campo giorni scadenza deve essere un numero intero.'],
        });

        const w = montaFinestra();
        await apri(w);
        await premiCrea(w);

        expect(w.text()).toContain('deve essere un numero intero');
    });

    test('l\'elenco dei campi «stampati» non deve mentire', async () => {
        // Se l'elenco dichiara stampato un campo che il template non rende, quel campo torna
        // invisibile: il ripiego lo scarterebbe credendo che abbia già un posto suo.
        const fs = await import('node:fs');
        const sorgente = fs.readFileSync(
            'resources/js/components/gestionale/movimenti/fatture/ModalCreaFornitoreDaXml.vue', 'utf8');

        const elenco = [...sorgente.matchAll(/^\s{4}'([a-z_]+)',$/gm)].map((m) => m[1]);
        const resi = new Set([...sorgente.matchAll(/erroriCampo\.([a-z_]+)/g)].map((m) => m[1]));

        expect(elenco.length, 'l\'elenco non è stato trovato: il test sarebbe verde senza guardare').toBeGreaterThan(3);

        const bugiardi = elenco.filter((c) => !resi.has(c));
        expect(bugiardi, `dichiarati stampati ma assenti dal template: ${bugiardi.join(', ')}`).toEqual([]);
    });
});
