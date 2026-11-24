<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script lang="ts">
/**
 * La issue 58 (Coda 174, decisione 674), nella finestra «Riparto saldi iniziali» del nuovo piano rate: il pregresso intestato all'unità
 * va a chi ne era titolare il primo giorno del suo esercizio, e dove un passaggio lo tocca l'amministratore può scegliere diversamente.
 * La forma è quella della bozza approvata (`docs/design/beta14_pregresso_passaggio_bozza.html`): il gruppo «Il passaggio» (il fatto in
 * una riga, e il giorno a cui il saldo si riferisce con chi era titolare), il gruppo «A chi va» con le scelte affiancate (la regola già
 * scelta e detta come tale, con la cifra), la frase della solidarietà sotto e il perché in un cassetto.
 *
 * Le scelte e le cifre le calcola il server (`GenerateSaldiAction::anteprimaSolidale()`), con le stesse funzioni della generazione: qui
 * non si ricalcola niente. La scelta diversa dalla regola si conserva nel piano come il giorno dei titolari (`alla_data`), «Divido a
 * mano» è il riparto a mano di sempre.
 */

export interface QuotaDellaScelta { anagrafica_id: number; nome: string; quota: number; importo: number }
export interface SceltaDelPregresso { alla_data: string; regola: boolean; etichetta: string; ruolo: string; ruolo_label: string; quote: QuotaDellaScelta[] }
export interface PassaggioDelPregresso { data: string; tipo: string; etichetta: string; riserva: boolean; uscenti: string[]; entranti: string[] }
export interface RipartoPrevisto {
    risolvibile: boolean;
    ruolo: string | null;
    ruolo_label: string | null;
    motivo: string | null;
    quote: QuotaDellaScelta[];
    riferito_al?: string | null;
    titolari_al?: string | null;
    esercizio?: string | null;
    /** La natura della gestione: la frase dell'art. 1005 c.c. vale per la straordinaria, quella dell'art. 1004 per l'ordinaria. */
    natura?: 'ordinaria' | 'straordinaria';
    /**
     * La successione nella regola: con l'arretrato agli eredi (decisione 685 (1)) la regola li mette al posto del defunto; con l'arretrato a nome
     * del defunto e la successione prima del primo giorno (decisione 689 (2)) mette il defunto al posto degli eredi. Chi era titolare quel
     * giorno, chi è morto, il giorno della successione.
     */
    successione?: { defunti: string[]; data: string; titolari: string[]; arretrato: 'eredi' | 'defunto' } | null;
    passaggi?: PassaggioDelPregresso[];
    scelte?: SceltaDelPregresso[];
}
export type ModoDelPregresso = 'automatica' | 'manuale';

const MESI = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

/** «2026-04-01» → «1 aprile 2026», senza il fuso del browser (la data è un giorno di calendario). */
export function giornoInParole(iso: string): string {
    const [a, m, g] = iso.slice(0, 10).split('-').map(Number);

    return `${g} ${MESI[m - 1]} ${a}`;
}

/** «del 15 dicembre 2025», «dell'1 aprile 2026», «dell'8 marzo», «dell'11 maggio»: con l'apostrofo davanti a un numero che comincia per vocale. */
export function delGiorno(iso: string): string {
    const g = Number(iso.slice(8, 10));

    return `${[1, 8, 11].includes(g) ? 'dell\'' : 'del '}${giornoInParole(iso)}`;
}

/**
 * I nomi in fila: «Anna», «Anna e Bruno», «Anna, Bruno e Carla»; «ed» davanti a un nome che comincia per «e» («Elsa Bianchi ed Erede Bruno»,
 * rilievo 7 del secondo scettico), come le frasi del server (`GenerateSaldiAction::elenco`).
 */
export function elencoDeiNomi(nomi: string[]): string {
    if (nomi.length <= 1) {
        return nomi[0] ?? '';
    }
    const ultimo = nomi[nomi.length - 1];

    return `${nomi.slice(0, -1).join(', ')} ${/^e/i.test(ultimo) ? 'ed' : 'e'} ${ultimo}`;
}

/** Il saldo tocca un passaggio: c'è almeno un passaggio nell'arco della solidarietà, e il server offre le scelte. */
export function toccaUnPassaggio(riparto: RipartoPrevisto | null | undefined): boolean {
    return !!riparto?.risolvibile && (riparto.passaggi?.length ?? 0) > 0 && (riparto.scelte?.length ?? 0) > 0;
}

