<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Registra il verbale sottoscritto» (scena 1 della bozza del verbale, scena 3 della bozza delle scene): la copia firmata (scansione, PDF o `.p7m`, fino a
 * 10 MB), il numero proposto dal registro (si corregge, mai uno già usato), «Sottoscritto il» fra il giorno della seduta e oggi. La prima volta il registro
 * chiede da dove parte: «Continua il libro di carta» (l'ultimo numero e la data, facoltativa) o «Comincia dal n. 1».
 *
 * Si registra il testo firmato: senza la bozza stampata la finestra chiede la conferma («Registro il testo di adesso»); un numero più alto del proposto chiede
 * la conferma del salto («Salti i numeri 43–44?»), perché il registro va solo avanti e un numero saltato non torna.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import CampoFile from '@/components/CampoFile.vue';
import CampoGiorno from '@/components/CampoGiorno.vue';
import InputError from '@/components/InputError.vue';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { AIUTO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, SCELTE, TESTA, TITOLO_GRUPPO, scelta } from './finestra';
import type { VerbaleDellaPagina } from '@/types/verbale';

const props = defineProps<{ open: boolean; verbale: VerbaleDellaPagina; rotta: string; giornoDellaSeduta: string }>();
const emit = defineEmits<{ 'update:open': [v: boolean] }>();

const oggi = giornoDiRoma();
const daImpostare = computed(() => props.verbale.registro.partenza === null);
const partenza = ref<'libro' | 'da_uno'>('libro');
const ultimo = ref('');
const del_ = ref('');
const numero = ref('');
const sottoscritto = ref(oggi);
const copia = ref<File | null>(null);
const senzaLaBozza = ref(false);
const salto = ref(false);
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);
watch(() => props.open, (a) => {
    if (!a) return;
    partenza.value = 'libro';
    ultimo.value = '';
    del_.value = '';
    numero.value = '';
    sottoscritto.value = props.giornoDellaSeduta > oggi ? oggi : (props.giornoDellaSeduta || oggi);
    copia.value = null;
    senzaLaBozza.value = false;
    salto.value = false;
    errori.value = {};
}, { immediate: true });
/** Il numero proposto: con il registro da impostare, quello dopo l'ultimo del libro (o il n. 1). */
const proposto = computed(() => (daImpostare.value ? (partenza.value === 'libro' ? (Number(ultimo.value) > 0 ? Number(ultimo.value) + 1 : null) : 1)
    : props.verbale.registro.numero_proposto));
const dopo = computed(() => (daImpostare.value && partenza.value === 'libro' && Number(ultimo.value) > 0 ? `dopo il n. ${ultimo.value} del libro` : 'il prossimo'));
/** Senza la bozza stampata si registra il testo di adesso solo con la conferma. */
const senzaBozza = computed(() => props.verbale.stampata === null || props.verbale.stampata === undefined);
/** Il numero scritto più alto del proposto: i numeri in mezzo non tornano. */
const saltati = computed(() => {
    const n = Number(numero.value.trim());
    const p = proposto.value;
    if (!p || !Number.isInteger(n) || n <= p) return null;
    return n - 1 === p ? `il numero ${p}` : `i numeri ${p}–${n - 1}`;
});
const pronto = computed(() => copia.value !== null && sottoscritto.value !== '' && (!daImpostare.value || partenza.value === 'da_uno' || Number(ultimo.value) > 0)
    && (!senzaBozza.value || senzaLaBozza.value) && (saltati.value === null || salto.value));

