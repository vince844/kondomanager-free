<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La scheda «Impostazioni» del condominio: le scelte che valgono solo per questo condominio, una sezione per argomento. Oggi «Il recupero dei crediti», con
 * due righe con l'interruttore, nella forma delle impostazioni generali (a sinistra il titolo, una frase che si accende, con la «i» e la spiegazione di acceso e
 * spento; a destra l'interruttore): «Una rata nuova non fa tornare indietro» e «Il programma suggerisce la prossima azione». Sotto ogni riga «Adesso: …» dice
 * con un esempio che cosa succede con l'interruttore com'è, e cambia appena lo si muove, prima di salvare; «di serie» compare solo lì. «Salva» manda le due
 * scelte insieme, e si accende quando qualcosa cambia. Chi non modifica il condominio vede gli interruttori, fermi. Il pulsante «Guida» dell'intestazione apre
 * la guida della pagina nel cassetto laterale.
 *
 * La sezione «Le assemblee»: «Una testa sola per chi ha anche una comproprietà», spento di serie (due teste: le comunioni nate da titoli diversi restano
 * distinte anche nell'assemblea; acceso è la scelta del condominio con una regola sua), con la riga
 * «Adesso: …» che il server scrive con un esempio vero del condominio per le due posizioni; ferma mentre un'assemblea è in corso. Ha il suo «Salva», con la
 * sua rotta.
 */
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import Alert from '@/components/Alert.vue';
import ImpostazioniCondominioGuide from '@/components/guides/ImpostazioniCondominioGuide.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { usePermission } from '@/composables/permissions';
import { useGiroFlash } from '@/composables/useGiroFlash';
import { Building2, Info, Lightbulb, Loader2, Settings2 } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Flash } from '@/types/flash';

interface Impostazioni { recupero_salita: 'avanti' | 'ricomincia'; recupero_suggerisce_azione: boolean }
/** Le teste nel caso misto: la scelta, il perché è ferma, la riga «Adesso» per le due posizioni. */
interface Assemblee { teste: 'una_testa' | 'due_teste'; ferma: string | null; adesso: { una_testa: string; due_teste: string } }

const props = defineProps<{
    condominio: Building; condomini: Building[]; esercizio: any;
    impostazioni: Impostazioni;
    /** La riga delle impostazioni, per la «Storia» (1.12.0-beta.13): vuota finché non sono state salvate la prima volta. */
    impostazioni_id?: number | null;
    /** Chi modifica il condominio salva; gli altri vedono. */
    puo_modificare: boolean;
    /** La sezione «Le assemblee». */
    assemblee?: Assemblee;
}>();

const { generateRoute, generatePath } = usePermission();
const page = usePage<{ flash?: { message?: Flash }; errors?: Record<string, string> }>();
const flashMessage = computed(() => page.props.flash?.message);
const giroFlash = useGiroFlash();
const errori = computed(() => Object.values(page.props.errors ?? {}).filter((m) => typeof m === 'string'));

const scelte = ref<Impostazioni>({ ...props.impostazioni });
const cambiate = computed(() => scelte.value.recupero_salita !== props.impostazioni.recupero_salita
    || scelte.value.recupero_suggerisce_azione !== props.impostazioni.recupero_suggerisce_azione);
const inCorso = ref(false);

/** Gli interruttori della sezione, accesi di serie: la salita va avanti, e il programma suggerisce la prossima azione. */
const salitaAvanti = computed({
    get: () => scelte.value.recupero_salita === 'avanti',
    set: (acceso: boolean) => { if (props.puo_modificare) scelte.value.recupero_salita = acceso ? 'avanti' : 'ricomincia'; },
});
const suggerisce = computed({
    get: () => scelte.value.recupero_suggerisce_azione,
    set: (acceso: boolean) => { if (props.puo_modificare) scelte.value.recupero_suggerisce_azione = acceso; },
});

