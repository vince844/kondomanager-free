// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import axios from 'axios';
import { ref } from 'vue';
import type { Rata } from '@/types/gestionale/rata';

export interface AccordoDellIncasso {
    id: number;
    data_accordo: string;
    in_accordo: number;
    prossima: { numero: number; data: string; mancano: number } | null;
    rate_non_versate: number | null;
}

export function useDebitiLoader() {

    // B2, S7: l'ultima risposta porta anche le note di solidarietà dell'art. 63 co. 4 (chi è entrato risponde
    // con chi è uscito): il chiamante le legge da qui dopo `fetchDebiti`, l'elenco delle rate resta com'era.
    const noteSolidarieta = ref<string[]>([]);
    /** L'accordo di rientro in corso della persona cercata, se c'è: il giorno, quanto è nell'accordo, la prossima rata del calendario. */
    const accordo = ref<AccordoDellIncasso | null>(null);

    const fetchDebiti = async (
        routeFn: any,
        condominioId: number,
        params: { anagrafica_id?: number | null; immobile_id?: number | null },
    ): Promise<Rata[]> => {
        
        if (!params.anagrafica_id && !params.immobile_id) {
            noteSolidarieta.value = [];
            accordo.value = null;
            return [];
        }

        try {
            const url = routeFn('gestionale.situazione-debitoria', {
                condominio: condominioId,
                ...params
            });
            
            const res = await axios.get(url);
            noteSolidarieta.value = Array.isArray(res.data.note_solidarieta) ? res.data.note_solidarieta : [];
            accordo.value = res.data.accordo ?? null;
            
            // `scaduta`, `is_emitted`, `pregresso` e `ordine` sono del server (il predicato comune del giorno, la rata 0,
            // l'ordine proposto): si usano come arrivano. Fino alla 1.12.0-beta.2 qui si riscriveva `scaduta` con una chiave
            // che il server non manda, e il badge «Scaduta» non compariva mai.
            return res.data.rate.map((r: any) => ({
                ...r,
                da_pagare: 0,
                selezionata: false,
            }));
        } catch (error) {
            console.error('Errore nel caricamento dei debiti:', error);
            accordo.value = null;
            return [];
        }
    };

    return {
        fetchDebiti,
        noteSolidarieta,
        accordo,
    };
}