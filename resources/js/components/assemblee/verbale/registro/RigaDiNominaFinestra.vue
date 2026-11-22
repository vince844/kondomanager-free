<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Una riga del registro di nomina e revoca (scena 7 della bozza delle scene; art. 1130 n. 7 c.c.): la nomina in corso la prima volta (chi amministra oggi,
 * da quando, da dove viene: una delibera su carta, un verbale registrato, un decreto con l'autorità, il numero e la data), una riga a mano, o «Correggi»
 * una riga proposta prima di confermarla. Una riga sbagliata si annulla con il perché: non si cancella.
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
import { AIUTO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, SCELTE, TESTA, TITOLO_GRUPPO, scelta } from '../finestra';
import type { RigaDiNomina } from '@/types/verbale';

const props = defineProps<{ open: boolean; modo: 'in_corso' | 'nuova' | 'correggi'; riga: RigaDiNomina | null; amministratore: string | null; rotta: string }>();
const emit = defineEmits<{ 'update:open': [v: boolean] }>();
const oggi = giornoDiRoma();
const evento = ref<'nomina' | 'revoca'>('nomina');
const chi = ref('');
const dal = ref('');
const daDove = ref<'carta' | 'verbale' | 'decreto'>('carta');
const estremi = ref('');
const decreto = ref({ autorita: '', numero: '', data: '' });
const documento = ref<File | null>(null);
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);
watch(() => props.open, (a) => {
    if (!a) return;
    const r = props.riga;
    evento.value = r?.evento ?? 'nomina';
    chi.value = r?.amministratore ?? props.amministratore ?? '';
    dal.value = r?.dal ?? '';
    daDove.value = r?.da_dove ?? 'carta';
    estremi.value = r?.estremi ?? '';
    decreto.value = { autorita: r?.decreto?.autorita ?? '', numero: r?.decreto?.numero ?? '', data: r?.decreto?.data ?? '' };
    documento.value = null;
    errori.value = {};
});
const titolo = computed(() => ({ in_corso: 'La nomina in corso', nuova: 'Una riga a mano', correggi: 'Correggi la riga proposta' })[props.modo]);
function salva() {
    inCorso.value = true;
    const corpo: Record<string, unknown> = { evento: evento.value, amministratore: chi.value.trim(), dal: dal.value, da_dove: daDove.value,
        estremi: estremi.value.trim() || null, ...(daDove.value === 'decreto' ? { decreto: decreto.value } : {}) };
    const opzioni = { preserveScroll: true, onSuccess: () => emit('update:open', false), onError: (e: Record<string, string>) => { errori.value = e; },
        onFinish: () => { inCorso.value = false; } };
    if (props.modo === 'correggi') router.patch(props.rotta, corpo as never, opzioni);
    else router.post(props.rotta, { ...corpo, ...(documento.value ? { documento: documento.value } : {}) } as never, { ...opzioni, forceFormData: true });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="finestra-nomina">
            <DialogHeader :class="TESTA">
                <DialogTitle>{{ titolo }}</DialogTitle>
                <DialogDescription>{{ modo === 'in_corso' ? 'Il registro di nomina e revoca comincia qui: chi amministra oggi, e da quando?'
                    : 'Le date della nomina e della revoca di ciascun amministratore, e gli estremi del decreto se c\'è (art. 1130 n. 7 c.c.).' }}</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section :class="GRUPPO">
                    <div v-if="modo !== 'in_corso'" :class="[SCELTE, 'grid-cols-2']" role="radiogroup" aria-label="Nomina o revoca">
                        <button v-for="e in (['nomina', 'revoca'] as const)" :key="e" type="button" role="radio" :aria-checked="evento === e" :class="scelta(evento === e)"
                            @click="evento = e">{{ e === 'nomina' ? 'Nomina' : 'Revoca' }}</button>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <Label for="nome-amministratore" class="text-xs">Amministratore</Label>
                            <Input id="nome-amministratore" v-model="chi" maxlength="255" class="h-9 text-sm" data-testid="nome-amministratore" />
                            <InputError :message="errori.amministratore" />
                        </div>
                        <div class="space-y-1.5">
                            <Label class="text-xs">Dal</Label>
                            <CampoGiorno v-model="dal" :max="oggi" class="h-9 text-sm" aria-label="Dal" data-testid="dal" />
                            <InputError :message="errori.dal" />
                        </div>
                    </div>
                </section>
                <section v-if="modo !== 'correggi'" :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Da dove viene</p>
                    <div :class="[SCELTE, 'grid-cols-3']" role="radiogroup" aria-label="Da dove viene">
                        <button v-for="d in ([['carta', 'Una delibera su carta'], ['verbale', 'Un verbale registrato'], ['decreto', 'Un decreto']] as const)" :key="d[0]" type="button"
                            role="radio" :aria-checked="daDove === d[0]" :class="scelta(daDove === d[0])" :data-testid="`da-dove-${d[0]}`" @click="daDove = d[0]">{{ d[1] }}</button>
                    </div>
                    <div v-if="daDove === 'decreto'" class="grid gap-3 sm:grid-cols-3">
                        <div class="space-y-1.5"><Label for="autorita" class="text-xs">L'autorità</Label><Input id="autorita" v-model="decreto.autorita" class="h-9 text-sm" /></div>
                        <div class="space-y-1.5"><Label for="numero-decreto" class="text-xs">Il numero</Label><Input id="numero-decreto" v-model="decreto.numero" class="h-9 text-sm" /></div>
                        <div class="space-y-1.5"><Label class="text-xs">La data</Label><CampoGiorno v-model="decreto.data" :max="oggi" class="h-9 text-sm" aria-label="La data del decreto" /></div>
                    </div>
                    <InputError :message="errori.decreto" />
                    <div v-if="daDove !== 'decreto'" class="space-y-1.5">
                        <Label for="estremi" class="text-xs">Gli estremi</Label>
                        <Input id="estremi" v-model="estremi" maxlength="255" class="h-9 text-sm" placeholder="Per esempio: verbale n. 36 del 10/04/2025, nel libro di carta" />
                    </div>
                    <Label class="text-xs">Il documento (facoltativo)</Label>
                    <CampoFile v-model="documento" accept=".pdf,.p7m,.jpg,.jpeg,.png" testid="documento-nomina" />
                    <p :class="AIUTO">{{ modo === 'in_corso' ? 'Si scrive una volta sola: dopo, le righe le propongono le delibere.' : 'Una riga sbagliata si annulla con il perché: non si cancella.' }}</p>
                </section>
                <section v-else :class="GRUPPO">
                    <div class="space-y-1.5">
                        <Label for="estremi-correggi" class="text-xs">Gli estremi</Label>
                        <Input id="estremi-correggi" v-model="estremi" maxlength="255" class="h-9 text-sm" />
                    </div>
                    <p :class="AIUTO">La riga resta «Proposta» finché non la confermi: fino ad allora non va nella stampa.</p>
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
                    <span>{{ evento === 'nomina' ? 'Nomina' : 'Revoca' }}: {{ chi || '…' }}{{ dal ? `, dal ${dal.slice(8, 10)}/${dal.slice(5, 7)}/${dal.slice(0, 4)}` : '' }}.</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="!chi.trim() || !dal || inCorso" data-testid="salva-nomina" @click="salva">{{ modo === 'in_corso' ? 'Scrivi la nomina in corso'
                        : modo === 'correggi' ? 'Salva la correzione' : 'Scrivi la riga' }}</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
