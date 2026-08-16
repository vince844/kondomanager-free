/**
 * beta.53 — il vocabolario dei ruoli, e il caso in cui **non** deve portarsi dietro il tema scuro.
 *
 * Unificare in un posto solo la mappa colore-ruolo, che prima era scritta a mano in tre punti
 * discordi, ha risolto un problema e ne ha aperto un altro: due delle tre copie erano nate senza
 * varianti `dark:`, perché vivevano dentro pannelli che **non invertono**. La fonte unica le ha
 * portate anche lì, e in tema scuro il badge finiva a leggersi verde chiaro su bianco.
 *
 * Il caso concreto è `ScopertoWarning.vue`: zero classi `dark:` in tutto il file, fondo `bg-amber-50`
 * e righe `bg-white/50` che restano tali in entrambi i temi. È il pannello che precede
 * un'operazione contabile, quindi la leggibilità lì non è un dettaglio estetico.
 */

import { describe, it, expect } from 'vitest';
import { coloreRuolo, etichettaRuolo, perOrdineRuolo, ORDINE_RUOLI } from './ruoli-immobile';

describe('coloreRuolo', () => {
    it('in automatico porta entrambe le metà: chiara e scura', () => {
        const c = coloreRuolo('proprietario');

        expect(c).toContain('bg-blue-100');
        expect(c).toContain('dark:bg-blue-900/30');
    });

    it('a tema chiaro non emette nessuna variante scura', () => {
        for (const ruolo of Object.keys(ORDINE_RUOLI)) {
            expect(coloreRuolo(ruolo, 'chiaro')).not.toContain('dark:');
        }
    });

    it('anche il grigio dei ruoli sconosciuti resta chiaro dove il pannello non inverte', () => {
        expect(coloreRuolo('capostipite', 'chiaro')).toBe('bg-slate-100 text-slate-700');
        expect(coloreRuolo(null, 'chiaro')).not.toContain('dark:');
    });

    it('un ruolo fuori catalogo prende il grigio, non il colore di un ruolo vero', () => {
        // Un dato sporco si deve vedere: travestirlo da proprietario è peggio che mostrarlo grigio.
        expect(coloreRuolo('comodatario')).toContain('slate');
    });

    it('nuda_proprietario ha un colore suo e non finisce fra gli sconosciuti', () => {
        // È registrabile dalla beta.43, e per metà interfaccia ha viaggiato col grigio.
        expect(coloreRuolo('nuda_proprietario')).toContain('amber');
        expect(coloreRuolo('nuda_proprietario', 'chiaro')).toContain('amber');
    });
});

describe('etichettaRuolo', () => {
    it('nuda_proprietario è un valore di colonna, non una parola da mostrare', () => {
        expect(etichettaRuolo('nuda_proprietario')).toBe('nudo proprietario');
    });

    it('gli altri passano invariati, e il vuoto non diventa la stringa «null»', () => {
        expect(etichettaRuolo('proprietario')).toBe('proprietario');
        expect(etichettaRuolo(null)).toBe('—');
    });
});

describe('perOrdineRuolo', () => {
    it('ordina dal diritto reale pieno verso il godimento', () => {
        const soggetti = [
            { pivot: { tipologia: 'inquilino' } },
            { pivot: { tipologia: 'usufruttuario' } },
            { pivot: { tipologia: 'proprietario' } },
            { pivot: { tipologia: 'nuda_proprietario' } },
        ];

        expect([...soggetti].sort(perOrdineRuolo).map((s) => s.pivot.tipologia)).toEqual([
            'proprietario',
            'nuda_proprietario',
            'usufruttuario',
            'inquilino',
        ]);
    });

    it('chi non è in catalogo va in fondo, non in mezzo', () => {
        const soggetti = [
            { pivot: { tipologia: 'comodatario' } },
            { pivot: { tipologia: 'inquilino' } },
        ];

        expect([...soggetti].sort(perOrdineRuolo)[0].pivot.tipologia).toBe('inquilino');
    });
});
