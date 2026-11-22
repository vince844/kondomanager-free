<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Registra la consegna» di una convocazione: l'esito, il giorno (non dopo oggi), l'ora se c'è, e la prova (l'avviso di giacenza, la cartolina, il foglio
 * firmato). Gli esiti sono quelli del mezzo: per la PEC «Consegnata» o «Non consegnata», a mano «Consegnata» o «Rifiutata», per la raccomandata tutti. Il
 * giorno che conta per i cinque giorni è il primo fra la consegna e l'avviso di giacenza. Una riga con un esito si corregge con un fatto nuovo, che tiene
 * quello di prima: la finestra chiede perché. «Registra il ritiro» dopo la giacenza è invece un fatto nuovo, senza perché.
 *
 * Per una PEC la finestra prende la ricevuta del gestore (il file `.eml`): il programma ne legge il giorno e l'ora della consegna e la archivia sulla riga
 * (decisione 577); se non è la ricevuta di consegna di quella persona, lo dice e niente si scrive. Scritto a mano resta possibile. La prova di una
 * convocazione resta: non si toglie.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { PackageCheck } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import CampoFile from '@/components/CampoFile.vue';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import type { EsitoInvio } from '@/types/assemblee';
import SuggerimentoDellaI from './SuggerimentoDellaI.vue';
import { SOLO_CONVOCAZIONE, conInvio } from './aiuti';
import type { RigaDelConvocato } from './tipi';

const props = withDefaults(defineProps<{ open: boolean; riga: RigaDelConvocato | null; rotta: string; rottaRicevuta?: string; ritiro?: boolean }>(),
    { rottaRicevuta: '', ritiro: false });
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

type Esito = EsitoInvio | 'ritirata';
const ESITI: { valore: Esito; etichetta: string; giorno: string }[] = [
    { valore: 'consegnata', etichetta: 'Consegnata', giorno: 'Il giorno della consegna' },
    { valore: 'in_giacenza', etichetta: 'In giacenza', giorno: 'Il giorno dell\'avviso di giacenza' },
    { valore: 'rifiutata', etichetta: 'Rifiutata', giorno: 'Il giorno del rifiuto' },
    { valore: 'non_consegnata', etichetta: 'Non consegnata', giorno: 'Il giorno in cui è tornata indietro' },
    { valore: 'ritirata', etichetta: 'Ritirata', giorno: 'Il giorno del ritiro' },
];
/** Gli esiti di ogni mezzo, come li accetta il server: la raccomandata li ha tutti. */
const ESITI_DEL_MEZZO: Record<string, Esito[]> = {
    pec: ['consegnata', 'non_consegnata'],
    a_mano: ['consegnata', 'rifiutata'],
    fax: ['consegnata', 'non_consegnata'],
    raccomandata: ['consegnata', 'in_giacenza', 'rifiutata', 'non_consegnata'],
};
const ESEMPIO = 'Per una raccomandata in giacenza scrivi il giorno dell\'avviso: conta da lì. Esempio: avviso lasciato il 07/12, ritirata il 10/12: scrivi «In giacenza» il 07/12.';

const oggi = giornoDiRoma();
const esito = ref<Esito>('consegnata');
const giorno = ref(oggi);
const ora = ref('');
const prova = ref<File | null>(null);
const perche = ref('');
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);
const ricevutaInCorso = ref(false);
const ricevuta = ref<File | null>(null);

const correzione = computed(() => props.riga?.esito != null && !props.ritiro);
const pec = computed(() => props.riga?.mezzo === 'pec');
const esiti = computed(() => {
    if (props.ritiro) return ESITI.filter((e) => e.valore === 'ritirata');
    const delMezzo = ESITI_DEL_MEZZO[props.riga?.mezzo ?? 'raccomandata'] ?? ESITI_DEL_MEZZO.raccomandata;

    return ESITI.filter((e) => delMezzo.includes(e.valore));
});
const etichettaDelGiorno = computed(() => ESITI.find((e) => e.valore === esito.value)?.giorno ?? 'Il giorno');
const manca = computed(() => !giorno.value || (correzione.value && perche.value.trim() === ''));

