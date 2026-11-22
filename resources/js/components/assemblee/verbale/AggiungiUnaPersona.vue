<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Aggiungi una persona» alla lista di chi riceve il verbale (scena 6 della bozza delle scene): si cerca fra chi c'era, ognuno con i suoi trenta giorni di
 * adesso; chi si aggiunge riceve il verbale come assente per i punti scelti, e i suoi trenta giorni partono dalla consegna. Serve quando la sala dice
 * presente chi non c'era. Il perché resta nel registro delle modifiche.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Loader2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { righeDeiTrentaGiorni } from '@/lib/assemblee/verbale/pagina';
import { AIUTO, CONTENUTO, CORPO, GRUPPO, PIEDE, PULSANTI, RIEPILOGO, TESTA, TITOLO_GRUPPO } from './finestra';
import type { DestinatarioDelVerbale, TrasmissioneDelVerbale } from '@/types/verbale';

const props = defineProps<{ open: boolean; trasmissione: TrasmissioneDelVerbale; rotta: string }>();
const emit = defineEmits<{ 'update:open': [v: boolean] }>();
const chi = ref<number | null>(null);
const punti = ref<number | 'tutti'>('tutti');
const perche = ref('');
const errore = ref('');
const inCorso = ref(false);
watch(() => props.open, (a) => { if (a) { chi.value = null; punti.value = 'tutti'; perche.value = ''; errore.value = ''; } });
const persone = computed(() => props.trasmissione.persone.filter((p) => !p.riceve).map((p: DestinatarioDelVerbale) => ({ id: p.anagrafica_id,
    etichetta: `${p.nome} · ${p.perche} · ${righeDeiTrentaGiorni(p).join('; ') || 'favorevole, nessun termine'}` })));
const opzioniDeiPunti = computed(() => [{ id: 'tutti' as const, etichetta: 'Tutti i punti' },
    ...props.trasmissione.punti.map((p) => ({ id: p.numero, etichetta: `Il punto ${p.numero}` }))]);

function aggiungi() {
    if (chi.value === null || !perche.value.trim()) return;
    inCorso.value = true;
    router.post(props.rotta, { anagrafica_id: chi.value, perche: perche.value.trim(), ...(punti.value === 'tutti' ? {} : { punti: [punti.value] }) }, {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
        onError: (e) => { errore.value = Object.values(e)[0] ?? ''; },
        onFinish: () => { inCorso.value = false; },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent :class="CONTENUTO" data-testid="finestra-aggiungi">
            <DialogHeader :class="TESTA">
                <DialogTitle>Aggiungi una persona</DialogTitle>
                <DialogDescription>Fra chi c'era, con i suoi trenta giorni di adesso.</DialogDescription>
            </DialogHeader>
            <div :class="CORPO">
                <section :class="GRUPPO">
                    <p :class="TITOLO_GRUPPO">Chi</p>
                    <v-select v-model="chi" :options="persone" label="etichetta" :reduce="(o: { id: number }) => o.id" placeholder="Cerca fra chi c'era…" data-testid="chi-aggiungi" />
                    <p :class="TITOLO_GRUPPO">Assente per</p>
                    <v-select v-model="punti" :options="opzioniDeiPunti" label="etichetta" :reduce="(o: { id: number | 'tutti' }) => o.id" :clearable="false" :searchable="false" />
                    <p :class="AIUTO">Chi aggiungi riceve il verbale come assente, per i punti che scegli: i suoi trenta giorni partono dalla consegna. Serve quando la sala dice presente
                        chi non c'era.</p>
                </section>
                <section :class="GRUPPO">
                    <Label for="perche-aggiungi" class="text-xs">Perché (obbligatorio)</Label>
                    <Textarea id="perche-aggiungi" v-model="perche" rows="2" class="min-h-[60px] resize-none text-sm" data-testid="perche-aggiungi" />
                    <InputError :message="errore" />
                </section>
            </div>
            <div :class="PIEDE">
                <p :class="RIEPILOGO" role="status"><Loader2 v-if="inCorso" class="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden="true" /><span>La scelta resta nel registro delle modifiche.</span></p>
                <div :class="PULSANTI">
                    <Button variant="outline" @click="emit('update:open', false)">Chiudi</Button>
                    <Button :disabled="chi === null || !perche.trim() || inCorso" data-testid="conferma-aggiungi" @click="aggiungi">Aggiungila alla lista</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
