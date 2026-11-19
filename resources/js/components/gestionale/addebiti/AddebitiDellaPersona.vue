<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Gli addebiti di una persona nella pagina del suo estratto conto, solo quando ce n'è almeno uno: ognuno con la sua causale, l'unità, la natura,
 * l'importo, il versato e la scadenza; «Annulla» per quelli che si annullano, e per gli altri il perché (l'esercizio chiuso, un accordo di rientro, un
 * incasso da stornare prima). «Nuovo addebito» e «Spese del ritardo» chiedono alla pagina le sue finestre, che sono una volta sola (le aprono anche il
 * menu «Azioni» e la card «Addebiti personali»). Di un interesse o di una spesa la riga dice la base dichiarata e gli avvisi, che non bloccano. Le righe
 * dello stesso addebito nel libro qui sotto (l'emissione, gli incassi) sono quelle di una rata: questo elenco dice che cosa è.
 */
import { ref } from 'vue';
import axios from 'axios';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { frasePerErrore } from '@/lib/gestionale/incassi/ricevute';
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';
import { FRASE_ANNULLA, nomeNatura } from '@/lib/gestionale/addebiti/addebiti';

export interface AddebitoDellaPersona {
    rata_id: number; causale: string; unita: string | null; importo: number; versato: number; scadenza: string | null; natura: string;
    spesa_fatturata: boolean; piano: string; annullabile: boolean;
    /** Perché non si annulla: l'esercizio chiuso, un accordo di rientro che lo riconosce, un incasso. */
    perche_resta?: string | null;
    /** Di un interesse o di una spesa: il tipo, la base dichiarata con il riferimento, gli avvisi. */
    tipo?: string | null; base?: string | null; avvisi?: string[];
    /** Decisione 493 (10): gli interessi registrati con una cifra più bassa della proposta, «Proposti € 0,37, registrati € 0,30 il …: «…».». */
    cifre_cambiate?: string | null;
}

const props = defineProps<{ condominioId: number; persona: { id: number; nome: string }; addebiti: AddebitoDellaPersona[] }>();
const emit = defineEmits<{ (e: 'fatto'): void; (e: 'nuovo'): void; (e: 'spese'): void }>();

// Le colonne della tabella, uguali per l'intestazione e per ogni riga: l'addebito, l'importo, la scadenza, «Annulla».
const COLONNE = 'sm:grid-cols-[minmax(0,1fr)_7rem_8rem_6rem]';

const { generateRoute } = usePermission();
// L'addebito scelto resta finché la richiesta non parte: la conferma si chiude da sé quando si preme il pulsante.
const daAnnullare = ref<AddebitoDellaPersona | null>(null);
const confermaAperta = ref(false);
const chiediConferma = (a: AddebitoDellaPersona) => { daAnnullare.value = a; confermaAperta.value = true; };
const errore = ref<string | null>(null);
const esito = ref<string | null>(null);

async function annulla() {
    const a = daAnnullare.value;
    confermaAperta.value = false;
    if (!a) {
        return;
    }
    errore.value = null;
    try {
        const r = (await axios.post(route(generateRoute('gestionale.addebiti.annulla'), { condominio: props.condominioId, addebito: a.rata_id }))).data;
        esito.value = r.messaggio;
        emit('fatto');
    } catch (e) {
        errore.value = frasePerErrore(e, 'L\'addebito non è stato annullato. Riprova.');
    }
}
</script>

<template>
    <section v-if="addebiti.length" class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-2 text-sm" data-testid="addebiti-della-persona">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h3 class="font-medium">Addebiti personali</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="frase-addebiti">Somme chieste solo a questa persona, fuori dal riparto</p>
            </div>
            <span class="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" data-testid="nuovo-addebito" @click="emit('nuovo')">Nuovo addebito</Button>
                <Button size="sm" variant="outline" data-testid="spese-del-ritardo" @click="emit('spese')">Spese del ritardo</Button>
            </span>
        </div>
        <!-- Una tabella con le colonne fisse: l'addebito, l'importo a destra, la scadenza, «Annulla» (o il perché resta, su una riga sua). -->
        <div class="hidden gap-x-4 px-2 text-xs font-medium text-slate-500 dark:text-slate-400 sm:grid" :class="COLONNE" aria-hidden="true">
            <span>Addebito</span><span class="text-right">Importo</span><span>Scadenza</span><span></span>
        </div>
        <div v-for="a in addebiti" :key="a.rata_id" class="grid grid-cols-1 gap-x-4 gap-y-1 rounded-lg border dark:border-slate-800 p-2 text-xs sm:items-center" :class="COLONNE" data-testid="addebito">
            <span class="min-w-0"><strong>{{ a.causale }}</strong><span class="block text-slate-500 dark:text-slate-400">{{ nomeNatura(a.natura, a.spesa_fatturata, a.tipo ?? null) }}<template v-if="a.unita"> · {{ a.unita }}</template> · {{ a.piano }}</span>
                <span v-if="a.base" class="block text-slate-500 dark:text-slate-400" data-testid="base">Base: {{ a.base }}</span>
                <span v-for="av in a.avvisi ?? []" :key="av" class="block text-amber-800 dark:text-amber-300" data-testid="avviso">{{ av }}</span>
                <span v-if="a.cifre_cambiate" class="block text-slate-600 dark:text-slate-300" data-testid="cifre-cambiate">{{ a.cifre_cambiate }}</span></span>
            <span class="tabular-nums sm:text-right" data-testid="importo-addebito">{{ euroTesto(a.importo) }}<span v-if="a.versato" class="block text-slate-500 dark:text-slate-400">versati {{ euroTesto(a.versato) }}</span></span>
            <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ a.scadenza ? `scade il ${giorno(a.scadenza)}` : '' }}</span>
            <span v-if="a.annullabile" class="sm:text-right">
                <Button size="sm" variant="ghost" class="h-7 w-full text-xs sm:w-auto" data-testid="annulla-addebito" @click="chiediConferma(a)">Annulla</Button>
            </span>
            <span v-else class="text-slate-500 dark:text-slate-400 sm:col-span-4" data-testid="non-annullabile">{{ a.perche_resta }}</span>
        </div>
        <p v-if="esito" class="text-xs text-emerald-800 dark:text-emerald-300" data-testid="esito">{{ esito }}</p>
        <p v-if="errore" class="text-xs text-red-700 dark:text-red-400" data-testid="errore">{{ errore }}</p>

        <ConfirmDialog
            :model-value="confermaAperta"
            title="Annullare l'addebito?"
            :description="FRASE_ANNULLA"
            confirm-text="Annulla l'addebito"
            cancel-text="Lascialo"
            variant="destructive"
            @update:model-value="(v: boolean) => { confermaAperta = v }"
            @confirm="annulla"
        />
    </section>
</template>