/** La scelta in vigore: quella del giorno scelto, o la regola senza un giorno. `null` con «Divido a mano». */
export function sceltaInVigore(riparto: RipartoPrevisto | null | undefined, modo: ModoDelPregresso, allaData: string | null): SceltaDelPregresso | null {
    if (modo === 'manuale' || !riparto?.scelte?.length) {
        return null;
    }

    return riparto.scelte.find((s) => (allaData === null ? s.regola : s.alla_data === allaData)) ?? riparto.scelte.find((s) => s.regola) ?? null;
}

/** A chi va, in parole: «Ugo Rossi, la regola», «Elsa Bianchi, scelta tua», «diviso a mano». */
export function aChiVa(riparto: RipartoPrevisto | null | undefined, modo: ModoDelPregresso, allaData: string | null): string {
    const scelta = sceltaInVigore(riparto, modo, allaData);
    if (scelta === null) {
        return 'diviso a mano';
    }

    return `${elencoDeiNomi(scelta.quote.map((q) => q.nome))}, ${scelta.regola ? 'la regola' : 'scelta tua'}`;
}
</script>

<script setup lang="ts">
import { computed } from 'vue';
import vSelect from 'vue-select';
import { ChevronRight } from 'lucide-vue-next';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';

const props = defineProps<{
    riparto: RipartoPrevisto;
    modo: ModoDelPregresso;
    allaData: string | null;
}>();

const emit = defineEmits<{ (e: 'scegli', valore: { modo: ModoDelPregresso; alla_data: string | null }): void }>();

const { euro } = useCurrencyFormatter();

const scelte = computed(() => props.riparto.scelte ?? []);
const passaggi = computed(() => props.riparto.passaggi ?? []);
const regola = computed(() => scelte.value.find((s) => s.regola) ?? null);
const attiva = computed(() => sceltaInVigore(props.riparto, props.modo, props.allaData));
// Fino a tre scelte più «Divido a mano» stanno affiancate; oltre, il menu (bozza, scena 3: «al massimo quattro affiancate»).
const comeMenu = computed(() => scelte.value.length > 3);

const scegli = (s: SceltaDelPregresso | 'manuale') =>
    emit('scegli', s === 'manuale' ? { modo: 'manuale', alla_data: null } : { modo: 'automatica', alla_data: s.regola ? null : s.alla_data });

const opzioniDelMenu = computed(() => [
    ...scelte.value.map((s) => ({ label: `${elencoDeiNomi(s.quote.map((q) => q.nome))} — ${s.etichetta}`, value: s.alla_data })),
    { label: 'Divido a mano', value: 'manuale' },
]);
const valoreDelMenu = computed(() => (props.modo === 'manuale' ? 'manuale' : (attiva.value?.alla_data ?? null)));
const dalMenu = (valore: string) => scegli(valore === 'manuale' ? 'manuale' : (scelte.value.find((s) => s.alla_data === valore) ?? 'manuale'));

/** Con la regola di un diritto diverso dalle altre scelte (la riserva) si dice il diritto, senza genere: «aveva la piena proprietà». */
const ruoloCambia = computed(() => new Set(scelte.value.map((s) => s.ruolo)).size > 1);
const quoteDellaRegola = computed(() => regola.value?.quote ?? props.riparto.quote);
const nomiDellaRegola = computed(() => elencoDeiNomi(quoteDellaRegola.value.map((q) => q.nome)));
const ilGiornoPrima = computed(() => !!props.riparto.titolari_al && !!props.riparto.riferito_al && props.riparto.titolari_al !== props.riparto.riferito_al);
/** «era titolare Ugo Rossi», «erano titolari Anna e Bruno»: senza l'articolo, che direbbe il genere (rilievo 15). */
const eraTitolare = (quanti: number) => (quanti > 1 ? 'erano titolari' : 'era titolare');
/** Il pregresso è un debito dell'unità (positivo = debito); un credito non ha nessuno che «ne risponde in solido». */
const debito = computed(() => quoteDellaRegola.value.reduce((somma, q) => somma + q.importo, 0) > 0);
/** C'è davvero da scegliere: almeno una scelta oltre alla regola. Senza, le frasi che spiegano la scelta non servono (rilievo 16). */
const ceDaScegliere = computed(() => scelte.value.length > 1);
/** La successione nella regola (rilievo 4, decisione 689 (2)): chi era titolare quel giorno, e dove è andato l'arretrato. */
const successione = computed(() => props.riparto.successione ?? null);
const aNomeDelDefunto = computed(() => successione.value?.arretrato === 'defunto');
const conVendita = computed(() => passaggi.value.some((p) => p.tipo === 'vendita'));
const conSuccessione = computed(() => passaggi.value.some((p) => p.tipo === 'successione'));

