// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I menu `v-select` si chiudono sempre dopo la scelta, in ogni pagina e in ogni finestra.
 *
 * `vue-select` 4.0.0-beta.6, dopo una scelta, non chiude il menu: lo **inverte** (`onAfterSelect`: `open = !open`) e poi toglie il cursore dal campo, che a sua
 * volta lo chiude. Se il menu era già chiuso, o il campo aveva già perso il cursore (una seconda scelta mentre la pagina si ridisegna, il cursore spostato
 * da una finestra), l'inversione lo **riapre**, e senza il cursore nel campo niente lo richiude: il menu resta aperto sopra la finestra. È il difetto della
 * visibilità della comunicazione (H2 della beta.12, corretto lì con una chiusura sulla pagina) e quello della tabella millesimale nella finestra «Nuova
 * voce di spesa o capitolo» del piano dei conti, visto a video nella beta.12: lo stesso, in ogni `v-select`.
 *
 * La correzione è una sola, qui: dopo la scelta il menu si chiude (`open = false`), mai si inverte; il resto come prima (il cursore lascia il campo, la
 * ricerca si svuota). Un menu con `close-on-select` spento resta aperto come prima. Il file si carica da `app.ts`, prima di ogni pagina.
 */
import vSelect from 'vue-select';

interface StatoDelMenu {
    closeOnSelect: boolean;
    clearSearchOnSelect: boolean;
    open: boolean;
    search: string;
    searchEl?: HTMLInputElement;
}

type Metodo = ((this: StatoDelMenu, ...argomenti: unknown[]) => void) & { chiusoDopoLaScelta?: true };

/** Mette la chiusura al posto dell'inversione nel componente dato (di serie quello di `vue-select`). Una volta sola. */
export function chiudiDopoLaScelta(componente: unknown = vSelect): void {
    const metodi = (componente as { methods?: Record<string, Metodo> }).methods;
    if (!metodi || metodi.onAfterSelect?.chiusoDopoLaScelta) {
        return;
    }
    const chiudi: Metodo = function (this: StatoDelMenu) {
        if (this.closeOnSelect) {
            this.open = false;
            this.searchEl?.blur();
        }
        if (this.clearSearchOnSelect) {
            this.search = '';
        }
    };
    chiudi.chiusoDopoLaScelta = true;
    metodi.onAfterSelect = chiudi;
}

chiudiDopoLaScelta();
