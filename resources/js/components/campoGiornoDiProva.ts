// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Per le prove: sceglie un giorno in un `CampoGiorno` come lo sceglie l'amministratore. Un clic sul pulsante apre il calendario, le frecce portano al mese
 * del giorno, un clic sul giorno lo sceglie. Un giorno spento nel calendario (fuori da `min` o `max`) non si sceglie: la funzione si ferma con un errore
 * che lo dice, invece di scrivere un giorno che a video non si può scegliere. Non è un test: lo importano i test dei campi del giorno.
 */
import { flushPromises } from '@vue/test-utils';

type Pulsante = Element | { element: Element };

const elemento = (p: Pulsante): HTMLElement => ('element' in p ? p.element : p) as HTMLElement;

/** Il calendario aperto: quello del campo del giorno, portato in fondo alla pagina. */
export function calendarioAperto(): HTMLElement | null {
    return document.body.querySelector<HTMLElement>('[data-testid="calendario-del-giorno"]');
}

/** Il giorno scritto sul pulsante, come lo legge l'amministratore: «14/12/2026», o «gg/mm/aaaa» senza giorno. */
export function giornoMostrato(pulsante: Pulsante): string {
    return (elemento(pulsante).textContent ?? '').trim();
}

/** La casella di un giorno nel calendario aperto (`aaaa-mm-gg`), nel mese che si vede. */
export function casellaDelGiorno(iso: string): HTMLElement | null {
    return calendarioAperto()?.querySelector<HTMLElement>(`[data-reka-calendar-cell-trigger][data-value="${iso}"]:not([data-outside-view])`) ?? null;
}

/** Apre il calendario del campo e va al mese di `iso` con le frecce; restituisce la casella del giorno. */
export async function apriAlGiorno(pulsante: Pulsante, iso: string): Promise<HTMLElement> {
    if (!calendarioAperto()) {
        elemento(pulsante).click();
        await flushPromises();
    }
    const calendario = calendarioAperto();
    if (!calendario) throw new Error('il calendario del giorno non si è aperto');
    const [anno, mese] = iso.split('-').map(Number);
    for (let passi = 0; passi < 1200; passi++) {
        const visto = calendario.querySelector('[data-reka-calendar-cell-trigger]:not([data-outside-view])')?.getAttribute('data-value') ?? '';
        const [a, m] = visto.split('-').map(Number);
        const mesi = (anno - a) * 12 + (mese - m);
        if (mesi === 0) break;
        const freccia = calendario.querySelector<HTMLButtonElement>(mesi > 0 ? '[aria-label="Next page"]' : '[aria-label="Previous page"]');
        if (!freccia || freccia.disabled) throw new Error(`il mese di ${iso} non si raggiunge nel calendario: è fuori dai giorni che si possono scegliere`);
        freccia.click();
        await flushPromises();
    }
    const casella = casellaDelGiorno(iso);
    if (!casella) throw new Error(`il giorno ${iso} non c'è nel calendario`);

    return casella;
}

/** Sceglie `iso` (`aaaa-mm-gg`) nel campo del giorno; il calendario si chiude. */
export async function scegliIlGiorno(pulsante: Pulsante, iso: string): Promise<void> {
    const casella = await apriAlGiorno(pulsante, iso);
    if (casella.hasAttribute('data-disabled')) throw new Error(`il giorno ${iso} è spento nel calendario`);
    casella.click();
    await flushPromises();
}
