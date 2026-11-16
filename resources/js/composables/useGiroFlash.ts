// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { onUnmounted, ref, type Ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Un numero che cresce a ogni visita Inertia riuscita: da usare come `:key` dell'`Alert` del messaggio flash (1.11.0-beta.37,
 * giro di verifica della Fase 1-bis, L-R4).
 *
 * Una pagina che resta montata fra due azioni — la scheda dello storico annulla il conguaglio e poi il passaggio — non
 * rimonta il suo `Alert`, e l'`Alert` accende il messaggio e il timer solo quando si monta: il secondo messaggio non
 * compariva, oppure ereditava il timer del primo (un errore che si chiudeva da solo). Il testo non basta come chiave: due
 * annullamenti a cascata danno due messaggi identici, e Inertia 3 riusa l'oggetto flash uguale al precedente.
 *
 * Le altre pagine con lo stesso schema restano com'erano: il cambio va fatto a parte, pagina per pagina.
 */
export function useGiroFlash(): Ref<number> {
    const giro = ref(0);
    onUnmounted(router.on('success', () => {
        giro.value++;
    }));

    return giro;
}

/**
 * Come {@link useGiroFlash}, ma cresce solo con le visite intere: un caricamento parziale (lo scorrimento di una lista, un riquadro ricaricato, `only`,
 * `except` o `reset`) lascia alla pagina il messaggio di prima, e rimontare l'`Alert` lo farebbe ricomparire. Per le pagine che restano montate e caricano a
 * pezzi: la casella delle attività e la dashboard del condominio.
 */
export function useGiroFlashDelleVisiteIntere(): Ref<number> {
    const giro = ref(0);
    let parziale = false;
    const viaInizio = router.on('start', (evento) => {
        const visita = (evento as CustomEvent<{ visit?: { only?: unknown[]; except?: unknown[]; reset?: unknown[] } }>).detail?.visit;
        parziale = (visita?.only?.length ?? 0) > 0 || (visita?.except?.length ?? 0) > 0 || (visita?.reset?.length ?? 0) > 0;
    });
    const viaRiuscita = router.on('success', () => {
        if (!parziale) {
            giro.value++;
        }
    });
    onUnmounted(() => {
        viaInizio();
        viaRiuscita();
    });

    return giro;
}
