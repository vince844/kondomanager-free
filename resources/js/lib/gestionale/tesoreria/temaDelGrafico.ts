// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * I colori dei grafici della tesoreria letti dal tema quando si disegna, e il segnale che il tema è passato fra chiaro e scuro: i due
 * grafici del saldo previsto (la card e il cassetto delle azioni consigliate) lo usano come chiave e si ridisegnano con i colori nuovi.
 */
import { onMounted, onUnmounted, ref, type Ref } from 'vue';

/** La pagina è nel tema scuro. */
export const scuro = (): boolean => typeof document !== 'undefined' && document.documentElement.classList.contains('dark');

/** Il colore di una variabile del tema, come lo risolve il browser («rgb(…)»); senza, il colore di riserva. */
export function coloreDelTema(variabile: string, riserva: string): string {
    if (typeof document === 'undefined' || typeof getComputedStyle === 'undefined') return riserva;
    const prova = document.createElement('span');
    prova.style.display = 'none';
    prova.style.color = `var(${variabile})`;
    document.body.appendChild(prova);
    const colore = getComputedStyle(prova).color;
    prova.remove();

    return /^rgba?\(/.test(colore) ? colore : riserva;
}

/** Lo stesso colore, trasparente. */
export function conAlfa(colore: string, alfa: number): string {
    const parti = colore.match(/rgba?\(([^)]+)\)/)?.[1].split(/[\s,/]+/).filter(Boolean).slice(0, 3);

    return parti && parti.length === 3 ? `rgba(${parti.join(', ')}, ${alfa})` : colore;
}

/** Un numero che cresce quando la pagina passa fra chiaro e scuro: la chiave del grafico, che si ridisegna. */
export function useTemaDelGrafico(): Ref<number> {
    const tema = ref(0);
    let ultimo = scuro();
    let osservatore: MutationObserver | null = null;
    onMounted(() => {
        if (typeof MutationObserver === 'undefined') return;
        osservatore = new MutationObserver(() => {
            if (scuro() !== ultimo) {
                ultimo = scuro();
                tema.value++;
            }
        });
        osservatore.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    });
    onUnmounted(() => osservatore?.disconnect());

    return tema;
}
