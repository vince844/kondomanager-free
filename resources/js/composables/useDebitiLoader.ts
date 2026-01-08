import axios from 'axios';
import type { Rata } from '@/types/gestionale/rata';

export function useDebitiLoader() {

    const fetchDebiti = async (
        routeFn: any,
        condominioId: number,
        params: { anagrafica_id?: number | null; immobile_id?: number | null },
        isScadutaFn: (data: string | null) => boolean

    ): Promise<Rata[]> => {
        
        if (!params.anagrafica_id && !params.immobile_id) {
            return [];
        }

        try {
            const url = routeFn('gestionale.situazione-debitoria', {
                condominio: condominioId,
                ...params
            });
            
            const res = await axios.get(url);
            
            return res.data.rate.map((r: any) => ({
                ...r,
                da_pagare: 0,
                selezionata: false,
                scaduta: isScadutaFn(r.data_scadenza ?? null),
            }));
        } catch (error) {
            console.error('Errore nel caricamento dei debiti:', error);
            return [];
        }
    };

    return {
        fetchDebiti
    };
}