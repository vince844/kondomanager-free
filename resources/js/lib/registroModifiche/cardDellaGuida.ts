// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le tre card della guida in testa alle due pagine del registro delle modifiche, come nelle altre schede della struttura e delle impostazioni: che cosa
 * tiene, la «Storia» con «Ripristina», e perché nessuno la può cambiare. La prima card dice di chi sono le righe: di un condominio, o di tutto lo studio.
 */
import { History, RotateCcw, ShieldCheck } from 'lucide-vue-next';
import type { GuideItem } from '@/components/PageHeaderGuide.vue';

export function cardDelRegistro(delloStudio: boolean): GuideItem[] {
    return [
        {
            title: 'Che cosa tiene',
            description: delloStudio
                ? 'Ogni modifica ai dati, in tutti i condomini e nello studio (utenti, ruoli, impostazioni), è una riga: chi l\'ha fatta, quando, il valore di prima e quello di dopo. Anche il programma, quando fa da solo.'
                : 'Ogni modifica ai dati di questo condominio è una riga: chi l\'ha fatta, quando, il valore di prima e quello di dopo. Anche il programma, quando fa da solo, per esempio una pubblicazione programmata.',
            icon: History,
            colorVariant: 'blue',
        },
        {
            title: 'La «Storia» e «Ripristina»',
            description: '«Storia» mostra le modifiche di una scheda, la più recente in alto. «Ripristina» rimette il valore di prima: passa dagli stessi controlli della pagina e diventa a sua volta una riga, con il tuo nome.',
            icon: RotateCcw,
            colorVariant: 'emerald',
        },
        {
            title: 'Nessuno la può cambiare',
            description: 'Le righe non si modificano e non si cancellano, neanche dall\'amministratore: si tolgono da sole dopo la durata scelta. «Verifica l\'integrità» rende visibile una riga cambiata fuori dal programma; non la impedisce.',
            icon: ShieldCheck,
            colorVariant: 'amber',
        },
    ];
}
