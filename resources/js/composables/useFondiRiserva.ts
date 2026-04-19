// composables/useFondiRiserva.ts
import { computed, type Ref } from 'vue';

export interface FondoRiserva {
    id: number;
    nome: string;
    saldo_attuale: number; // in centesimi
    is_utilizzabile_per_imprevisti?: boolean;
    sottotipo_fondo?: string;
    is_override_assemblea?: boolean;
}

export function useFondiRiserva(fondiRiserva: Ref<FondoRiserva[]>) {
    /**
     * Filtra i fondi che possono essere utilizzati per coprire imprevisti.
     * Un fondo è utilizzabile se:
     * - è esplicitamente marcato come utilizzabile per imprevisti, OPPURE
     * - è di sottotipo generico (nessuna destinazione vincolata), OPPURE
     * - ha una deroga assembleare attiva
     */
    const fondiUtilizzabili = computed(() =>
        (fondiRiserva.value ?? []).filter(fondo =>
            fondo.is_utilizzabile_per_imprevisti === true ||
            fondo.sottotipo_fondo === 'generico' ||
            fondo.is_override_assemblea === true
        )
    );

    return { fondiUtilizzabili };
}