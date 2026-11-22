<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il menu «Registro» dell'intestazione del registro, accanto a «Guida» (decisione 570, la scena 3 della bozza dei due menu), alto `h-8` come «Guida»; e
 * accanto al menu il pulsante «Stampa» (la bozza della stampa scelta, 21/11/2026), che apre la finestra «Stampa il registro delle modifiche»
 * (`FinestraDellaStampa.vue`) con i filtri della pagina, il periodo dal … al … e il conto delle righe prima del PDF.
 *
 * - «Verifica l'integrità» rilegge la catena delle impronte e dice una delle due frasi della bozza (scena 5) in una finestra: «Il registro è integro.» in
 *   verde, o «Una riga non torna.» in rosso con «Scarica il rapporto»; poi la card «Integrità» si rilegge (una visita parziale di `riepilogo`).
 * - **La sospensione**, solo nel registro del condominio (con `condominioId`) e solo a chi la può cambiare: «Sospendi la cancellazione», o «Togli la
 *   sospensione» se è in corso. La finestra è quella di `SospensioneDellaCancellazione.vue`, montata qui senza il suo pulsante.
 *
 * Le finestre stanno fuori dal menu: si aprono quando il menu si chiude, e il menu non è modale perché la finestra prenda il fuoco.
 */
import { computed, ref } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import SospensioneDellaCancellazione from '@/components/registroModifiche/SospensioneDellaCancellazione.vue';
import FinestraDellaStampa from '@/components/registroModifiche/FinestraDellaStampa.vue';
import { ChevronDown, FileText, History, PauseCircle, Printer, ShieldCheck, ShieldX, X } from 'lucide-vue-next';
import { messaggioDellErrore } from '@/lib/registroModifiche/registro';
import type { StampaDelRegistro } from '@/lib/registroModifiche/stampa';

const props = defineProps<{ stampa: StampaDelRegistro; condominioId?: number | null; condominioNome?: string }>();
const emit = defineEmits<{ (e: 'cambiata'): void }>();

/** La finestra della sospensione, montata senza il suo pulsante: il menu ne legge lo stato e la apre. */
const sospensione = ref<{ apri: () => void; stato: { sospesa: boolean; puo_cambiare: boolean } | null } | null>(null);
const voceSospensione = computed(() => {
    const stato = sospensione.value?.stato;
    if (!stato?.puo_cambiare) return null;

    return stato.sospesa ? 'Togli la sospensione' : 'Sospendi la cancellazione';
});

const { generateRoute } = usePermission();
const aperta = ref(false);
const stampaAperta = ref(false);
const inCorso = ref(false);
const esito = ref<{ integro: boolean; titolo: string; frase: string; verificata_il: string } | null>(null);
const errore = ref<string | null>(null);

const classePulsante = 'inline-flex h-8 items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 disabled:opacity-60 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800';

async function verifica() {
    aperta.value = true;
    inCorso.value = true;
    esito.value = null;
    errore.value = null;
    try {
        const r = await axios.post(route(generateRoute('registro-modifiche.verifica')));
        esito.value = r.data;
        router.reload({ only: ['riepilogo'] });
    } catch (e) {
        errore.value = messaggioDellErrore(e, 'La verifica non si è conclusa. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

const indirizzoRapporto = () => route(generateRoute('registro-modifiche.verifica.rapporto'));
/** La frase senza il titolo, che la finestra scrive in grassetto. */
const resto = (e: { titolo: string; frase: string }) => e.frase.startsWith(e.titolo) ? e.frase.slice(e.titolo.length).trim() : e.frase;
</script>

<template>
    <button type="button" :class="classePulsante" data-testid="stampa-registro" @click="stampaAperta = true">
        <Printer class="h-3.5 w-3.5" /> Stampa
    </button>

    <DropdownMenu :modal="false">
        <DropdownMenuTrigger as-child>
            <button type="button" :class="classePulsante" data-testid="menu-registro">
                <History class="h-3.5 w-3.5" /> Registro <ChevronDown class="h-3.5 w-3.5 text-slate-400" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-60">
            <DropdownMenuItem :disabled="inCorso" class="text-xs" data-testid="verifica-integrita" @select="verifica">
                <ShieldCheck class="h-3.5 w-3.5" /> Verifica l'integrità
            </DropdownMenuItem>
            <DropdownMenuItem v-if="voceSospensione" class="text-xs" data-testid="voce-sospensione" @select="sospensione?.apri()">
                <PauseCircle class="h-3.5 w-3.5" /> {{ voceSospensione }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <FinestraDellaStampa v-model:open="stampaAperta" :stampa="props.stampa" />

    <SospensioneDellaCancellazione
        v-if="props.condominioId"
        ref="sospensione"
        senza-pulsante
        :condominio-id="props.condominioId"
        :condominio-nome="props.condominioNome"
        @cambiata="emit('cambiata')"
    />

    <Dialog :open="aperta" @update:open="(v: boolean) => (aperta = v)">
        <DialogContent class="max-w-[520px]" data-testid="finestra-verifica">
            <DialogHeader>
                <DialogTitle class="text-lg font-semibold">Verifica l'integrità</DialogTitle>
                <DialogDescription class="text-sm text-slate-500 dark:text-slate-400">
                    Ogni riga nasce con la firma del programma e porta l'impronta di quella prima: così una riga cambiata, aggiunta o tolta fuori dal
                    programma si vede.
                </DialogDescription>
            </DialogHeader>
            <p v-if="inCorso" class="text-sm text-slate-500 dark:text-slate-400">Rileggo la catena delle righe…</p>
            <div v-else-if="esito && esito.integro" class="flex items-start gap-2.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300" data-testid="esito-integro">
                <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0" />
                <div><strong class="text-sm">{{ esito.titolo }}</strong><p class="mt-0.5 text-xs">{{ resto(esito) }}</p></div>
            </div>
            <div v-else-if="esito" class="flex items-start gap-2.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300" data-testid="esito-non-torna">
                <ShieldX class="mt-0.5 h-4 w-4 shrink-0" />
                <div>
                    <strong class="text-sm">{{ esito.titolo }}</strong>
                    <p class="mt-0.5 text-xs">{{ resto(esito) }} Scarica il rapporto e chiedi a chi gestisce il server.</p>
                    <a :href="indirizzoRapporto()" class="mt-2 inline-flex h-7 items-center gap-1.5 rounded-md border border-rose-200 bg-white px-2.5 text-xs font-medium text-rose-800 hover:bg-rose-50 dark:border-rose-800 dark:bg-slate-900 dark:text-rose-300" data-testid="scarica-rapporto">
                        <FileText class="h-3.5 w-3.5" /> Scarica il rapporto
                    </a>
                </div>
            </div>
            <p v-if="errore" class="text-xs text-rose-700 dark:text-rose-400">{{ errore }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                La catena rende visibile una modifica fatta fuori dal programma; non la impedisce. Chi ha anche la chiave del server può riscrivere tutto:
                per questo la verifica si può rifare quando serve, e la stampa porta l'impronta.
            </p>
            <div class="flex justify-end border-t border-slate-100 pt-3 dark:border-slate-800">
                <Button variant="outline" size="sm" @click="aperta = false"><X class="h-3.5 w-3.5" /> Chiudi</Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
