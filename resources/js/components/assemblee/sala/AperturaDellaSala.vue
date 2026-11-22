<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Prima di aprire (scena 1 della bozza approvata della sala; scene 3 e 4 della bozza delle scene della tappa 3):
 *
 * - **il controllo dell'art. 1136 c. 6**: chi non risulta convocato, convocato fuori tempo o con l'avviso rifiutato, con le due scelte del presidente,
 *   «Rinvia l'assemblea» o «Apri, e il verbale lo scrive» con il motivo; le cose da guardare (la PEC non comunicata, il consenso alla videoconferenza);
 * - **presidente e segretario** (il segretario anche «L'amministratore»);
 * - **«Segna deserta la prima convocazione»**, dall'ora della prima;
 * - l'avviso del browser che non tiene il lavoro (sembra una finestra privata) e quello dell'indirizzo senza https, sopra «Apri l'assemblea alle …»;
 * - le deleghe oltre il quinto «da decidere», che il presidente decide qui (non contano finché non decide).
 *
 * Senza una seduta da aprire dice perché (chiusa, deserta, non ancora convocata); prima del giorno dice quando si apre. Senza un controllo letto oggi (la
 * sala riaperta senza rete, o da aprire dopo «Segna deserta» segnato qui) dice che lo rifà il server: l'apertura dice che cosa la pagina ha visto del controllo
 * (`controllo_visto`), e se intanto il controllo chiede la scelta il server apre lo stesso e il presidente decide dopo.
 */
import { computed, onMounted, ref } from 'vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { AlertTriangle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useSala } from './contesto';
import { giornoDellaSeduta, istanteDiRoma } from './sala';
import SegnaDeserta from './SegnaDeserta.vue';
import DelegheDaDecidere from './DelegheDaDecidere.vue';
import AvvisoDelBrowser from './rete/AvvisoDelBrowser.vue';

/** `deserta`: la pagina aperta dal passo «In sala» con «Segna deserta» apre subito la sua finestra. */
const props = withDefaults(defineProps<{ deserta?: boolean }>(), { deserta: false });
const emit = defineEmits<{ (e: 'rinvia'): void }>();
const { sala, segna, puoGestire, oraDelGesto, adesso, dalFoglio, tenuta } = useSala();
/** La finestra del browser che non tiene il lavoro (sembra una finestra privata): si apre prima di aprire, e non ferma. */
const avvisoAperto = ref(true);

const AMMINISTRATORE = 'L\'amministratore';
const daAprire = computed(() => sala.value.da_aprire);
const seduta = computed(() => sala.value.sedute.find((s) => s.id === daAprire.value?.seduta_id) ?? null);
const seconda = computed(() => sala.value.sedute.find((s) => s.convocazione === 'seconda' && s.numero === 1) ?? null);
const prosecuzione = computed(() => (daAprire.value?.numero ?? 1) > 1);
const controllo = computed(() => sala.value.controllo);

/** Le persone della fotografia, per nome: presidente e segretario si scelgono fra loro. */
const persone = computed(() => Object.entries(sala.value.fotografia?.persone ?? {}).map(([id, p]) => ({ id: Number(id), etichetta: p.nome }))
    .sort((a, b) => a.etichetta.localeCompare(b.etichetta, 'it')));
const presidente = ref<number | null>(null);
const segretario = ref<number | string | null>(AMMINISTRATORE);
/** L'id di una voce dei menu (per `v-select`). */
const idDi = (o: { id: number | string }) => o.id;
const opzioniDelSegretario = computed(() => [{ id: AMMINISTRATORE, etichetta: AMMINISTRATORE }, ...persone.value]);

const scelta = ref<'apri' | null>(null);
const motivo = ref('');
const serveLaScelta = computed(() => controllo.value?.serve_la_scelta === true);
const pronta = computed(() => presidente.value !== null && segretario.value !== null && (!serveLaScelta.value || (scelta.value === 'apri' && motivo.value.trim() !== '')));

/** «Segna deserta» si accende dall'ora della convocazione. */
const daQuandoDeserta = computed(() => (seduta.value ? new Date(istanteDiRoma(seduta.value.giorno, seduta.value.ora)) : null));
const desertaAccesa = computed(() => dalFoglio.value || (daQuandoDeserta.value !== null && adesso() >= daQuandoDeserta.value));
const finestraDeserta = ref(false);
onMounted(() => { if (props.deserta && desertaAccesa.value && puoGestire.value) finestraDeserta.value = true; });

const motivoChiuso = computed(() => {
    switch (sala.value.assemblea.stato) {
        case 'chiusa': return 'L\'assemblea è chiusa: la sala resta in sola lettura.';
        case 'deserta': return 'L\'assemblea è deserta in prima e in seconda convocazione.';
        case 'annullata': return 'L\'assemblea è annullata: la sala non si riapre.';
        case 'in_preparazione': return 'L\'assemblea non è convocata: la sala si apre dalla prima spedizione.';
        default: return 'Nessuna seduta da aprire adesso.';
    }
});

/**
 * Prima dell'ora della convocazione (la prima, o la seconda se la prima è andata deserta; non la prosecuzione, non dal foglio): il pulsante dice anche l'ora
 * della convocazione e al clic chiede conferma, perché chi arriva all'ora giusta potrebbe impugnare. Non è spento (decisione 644).
 */
const minutiAllOra = computed(() => {
    if (!daQuandoDeserta.value || prosecuzione.value || dalFoglio.value) return 0;
    const m = Math.ceil((daQuandoDeserta.value.getTime() - adesso().getTime()) / 60000);

    return m > 0 ? m : 0;
});
const presto = computed(() => minutiAllOra.value > 0);
const frasePresto = computed(() => {
    const m = minutiAllOra.value;
    const quanto = m < 120 ? (m === 1 ? '1 minuto' : `${m} minuti`) : `${Math.floor(m / 60)} ore${m % 60 ? ` e ${m % 60} minuti` : ''}`;

    return `Mancano ${quanto} all'ora della convocazione (${seduta.value?.ora}): chi arriva all'ora giusta potrebbe impugnare. Apri lo stesso?`;
});
const confermaPresto = ref(false);
function clicApri() {
    if (!pronta.value) return;
    if (presto.value) confermaPresto.value = true;
    else apri();
}

async function apri() {
    confermaPresto.value = false;
    if (!seduta.value || !pronta.value) return;
    const persona = (v: number | string | null) => (typeof v === 'number' ? { anagrafica_id: v } : { nome: String(v ?? AMMINISTRATORE) });
    await segna('apri', {
        seduta_id: seduta.value.id,
        presidente: { anagrafica_id: presidente.value! },
        segretario: persona(segretario.value),
        ...(serveLaScelta.value ? { scelta: 'apri' as const, motivo: motivo.value.trim() } : {}),
        ...(dalFoglio.value ? { dal_foglio: true } : {}),
        // Che cosa la pagina ha visto del controllo: le persone con un problema, o null senza un controllo letto oggi.
        controllo_visto: controllo.value ? controllo.value.problemi.map((p) => p.anagrafica_id) : null,
    });
}
</script>

<template>
    <section class="space-y-4 rounded-xl border border-slate-200 bg-white px-4 py-4 dark:border-slate-800 dark:bg-slate-950" data-testid="apertura">
        <p v-if="!daAprire" class="text-xs text-slate-600 dark:text-slate-400" data-testid="motivo-sala-chiusa">{{ motivoChiuso }}</p>
        <p v-else-if="!daAprire.si_apre" class="text-sm text-slate-700 dark:text-slate-300">{{ daAprire.perche }}</p>

        <template v-else>
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                        {{ prosecuzione ? 'La prosecuzione' : daAprire.convocazione === 'prima' ? 'La prima convocazione' : 'La seconda convocazione' }}
                    </h2>
                    <p v-if="seduta" class="text-xs text-slate-500 dark:text-slate-400">{{ giornoDellaSeduta(seduta.giorno) }} alle {{ seduta.ora }}</p>
                </div>
                <Button v-if="puoGestire && !prosecuzione" variant="outline" size="sm" :disabled="!desertaAccesa" data-testid="segna-deserta"
                    :title="desertaAccesa ? undefined : `Si accende alle ${seduta?.ora}, l'ora della convocazione.`" @click="finestraDeserta = true">
                    {{ daAprire.convocazione === 'prima' ? 'Segna deserta la prima convocazione' : 'Segna deserta la seconda convocazione' }}
                </Button>
            </div>
            <p v-if="prosecuzione" class="text-xs text-slate-600 dark:text-slate-400">Si riprende dal primo punto senza esito; le presenze si segnano di nuovo.</p>
            <p v-if="!controllo && sala.controllo_da_rifare" class="text-xs text-slate-600 dark:text-slate-400" data-testid="controllo-da-rifare">
                Il controllo dei convocati (art. 1136 c. 6 c.c.) lo rifà il server quando torna la rete: se chiede la scelta del presidente, la sala lo dice dopo
                l'apertura.
            </p>
            <DelegheDaDecidere />

            <!-- Il controllo dell'art. 1136 c. 6: decide il presidente. -->
            <div v-if="controllo && (controllo.problemi.length || controllo.da_guardare.length)" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5
                text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100" data-testid="controllo-apertura">
                <p v-if="controllo.problemi.length" class="flex items-start gap-2 font-semibold">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    Prima di aprire: {{ controllo.problemi.length === 1 ? 'una persona non risulta convocata' : `${controllo.problemi.length} persone non risultano convocate` }}.
                </p>
                <ul v-if="controllo.problemi.length" class="list-disc space-y-0.5 pl-5 text-xs">
                    <li v-for="p in controllo.problemi" :key="p.anagrafica_id">{{ p.frase }}</li>
                </ul>
                <p v-if="controllo.problemi.length" class="text-xs">L'assemblea non può deliberare se non consta che tutti gli aventi diritto sono stati convocati (art. 1136 c. 6 c.c.). Decide il presidente.</p>
                <ul v-if="controllo.da_guardare.length" class="list-disc space-y-0.5 pl-5 text-xs" data-testid="da-guardare">
                    <li v-for="(g, i) in controllo.da_guardare" :key="i">{{ g.frase }}</li>
                </ul>
                <div v-if="serveLaScelta && puoGestire" class="flex flex-wrap gap-2 pt-1">
                    <Button variant="outline" size="sm" data-testid="scelta-rinvia" @click="emit('rinvia')">Rinvia l'assemblea</Button>
                    <Button :variant="scelta === 'apri' ? 'default' : 'outline'" size="sm" data-testid="scelta-apri-lo-stesso" @click="scelta = 'apri'">Apri, e il verbale lo scrive</Button>
                </div>
                <div v-if="scelta === 'apri'" class="space-y-1.5">
                    <Label for="motivo-apertura" class="text-xs">Il motivo del presidente</Label>
                    <Textarea id="motivo-apertura" v-model="motivo" rows="2" class="min-h-[60px] resize-none bg-white text-sm dark:bg-slate-950"
                        placeholder="Per esempio: Bianchi Anna è presente e non si oppone." data-testid="motivo-apertura" />
                    <p class="text-xs">Va nel verbale, che leggono tutti: niente dati delicati.</p>
                </div>
            </div>

            <div v-if="puoGestire" class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <Label class="text-xs">Presidente</Label>
                    <v-select v-model="presidente" :options="persone" label="etichetta" :reduce="idDi" :clearable="false"
                        placeholder="Chi presiede" data-testid="presidente" />
                </div>
                <div class="space-y-1.5">
                    <Label class="text-xs">Segretario</Label>
                    <v-select v-model="segretario" :options="opzioniDelSegretario" label="etichetta" :reduce="idDi" :clearable="false"
                        data-testid="segretario" />
                </div>
            </div>

            <!-- L'avviso della finestra privata (il browser che non tiene il lavoro) e dell'indirizzo senza https stanno qui, sopra «Apri l'assemblea». -->
            <div v-if="puoGestire" data-innesto="finestra-privata">
                <AvvisoDelBrowser v-model:open="avvisoAperto" :tenuta="tenuta" :foglio="sala.rotte.foglio ?? null" />
            </div>

            <div v-if="puoGestire" class="flex flex-wrap items-center justify-end gap-3">
                <p v-if="!pronta" class="text-xs text-slate-500 dark:text-slate-400">
                    {{ presidente === null ? 'Scegli il presidente.' : serveLaScelta ? 'Il presidente sceglie: rinvia, o apre con il motivo.' : '' }}
                </p>
                <Button :disabled="!pronta" data-testid="apri-assemblea" @click="clicApri">
                    {{ prosecuzione ? 'Riapri la sala' : 'Apri l\'assemblea' }} alle {{ oraDelGesto() }}<template v-if="presto"> · la convocazione dice alle {{ seduta?.ora }}</template>
                </Button>
            </div>
            <div v-if="puoGestire && confermaPresto" class="space-y-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                data-testid="conferma-apertura-presto">
                <p class="flex items-start gap-2"><AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /> {{ frasePresto }}</p>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="outline" size="sm" data-testid="aspetta" @click="confermaPresto = false">Aspetta</Button>
                    <Button size="sm" data-testid="apri-lo-stesso" @click="apri">Apri lo stesso</Button>
                </div>
            </div>
        </template>

        <SegnaDeserta v-model:open="finestraDeserta" :seduta="seduta" :seconda="seconda" />
    </section>
</template>
