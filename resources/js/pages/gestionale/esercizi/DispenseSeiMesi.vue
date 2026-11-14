<script lang="ts">
/** La pagina da cui si è arrivati alle dispense. Resta fra una visita e l'altra della pagina: registrare o revocare una dispensa ricarica la pagina, e
 * dopo la «pagina precedente» sarebbe la pagina stessa. */
let paginaDiPartenza: string | null = null;
</script>

<script setup lang="ts">
/**
 * La dispensa dell'assemblea dall'agire entro sei mesi (art. 1129 c. 9): l'elenco delle delibere registrate, la registrazione di una nuova
 * (data, verbale, esercizi coperti, tutte le persone o alcune, nota) e la revoca. Una delibera alla volta. Il programma la mostra accanto
 * alle date dei sei mesi dell'elenco delle rate scadute; le date non cambiano.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { TooltipProvider } from '@/components/ui/tooltip';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import SpiegazioneDelFiltro from '@/components/gestionale/debiti/SpiegazioneDelFiltro.vue';
import CampoFile from '@/components/CampoFile.vue';
import DispenseSeiMesiGuide from '@/components/guides/DispenseSeiMesiGuide.vue';
import { usePermission } from '@/composables/permissions';
import { useUrlPrecedente } from '@/composables/useUrlPrecedente';
import { CalendarClock, FileText, Scale } from 'lucide-vue-next';
import { giorno, personeDelMenu } from '@/lib/gestionale/debiti/rateScadute';
import type { Flash } from '@/types/flash';

interface Dispensa {
    id: number; data_delibera: string; numero_verbale: string | null; nota: string | null; tutte_le_persone: boolean;
    esercizi: Array<{ id: number; nome: string }>; persone: Array<{ id: number; nome: string }>; revocata_il: string | null; nota_revoca: string | null; verbale: string | null;
}
const props = defineProps<{
    condominio: { id: number; nome: string }; condomini: any; esercizio: any;
    esercizi: Array<{ id: number; nome: string; stato: string }>; persone: Array<{ id: number; nome: string }>;
    dispense: Dispensa[]; testo_norma: string; oggi: string;
}>();

const { generateRoute, generatePath } = usePermission();
const page = usePage<{ flash?: { message?: Flash }; errors?: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash?.message);
const errori = computed(() => page.props.errors ?? {});

const nuova = ref({ data_delibera: '', numero_verbale: '', nota: '', esercizi: [] as number[], per_tutti: '' as '' | '1' | '0', persone: [] as number[] });
const verbale = ref<File | null>(null);
// «Solo per alcune persone»: un menu con la ricerca, come la scelta della persona nelle rate scadute; con un condominio grande ne mostra 50,
// e le persone già scelte restano fra le opzioni perché il campo ne mostri il nome.
const testoPersona = ref('');
const opzioniPersone = computed(() => {
    const mostrate = personeDelMenu(props.persone, testoPersona.value).mostrate;
    const scelte = props.persone.filter((p) => nuova.value.persone.includes(p.id) && !mostrate.some((m) => m.id === p.id));

    return [...mostrate, ...scelte];
});
const alterna = (lista: number[], id: number) => (lista.includes(id) ? lista.filter((x) => x !== id) : [...lista, id]);
function registra() {
    router.post(route(generateRoute('gestionale.dispense.store'), { condominio: props.condominio.id }), {
        ...nuova.value, ...(verbale.value ? { verbale: verbale.value } : {}),
    }, { forceFormData: true, preserveScroll: true, onSuccess: () => { nuova.value = { data_delibera: '', numero_verbale: '', nota: '', esercizi: [], per_tutti: '', persone: [] }; verbale.value = null; } });
}

const revoca = ref<{ id: number | null; revocata_il: string; nota_revoca: string }>({ id: null, revocata_il: '', nota_revoca: '' });
function revoca_conferma() {
    if (!revoca.value.id) return;
    router.post(route(generateRoute('gestionale.dispense.revoca'), { condominio: props.condominio.id, dispensa: revoca.value.id }), {
        revocata_il: revoca.value.revocata_il, nota_revoca: revoca.value.nota_revoca,
    }, { preserveScroll: true, onSuccess: () => { revoca.value = { id: null, revocata_il: '', nota_revoca: '' }; } });
}
const indirizzoVerbale = (d: Dispensa) => route(generateRoute('gestionale.dispense.verbale'), { condominio: props.condominio.id, dispensa: d.id });
const indirizzoElenco = computed(() => route(generateRoute('gestionale.rate-scadute.index'), { condominio: props.condominio.id }));
// «Indietro» torna alla pagina da cui si è arrivati (le rate scadute, gli esercizi, …); aperta da un indirizzo incollato, alle rate scadute.
const { urlPrecedente } = useUrlPrecedente();
const precedente = urlPrecedente();
if (precedente && precedente.split('?')[0] !== window.location.pathname) {
    paginaDiPartenza = precedente;
}
const indirizzoIndietro = computed(() => paginaDiPartenza ?? indirizzoElenco.value);

// La guida che si apre di lato dal pulsante «Guida» dell'intestazione, come nelle altre pagine.
const mostraGuida = ref(false);

const SPIEGAZIONE_VERBALE = 'Il numero scritto sul verbale dell\'assemblea che ha deciso la dispensa, per esempio 3/2027. Si scrive com\'è sul verbale; '
    + 'si può lasciare vuoto.';
const SPIEGAZIONE_FILE = 'Il PDF o la foto del verbale, se vuoi conservarlo qui: si riapre dalla dispensa registrata. Si può lasciare vuoto.';
const pageGuides = [
    { title: 'A che cosa serve', description: 'La legge chiede all\'amministratore di agire per riscuotere le quote non pagate entro sei mesi dalla chiusura dell\'esercizio (art. 1129, comma 9, c.c.), salvo che l\'assemblea lo dispensi. Qui registri la delibera con cui l\'assemblea lo ha dispensato.', icon: Scale, colorVariant: 'blue' as const },
    { title: 'Che cosa cambia', description: 'Le date dei sei mesi non cambiano. Nell\'elenco delle rate scadute, accanto a ogni data, compare la dispensa che la copre: chi guarda sa che l\'assemblea ha deciso così. Non è l\'accordo di rientro con una persona.', icon: CalendarClock, colorVariant: 'amber' as const },
    { title: 'Che cosa ti serve', description: 'Il verbale dell\'assemblea: la data della delibera, il numero del verbale, gli esercizi e le persone per cui vale. Il PDF del verbale si può allegare. Se l\'assemblea cambia idea, registri la revoca dalla dispensa.', icon: FileText, colorVariant: 'emerald' as const },
];

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Esercizi', href: generatePath('gestionale/:condominio/esercizi', { condominio: props.condominio.id }) },
    { title: 'Dispense dei sei mesi' },
]);
</script>

<template>
    <Head title="Dispense dei sei mesi" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                page-title="Dispense dei sei mesi"
                page-subtitle="Le delibere con cui l'assemblea dispensa l'amministratore dall'agire entro sei mesi contro chi non paga."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :back-url="indirizzoIndietro"
                back-text="Indietro"
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
            />
            <div v-if="flashMessage"><Alert :message="flashMessage.message" :type="flashMessage.type" /></div>
            <p class="text-xs text-slate-500 dark:text-slate-400 italic">{{ testo_norma }}</p>
            <p class="text-xs text-slate-600 dark:text-slate-300">
                <Link :href="indirizzoElenco" class="underline">Vai alle rate scadute</Link>
            </p>

            <!-- Le dispense registrate -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 divide-y dark:divide-slate-800">
                <div v-for="d in dispense" :key="d.id" class="p-3 text-sm space-y-1" data-testid="dispensa">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-semibold">Assemblea del {{ giorno(d.data_delibera) }}<template v-if="d.numero_verbale"> — verbale n. {{ d.numero_verbale }}</template></span>
                        <span v-if="d.revocata_il" class="text-xs text-rose-700 dark:text-rose-300">revocata il {{ giorno(d.revocata_il) }}</span>
                        <Button v-else size="sm" variant="outline" class="h-7 text-xs" @click="revoca = { id: d.id, revocata_il: oggi, nota_revoca: '' }">Registra la revoca</Button>
                    </div>
                    <p class="text-xs">Esercizi: {{ d.esercizi.map((e) => e.nome).join(', ') }} · {{ d.tutte_le_persone ? 'tutte le persone' : `solo ${d.persone.map((p) => p.nome).join(', ')}` }}</p>
                    <p v-if="d.nota" class="text-xs text-slate-500">{{ d.nota }}</p>
                    <p v-if="d.nota_revoca" class="text-xs text-slate-500">Revoca: {{ d.nota_revoca }}</p>
                    <a v-if="d.verbale" :href="indirizzoVerbale(d)" class="text-xs underline">Verbale: {{ d.verbale }}</a>
                    <div v-if="revoca.id === d.id" class="flex flex-wrap items-end gap-2 pt-2">
                        <label class="text-xs space-y-1"><span class="block">Data della revoca</span><Input v-model="revoca.revocata_il" type="date" :max="oggi" class="h-8 w-[150px] text-xs" /></label>
                        <label class="text-xs space-y-1 flex-1 min-w-[200px]"><span class="block">Nota</span><Input v-model="revoca.nota_revoca" class="h-8 text-xs" /></label>
                        <Button size="sm" class="h-8" @click="revoca_conferma">Revoca</Button>
                        <Button size="sm" variant="ghost" class="h-8" @click="revoca = { id: null, revocata_il: '', nota_revoca: '' }">Annulla</Button>
                    </div>
                </div>
                <p v-if="!dispense.length" class="p-4 text-sm text-slate-500">Nessuna dispensa registrata.</p>
            </div>

            <!-- Una dispensa nuova -->
            <div class="rounded-xl border bg-white dark:bg-slate-900 p-4 space-y-3 text-sm">
                <h3 class="font-semibold">Registra una dispensa</h3>
                <TooltipProvider :delay-duration="200">
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="text-xs flex flex-col gap-1"><span class="block">Data della delibera</span><Input v-model="nuova.data_delibera" type="date" :max="oggi" class="h-8 w-[150px] text-xs" /></label>
                        <label class="text-xs flex flex-col gap-1">
                            <span class="flex items-center gap-1">Numero del verbale <SpiegazioneDelFiltro :testo="SPIEGAZIONE_VERBALE" /></span>
                            <Input v-model="nuova.numero_verbale" placeholder="per esempio 3/2027" class="h-8 w-[160px] text-xs" data-testid="numero-verbale" />
                        </label>
                        <div class="text-xs flex flex-col gap-1">
                            <span class="flex items-center gap-1">Il verbale (PDF o immagine, facoltativo) <SpiegazioneDelFiltro :testo="SPIEGAZIONE_FILE" /></span>
                            <CampoFile v-model="verbale" accept=".pdf,.jpg,.jpeg,.png" testid="file-verbale" class="max-w-[360px]" />
                        </div>
                    </div>
                </TooltipProvider>
                <div class="text-xs space-y-1">
                    <span class="block font-medium">Gli esercizi coperti</span>
                    <div class="flex flex-wrap gap-3">
                        <label v-for="e in esercizi" :key="e.id" class="flex items-center gap-1.5">
                            <Checkbox :model-value="nuova.esercizi.includes(e.id)" @update:model-value="nuova.esercizi = alterna(nuova.esercizi, e.id)" /> {{ e.nome }}
                        </label>
                    </div>
                    <span class="block text-slate-500">Gli esercizi creati dopo non sono coperti finché non registri una dispensa che li comprende.</span>
                </div>
                <div class="text-xs space-y-1">
                    <span class="block font-medium">Per chi vale (lo dice la delibera)</span>
                    <label class="flex items-center gap-1.5"><input v-model="nuova.per_tutti" type="radio" value="1" /> Per tutte le persone</label>
                    <label class="flex items-center gap-1.5"><input v-model="nuova.per_tutti" type="radio" value="0" /> Solo per alcune persone</label>
                    <div v-if="nuova.per_tutti === '0'" class="pt-1 max-w-xl dispense-persone" data-testid="scelta-persone">
                        <v-select
                            v-model="nuova.persone"
                            multiple
                            :options="opzioniPersone"
                            label="nome"
                            :reduce="(p: { id: number }) => p.id"
                            :filterable="false"
                            :close-on-select="false"
                            placeholder="Scrivi una parte del nome per cercare"
                            @search="(t: string) => (testoPersona = t)"
                        >
                            <template #no-options>Nessuna persona con questo nome.</template>
                        </v-select>
                    </div>
                </div>
                <label class="text-xs space-y-1 block"><span class="block">Nota</span><Textarea v-model="nuova.nota" rows="2" class="text-xs" /></label>
                <ul v-if="Object.keys(errori).length" class="text-xs text-rose-700 dark:text-rose-300 list-disc pl-4">
                    <li v-for="(m, k) in errori" :key="k">{{ m }}</li>
                </ul>
                <Button size="sm" :disabled="!nuova.data_delibera || !nuova.esercizi.length || !nuova.per_tutti" @click="registra">Registra la dispensa</Button>
            </div>
        </div>
        <DispenseSeiMesiGuide v-model:open="mostraGuida" :testo-norma="testo_norma" />
    </GestionaleLayout>
</template>

<style>
/* Il menu delle persone della dispensa con il testo piccolo del modulo; solo qui. */
.dispense-persone .v-select {
    --vs-font-size: 0.75rem;
    --vs-border-color: var(--input);
    --vs-controls-color: rgb(100 116 139);
}
.dispense-persone .vs__dropdown-toggle {
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}
.dispense-persone .vs__search,
.dispense-persone .vs__search::placeholder {
    font-size: 0.75rem !important;
}
.dark .dispense-persone .v-select {
    --vs-dropdown-bg: rgb(15 23 42);
    --vs-dropdown-option-color: rgb(226 232 240);
    --vs-selected-bg: rgb(30 41 59);
    --vs-selected-color: rgb(226 232 240);
    --vs-search-input-color: rgb(226 232 240);
    --vs-border-color: var(--input);
}
.dark .dispense-persone .vs__dropdown-toggle {
    background: color-mix(in oklab, var(--input) 30%, transparent);
}
/* `custom.css` evidenzia l'opzione con un grigio chiarissimo e il testo nero: nel tema scuro diventava una barra bianca. */
.dark .dispense-persone .vs__dropdown-option--highlight {
    background: rgb(30 41 59) !important;
    color: rgb(241 245 249) !important;
}
</style>
