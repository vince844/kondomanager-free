// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import type { DueVersioniDellaSala, SalaDellAssemblea } from '@/types/assemblee';
import type { FotoDellaCoda } from './coda';
import type { TenutaDelBrowser } from './persistenza';

/**
 * La striscia della rete, sopra la barra della costituzione (la scena 2 della bozza delle scene della sala): uno stato solo alla volta, il più importante.
 *
 * 1. `sessione_scaduta`: «La sessione è scaduta: rientra; i 12 gesti in coda partono da soli.»
 * 2. `non_puoi`: i gesti non partono perché chi è collegato non gestisce le assemblee.
 * 3. `due_versioni`: «Due versioni: scegli.» con che cosa.
 * 4. `invio`: «Sto mandando i gesti salvati… 2 di 4».
 * 5. `senza_rete`: «Senza rete: 4 gesti salvati su questo computer.» (o il server che non risponde).
 * 6. `non_tiene`: «Questo browser non tiene il lavoro senza rete.» (la sala aperta lo stesso, per tutta la sera).
 * 7. `in_linea`: una riga grigia, «tutto sul server».
 */
export type StatoDellaStriscia = 'in_linea' | 'senza_rete' | 'invio' | 'sessione_scaduta' | 'non_puoi' | 'due_versioni' | 'non_tiene';

export function statoDellaStriscia(foto: Pick<FotoDellaCoda, 'stato'>, tenuta: TenutaDelBrowser | null, conflitti: unknown[]): StatoDellaStriscia {
    if (foto.stato === 'sessione_scaduta' || foto.stato === 'non_puoi') return foto.stato;
    if (conflitti.length > 0) return 'due_versioni';
    if (foto.stato === 'invio' || foto.stato === 'senza_rete') return foto.stato;
    if (tenuta === 'non_tiene') return 'non_tiene';

    return 'in_linea';
}

/** «Il voto di Romano Ida al punto 4.», «La presenza di Rossi Mario.», «La conferma del punto 4.»: che cosa ha due versioni. */
export function fraseDelConflitto(c: DueVersioniDellaSala, sala: SalaDellAssemblea | null): string {
    const chi = (c.questa ?? '').split(':')[0].trim();
    const votazione = /^votazione:(\d+)/.exec(c.su ?? '')?.[1];
    const punto = votazione ? sala?.punti.find((p) => p.votazioni.some((v) => v.id === Number(votazione)))?.numero : undefined;
    const alPunto = punto !== undefined ? ` al punto ${punto}` : '';
    switch (c.tipo) {
        case 'voto':
            return `Il voto di ${chi}${alPunto}.`;
        case 'entra':
        case 'esce':
            return `La presenza di ${chi}.`;
        case 'conferma':
            return punto !== undefined ? `La conferma del punto ${punto}.` : 'La conferma di un punto.';
        default:
            return `${c.questa ?? 'Un gesto'}.`;
    }
}

/** Il nome di un dispositivo da mostrare: «tablet di Marco Galli» (il numero dopo «#» non si mostra). */
export function nomeDelDispositivo(dispositivo: string): string {
    return dispositivo.split('#')[0].trim();
}

/** Il dispositivo come lo manda la coda: «computer di Andrea Costa#a1b2c3d4», al più 64 caratteri. */
export function creaIlDispositivo(tipo: string, nome: string, numero: string): string {
    const coda = '#' + numero.replace(/-/g, '').slice(0, 8);
    const testa = (nome.trim() !== '' ? `${tipo} di ${nome.trim()}` : tipo).slice(0, 64 - coda.length);

    return testa + coda;
}
