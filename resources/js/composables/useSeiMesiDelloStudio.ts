// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * I sei mesi per agire dei condomini dello studio, chiesti uno alla volta, in fila, dopo che la pagina si è aperta (`admin.dashboard.sei-mesi`, un condominio
 * per richiesta: ogni richiesta pesa quanto il suo condominio). Lo stato lo leggono la colonna «Sei mesi per agire», la card «Sei mesi» e il cassetto
 * dell'Action inbox: una fila sola per tutti. Quando i condomini cambiano (il condominio scelto nella pagina) la fila ricomincia; lasciando la pagina si
 * ferma. Un condominio che non c'è più (404) si salta; uno che non risponde va fra quelli da riprovare.
 */
import { onMounted, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';
import { chiediInFila, type CondominioBreve, type CondominioDelloStudio } from '@/lib/gestionale/debiti/seiMesiDelloStudio';

export function useSeiMesiDelloStudio(condomini: () => readonly CondominioBreve[]) {
    const esiti = ref<CondominioDelloStudio[]>([]);
    const totale = ref(condomini().length);
    const fatti = ref(0);
    // Prima della prima risposta la fila è già «in corso»: la card dice «calcolo 0 su 40», non «niente».
    const inCorso = ref(condomini().length > 0);
    const daRiprovare = ref<CondominioBreve[]>([]);
    let fila: AbortController | null = null;

    async function carica(quali: readonly CondominioBreve[]) {
        fila?.abort();
        const controllo = new AbortController();
        fila = controllo;
        totale.value = quali.length;
        fatti.value = 0;
        inCorso.value = quali.length > 0;
        await chiediInFila(
            quali,
            async (c, segnale) => (await axios.get(route('admin.dashboard.sei-mesi', { condominio: c.id }), { signal: segnale })).data as CondominioDelloStudio,
            controllo.signal,
            ({ condominio, dati, nonRiuscito }) => {
                fatti.value++;
                if (dati) {
                    esiti.value = [...esiti.value.filter((e) => e.condominio.id !== condominio.id), dati];
                }
                if (nonRiuscito) {
                    daRiprovare.value = [...daRiprovare.value, condominio];
                }
            },
        );
        if (fila === controllo) {
            inCorso.value = false;
        }
    }

    /** Chiede di nuovo i condomini che non hanno risposto. */
    function riprova() {
        const quali = daRiprovare.value;
        daRiprovare.value = [];
        carica(quali);
    }

    function daCapo() {
        esiti.value = [];
        daRiprovare.value = [];
        carica(condomini());
    }

    onMounted(daCapo);
    watch(() => condomini().map((c) => c.id).join(','), daCapo);
    onUnmounted(() => fila?.abort());

    return { esiti, totale, fatti, inCorso, daRiprovare, riprova };
}
