// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La forma delle finestre del verbale, quella dello standard delle finestre (la finestra delle ricevute): ancorata in alto e non si ricentra quando cambia
 * altezza, il contenuto scorre dentro; i gruppi con il titolo piccolo e grigio, separati da una riga; il riepilogo fisso nel piede accanto ai pulsanti, con
 * il segno piccolo del lavoro in corso; ciò che resta fuori in un riquadro arancio con l'azione. L'errore è solo rosso sotto il campo, senza icona.
 */
export const CONTENUTO = 'top-[5vh] flex max-h-[90vh] translate-y-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-lg';
export const CONTENUTO_LARGO = 'top-[5vh] flex max-h-[90vh] translate-y-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl';
export const TESTA = 'border-b border-slate-100 px-5 pt-4 pb-3 pr-12 text-left dark:border-slate-800';
export const CORPO = 'min-h-0 flex-1 overflow-y-auto px-5 text-sm';
export const GRUPPO = 'space-y-2.5 border-b border-slate-100 py-4 last:border-b-0 dark:border-slate-800';
export const TITOLO_GRUPPO = 'text-xs font-semibold text-slate-500 dark:text-slate-400';
export const AIUTO = 'text-xs text-slate-500 dark:text-slate-400';
export const ARANCIO = 'flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200';
export const PIEDE = 'flex flex-wrap items-center justify-between gap-x-4 gap-y-2.5 border-t border-slate-200 bg-slate-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-900/60';
export const RIEPILOGO = 'flex min-w-[200px] flex-1 items-center gap-2 text-[13px] text-slate-600 dark:text-slate-300';
export const PULSANTI = 'ml-auto flex flex-wrap justify-end gap-2';
/** Una scelta fra poche voci uguali, affiancate. */
export const SCELTE = 'grid gap-1 rounded-lg bg-slate-100 p-1 dark:bg-slate-900';
export const scelta = (attiva: boolean) => ['min-h-8 rounded-md px-2 py-1 text-xs font-medium', attiva
    ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-800 dark:text-white' : 'text-slate-600 dark:text-slate-400'];
export const PULSANTE = 'inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 shadow-sm transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
/** Il pulsante piccolo e chiaro di una riga o di una card (decisione 645): `outline`, alto 28 px, mai il pulsante nero grande. */
export const PICCOLO = PULSANTE.replace('h-8', 'h-7').replace('px-3', 'px-2.5');
/** Il gesto principale di una card, piccolo come gli altri: scuro, alto 28 px (decisione 656). */
export const PRINCIPALE_PICCOLO = 'inline-flex h-7 items-center justify-center gap-1.5 rounded-md bg-slate-900 px-2.5 text-xs font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100';
export const PRINCIPALE = 'inline-flex h-9 items-center justify-center gap-1.5 rounded-md bg-slate-900 px-3.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100';
export const CARD = 'rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950';

/** I percorsi che si costruiscono da quelli della pagina (le rotte del verbale sono annidate sotto l'assemblea). */
export const percorso = {
    versione: (pagina: string, verbale: number, versione: number) => `${pagina}/${verbale}/versioni/${versione}`,
    ripristina: (pagina: string, verbale: number, versione: number) => `${pagina}/${verbale}/versioni/${versione}/ripristina`,
    scegli: (pagina: string, verbale: number, versione: number) => `${pagina}/${verbale}/versioni/${versione}/scegli`,
    impugnata: (assemblea: string, delibera: number) => `${assemblea}/delibere/${delibera}/impugnata`,
    consegna: (assemblea: string) => `${assemblea}/invii/__INVIO__/consegna`,
    ricevuta: (assemblea: string) => `${assemblea}/invii/__INVIO__/ricevuta`,
};

/**
 * Il menu di una tendina (`v-select`) dentro una finestra, con `append-to-body :calculate-position="menuFuoriDallaFinestra"` (rilievo C43): appeso al
 * documento, il corpo che scorre non lo taglia più (il menu «A chi» in fondo alla finestra del memo restava nascosto sotto il piede). Fisso, sopra la
 * finestra; sotto il campo, o sopra se sotto non c'è posto; si tocca anche con la finestra modale, che spegne i tocchi fuori da sé; segue il campo quando
 * il corpo scorre, e smette quando il menu si chiude. Va con `restaSeNelMenu` sul contenuto della finestra.
 */
/**
 * Con il menu appeso fuori, il tocco su una voce cade fuori dal contenuto della finestra, e la finestra modale lo prendeva per «tocca fuori: chiudi».
 * `@interact-outside="restaSeNelMenu"` sul contenuto della finestra lo riconosce e la lascia aperta.
 */
export function restaSeNelMenu(evento: Event): void {
    if ((evento.target as Element | null)?.closest?.('.vs__dropdown-menu')) evento.preventDefault();
}

export function menuFuoriDallaFinestra(lista: HTMLElement, componente: { $refs: { toggle: HTMLElement } }, { width }: { width: string }): () => void {
    const campo = componente.$refs.toggle;
    const posa = () => {
        const r = campo.getBoundingClientRect();
        const altezza = Math.min(lista.scrollHeight, 256);
        const sotto = window.innerHeight - r.bottom;
        Object.assign(lista.style, { position: 'fixed', zIndex: '60', pointerEvents: 'auto', width, left: `${r.left}px`, maxHeight: '16rem',
            fontSize: getComputedStyle(campo).fontSize });
        if (sotto < altezza + 8 && r.top > sotto) {
            Object.assign(lista.style, { top: 'auto', bottom: `${window.innerHeight - r.top}px` });
        } else {
            Object.assign(lista.style, { top: `${r.bottom}px`, bottom: 'auto' });
        }
    };
    posa();
    window.addEventListener('scroll', posa, true);
    window.addEventListener('resize', posa);
    return () => {
        window.removeEventListener('scroll', posa, true);
        window.removeEventListener('resize', posa);
    };
}