/** Le due righe: il titolo che si accende, l'esempio della «i», la spiegazione di acceso e spento, e la riga «Adesso» per ognuna delle due posizioni. */
const RIGHE = [
    {
        chiave: 'salita' as const,
        titolo: 'Una rata nuova non fa tornare indietro',
        esempio: 'Per esempio: a ottobre hai mandato un sollecito per le rate fino a settembre, il termine è passato, e a novembre scade una rata nuova.',
        spiegazione: 'Acceso: dopo una lettera le rate che scadono dopo non fanno tornare indietro la prossima azione; dopo il sollecito la diffida, dopo la diffida il fascicolo per il legale. Spento: per le rate scadute dopo la lettera il programma suggerisce di nuovo la stessa lettera.',
        acceso: 'Adesso: con un sollecito di ottobre, il termine passato e una rata nuova a novembre, il programma suggerisce «Manda la diffida», che chiede anche la rata nuova.',
        spento: 'Adesso: con un sollecito di ottobre, il termine passato e una rata nuova a novembre, il programma suggerisce «Manda un sollecito» per la rata nuova.',
    },
    {
        chiave: 'azione' as const,
        titolo: 'Il programma suggerisce la prossima azione',
        esempio: 'Per esempio: nel riquadro «Sei mesi per agire» della dashboard del condominio, accanto a ogni persona con rate scadute.',
        spiegazione: 'Acceso: ogni persona ha un pulsante con l\'azione suggerita, e le altre nel menu accanto. Spento: ogni persona ha «Scegli l\'azione», con tutte le azioni e nessuna evidenziata.',
        acceso: 'Adesso: per Rossi, con il sollecito spedito e il termine passato, c\'è il pulsante «Manda la diffida», con la freccia per le altre azioni.',
        spento: 'Adesso: per Rossi c\'è «Scegli l\'azione», con tutte le azioni possibili e nessuna evidenziata.',
    },
];

const acceso = (chiave: 'salita' | 'azione') => (chiave === 'salita' ? salitaAvanti.value : suggerisce.value);
/** «Adesso: …» con l'interruttore com'è; acceso è la scelta di serie, e lo dice. */
const adesso = (r: (typeof RIGHE)[number]) => (acceso(r.chiave) ? `${r.acceso} Di serie.` : r.spento);

function salva() {
    if (!cambiate.value || inCorso.value) return;
    inCorso.value = true;
    router.post(route(generateRoute('gestionale.impostazioni.update'), { condominio: props.condominio.id }), { ...scelte.value }, {
        preserveScroll: true,
        onFinish: () => { inCorso.value = false; },
    });
}

// Le assemblee: una testa o due teste nel caso misto, la scelta del condominio.
const unaTesta = ref(props.assemblee?.teste === 'una_testa');
const testeCambiate = computed(() => props.assemblee !== undefined && unaTesta.value !== (props.assemblee.teste === 'una_testa'));
const testeFerme = computed(() => !props.puo_modificare || !!props.assemblee?.ferma);
const testeInCorso = ref(false);
const ESEMPIO_TESTE = 'Per esempio: Moretti Franco ha l\'Int. 11 da solo e l\'Int. 10 a metà con Moretti Elisa. Due teste, di serie: Franco per l\'Int. 11, e l\'Int. 10 a parte; una testa: contano una volta, con le due unità.';
const adessoDelleTeste = computed(() => (!props.assemblee ? '' : unaTesta.value ? props.assemblee.adesso.una_testa : `${props.assemblee.adesso.due_teste} Di serie.`));
function salvaLeTeste() {
    if (!testeCambiate.value || testeInCorso.value || testeFerme.value) return;
    testeInCorso.value = true;
    router.patch(route(generateRoute('gestionale.assemblee.teste'), { condominio: props.condominio.id }), { assemblee_teste: unaTesta.value ? 'una_testa' : 'due_teste' }, {
        preserveScroll: true,
        onFinish: () => { testeInCorso.value = false; },
    });
}

// La guida che si apre di lato dal pulsante «Guida» dell'intestazione, come nelle altre pagine.
const mostraGuida = ref(false);

