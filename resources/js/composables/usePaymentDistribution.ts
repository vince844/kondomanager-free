import { ref } from 'vue';
import type { Rata, BilancioFinale } from '@/types/gestionale/rata';

export function usePaymentDistribution() {
    const rawRateList = ref<Rata[]>([]);
    const loadingRate = ref(false);
    const mode = ref<'auto' | 'manual'>('auto');
    const priorityRataId = ref<number | null>(null);

    const isRataZero = (r: Rata) => {
        const desc = (r.descrizione || '').toLowerCase();
        
        const isSaldo = desc.includes('saldo') || desc.includes('rata 0') || desc.includes('pregresso');
        const isContabilizzata = r.is_emitted === true;
        const isInBozza = r.is_emitted === false;

        return isSaldo || isContabilizzata || isInBozza;
    }; 

    const getValidTime = (dateStr: string | null) => {
        if (!dateStr) return 0; 
        let parsed = dateStr;
        if (dateStr.includes('/')) { 
            const [d, m, y] = dateStr.split('/'); 
            parsed = `${y}-${m}-${d}`; 
        }
        const time = new Date(parsed).getTime();
        return isNaN(time) ? 0 : time;
    };
    
    const parseMoney = (val: any) => { 
        if (typeof val === 'number') return val;
        if (!val) return 0;
        let str = String(val).replace('€', '').trim();
        if (str.includes(',')) {
            str = str.replace(/\./g, '');
            str = str.replace(',', '.');
        }
        return parseFloat(str.replace(/[^\d.-]/g, '')) || 0; 
    };
    
    const setPriorityRataId = (id: number | null) => { priorityRataId.value = id; };

    const isScaduta = (dateStr: string | null) => {
        if (!dateStr) return false;
        
        const oggi = new Date();
        oggi.setHours(0, 0, 0, 0);

        if (dateStr.includes('/')) {
            const [d, m, y] = dateStr.split('/');
            const dataRata = new Date(parseInt(y), parseInt(m) - 1, parseInt(d));
            return dataRata < oggi;
        }
        
        const dataRata = new Date(dateStr);
        dataRata.setHours(0, 0, 0, 0);
        return dataRata < oggi;
    };

    const getRateListByGestione = (gestioneId: number | null) => {
        if (!rawRateList.value) return [];
        
        let list = [...rawRateList.value].map(r => ({...r}));
        
        if (gestioneId) {
            list = list.filter(r => r.gestione_id === gestioneId);
        }

        const cronologicalSort = (a: Rata, b: Rata) => {
            if (isRataZero(a) && !isRataZero(b)) return -1;
            if (!isRataZero(a) && isRataZero(b)) return 1;
            const timeA = getValidTime(a.scadenza_human || a.data_scadenza);
            const timeB = getValidTime(b.scadenza_human || b.data_scadenza);
            if (timeA !== timeB) return timeA - timeB;
            return (a.id || 0) - (b.id || 0);
        };
        
        list.sort(cronologicalSort);

        return list;
    };

    const getTotalAllocato = (rateList: Rata[]) => rateList.reduce((sum, r) => sum + parseMoney(r.da_pagare), 0);
    const getTotaleDebito = (rateList: Rata[]) => rateList.reduce((sum, r) => sum + parseMoney(r.residuo), 0);
    
    const getBilancioFinale = (totaleDebito: number, importoTotale: number): BilancioFinale => {
        const differenza = totaleDebito - importoTotale;
        if (differenza > 0.01) return { label: 'Residuo:', value: differenza, class: 'text-red-600 bg-red-50 border-red-200' };
        else if (differenza < -0.01) return { label: 'Credito:', value: Math.abs(differenza), class: 'text-emerald-600 bg-emerald-50 border-emerald-200' };
        else return { label: 'Saldo:', value: 0, class: 'text-gray-600 bg-gray-50 border-gray-200' };
    };

    const distributeGreedy = (rateList: Rata[], importoTotaleEuro: number) => {
        let budgetCents = Math.round(importoTotaleEuro * 100);

        rateList.forEach(r => {
            r.da_pagare = 0;
            r.selezionata = false;

            const residuoCents = Math.round(parseMoney(r.residuo) * 100);
            
            if (residuoCents <= 0) return; 

            const allocabileCents = Math.min(budgetCents, residuoCents);
            
            r.da_pagare = allocabileCents / 100;
            r.selezionata = r.da_pagare > 0;

            budgetCents -= allocabileCents;
            if (budgetCents < 0) budgetCents = 0;
        });

        return budgetCents / 100;
    };

    const calculateExcess = (rateList: Rata[], importoTotale: number) => {
        const tot = parseFloat(String(importoTotale)) || 0;
        const alloc = rateList.reduce((s, r) => s + parseMoney(r.da_pagare), 0);
        return Math.max(0, parseFloat((tot - alloc).toFixed(2)));
    };

    const onManualChange = (rata: Rata, val: string) => {
        if (mode.value === 'auto') return;
        let amount = parseMoney(val);
        const res = parseMoney(rata.residuo);
        if (amount > res) amount = res;
        rata.da_pagare = amount; 
        rata.selezionata = amount > 0;
    };

    const resetAllocation = (rateList: Rata[]) => { 
        mode.value = 'manual'; 
        rateList.forEach(r => { r.da_pagare = 0; r.selezionata = false; }); 
    };

    const pagaTutto = (rateList: Rata[]) => { 
        mode.value = 'manual'; let somma = 0; 
        rateList.forEach(r => { 
            const res = parseMoney(r.residuo);
            if (res > 0) { r.da_pagare = res; r.selezionata = true; somma += res; } 
            else { r.da_pagare = 0; r.selezionata = false; } 
        }); return parseFloat(somma.toFixed(2)); 
    };

    const pagaScadute = (rateList: Rata[]) => { 
        mode.value = 'manual'; 
        let somma = 0; 
        rateList.forEach(r => { 
            const res = parseMoney(r.residuo);
            if (isScaduta(r.data_scadenza || r.scadenza_human) && res > 0) { 
                r.da_pagare = res; 
                r.selezionata = true; 
                somma += res; 
            } else { 
                r.da_pagare = 0; 
                r.selezionata = false; 
            } 
        }); 
        return parseFloat(somma.toFixed(2)); 
    };

    const syncFormData = (rateList: Rata[]) => [...rateList]
        .filter(r => r.selezionata && parseMoney(r.da_pagare) !== 0) // 🟢 FIX: Include i negativi
        .map(r => ({ rata_id: r.id, importo: parseMoney(r.da_pagare) }));

    return { 
        rawRateList, 
        loadingRate, 
        mode, 
        isScaduta, 
        priorityRataId, // 🟢 FIX: Esportato correttamente
        setPriorityRataId, 
        getRateListByGestione, 
        getTotalAllocato, 
        getTotaleDebito, 
        getBilancioFinale, 
        distributeGreedy, 
        calculateExcess, 
        onManualChange, 
        resetAllocation, 
        pagaTutto, 
        pagaScadute, 
        syncFormData 
    };
}