/**
 * Chi entra con una vendita piena dal primo giorno in poi, non è nella regola ed è fra le altre scelte: risponde in solido (art. 63 co. 4),
 * e se nell'atto il debito è passato a lui l'amministratore lo sceglie. Dal primo giorno compreso: con la vendita datata il primo giorno
 * la regola guarda il giorno prima (decisione 685 (2)) e chi compra quel giorno è il caso della solidarietà (rilievo 7).
 */
const inSolido = computed(() => {
    const nellaRegola = new Set(quoteDellaRegola.value.map((q) => q.nome));
    const nelleAltre = new Set(scelte.value.filter((s) => !s.regola).flatMap((s) => s.quote.map((q) => q.nome)));
    const primo = props.riparto.riferito_al ?? '';

    return [...new Set(passaggi.value.filter((p) => p.tipo === 'vendita' && !p.riserva && p.data >= primo).flatMap((p) => p.entranti))]
        .filter((n) => !nellaRegola.has(n) && nelleAltre.has(n));
});
const conRiserva = computed(() => passaggi.value.some((p) => p.riserva || p.tipo === 'usufrutto'));
const primaDellEsercizio = computed(() => passaggi.value.find((p) => p.data < (props.riparto.riferito_al ?? '')) ?? null);

/** Il colore della cifra (standard 12): il debito rosso, il credito verde, lo zero grigio. */
const colore = (cents: number) => (cents > 0 ? 'text-red-700 dark:text-red-400' : cents < 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500 dark:text-slate-400');

const gruppo = 'space-y-2 border-b border-slate-100 py-3 last:border-b-0 dark:border-slate-800';
const titoloGruppo = 'text-xs font-semibold text-slate-500 dark:text-slate-400';
</script>

<template>
    <div data-testid="a-chi-va-il-pregresso">
        <section :class="gruppo" data-testid="il-passaggio">
            <h4 :class="titoloGruppo">Il passaggio</h4>
            <p v-for="p in passaggi" :key="`${p.data}-${p.tipo}`" class="text-sm text-slate-700 dark:text-slate-200">
                <template v-if="p.riserva">
                    {{ p.etichetta }} {{ delGiorno(p.data) }}: la nuda proprietà a <b class="font-semibold text-slate-900 dark:text-white">{{ elencoDeiNomi(p.entranti) }}</b>,
                    l'usufrutto resta a <b class="font-semibold text-slate-900 dark:text-white">{{ elencoDeiNomi(p.uscenti) }}</b>.
                </template>
                <template v-else>
                    {{ p.etichetta }} {{ delGiorno(p.data) }}: <b class="font-semibold text-slate-900 dark:text-white">{{ elencoDeiNomi(p.uscenti) }}</b>
                    → <b class="font-semibold text-slate-900 dark:text-white">{{ elencoDeiNomi(p.entranti) }}</b>.
                </template>
            </p>
            <!-- «primo giorno del suo esercizio», non il nome dell'esercizio, che è quello dato dallo studio («Esercizio anno 2026»: rilievo 16). -->
            <p v-if="riparto.riferito_al" class="text-sm text-slate-700 dark:text-slate-200" data-testid="giorno-del-saldo">
                Il saldo è al {{ giornoInParole(riparto.riferito_al) }}, primo giorno del suo esercizio:
                <template v-if="ilGiornoPrima">il passaggio è di quel giorno, e conta chi era titolare il giorno prima, <b class="font-semibold text-slate-900 dark:text-white">{{ nomiDellaRegola }}</b>.</template>
                <template v-else-if="successione && aNomeDelDefunto">quel giorno {{ eraTitolare(successione.titolari.length) }} <b class="font-semibold text-slate-900 dark:text-white">{{ elencoDeiNomi(successione.titolari) }}</b>;
                    con la successione {{ delGiorno(successione.data) }} l'arretrato è rimasto a nome di <b class="font-semibold text-slate-900 dark:text-white">{{ elencoDeiNomi(successione.defunti) }}</b>.</template>
                <template v-else-if="successione">quel giorno {{ eraTitolare(successione.titolari.length) }} <b class="font-semibold text-slate-900 dark:text-white">{{ elencoDeiNomi(successione.titolari) }}</b>;
                    con la successione {{ delGiorno(successione.data) }} l'arretrato<template v-if="successione.titolari.length > successione.defunti.length"> di {{ elencoDeiNomi(successione.defunti) }}</template> è passato agli eredi.</template>
                <template v-else-if="ruoloCambia && regola?.ruolo === 'proprietario'">quel giorno <b class="font-semibold text-slate-900 dark:text-white">{{ nomiDellaRegola }}</b> aveva la piena proprietà.</template>
                <template v-else>quel giorno {{ eraTitolare(quoteDellaRegola.length).replace('era', primaDellEsercizio ? 'era già' : 'era') }} <b class="font-semibold text-slate-900 dark:text-white">{{ nomiDellaRegola }}</b>.</template>
            </p>
        </section>

        <section :class="gruppo" data-testid="a-chi-va">
            <h4 :class="titoloGruppo">A chi va</h4>

            <div v-if="!comeMenu" class="grid grid-cols-1 gap-2 sm:grid-cols-3" role="radiogroup" aria-label="A chi va il pregresso">
                <button v-for="s in scelte" :key="s.alla_data" type="button" role="radio" :aria-checked="attiva?.alla_data === s.alla_data"
                    class="flex items-start gap-2.5 rounded-lg border bg-white p-2.5 text-left dark:bg-slate-950"
                    :class="attiva?.alla_data === s.alla_data ? 'border-slate-900 ring-1 ring-inset ring-slate-900 dark:border-white dark:ring-white' : 'border-slate-200 dark:border-slate-700'"
                    :data-testid="`scelta-${s.alla_data}`" @click="scegli(s)">
                    <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-[1.5px]"
                        :class="attiva?.alla_data === s.alla_data ? 'border-slate-900 dark:border-white' : 'border-slate-400'">
                        <span v-if="attiva?.alla_data === s.alla_data" class="h-2 w-2 rounded-full bg-slate-900 dark:bg-white" />
                    </span>
                    <span class="min-w-0">
                        <template v-if="s.quote.length === 1">
                            <b class="block text-sm font-semibold text-slate-900 dark:text-white">{{ s.quote[0].nome }}</b>
                            <small class="block text-xs leading-snug text-slate-500 dark:text-slate-400">{{ s.etichetta }}</small>
                            <span class="mt-0.5 block text-sm font-semibold tabular-nums" :class="colore(s.quote[0].importo)">{{ euro(s.quote[0].importo) }}</span>
                        </template>
                        <template v-else>
                            <small class="mb-1 block text-xs leading-snug text-slate-500 dark:text-slate-400">{{ s.etichetta }}</small>
                            <span v-for="q in s.quote" :key="q.anagrafica_id" class="flex justify-between gap-2 text-sm">
                                <b class="truncate font-semibold text-slate-900 dark:text-white">{{ q.nome }}</b>
                                <span class="shrink-0 font-semibold tabular-nums" :class="colore(q.importo)">{{ euro(q.importo) }}</span>
                            </span>
                        </template>
                    </span>
                </button>
                <button type="button" role="radio" :aria-checked="modo === 'manuale'"
                    class="flex items-start gap-2.5 rounded-lg border bg-white p-2.5 text-left dark:bg-slate-950"
                    :class="modo === 'manuale' ? 'border-slate-900 ring-1 ring-inset ring-slate-900 dark:border-white dark:ring-white' : 'border-slate-200 dark:border-slate-700'"
                    data-testid="scelta-manuale" @click="scegli('manuale')">
                    <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border-[1.5px]"
                        :class="modo === 'manuale' ? 'border-slate-900 dark:border-white' : 'border-slate-400'">
                        <span v-if="modo === 'manuale'" class="h-2 w-2 rounded-full bg-slate-900 dark:bg-white" />
                    </span>
                    <span class="min-w-0">
                        <b class="block text-sm font-semibold text-slate-900 dark:text-white">Divido a mano</b>
                        <small class="block text-xs leading-snug text-slate-500 dark:text-slate-400">{{ primaDellEsercizio ? (primaDellEsercizio.tipo === 'vendita' ? 'se una parte è di prima della vendita' : 'se una parte è di prima del passaggio') : 'fra le persone che scegli' }}</small>
                    </span>
                </button>
            </div>

            <div v-else data-testid="scelta-menu">
                <v-select :options="opzioniDelMenu" :model-value="valoreDelMenu" :reduce="(o: any) => o.value" :clearable="false" class="text-sm bg-white"
                    @update:model-value="dalMenu" />
            </div>

            <p class="text-xs leading-snug text-slate-500 dark:text-slate-400" data-testid="frase-sotto-le-scelte">
                <template v-if="modo === 'manuale'">Dividi il pregresso fra le persone che scegli: la somma deve tornare al centesimo.</template>
                <template v-else-if="attiva && !attiva.regola">Hai scelto diversamente dalla regola: nel piano resta scritto, con il giorno dei titolari che hai scelto ({{ giornoInParole(attiva.alla_data) }}).</template>
                <template v-else-if="successione && aNomeDelDefunto">Con la successione l'arretrato è rimasto a nome del defunto: il pregresso va a {{ elencoDeiNomi(successione.defunti) }}<template v-if="ceDaScegliere">, e gli eredi sono l'altra scelta</template>.</template>
                <template v-else-if="successione">Con la successione l'arretrato è passato agli eredi: il pregresso va a loro, ciascuno per la sua quota.</template>
                <!-- Le frasi che spiegano la scelta solo se c'è da scegliere (rilievo 16); l'art. 1005 solo sulla straordinaria. -->
                <template v-else-if="ceDaScegliere && conRiserva && riparto.natura === 'straordinaria'">Dopo l'atto la straordinaria è del nudo proprietario (art. 1005 c.c.); il {{ debito ? 'debito' : 'credito' }} di prima resta di chi era proprietario.</template>
                <template v-else-if="ceDaScegliere && conRiserva">Dopo l'atto l'ordinaria è dell'usufruttuario (art. 1004 c.c.); il {{ debito ? 'debito' : 'credito' }} di prima resta di chi era proprietario.</template>
                <template v-else-if="ceDaScegliere && primaDellEsercizio">Il saldo di un esercizio è di chi è titolare quando l'esercizio comincia. Se una parte è di {{ elencoDeiNomi(primaDellEsercizio.uscenti) }}, dividi a mano.</template>
                <template v-else-if="debito && inSolido.length">{{ elencoDeiNomi(inSolido) }} ne {{ inSolido.length > 1 ? 'rispondono' : 'risponde' }} comunque in solido verso il condominio (art. 63 disp. att. c.c.). Se nell'atto il debito è passato a {{ elencoDeiNomi(inSolido) }}, scegli {{ inSolido.length > 1 ? 'i loro nomi' : 'il suo nome' }} qui.</template>
                <template v-else-if="ilGiornoPrima">Il passaggio è del primo giorno dell'esercizio: la regola dà il pregresso a chi era titolare il giorno prima.</template>
                <template v-else>La regola dà il pregresso a chi era titolare il primo giorno del suo esercizio.</template>
            </p>

            <details class="group rounded-md border border-slate-200 dark:border-slate-700" data-testid="perche-questa-regola">
                <summary class="flex cursor-pointer list-none items-center gap-1.5 px-2.5 py-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400">
                    <ChevronRight class="h-3.5 w-3.5 transition-transform group-open:rotate-90" aria-hidden="true" />Perché questa regola
                </summary>
                <ul class="list-disc space-y-1 px-3 pb-2 pl-7 text-xs text-slate-600 dark:text-slate-300">
                    <li>Il saldo è il {{ debito ? 'debito' : 'credito' }} dell'unità al primo giorno del suo esercizio.</li>
                    <li v-if="ilGiornoPrima">Il passaggio è del primo giorno: il {{ debito ? 'debito' : 'credito' }} è di chi era titolare il giorno prima, {{ nomiDellaRegola }}.</li>
                    <li v-else-if="successione && aNomeDelDefunto">Quel giorno {{ eraTitolare(successione.titolari.length) }} {{ elencoDeiNomi(successione.titolari) }}; con la successione l'arretrato è rimasto a nome di {{ elencoDeiNomi(successione.defunti) }}: il {{ debito ? 'debito' : 'credito' }} è {{ successione.defunti.length > 1 ? 'loro' : 'suo' }}.</li>
                    <li v-else-if="successione">Quel giorno {{ eraTitolare(successione.titolari.length) }} {{ elencoDeiNomi(successione.titolari) }}; con la successione l'arretrato<template v-if="successione.titolari.length > successione.defunti.length"> di {{ elencoDeiNomi(successione.defunti) }}</template> è passato agli eredi, ciascuno per la sua quota.</li>
                    <li v-else>Quel giorno {{ eraTitolare(quoteDellaRegola.length) }} {{ nomiDellaRegola }}: il {{ debito ? 'debito' : 'credito' }} è {{ quoteDellaRegola.length > 1 ? 'loro' : 'suo' }}.</li>
                    <!-- La frase del compratore solo con una vendita, e la regola della successione solo con una successione (rilievi 4 e 8). -->
                    <li v-if="conVendita">Chi compra risponde in solido per l'anno in corso e il precedente, e ha regresso verso chi vende.</li>
                    <li v-if="conSuccessione">Con una successione: con l'arretrato agli eredi va agli eredi per le loro quote, con l'arretrato a nome del defunto resta al defunto.</li>
                    <li>Un accordo diverso fra le parti lo sai tu: per questo la scelta è tua.</li>
                </ul>
            </details>
        </section>
    </div>
</template>
