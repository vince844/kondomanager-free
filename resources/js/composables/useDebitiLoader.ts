import axios from 'axios';
import { ref } from 'vue';
import type { Rata } from '@/types/gestionale/rata';

export function useDebitiLoader() {

    // B2, S7: l'ultima risposta porta anche le note di solidarietà dell'art. 63 co. 4 (chi è entrato risponde
    // con chi è uscito): il chiamante le legge da qui dopo `fetchDebiti`, l'elenco delle rate resta com'era.
    const noteSolidarieta = ref<string[]>([]);

    const fetchDebiti = async (
        routeFn: any,
        condominioId: number,
        params: { anagrafica_id?: number | null; immobile_id?: number | null },
    ): Promise<Rata[]> => {
        
        if (!params.anagrafica_id && !params.immobile_id) {
            noteSolidarieta.value = [];
            return [];
        }

        try {
            const url = routeFn('gestionale.situazione-debitoria', {
                condominio: condominioId,
                ...params
            });
            
            const res = await axios.get(url);
            noteSolidarieta.value = Array.isArray(res.data.note_solidarieta) ? res.data.note_solidarieta : [];
            
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
            return [];
        }
    };

    return {
        fetchDebiti,
        noteSolidarieta,
    };
}