watch(() => props.open, (aperta) => {
    if (!aperta || !props.riga) return;
    esito.value = props.ritiro ? 'ritirata' : (esiti.value.some((e) => e.valore === props.riga!.esito) ? props.riga.esito! : 'consegnata');
    giorno.value = props.ritiro ? oggi : (props.riga.ricevuta_il ?? oggi);
    ricevutaInCorso.value = false;
    ora.value = '';
    prova.value = null;
    perche.value = '';
    errori.value = {};
}, { immediate: true });


// La ricevuta PEC: si manda appena scelta, e il server ne legge il giorno e l'ora.
function sceltaLaRicevuta(file: File | null) {
    ricevuta.value = file;
    if (!file) return;
    if (!props.riga?.invio_id || !props.rottaRicevuta || (correzione.value && perche.value.trim() === '')) {
        if (correzione.value && perche.value.trim() === '') errori.value = { perche: 'Scrivi perché la correggi, poi scegli la ricevuta.' };
        // Il campo si svuota dopo che ha mostrato il file: così la stessa ricevuta si può scegliere di nuovo.
        nextTick(() => { ricevuta.value = null; });
        return;
    }
    ricevutaInCorso.value = true;
    const corpo: Record<string, unknown> = { ricevuta: file };
    if (correzione.value) corpo.perche = perche.value.trim();
    router.post(conInvio(props.rottaRicevuta, props.riga.invio_id), corpo as never, {
        forceFormData: true,
        preserveScroll: true,
        only: SOLO_CONVOCAZIONE,
        onSuccess: () => emit('update:open', false),
        onError: (er) => { errori.value = er as Record<string, string>; },
        onFinish: () => { ricevutaInCorso.value = false; ricevuta.value = null; },
    });
}

