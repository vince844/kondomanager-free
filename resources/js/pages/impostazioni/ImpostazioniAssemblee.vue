<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La card «Assemblee» delle impostazioni dello studio (scena 9 della bozza delle scene della tappa 3), solo all'amministratore: valgono per tutti i
 * condomini dello studio, e ogni cambio resta nel registro delle modifiche. Tre righe nella forma delle impostazioni del condominio (il titolo con la «i» e
 * l'esempio, la spiegazione di acceso e spento, la riga «Adesso: …» che cambia appena si muove l'interruttore, «Di serie» solo lì):
 *
 * - **i giorni di posta** per una raccomandata, con cui il programma propone entro quando spedire;
 * - **«La delega oltre il quinto si rifiuta»** (acceso di serie): spento, decide il presidente con il motivo;
 * - **«Il voto discorde dei comproprietari conta astenuto»** (acceso di serie): spento, decide il presidente quale voto vale.
 *
 * «Salva» manda le tre scelte insieme, e si accende quando qualcosa cambia. La scelta delle teste nel caso misto è di ogni condominio, nelle sue impostazioni.
 */
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Gavel, Loader2, Settings2, Users } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import SuggerimentoDellaI from '@/components/assemblee/convocazione/SuggerimentoDellaI.vue';
import type { Flash } from '@/types/flash';

interface Impostazioni { delega_oltre_il_quinto: 'rifiuta' | 'decide_il_presidente'; voto_discorde_comproprietari: 'astenuti' | 'decide_il_presidente'; giorni_di_posta: number }

const props = defineProps<{ impostazioni: Impostazioni }>();
const page = usePage<{ flash?: { message?: Flash }; errors?: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash?.message);
const errori = computed(() => Object.values(page.props.errors ?? {}).filter((m) => typeof m === 'string'));

const scelte = ref<Impostazioni>({ ...props.impostazioni });
const cambiate = computed(() => JSON.stringify(scelte.value) !== JSON.stringify(props.impostazioni));
const inCorso = ref(false);

const rifiuta = computed({
    get: () => scelte.value.delega_oltre_il_quinto === 'rifiuta',
    set: (acceso: boolean) => { scelte.value.delega_oltre_il_quinto = acceso ? 'rifiuta' : 'decide_il_presidente'; },
});
const astenuti = computed({
    get: () => scelte.value.voto_discorde_comproprietari === 'astenuti',
    set: (acceso: boolean) => { scelte.value.voto_discorde_comproprietari = acceso ? 'astenuti' : 'decide_il_presidente'; },
});
const valoreDi = (g: { valore: number }) => g.valore;
const GIORNI = Array.from({ length: 10 }, (_, i) => ({ valore: i + 1, etichetta: i === 0 ? '1 giorno lavorativo' : `${i + 1} giorni lavorativi` }));

const RIGHE = {
    posta: {
        titolo: 'Giorni di posta per una raccomandata',
        esempio: 'Per esempio: con tre giorni, una raccomandata spedita giovedì arriva il martedì dopo; il sabato, la domenica e le feste non contano.',
        spiegazione: 'Quanti giorni lavorativi conti per l\'arrivo, per proporre entro quando spedire le raccomandate della convocazione.',
    },
    quinto: {
        titolo: 'La delega oltre il quinto si rifiuta',
        esempio: 'Per esempio: 26 condòmini, Bianchi Giorgio ha già le deleghe di tre condòmini con 125,70 millesimi, e Rossi Mario (98,30) gli dà la sua: sarebbero 224,00 su 200,00.',
        spiegazione: 'Acceso: il programma la rifiuta e scrive l\'articolo. Spento: avvisa, e decide il presidente con il motivo.',
        acceso: 'Adesso: con più di venti condòmini, la delega che porta il delegato oltre un quinto dei condòmini o del valore si registra rifiutata, con l\'art. 67 c. 1 disp. att. Di serie.',
        spento: 'Adesso: la delega oltre il quinto la decide il presidente: in sala la accetta con il suo motivo, e una delega ricevuta prima la decide all\'apertura.',
    },
    discorde: {
        titolo: 'Il voto discorde dei comproprietari conta astenuto',
        esempio: 'Per esempio: Russo Anna vota a favore e Russo Marco contro, sull\'Int. 5 che hanno in comproprietà.',
        spiegazione: 'Acceso: l\'unità conta astenuta. Spento: decide il presidente quale voto vale, con il motivo.',
        acceso: 'Adesso: se i comproprietari di un\'unità presenti votano in modo diverso, l\'unità conta astenuta, con i suoi millesimi. Di serie.',
        spento: 'Adesso: se i comproprietari votano in modo diverso, il presidente sceglie come conta il voto dell\'unità, e il verbale lo scrive.',
    },
};
const adessoDellaPosta = computed(() => `Adesso: il programma conta ${GIORNI[scelte.value.giorni_di_posta - 1]?.etichetta ?? `${scelte.value.giorni_di_posta} giorni lavorativi`}`
    + ' di posta, dal lunedì al venerdì e senza le feste, per proporre entro quando spedire.' + (scelte.value.giorni_di_posta === 3 ? ' Di serie.' : ''));

function salva() {
    if (!cambiate.value || inCorso.value) return;
    inCorso.value = true;
    router.patch(route('impostazioni.assemblee.update'), { ...scelte.value }, { preserveScroll: true, onFinish: () => { inCorso.value = false; } });
}