function registra() {
    if (!pronto.value) return;
    inCorso.value = true;
    const corpo: Record<string, unknown> = { copia: copia.value, sottoscritto_il: sottoscritto.value };
    if (numero.value.trim() !== '') corpo.numero = Number(numero.value);
    if (senzaBozza.value && senzaLaBozza.value) corpo.senza_la_bozza = 1;
    if (saltati.value !== null && salto.value) corpo.salto = 1;
    if (daImpostare.value) corpo.partenza = { scelta: partenza.value, ...(partenza.value === 'libro' ? { ultimo: Number(ultimo.value), del: del_.value || null } : {}) };
    router.post(props.rotta, corpo as never, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errori.value = e as Record<string, string>; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="finestra-registra">
            <DialogHeader :class="TESTA">
                <DialogTitle>Registra il verbale sottoscritto</DialogTitle>
                <DialogDescription>Prende il numero del registro dei verbali «{{ verbale.registro.nome }}».</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <p v-if="verbale.stampata?.cambiata || errori.bozza" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300"
                    data-testid="testo-cambiato">{{ errori.bozza ?? verbale.stampata?.cambiata }}</p>
                <section v-if="senzaBozza" :class="GRUPPO" data-testid="senza-la-bozza">
                    <p :class="TITOLO_GRUPPO">La bozza da firmare non è stata stampata</p>
                    <p :class="AIUTO">Si registra il testo firmato. Se la copia viene da questo testo, conferma; se no, chiudi e stampa prima la bozza.</p>
                    <div :class="[SCELTE, 'grid-cols-1 sm:grid-cols-2']" role="radiogroup" aria-label="Senza la bozza stampata">
                        <button type="button" role="radio" :aria-checked="senzaLaBozza" :class="scelta(senzaLaBozza)" data-testid="conferma-senza-bozza"
                            @click="senzaLaBozza = true">Registro il testo di adesso</button>
                        <button type="button" role="radio" :aria-checked="!senzaLaBozza" :class="scelta(!senzaLaBozza)" @click="senzaLaBozza = false">Stampo prima la bozza</button>
                    </div>
                    <InputError :message="errori.senza_la_bozza" />
                </section>
                <section v-if="daImpostare" :class="GRUPPO" data-testid="da-dove-parte">
                    <p :class="TITOLO_GRUPPO">Da dove parte il registro</p>
                    <div :class="[SCELTE, 'grid-cols-2']" role="radiogroup" aria-label="Da dove parte il registro">
                        <button type="button" role="radio" :aria-checked="partenza === 'libro'" :class="scelta(partenza === 'libro')" data-testid="partenza-libro"
                            @click="partenza = 'libro'">Continua il libro di carta</button>
                        <button type="button" role="radio" :aria-checked="partenza === 'da_uno'" :class="scelta(partenza === 'da_uno')" data-testid="partenza-da-uno"
                            @click="partenza = 'da_uno'">Comincia dal n. 1</button>
                    </div>
                    <div v-if="partenza === 'libro'" class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="ultimo-del-libro" class="text-xs">L'ultimo numero del libro</Label>
                            <Input id="ultimo-del-libro" v-model="ultimo" inputmode="numeric" class="h-9 text-sm" data-testid="ultimo-del-libro" />
                            <InputError :message="errori['partenza.ultimo']" />
                        </div>
                        <div class="space-y-1.5">
                            <Label class="text-xs">Del (facoltativo)</Label>
                            <CampoGiorno v-model="del_" :max="oggi" svuotabile class="h-9 text-sm" aria-label="Il giorno dell'ultimo verbale del libro" />
                        </div>
                    </div>
                    <p :class="AIUTO">Lo chiedo una volta sola: lo cambi finché non registri il primo verbale; dopo, il numero va solo avanti.</p>
                </section>
                <section :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">La copia firmata</p>
                    <CampoFile v-model="copia" accept=".pdf,.p7m,.jpg,.jpeg,.png" testid="copia-firmata" />
                    <p :class="AIUTO">PDF, .p7m o immagine, fino a 10 MB: firmata da presidente e segretario.</p>
                    <InputError :message="errori.copia" />
                </section>
                <section :class="GRUPPO">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="numero-nel-registro" class="text-xs">Numero nel registro</Label>
                            <Input id="numero-nel-registro" v-model="numero" inputmode="numeric" :placeholder="proposto ? String(proposto) : ''" class="h-9 text-sm"
                                data-testid="numero-nel-registro" />
                            <p :class="AIUTO"><template v-if="proposto">{{ proposto }} {{ dopo }}. </template>Si corregge, ma non può essere uno già usato.</p>
                            <div v-if="saltati" class="space-y-1.5 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
                                data-testid="salto-del-numero">
                                <p>Salti {{ saltati }}? Il registro va solo avanti: un numero saltato non torna.</p>
                                <button type="button" role="checkbox" :aria-checked="salto" :class="scelta(salto)" data-testid="conferma-salto" @click="salto = !salto">Confermo il salto</button>
                            </div>
                            <InputError :message="errori.numero" />
                        </div>
                        <div class="space-y-1.5">
                            <Label class="text-xs">Sottoscritto il</Label>
                            <CampoGiorno v-model="sottoscritto" :min="giornoDellaSeduta" :max="oggi" class="h-9 text-sm" aria-label="Sottoscritto il" data-testid="sottoscritto-il" />
                            <InputError :message="errori.sottoscritto_il" />
                        </div>
                    </div>
                    <p :class="AIUTO">Un verbale registrato non si cancella: si annulla con il motivo, e resta nel registro.</p>
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status">
                    <Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
                    <span>{{ copia ? `Registro il verbale n. ${numero || proposto || '…'}.` : 'Scegli la copia firmata.' }}</span>
                </p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="!pronto || inCorso" data-testid="conferma-registra" @click="registra">Registra il verbale n. {{ numero || proposto || '…' }}</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