function registra() {
    if (!props.riga?.invio_id || manca.value) return;
    inCorso.value = true;
    const corpo: Record<string, unknown> = { esito: esito.value, giorno: giorno.value };
    if (ora.value) corpo.ora = ora.value;
    if (prova.value) corpo.prova = prova.value;
    if (correzione.value) corpo.perche = perche.value.trim();
    router.post(conInvio(props.rotta, props.riga.invio_id), corpo as never, {
        forceFormData: true,
        preserveScroll: true,
        only: SOLO_CONVOCAZIONE,
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errori.value = e as Record<string, string>; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <PackageCheck class="h-5 w-5 text-emerald-600" /> {{ ritiro ? 'Registra il ritiro' : correzione ? 'Correggi la consegna' : 'Registra la consegna' }}
                </DialogTitle>
                <DialogDescription>{{ riga?.nome }}<template v-if="riga"> · {{ riga.unita }} · {{ riga.mezzo_parola }}</template></DialogDescription>
            </DialogHeader>

            <div class="space-y-3 text-sm">
                <p v-if="correzione && riga" class="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                    <b class="font-semibold">Adesso:</b> {{ riga.stato_parola }}<template v-if="riga.dettaglio">, {{ riga.dettaglio }}</template>. La correzione è un fatto nuovo: quello di prima resta nel diario.
                </p>
                <p v-if="ritiro && riga" class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                    data-testid="nota-ritiro">
                    Il giorno che conta resta l'avviso di giacenza<template v-if="riga.ricevuta_il">, del {{ riga.ricevuta_il.slice(8, 10) }}/{{ riga.ricevuta_il.slice(5, 7) }}</template>:
                    il ritiro è un fatto in più, non una correzione.
                </p>
                <div v-if="pec && !ritiro && rottaRicevuta" class="space-y-1.5 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2.5 dark:border-sky-900 dark:bg-sky-950/40"
                    data-testid="ricevuta-pec">
                    <Label class="text-xs font-semibold text-sky-900 dark:text-sky-200">La ricevuta di consegna del gestore (.eml)</Label>
                    <ul class="list-disc space-y-0.5 pl-4 text-xs text-sky-900 dark:text-sky-200">
                        <li>La salvi dalla casella PEC, così com'è.</li>
                        <li>Il programma legge il giorno e l'ora della consegna.</li>
                        <li>Il file resta sulla riga, e si scarica.</li>
                    </ul>
                    <CampoFile :model-value="ricevuta" accept=".eml" testid="file-ricevuta" :disabled="ricevutaInCorso" @update:model-value="sceltaLaRicevuta" />
                    <p v-if="ricevutaInCorso" class="text-xs text-sky-900 dark:text-sky-200">Sto leggendo la ricevuta…</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Senza la ricevuta, scrivi qui sotto il giorno e l'ora.</p>
                </div>
                <div v-if="!ritiro" class="space-y-1.5">
                    <div class="flex items-center gap-1.5">
                        <Label class="text-xs">Che cosa è successo</Label>
                        <!-- Il suggerimento del programma, che compare subito e al tocco. -->
                        <SuggerimentoDellaI :testo="ESEMPIO" testid="esempio-consegna" />
                    </div>
                    <v-select v-model="esito" :options="esiti" label="etichetta" :reduce="(e: { valore: Esito }) => e.valore" :searchable="false" :clearable="false"
                        data-testid="esito-consegna" />
                    <p v-if="esito === 'in_giacenza'" class="text-xs text-slate-500 dark:text-slate-400">Per una raccomandata in giacenza scrivi il giorno dell'avviso: conta da lì.</p>
                    <p v-else-if="esito === 'rifiutata'" class="text-xs text-slate-500 dark:text-slate-400">Il rifiuto non conta da solo come ricevuta: lo vedrà chi presiede, all'apertura della sala.</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_8rem]">
                    <div class="space-y-1.5">
                        <Label for="consegna-giorno" class="text-xs">{{ etichettaDelGiorno }}</Label>
                        <CampoGiorno id="consegna-giorno" v-model="giorno" :max="oggi" class="h-9 text-sm" />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="consegna-ora" class="text-xs">L'ora (facoltativa)</Label>
                        <Input id="consegna-ora" v-model="ora" type="time" class="h-9 text-sm" />
                    </div>
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">La prova (facoltativa): l'avviso, la cartolina, il foglio firmato</Label>
                    <CampoFile v-model="prova" accept=".pdf,.jpg,.jpeg,.png,.eml,.p7m" testid="file-prova" />
                    <p class="text-xs text-slate-500 dark:text-slate-400">La prova di una convocazione resta con la sua riga: non si toglie.</p>
                </div>
                <div v-if="correzione" class="space-y-1.5">
                    <Label for="consegna-perche" class="text-xs">Perché la correggi</Label>
                    <Textarea id="consegna-perche" v-model="perche" rows="2" class="min-h-[60px] resize-none text-sm"
                        placeholder="Per esempio: sulla cartolina il giorno è il 09/12, non il 10/12." data-testid="perche-correzione" />
                </div>
                <ul v-if="Object.keys(errori).length" class="list-disc pl-4 text-xs text-rose-700 dark:text-rose-300" data-testid="errori-consegna">
                    <li v-for="(m, k) in errori" :key="k">{{ m }}</li>
                </ul>
            </div>

            <DialogFooter class="items-center gap-2">
                <span v-if="correzione && perche.trim() === ''" class="text-xs text-slate-500">Scrivi perché la correggi.</span>
                <Button variant="outline" size="sm" @click="emit('update:open', false)">Annulla</Button>
                <Button size="sm" :disabled="manca || inCorso" data-testid="registra-consegna" @click="registra">
                    {{ ritiro ? 'Registra il ritiro' : correzione ? 'Correggi' : 'Registra la consegna' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