const breadcrumbs = [{ title: 'Impostazioni', href: '/impostazioni' }, { title: 'Le assemblee', href: '/impostazioni/assemblee' }];
const pageGuides = [
    { title: 'Per tutto lo studio', description: 'Valgono per tutti i condomini. Le cambia solo l\'amministratore, e ogni cambio resta nel registro delle modifiche.', icon: Gavel,
        colorVariant: 'blue' as const },
    { title: 'Decide il presidente', description: 'Spenti, il programma avvisa e il presidente sceglie in sala, con il motivo nel verbale.', icon: Users, colorVariant: 'amber' as const },
    { title: 'Di serie', description: 'Senza toccare niente: tre giorni di posta, la delega oltre il quinto rifiutata, il voto discorde astenuto.', icon: Settings2,
        colorVariant: 'emerald' as const },
];
const riga = 'flex flex-col justify-between gap-4 rounded-lg border p-4 sm:flex-row sm:items-center dark:border-slate-800';
const adessoClasse = 'mt-2 rounded-md bg-slate-50 px-2.5 py-1.5 text-xs leading-snug text-slate-700 dark:bg-slate-800/50 dark:text-slate-300';
</script>

<template>
    <Head title="Le assemblee" />
    <!-- La testa come nelle altre pagine delle impostazioni (le stampe, il cron): il percorso nella testa della pagina, «Impostazioni» per tornare, l'icona e
         la «Storia» delle impostazioni delle assemblee; la barra in alto senza percorso. -->
    <AppLayout :breadcrumbs="[]">
        <div class="space-y-6 px-4 py-6">
            <PageHeaderGuide page-title="Le assemblee"
                page-subtitle="Valgono per tutti i condomini dello studio. Le cambia solo l'amministratore, e ogni cambio resta nel registro delle modifiche."
                :icon="Gavel" :guides="pageGuides" :breadcrumbs="breadcrumbs" back-url="/impostazioni" back-text="Impostazioni"
                :storia="{ tipo: 'impostazioni_assemblee' }" :video-url="null" />

            <div v-if="flashMessage"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>

            <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 text-sm shadow-sm lg:p-8 dark:border-slate-800/80 dark:bg-slate-950" data-testid="sezione-assemblee">
                    <div :class="riga" data-riga="posta">
                        <div class="flex-1">
                            <div class="mb-1 flex items-center gap-1.5">
                                <span class="text-sm font-medium text-slate-900 dark:text-white">{{ RIGHE.posta.titolo }}</span>
                                <SuggerimentoDellaI :testo="RIGHE.posta.esempio" testid="info-posta" />
                            </div>
                            <p class="text-sm text-muted-foreground">{{ RIGHE.posta.spiegazione }}</p>
                            <p :class="adessoClasse" data-testid="adesso">{{ adessoDellaPosta }}</p>
                        </div>
                        <v-select v-model="scelte.giorni_di_posta" class="w-full sm:w-52" :options="GIORNI" label="etichetta" :reduce="valoreDi"
                            :clearable="false" :searchable="false" data-testid="giorni-di-posta" />
                    </div>

                    <div :class="riga" data-riga="quinto">
                        <div class="flex-1">
                            <div class="mb-1 flex items-center gap-1.5">
                                <label for="interruttore-quinto" class="text-sm font-medium text-slate-900 dark:text-white">{{ RIGHE.quinto.titolo }}</label>
                                <SuggerimentoDellaI :testo="RIGHE.quinto.esempio" testid="info-quinto" />
                            </div>
                            <p class="text-sm text-muted-foreground">{{ RIGHE.quinto.spiegazione }}</p>
                            <p :class="adessoClasse" data-testid="adesso">{{ rifiuta ? RIGHE.quinto.acceso : RIGHE.quinto.spento }}</p>
                        </div>
                        <Switch id="interruttore-quinto" v-model="rifiuta" data-testid="interruttore-quinto" />
                    </div>

                    <div :class="riga" data-riga="discorde">
                        <div class="flex-1">
                            <div class="mb-1 flex items-center gap-1.5">
                                <label for="interruttore-discorde" class="text-sm font-medium text-slate-900 dark:text-white">{{ RIGHE.discorde.titolo }}</label>
                                <SuggerimentoDellaI :testo="RIGHE.discorde.esempio" testid="info-discorde" />
                            </div>
                            <p class="text-sm text-muted-foreground">{{ RIGHE.discorde.spiegazione }}</p>
                            <p :class="adessoClasse" data-testid="adesso">{{ astenuti ? RIGHE.discorde.acceso : RIGHE.discorde.spento }}</p>
                        </div>
                        <Switch id="interruttore-discorde" v-model="astenuti" data-testid="interruttore-discorde" />
                    </div>

                <ul v-if="errori.length" class="list-disc pl-4 text-xs text-rose-700 dark:text-rose-300" data-testid="errori"><li v-for="m in errori" :key="m">{{ m }}</li></ul>

                <div class="flex flex-col gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                    <ul class="list-disc pl-4 text-xs text-slate-500 dark:text-slate-400">
                        <li>Valgono da quando salvi. Una sala già aperta tiene le scelte della sua apertura, fino alla chiusura.</li>
                        <li>Il conto delle teste (una testa o due teste si sceglie in ogni condominio) è nelle impostazioni del condominio.</li>
                    </ul>
                    <Button size="sm" class="h-9 w-full sm:w-auto" :disabled="!cambiate || inCorso" data-testid="salva" @click="salva">
                        <Loader2 v-if="inCorso" class="mr-1 h-4 w-4 animate-spin" /> Salva
                    </Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
