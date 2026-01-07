import { ref } from 'vue';
import type { Rata, BilancioFinale, PreviewContabile } from '@/types/gestionale/rata';

export function usePaymentDistribution() {
    const rawRateList = ref<Rata[]>([]);
    const loadingRate = ref(false);
    const mode = ref<'auto' | 'manual'>('auto');

    const isScaduta = (data: string | null) => {
        if (!data) return false;
        return new Date(data) < new Date(new Date().toDateString());
    };

    const getRateListByGestione = (gestioneId: number | null) => {
        if (!gestioneId) return rawRateList.value;
        return rawRateList.value.filter(r => r.gestione_id === gestioneId);
    };

    const getTotalAllocato = (rateList: Rata[]) => {
        return rateList.reduce((sum, r) => sum + (parseFloat(String(r.da_pagare)) || 0), 0);
    };

    const getTotaleDebito = (rateList: Rata[]) => {
        return rateList.reduce((sum, r) => sum + (parseFloat(String(r.residuo)) || 0), 0);
    };

    const getBilancioFinale = (totaleDebito: number, importoTotale: number): BilancioFinale => {
        const differenza = totaleDebito - importoTotale;

        if (differenza > 0.01) {
            return { 
                label: 'Residuo:', 
                value: differenza, 
                class: 'text-red-600 bg-red-50 border-red-200' 
            };
        } else if (differenza < -0.01) {
            return { 
                label: 'Credito:', 
                value: Math.abs(differenza), 
                class: 'text-emerald-600 bg-emerald-50 border-emerald-200' 
            };
        } else {
            return { 
                label: 'Saldo:', 
                value: 0, 
                class: 'text-gray-600 bg-gray-50 border-gray-200' 
            };
        }
    };

    const getPreviewContabile = (rateList: Rata[], importoTotale: number, eccedenza: number): PreviewContabile => {
        const rateCoinvolte = rateList.filter(r => r.selezionata && r.da_pagare > 0);

        return {
            hasData: importoTotale > 0,
            totale_versato: importoTotale,
            allocato_rate: getTotalAllocato(rateList),
            anticipo: eccedenza,
            righe: rateCoinvolte.map(r => {
                const residuoDopoPagamento = r.residuo - r.da_pagare;
                return {
                    id: r.id,
                    descrizione: r.descrizione,
                    pagato: r.da_pagare,
                    status: residuoDopoPagamento < 0.01 ? 'SALDATA' : 'PARZIALE',
                    residuo_futuro: Math.max(0, residuoDopoPagamento)
                };
            })
        };
    };

    const distributeGreedy = (rateList: Rata[], importoTotale: number) => {
        let budget = parseFloat(String(importoTotale)) || 0;
        
        rateList.forEach(r => {
            if (r.residuo <= 0) {
                r.da_pagare = 0;
                r.selezionata = false;
                return;
            }

            const allocabile = Math.min(budget, r.residuo);
            r.da_pagare = parseFloat(allocabile.toFixed(2));
            r.selezionata = r.da_pagare > 0;
            budget -= r.da_pagare;
            if (budget < 0.01) budget = 0;
        });

        return parseFloat(budget.toFixed(2));
    };

    const calculateExcess = (rateList: Rata[], importoTotale: number) => {
        const tot = parseFloat(String(importoTotale)) || 0;
        const alloc = rateList.reduce((s, r) => s + (parseFloat(String(r.da_pagare)) || 0), 0);
        return Math.max(0, parseFloat((tot - alloc).toFixed(2)));
    };

    const onManualChange = (rata: Rata, val: string) => {
        if (mode.value === 'auto') return;
        
        let amount = parseFloat(val) || 0;
        if (amount > rata.residuo) amount = rata.residuo;
        
        rata.da_pagare = amount;
        rata.selezionata = amount > 0;
    };

    const resetAllocation = (rateList: Rata[]) => {
        mode.value = 'manual';
        rateList.forEach(r => {
            r.da_pagare = 0;
            r.selezionata = false;
        });
    };

    const pagaTutto = (rateList: Rata[]) => {
        mode.value = 'manual';
        let somma = 0;
        
        rateList.forEach(r => {
            if (r.residuo > 0) {
                r.da_pagare = r.residuo;
                r.selezionata = true;
                somma += r.residuo;
            } else {
                r.da_pagare = 0;
                r.selezionata = false;
            }
        });
        
        return parseFloat(somma.toFixed(2));
    };

    const pagaScadute = (rateList: Rata[]) => {
        mode.value = 'manual';
        let somma = 0;
        
        rateList.forEach(r => {
            if (r.scaduta && r.residuo > 0) {
                r.da_pagare = r.residuo;
                r.selezionata = true;
                somma += r.residuo;
            } else {
                r.da_pagare = 0;
                r.selezionata = false;
            }
        });
        
        return parseFloat(somma.toFixed(2));
    };

    const syncFormData = (rateList: Rata[]) => {
        return rateList
            .filter(r => r.selezionata && r.da_pagare > 0)
            .map(r => ({ rata_id: r.id, importo: r.da_pagare }));
    };

    return {
        rawRateList,
        loadingRate,
        mode,
        isScaduta,
        getRateListByGestione,
        getTotalAllocato,
        getTotaleDebito,
        getBilancioFinale,
        getPreviewContabile,
        distributeGreedy,
        calculateExcess,
        onManualChange,
        resetAllocation,
        pagaTutto,
        pagaScadute,
        syncFormData
    };
}