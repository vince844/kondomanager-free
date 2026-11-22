<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Sostituisci la copia» del verbale registrato (scena 4 della bozza delle scene): il file nuovo e il perché; il numero e il giorno della firma restano, la
 * copia di prima resta fra le versioni. Se il verbale è già partito, la finestra dice a chi: hanno la copia di prima, e dopo la pagina propone di
 * rimandarla (decisione 615 (6)).
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import CampoFile from '@/components/CampoFile.vue';
import InputError from '@/components/InputError.vue';
import { AIUTO, ARANCIO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, TESTA, TITOLO_GRUPPO } from './finestra';
import { lettereSoloStampate } from '@/lib/assemblee/verbale/pagina';
import type { VerbaleDellaPagina } from '@/types/verbale';

const props = defineProps<{ open: boolean; verbale: VerbaleDellaPagina; rotta: string }>();
const emit = defineEmits<{ 'update:open': [v: boolean] }>();
const copia = ref<File | null>(null);
const perche = ref('');
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);
watch(() => props.open, (a) => { if (a) { copia.value = null; perche.value = ''; errori.value = {}; } });
/** Chi ha già la copia: l'invio è partito. */
const partiti = computed(() => (props.verbale.trasmissione?.persone ?? []).filter((p) => p.riceve && p.invio?.spedito));
/** Le lettere già stampate con questa copia e non spedite: dopo la sostituzione si ristampano. */
const stampate = computed(() => lettereSoloStampate(props.verbale.trasmissione ?? null));

function sostituisci() {
    if (!copia.value || !perche.value.trim()) return;
    inCorso.value = true;
    router.post(props.rotta, { copia: copia.value, perche: perche.value.trim() } as never, {
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
        <DialogContent :class="CONTENUTO" data-testid="finestra-sostituisci">
            <DialogHeader :class="TESTA">
                <DialogTitle>Sostituisci la copia del verbale n. {{ verbale.numero }}</DialogTitle>
                <DialogDescription>Il numero e il giorno della firma restano.</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Adesso</p>
                    <p class="text-xs text-slate-700 dark:text-slate-300">{{ verbale.copia?.nome }}</p>
                </section>
                <section :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">La copia nuova</p>
                    <CampoFile v-model="copia" accept=".pdf,.p7m,.jpg,.jpeg,.png" testid="copia-nuova" />
                    <p :class="AIUTO">PDF, .p7m o immagine, fino a 10 MB.</p>
                    <InputError :message="errori.copia" />
                    <div class="space-y-1.5">
                        <Label for="perche-sostituisci" class="text-xs">Perché</Label>
                        <Textarea id="perche-sostituisci" v-model="perche" rows="2" class="min-h-[60px] resize-none text-sm" data-testid="perche-sostituisci"
                            placeholder="Per esempio: nelle foto mancava la pagina 4, l'elenco dei presenti in calce." />
                        <InputError :message="errori.perche" />
                    </div>
                    <ul class="list-disc space-y-0.5 pl-5 text-xs text-slate-600 dark:text-slate-400">
                        <li>La copia di prima resta, fra le versioni della copia.</li>
                        <li>Il testo firmato dev'essere lo stesso: se è cambiato il testo, non è una copia nuova, si annulla e si registra di nuovo.</li>
                        <li>La sostituzione resta nel registro delle modifiche, con il perché.</li>
                    </ul>
                </section>
                <section :class="GRUPPO">
                    <p v-if="partiti.length" :class="ARANCIO" data-testid="gia-partito">
                        <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span>{{ partiti.length === 1 ? '1 ha' : `${partiti.length} hanno` }} già la copia di prima: {{ partiti.map((p) => p.nome).join(', ') }}. Dopo la
                            sostituzione la pagina ti propone di rimandarla.</span>
                    </p>
                    <p v-else-if="!stampate.length" class="text-xs text-slate-600 dark:text-slate-400">Non è ancora partito a nessuno: le PEC e le lettere useranno questa copia.</p>
                    <p v-if="stampate.length" :class="ARANCIO" data-testid="gia-stampate">
                        <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span>{{ stampate.length === 1 ? '1 lettera stampata' : `${stampate.length} lettere stampate` }} con questa copia e non ancora spedite: dopo la
                            sostituzione ristampale prima di spedirle ({{ stampate.map((p) => p.nome).join(', ') }}).</span>
                    </p>
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" />
                    <span>{{ copia ? copia.name : 'Scegli la copia nuova.' }}</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="!copia || !perche.trim() || inCorso" data-testid="conferma-sostituisci" @click="sostituisci">Sostituisci la copia</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