const pageGuides = [
    { title: 'Valgono solo qui', description: 'Ogni condominio ha le sue impostazioni: cambiarle qui non tocca gli altri condomini né le impostazioni generali dello studio.', icon: Building2, colorVariant: 'blue' as const },
    { title: 'Suggerimenti, non azioni', description: 'Le scelte cambiano che cosa il programma ti suggerisce nel riquadro «Sei mesi per agire». Nessuna lettera parte da sola: la scelta resta tua.', icon: Lightbulb, colorVariant: 'amber' as const },
    { title: 'Di serie', description: 'Senza toccare niente i due interruttori sono accesi: «Una rata nuova non fa tornare indietro» e «Il programma suggerisce la prossima azione». Li cambi quando vuoi, con «Salva».', icon: Settings2, colorVariant: 'emerald' as const },
];

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Struttura', href: generatePath('gestionale/:condominio/struttura', { condominio: props.condominio.id }) },
    { title: 'Impostazioni' },
]);

</script>

<template>
    <Head title="Impostazioni del condominio" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-4">
            <PageHeaderGuide
                :storia="props.impostazioni_id ? { tipo: 'impostazioni_condominio', id: props.impostazioni_id } : null"
                page-title="Impostazioni del condominio"
                page-subtitle="Le scelte che valgono solo per questo condominio. Decidono che cosa il programma ti suggerisce: da solo non manda niente."
                :guides="pageGuides"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :condominio="props.condominio"
                :condomini="props.condomini"
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
            />

            <div class="w-full">
                <StrutturaLayout>
                    <div class="container mx-auto p-0 mt-4 space-y-6">
                        <div v-if="flashMessage"><Alert :key="giroFlash" :message="flashMessage.message" :type="flashMessage.type" /></div>

                        <!-- Il recupero dei crediti -->
                        <section class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl shadow-sm p-6 lg:p-8 text-sm" data-testid="sezione-recupero">
                            <h3 class="text-base font-bold text-slate-900 dark:text-white">Il recupero dei crediti</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-3xl" data-testid="descrizione-sezione">
                                Come il programma legge le lettere già mandate e che cosa ti suggerisce per le persone con rate scadute, nel riquadro «Sei mesi per agire»
                                della dashboard del condominio. In tutti e due i modi una rata ancora nella tolleranza del condominio non cambia la prossima azione.
                            </p>

                            <div class="mt-6 space-y-4">
                                <div v-for="r in RIGHE" :key="r.chiave">
                                    <!-- Come le righe delle impostazioni generali: a sinistra il titolo e la spiegazione, a destra l'interruttore. -->
                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4 dark:border-slate-800" data-testid="riga-interruttore">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-1.5 mb-1">
                                                <label :for="`interruttore-${r.chiave}`" class="block text-sm font-medium leading-none text-slate-900 dark:text-white" data-testid="titolo-scelta">{{ r.titolo }}</label>
                                                <TooltipProvider :delay-duration="200">
                                                    <Tooltip>
                                                        <TooltipTrigger as-child>
                                                            <span tabindex="0" role="img" :aria-label="r.esempio" :data-testid="`info-${r.chiave}`"
                                                                class="inline-flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                                                <Info class="w-3.5 h-3.5" />
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ r.esempio }}</p></TooltipContent>
                                                    </Tooltip>
                                                </TooltipProvider>
                                            </div>
                                            <p class="text-sm text-muted-foreground" data-testid="spiegazione">{{ r.spiegazione }}</p>
                                            <!-- Che cosa succede con l'interruttore com'è: cambia appena lo si muove, prima di salvare. -->
                                            <p class="mt-2 rounded-md bg-slate-50 px-2.5 py-1.5 text-xs leading-snug text-slate-700 dark:bg-slate-800/50 dark:text-slate-300"
                                                data-testid="adesso">{{ adesso(r) }}</p>
                                        </div>
                                        <Switch v-if="r.chiave === 'salita'" :id="`interruttore-${r.chiave}`" v-model="salitaAvanti" :disabled="!puo_modificare" data-testid="interruttore-salita" />
                                        <Switch v-else :id="`interruttore-${r.chiave}`" v-model="suggerisce" :disabled="!puo_modificare" data-testid="interruttore-azione" />
                                    </div>
                                </div>
                            </div>

                            <ul v-if="errori.length" class="mt-4 text-xs text-rose-700 dark:text-rose-300 list-disc pl-4" data-testid="errori">
                                <li v-for="m in errori" :key="m">{{ m }}</li>
                            </ul>

                            <div class="mt-6 flex flex-col gap-2 border-t border-slate-100 pt-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    <template v-if="puo_modificare">Valgono solo per questo condominio, da quando salvi.</template>
                                    <template v-else>Le cambia chi può modificare il condominio.</template>
                                </p>
                                <Button v-if="puo_modificare" size="sm" class="h-9 w-full sm:w-auto" :disabled="!cambiate || inCorso" data-testid="salva" @click="salva">
                                    <Loader2 v-if="inCorso" class="mr-1 h-4 w-4 animate-spin" /> Salva
                                </Button>
                            </div>
                        </section>

                        <!-- Le assemblee: le teste nel caso misto -->
                        <section v-if="assemblee" class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800/80 rounded-xl shadow-sm p-6 lg:p-8 text-sm" data-testid="sezione-assemblee">
                            <h3 class="text-base font-bold text-slate-900 dark:text-white">Le assemblee</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 max-w-3xl">Come la sala conta le teste di questo condominio. Vale solo qui.</p>
                            <div class="mt-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4 dark:border-slate-800" data-testid="riga-teste">
                                <div class="flex-1">
                                    <div class="flex items-center gap-1.5 mb-1">
                                        <label for="interruttore-teste" class="block text-sm font-medium leading-none text-slate-900 dark:text-white" data-testid="titolo-teste">Una testa sola per chi ha anche una comproprietà</label>
                                        <TooltipProvider :delay-duration="200">
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <span tabindex="0" role="img" :aria-label="ESEMPIO_TESTE" data-testid="info-teste"
                                                        class="inline-flex items-center text-slate-400 hover:text-slate-600 dark:text-slate-500 dark:hover:text-slate-300 cursor-help rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                                                        <Info class="w-3.5 h-3.5" />
                                                    </span>
                                                </TooltipTrigger>
                                                <TooltipContent class="max-w-80 text-xs leading-snug"><p>{{ ESEMPIO_TESTE }}</p></TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </div>
                                    <p class="text-sm text-muted-foreground">Spento, di serie: due teste, la persona per le sue unità e l'unità in comproprietà a parte. Acceso: una testa.</p>
                                    <p class="mt-2 rounded-md bg-slate-50 px-2.5 py-1.5 text-xs leading-snug text-slate-700 dark:bg-slate-800/50 dark:text-slate-300" data-testid="adesso-teste">{{ adessoDelleTeste }}</p>
                                    <p v-if="assemblee.ferma" class="mt-2 text-xs text-amber-800 dark:text-amber-300" data-testid="ferma-teste">{{ assemblee.ferma }} La sala conta con la regola fermata all'apertura.</p>
                                </div>
                                <Switch id="interruttore-teste" v-model="unaTesta" :disabled="testeFerme" data-testid="interruttore-teste" />
                            </div>
                            <ul class="mt-4 list-disc space-y-0.5 pl-4 text-xs text-slate-600 dark:text-slate-400" data-testid="da-sapere">
                                <li>Le comunioni nate da titoli diversi restano distinte anche nell'assemblea: chi ha un'unità da solo e un'altra in comproprietà conta due volte, una per titolo.</li>
                                <li>Una persona, una testa (Cass. 25558/2020); un'unità in comproprietà, un rappresentante (art. 67 c. 2 disp. att.).</li>
                                <li>Di serie due teste. Se il regolamento del condominio ha una regola sua, una testa sola, accendi l'interruttore.</li>
                                <li>Il cambio resta nel registro delle modifiche.</li>
                            </ul>
                            <div class="mt-6 flex flex-col gap-2 border-t border-slate-100 pt-4 dark:border-slate-800 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs text-slate-500 dark:text-slate-400">Vale solo per questo condominio, da quando salvi.</p>
                                <Button v-if="puo_modificare" size="sm" class="h-9 w-full sm:w-auto" :disabled="!testeCambiate || testeInCorso || testeFerme" data-testid="salva-teste" @click="salvaLeTeste">
                                    <Loader2 v-if="testeInCorso" class="mr-1 h-4 w-4 animate-spin" /> Salva
                                </Button>
                            </div>
                        </section>
                    </div>
                </StrutturaLayout>
            </div>
        </div>
        <ImpostazioniCondominioGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
