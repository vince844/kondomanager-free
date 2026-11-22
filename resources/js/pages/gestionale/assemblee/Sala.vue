<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La sala dell'assemblea (la bozza approvata della sala, decisioni 513 e 521; le scene della tappa 3, decisione 599).
 *
 * - **In alto, per tutta l'assemblea**: la striscia della rete (`data-innesto="rete"`: lo stato, il lavoro ritrovato, le due versioni) e la barra della
 *   costituzione, contata sul posto con `conti.ts` dalla sala vista da questo dispositivo.
 * - **Prima di aprire**: il controllo dell'art. 1136 c. 6 con le due scelte del presidente, presidente e segretario, «Segna deserta la prima
 *   convocazione», l'avviso del browser che non tiene il lavoro, «Apri l'assemblea alle …».
 * - **Aperta**: le presenze e il voto del punto; al telefono due schede, «Presenze» e «Punto N di M», senza ricaricare. Fino a sei punti i pulsanti
 *   «Punto N», oltre il selettore «‹ Punto 3 di 15 · … ›» (`SceltaDelPunto`, decisione 664 (3)); nei messaggi al più uno della costituzione, e mai due testi
 *   uguali (rilievo C52). Il menu «Azioni» ha «Registra dal foglio
 *   della sala», «Stampa il foglio» (vuoto) e «Stampa il foglio con quello che c'è» (i segni fatti fin qui, per continuare a penna; spento finché dei gesti
 *   stanno partendo), «Segna deserta la prima (o la seconda) convocazione» (aperta, finché non si vota), «Sospendi, prosegue il …», «Rinvia l'assemblea»
 *   (non dopo un punto con l'esito, nemmeno «Trattato»), «Chiudi l'assemblea». In testa, quando servono: la scelta del presidente sull'apertura rimasta da
 *   decidere, le deleghe oltre il quinto da decidere.
 * - **Chiusa**: la sala si legge com'era (decisione 660), senza nessun gesto: la barra dice la costituzione vera («Chiusa alle 08:00 · costituita in prima
 *   convocazione con 3 su 4 e 760,00 millesimi»), le presenze dell'ultima seduta con le ore di entrata e di uscita, i punti con l'esito e i conti. In testa
 *   «Vai a» (l'assemblea, il verbale, il registro) e il menu «Stampe» con «Stampa il prospetto». Le tre card corte della guida stanno in testa a ogni sala.
 * - **Dal foglio della sala** (`?foglio=1`): la stessa sala, con la scritta in testa e l'ora del foglio su ogni gesto, e il segno del foglio nei dati di ogni
 *   gesto (il diario dice «registrato dal foglio della sala» anche a sala aperta).
 *
 * **La tenuta senza rete** (`useSalaSenzaRete`): la pagina mostra la sala vista da questo dispositivo, lo stato del server più i gesti in coda; ogni azione è
 * un gesto numerato, prima sul disco del browser, poi a video, poi verso il server, mai una visita. Le risposte arrivano gesto per gesto, i messaggi si
 * leggono in testa; un gesto non accettato resta su questo computer, da rivedere. Un gesto arrivato resta a video finché lo stato riletto lo porta: dopo
 * la risposta la sala non torna a com'era prima (rilievo C46). La lettura periodica è una richiesta JSON della coda, non una visita: la
 * sessione scaduta o il server fermo la fermano, e la striscia lo dice. L'ora della pagina è quella dell'orologio della coda. Chi vede soltanto legge la sala
 * senza i pulsanti.
 */
import { computed, onBeforeUnmount, onMounted, provide, ref, toRef, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ChevronDown, Compass, DoorOpen, FileText, ListChecks, Printer, Zap } from 'lucide-vue-next';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SalaGuide from '@/components/guides/SalaGuide.vue';
import BarraDellaCostituzione from '@/components/assemblee/sala/BarraDellaCostituzione.vue';
import AperturaDellaSala from '@/components/assemblee/sala/AperturaDellaSala.vue';
import SalaInSolaLettura from '@/components/assemblee/sala/SalaInSolaLettura.vue';
import PresenzeDellaSala from '@/components/assemblee/sala/PresenzeDellaSala.vue';
import VotoDelPunto from '@/components/assemblee/sala/VotoDelPunto.vue';
import SceltaDelPunto from '@/components/assemblee/sala/SceltaDelPunto.vue';
import SegnaDeserta from '@/components/assemblee/sala/SegnaDeserta.vue';
import SospendiLAssemblea from '@/components/assemblee/sala/SospendiLAssemblea.vue';
import ChiudiLAssemblea from '@/components/assemblee/sala/ChiudiLAssemblea.vue';
import RinviaLAssemblea from '@/components/assemblee/sala/RinviaLAssemblea.vue';
import StrisciaDellaRete from '@/components/assemblee/sala/rete/StrisciaDellaRete.vue';
import LavoroRitrovato from '@/components/assemblee/sala/rete/LavoroRitrovato.vue';
import DueVersioni from '@/components/assemblee/sala/rete/DueVersioni.vue';
import GestiDaRivedere from '@/components/assemblee/sala/rete/GestiDaRivedere.vue';
import AperturaDaDecidere from '@/components/assemblee/sala/AperturaDaDecidere.vue';
import DelegheDaDecidere from '@/components/assemblee/sala/DelegheDaDecidere.vue';
import { CONTESTO_DELLA_SALA } from '@/components/assemblee/sala/contesto';
import { barraSulPosto, giornoDellaSeduta, giornoDiRoma, istanteDiRoma, oraDiRoma, type PresenzaConLaCoda } from '@/components/assemblee/sala/sala';
import { personePresenti } from '@/lib/assemblee/sala/conti';
import { cardDellaSala } from '@/lib/assemblee/cardDellaGuida';
import { useSalaSenzaRete } from '@/lib/senzaRete/useSalaSenzaRete';
import { fraseDelConflitto } from '@/lib/senzaRete/striscia';
import { tipoDiDispositivo } from '@/lib/senzaRete/sala';
import { usePermission } from '@/composables/permissions';
import type { DatiDeiGesti, GestoDellaSala, SalaDellAssemblea, TipoDiGesto } from '@/types/assemblee';
import type { Building } from '@/types/buildings';

const props = defineProps<{ condominio: Building; condomini: Building[]; sala: SalaDellAssemblea }>();

const page = usePage<{ auth?: { user?: { id?: number; name?: string } } }>();
const { generatePath, generateRoute } = usePermission();

// ─── La sala vista da questo dispositivo: lo stato del server più i gesti in coda ───────────────────────────────────────────────────────────────────
// La coda è di chi la segna (sala:<assemblea>:<utente>); «Rientra» apre la pagina di accesso del programma, anche in una sottocartella.
const rete = useSalaSenzaRete(toRef(props, 'sala'), { nomeUtente: page.props.auth?.user?.name ?? '', fuso: 'Europe/Rome', utente: page.props.auth?.user?.id ?? null,
    indirizzoDiAccesso: typeof route === 'function' ? route('login') : undefined });
const vista = computed(() => rete.salaVista.value);
const inCoda = computed(() => rete.foto.value.inCoda as unknown as GestoDellaSala[]);
const striscia = computed(() => rete.striscia.value);
const dispositivo = tipoDiDispositivo();
const puoGestire = computed(() => vista.value.puo_gestire);

// ─── L'orologio: quello della coda, con lo scarto dal server tenuto nel deposito (non l'ora della pagina, che riaperta senza rete è vecchia) ───────────
const orologio = ref(Date.now());
/** Adesso con l'ora del server; legge l'orologio della pagina, così le ore scritte («Apri l'assemblea alle 18:42») si rinnovano. */
const adesso = () => { void orologio.value; return rete.adesso(); };

// ─── Dal foglio della sala ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const dalFoglio = ref(/[?&]foglio=1\b/.test(page.url ?? ''));
const sedutaDelGesto = computed(() => vista.value.sedute.find((s) => s.id === (vista.value.seduta_id ?? vista.value.da_aprire?.seduta_id)) ?? null);
const oraDelFoglio = ref(sedutaDelGesto.value?.aperta_il ? oraDiRoma(sedutaDelGesto.value.aperta_il) : sedutaDelGesto.value?.ora ?? '18:00');
const oraDelGesto = () => (dalFoglio.value ? oraDelFoglio.value : oraDiRoma(adesso().toISOString()));

// ─── I gesti e le risposte ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
interface Messaggio { tipo: 'errore' | 'avviso' | 'info'; testo: string; link?: { testo: string; url: string } }
const messaggi = ref<Messaggio[]>([]);
/** Il messaggio della costituzione («Punto 3: l'assemblea non risulta costituita …»): nella sala ce n'è al più uno, l'ultimo (rilievo C52). */
const dellaCostituzione = (m: Messaggio) => /l'assemblea non risulta costituita/i.test(m.testo);
/** I più nuovi in testa: un testo già presente non si ripete, e della costituzione resta il più nuovo. */
function senzaDoppi(elenco: Messaggio[]): Messaggio[] {
    const visti = new Set<string>();
    let costituzione = false;
    return elenco.filter((m) => {
        if (visti.has(m.testo) || (dellaCostituzione(m) && costituzione)) return false;
        visti.add(m.testo);
        costituzione ||= dellaCostituzione(m);
        return true;
    });
}
const risposteViste = new Set<string>();
watch(() => rete.foto.value.esiti, (esiti) => {
    const nuovi: Messaggio[] = [];
    for (const r of esiti) {
        if (risposteViste.has(r.gesto)) continue;
        risposteViste.add(r.gesto);
        if (r.esito === 'rifiutato' && r.messaggio) nuovi.push({ tipo: 'errore', testo: r.messaggio });
        else if (r.esito === 'in_conflitto') nuovi.push({ tipo: 'avviso', testo: r.messaggio ?? 'Due versioni: scegli quale tenere.' });
        else if (r.esito === 'applicato' && r.messaggio && !r.messaggio.startsWith('Era già così')) nuovi.push({ tipo: 'avviso', testo: r.messaggio });
        const nuova = r.numeri?.assemblea_nuova_id;
        if (typeof nuova === 'number') {
            nuovi.push({ tipo: 'info', testo: 'L\'assemblea è rinviata e riconvocata.', link: { testo: 'Apri la nuova assemblea',
                url: route(generateRoute('gestionale.assemblee.show'), { condominio: props.condominio.id, assemblea: nuova }) } });
        }
    }
    if (nuovi.length) messaggi.value = senzaDoppi([...nuovi, ...messaggi.value]).slice(0, 6);
    // Lo stato si rilegge da sé dopo ogni risposta (`useSalaSenzaRete`), con una richiesta JSON: il gesto arrivato resta a video finché lo stato lo porta.
});

async function segna<T extends TipoDiGesto>(tipo: T, dati: DatiDeiGesti[T], opzioni: { base?: number | null; ora?: string; giorno?: string } = {}) {
    if (!puoGestire.value) return;
    const giorno = opzioni.giorno ?? sedutaDelGesto.value?.giorno ?? giornoDiRoma(adesso());
    const ora = opzioni.ora ?? (dalFoglio.value ? oraDelFoglio.value : null);
    // Dal foglio della sala il gesto lo dice nei suoi dati: il diario scrive «registrato dal foglio della sala» anche a sala aperta.
    const conIlFoglio = dalFoglio.value ? { ...(dati as Record<string, unknown>), dal_foglio: true } : (dati as Record<string, unknown>);
    await rete.segna(tipo, conIlFoglio, opzioni.base ?? null, ora ? istanteDiRoma(giorno, ora) : undefined);
}

const nomeDi = (id: number | null) => (id === null ? '' : vista.value.fotografia?.persone[String(id)]?.nome ?? '');
provide(CONTESTO_DELLA_SALA, { sala: vista, inCoda, tenuta: rete.tenuta, dispositivo, segna, adesso, puoGestire, dalFoglio, oraDelFoglio, oraDelGesto, nomeDi,
    scriviLaDiscussione: (puntoId, documento) => { if (puoGestire.value) rete.scriviLaDiscussione(puntoId, documento); } });

// La lettura periodica (i gesti degli altri dispositivi, e la sessione tenuta viva) la fa la coda, con una richiesta JSON: `useSalaSenzaRete`.
let battito: ReturnType<typeof setInterval> | null = null;
onMounted(() => { battito = setInterval(() => { orologio.value = Date.now(); }, 20000); });
onBeforeUnmount(() => { if (battito) clearInterval(battito); });

// ─── La striscia: il lavoro ritrovato, le due versioni, il foglio ─────────────────────────────────────────────────────────────────────────────────────
const ritrovatiChiusi = ref(false);
const conflittoAperto = ref(false);
const conflitto = computed(() => vista.value.conflitti[0] ?? null);
const puntoDelConflitto = computed(() => {
    const id = /^votazione:(-?\d+)/.exec(conflitto.value?.su ?? '')?.[1];
    return id === undefined ? null : vista.value.punti.find((p) => p.votazioni.some((v) => v.id === Number(id)))?.numero ?? null;
});
function scelto(scelta: 'questa' | 'quella') {
    if (conflitto.value) void rete.scegli(conflitto.value.gesto, scelta);
    conflittoAperto.value = false;
}
function stampaIlFoglio() {
    if (vista.value.rotte.foglio) globalThis.open?.(vista.value.rotte.foglio, '_blank', 'noopener');
}
/** «Stampa il foglio con quello che c'è»: a sala aperta, il foglio con i segni già fatti, per continuare a penna. */
function stampaIlFoglioCompilato() {
    if (vista.value.rotte.foglio_compilato) globalThis.open?.(vista.value.rotte.foglio_compilato, '_blank', 'noopener');
}
/** «Stampa il prospetto»: il PDF «Le presenze e i voti», anche a sala chiusa (chi vede le assemblee lo stampa). */
function stampaIlProspetto() {
    if (vista.value.rotte.prospetto) globalThis.open?.(vista.value.rotte.prospetto, '_blank', 'noopener');
}
/** Il foglio con quello che c'è non sa dei gesti ancora in coda: finché partono resta spento, e dice perché. */
const gestiCheStannoPartendo = computed(() => (inCoda.value.length === 0 ? null : inCoda.value.length === 1 ? '1 gesto sta ancora partendo'
    : `${inCoda.value.length} gesti stanno ancora partendo`));
/** Il foglio stampato a sala aperta: da lì «Registra dal foglio della sala» riporta solo ciò che è scritto a mano dopo. */
const foglioCompilatoAlle = computed(() => {
    const stampato = vista.value.foglio?.stampato_il;
    const aperta = sedutaDelGesto.value?.aperta_il;
    return stampato && aperta && stampato > aperta ? oraDiRoma(stampato) : null;
});

// ─── Le presenze e la barra, contate sul posto ────────────────────────────────────────────────────────────────────────────────────────────────────────
const aperta = computed(() => vista.value.seduta_id !== null);
/** Le presenze della seduta aperta; «Su questo computer» sulle righe che hanno un gesto non ancora arrivato. */
const presenze = computed<PresenzaConLaCoda[]>(() => {
    const ultime = new Map<string, number>();
    vista.value.presenze.forEach((p, i) => ultime.set(p.chiave, i));
    return vista.value.presenze.map((p, i) => ({ ...p, inCoda: vista.value.in_coda.includes(`presenza:${p.chiave}`) && (p.id < 0 || ultime.get(p.chiave) === i) }));
});
const dentro = computed(() => presenze.value.filter((p) => p.uscita_il === null));
const diPersona = computed(() => new Set(dentro.value.map((p) => p.anagrafica_id).filter((x): x is number => x !== null)));
/** Chi è presente per delega: la regola del server (`conti.ts`), anche la delega per alcuni punti; quella oltre il quinto «da decidere» no. */
const perDelega = computed(() => new Set([...personePresenti(presenze.value, vista.value.deleghe)].filter((p) => !diPersona.value.has(p))));
const presenti = computed(() => [...diPersona.value, ...perDelega.value]);
const conto = computed(() => (aperta.value && vista.value.fotografia ? barraSulPosto(vista.value.fotografia, presenze.value, vista.value.deleghe) : null));
const testePerDelega = computed(() => (vista.value.fotografia?.costituzione.teste ?? [])
    .filter((t) => !t.persone.some((p) => diPersona.value.has(p)) && t.persone.some((p) => perDelega.value.has(p))).length);
const presentiTeste = computed(() => (vista.value.fotografia?.costituzione.teste ?? []).filter((t) => t.persone.some((p) => presenti.value.includes(p))).length);
const presentiValore = computed(() => (vista.value.fotografia?.costituzione.teste ?? []).filter((t) => t.persone.some((p) => presenti.value.includes(p)))
    .reduce((n, t) => n + t.millesimi, 0));

// ─── I punti ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const puntoId = ref<number | null>(vista.value.punti.find((p) => p.esito === null)?.id ?? vista.value.punti[0]?.id ?? null);
const punto = computed(() => vista.value.punti.find((p) => p.id === puntoId.value) ?? null);
const rigaDellaParte = computed(() => (punto.value?.perimetro ? { numero: punto.value.numero, perimetro: punto.value.perimetro } : null));
const scheda = ref<'presenze' | 'punto'>('presenze');
function vaiAlPunto(id: number) {
    puntoId.value = id;
    scheda.value = 'punto';
}

// ─── Le finestre del menu ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const finestraSospendi = ref(false);
const finestraChiudi = ref(false);
const finestraRinvia = ref(false);
const finestraDeserta = ref(false);
const inCorso = computed(() => vista.value.assemblea.stato === 'in_corso');
/** «Rinvia»: non dopo un punto con l'esito confermato, nemmeno «Trattato» (la stessa regola del server). */
const puoRinviare = computed(() => puoGestire.value && ['convocata', 'in_corso'].includes(vista.value.assemblea.stato)
    && !vista.value.punti.some((p) => p.votazioni.some((v) => v.confermata_il !== null)));
/** «Segna deserta» dopo l'appello: la prima o la seconda convocazione aperta (non la prosecuzione), finché non si vota (né si tratta) un punto. */
const sedutaAperta = computed(() => vista.value.sedute.find((s) => s.id === vista.value.seduta_id) ?? null);
const desertaDopoLAppello = computed(() => sedutaAperta.value !== null && sedutaAperta.value.numero === 1
    && !vista.value.punti.some((p) => p.votazioni.some((v) => v.seduta_id === sedutaAperta.value!.id)));
const seconda = computed(() => vista.value.sedute.find((s) => s.convocazione === 'seconda' && s.numero === 1) ?? null);
/** I gesti del menu: solo a chi gestisce, finché la sala è in corso o si può rinviare. */
const menuDiGestione = computed(() => puoGestire.value && (inCorso.value || puoRinviare.value));
/** Il prospetto ha una seduta da raccontare: aperta, o già tenuta. */
const conIlProspetto = computed(() => !!vista.value.rotte.prospetto && (aperta.value || !!vista.value.ultima_seduta));
/** A sala senza una seduta aperta, ma con una seduta tenuta: la sala si legge com'era (decisione 660). */
const sedutaDaLeggere = computed(() => !aperta.value && (!!vista.value.ultima_seduta || vista.value.presenze.length > 0));
const mostraGuida = ref(false);
const apriDeserta = /[?&]deserta=1\b/.test(page.url ?? '');

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee', href: route(generateRoute('gestionale.assemblee.index'), { condominio: props.condominio.id }) },
    { title: vista.value.assemblea.nome, href: vista.value.rotte.assemblea },
    { title: 'La sala' },
]);
const sottotitolo = computed(() => {
    const s = sedutaDelGesto.value;
    return `${vista.value.assemblea.titolo}${s ? ` · ${s.convocazione === 'prima' ? 'prima' : 'seconda'} convocazione, ${giornoDellaSeduta(s.giorno)}` : ''}`;
});
/** Una voce del menu «Vai a»: l'icona, il nome e la riga grigia sotto, come nel verbale. */
const voceDiVaiA = 'flex cursor-pointer items-start gap-2 px-2 py-1.5 text-[13px]';
const iconaDellaVoce = 'mt-0.5 h-3.5 w-3.5 shrink-0';
const pulsanteDellaTesta = 'inline-flex h-8 items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 shadow-sm transition-colors hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800';
const linguetta = (attiva: boolean) => ['h-9 flex-1 rounded-md text-sm font-medium', attiva ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-800 dark:text-white'
    : 'text-slate-600 dark:text-slate-400'];
</script>

<template>
    <Head title="La sala" />
    <GestionaleLayout>
        <div class="space-y-4 px-4 py-6 sm:px-6 sm:py-8">
            <PageHeaderGuide page-title="La sala" :page-subtitle="sottotitolo" :guides="cardDellaSala()" :breadcrumbs="(breadcrumbs as any)" :video-url="null" :condominio="condominio"
                :condomini="condomini" condominio-fisso has-text-guide text-guide-title="Guida" una-fila @open-text-guide="mostraGuida = true">
                <template #actions>
                    <!-- «Vai a»: l'assemblea, il verbale e il registro in un menu solo, perché la testa resti su una riga, come nella pagina del verbale. -->
                    <DropdownMenu :modal="false">
                        <DropdownMenuTrigger as-child>
                            <button type="button" :class="pulsanteDellaTesta" data-testid="vai-a">
                                <Compass class="h-3.5 w-3.5 text-slate-500 dark:text-slate-400" aria-hidden="true" /> Vai a <ChevronDown class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500" aria-hidden="true" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64">
                            <DropdownMenuItem as-child :class="voceDiVaiA">
                                <Link :href="vista.rotte.assemblea" data-testid="torna-all-assemblea">
                                    <ListChecks :class="iconaDellaVoce" aria-hidden="true" />
                                    <span data-testid="voce-vai-a"><span class="block">L'assemblea</span><span class="block text-xs text-slate-500 dark:text-slate-400">I sei passi</span></span>
                                </Link>
                            </DropdownMenuItem>
                            <!-- La pagina del verbale: la stessa discussione dei punti, che un segretario può tenere su un altro computer. -->
                            <DropdownMenuItem v-if="vista.rotte.verbale" as-child :class="voceDiVaiA">
                                <Link :href="vista.rotte.verbale" data-testid="vai-al-verbale">
                                    <FileText :class="iconaDellaVoce" aria-hidden="true" />
                                    <span data-testid="voce-vai-a"><span class="block">Il verbale</span><span class="block text-xs text-slate-500 dark:text-slate-400">Il testo e la registrazione</span></span>
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="vista.rotte.registro" as-child :class="voceDiVaiA">
                                <Link :href="vista.rotte.registro" data-testid="vai-al-registro">
                                    <DoorOpen :class="iconaDellaVoce" aria-hidden="true" />
                                    <span data-testid="voce-vai-a"><span class="block">Il registro dei verbali</span><span class="block text-xs text-slate-500 dark:text-slate-400">I verbali di {{ condominio.nome }}</span></span>
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <!-- Il menu della sala: i gesti solo a chi gestisce e finché la sala è viva, e allora si chiama «Azioni» come nella pagina dell'assemblea;
                         a sala chiusa restano le stampe, e si chiama «Stampe». -->
                    <DropdownMenu v-if="menuDiGestione || conIlProspetto">
                        <DropdownMenuTrigger as-child>
                            <button type="button" :class="pulsanteDellaTesta" data-testid="menu-sala">
                                <component :is="menuDiGestione ? Zap : Printer" class="h-3.5 w-3.5 text-slate-500 dark:text-slate-400" aria-hidden="true" />
                                {{ menuDiGestione ? 'Azioni' : 'Stampe' }}
                                <ChevronDown class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500" aria-hidden="true" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64">
                            <template v-if="menuDiGestione">
                            <DropdownMenuItem v-if="!dalFoglio" data-testid="voce-foglio" @select="dalFoglio = true">Registra dal foglio della sala</DropdownMenuItem>
                            <DropdownMenuItem v-if="aperta && vista.rotte.foglio" data-testid="voce-foglio-vuoto" @select="stampaIlFoglio">Stampa il foglio</DropdownMenuItem>
                            <DropdownMenuItem v-if="aperta && vista.rotte.foglio_compilato" :disabled="gestiCheStannoPartendo !== null" data-testid="voce-foglio-compilato"
                                @select="stampaIlFoglioCompilato">
                                <span class="flex flex-col">
                                    <span>Stampa il foglio con quello che c'è</span>
                                    <span v-if="gestiCheStannoPartendo" class="text-xs text-slate-500 dark:text-slate-400">{{ gestiCheStannoPartendo }}: aspetta che arrivino</span>
                                </span>
                            </DropdownMenuItem>
                            </template>
                            <DropdownMenuItem v-if="conIlProspetto" data-testid="voce-prospetto" @select="stampaIlProspetto">Stampa il prospetto</DropdownMenuItem>
                            <template v-if="menuDiGestione">
                            <DropdownMenuItem v-if="aperta && desertaDopoLAppello" data-testid="voce-deserta" @select="finestraDeserta = true">
                                {{ sedutaAperta?.convocazione === 'seconda' ? 'Segna deserta la seconda convocazione' : 'Segna deserta la prima convocazione' }}
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="aperta" data-testid="voce-sospendi" @select="finestraSospendi = true">Sospendi, prosegue il …</DropdownMenuItem>
                            <DropdownMenuItem v-if="puoRinviare" data-testid="voce-rinvia" @select="finestraRinvia = true">Rinvia l'assemblea</DropdownMenuItem>
                            <DropdownMenuItem v-if="aperta" data-testid="voce-chiudi" @select="finestraChiudi = true">Chiudi l'assemblea</DropdownMenuItem>
                            </template>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </PageHeaderGuide>

            <!-- La striscia della rete e la barra: in alto per tutta l'assemblea -->
            <!-- Lo sfondo è quello della pagina (copre ciò che scorre sotto senza disegnare un riquadro suo). -->
            <div class="sticky top-0 z-30 -mx-4 space-y-2 bg-background/95 px-4 py-2 backdrop-blur sm:-mx-6 sm:px-6" data-testid="testa-della-sala">
                <div class="space-y-2" data-innesto="rete">
                    <StrisciaDellaRete v-bind="striscia" @rientra="rete.rientra()" @scegli="conflittoAperto = true" @stampa-foglio="stampaIlFoglio" />
                    <LavoroRitrovato v-if="!ritrovatiChiusi" :ritrovati="rete.foto.value.ritrovati" :sala="vista" :riaperta-senza-rete="rete.riapertaSenzaRete.value"
                        :in-coda="striscia.inCoda" :dispositivo="dispositivo" @chiudi="ritrovatiChiusi = true" />
                    <GestiDaRivedere :gesti="rete.foto.value.daRivedere" :sala="vista" :dispositivo="dispositivo" :puo-gestire="puoGestire" @rimanda="rete.rimanda()"
                        @togli="(g: string) => rete.lascia(g)" />
                </div>
                <BarraDellaCostituzione v-if="vista.fotografia" :conto="conto" :convocazione="vista.fotografia.convocazione"
                    :totale-teste="vista.fotografia.costituzione.totale_teste" :totale-valore="vista.fotografia.costituzione.totale_valore"
                    :presenti-teste="presentiTeste"
                    :presenti-valore="presentiValore" :per-delega="testePerDelega" :punto="rigaDellaParte" :chiusura="vista.chiusura ?? null" />
            </div>

            <!-- Dal foglio della sala -->
            <div v-if="dalFoglio" class="flex flex-col gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-sky-800
                dark:bg-sky-950/40" data-testid="foglio-banner">
                <div class="text-sm text-sky-950 dark:text-sky-100">
                    <p class="font-semibold">Registri dal foglio della sala di {{ sedutaDelGesto ? giornoDellaSeduta(sedutaDelGesto.giorno) : '' }}.</p>
                    <ul class="mt-0.5 list-disc pl-4 text-xs">
                        <li>Le ore sono quelle scritte sul foglio: decidono chi conta per ogni voto, come in sala.</li>
                        <li>Nel diario ogni riga dice «registrato dal foglio della sala», con l'ora di oggi.</li>
                        <li v-if="foglioCompilatoAlle">Il foglio stampato alle {{ foglioCompilatoAlle }} aveva già i segni fino a quell'ora: registra solo ciò che è scritto a mano.</li>
                    </ul>
                </div>
                <div class="flex items-center gap-2">
                    <Label for="ora-del-foglio" class="text-xs text-sky-950 dark:text-sky-100">Il prossimo gesto alle</Label>
                    <Input id="ora-del-foglio" v-model="oraDelFoglio" type="time" class="h-9 w-28 bg-white text-sm dark:bg-slate-950" data-testid="ora-del-foglio" />
                </div>
            </div>

            <!-- I messaggi dei gesti -->
            <ul class="space-y-1.5" aria-live="polite" data-testid="messaggi-della-sala">
                <li v-for="(m, i) in messaggi" :key="i" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                    :class="m.tipo === 'errore' ? 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200'
                        : m.tipo === 'avviso' ? 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
                            : 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200'">
                    <span class="min-w-0 break-words">{{ m.testo }}</span>
                    <span class="flex items-center gap-3">
                        <Link v-if="m.link" :href="m.link.url" class="text-xs font-semibold underline underline-offset-2">{{ m.link.testo }}</Link>
                        <button type="button" class="text-xs underline underline-offset-2" @click="messaggi.splice(i, 1)">Chiudi</button>
                    </span>
                </li>
            </ul>

            <!-- Prima di aprire, o senza una seduta aperta -->
            <AperturaDellaSala v-if="!aperta" :deserta="apriDeserta" @rinvia="finestraRinvia = true" />

            <!-- Senza una seduta aperta: l'ultima seduta com'era, in sola lettura (decisione 660) -->
            <SalaInSolaLettura v-if="sedutaDaLeggere" />

            <!-- Aperta: le presenze e il voto del punto -->
            <template v-else>
                <AperturaDaDecidere @rinvia="finestraRinvia = true" />
                <DelegheDaDecidere />
                <div class="flex gap-1 rounded-lg bg-slate-100 p-1 sm:hidden dark:bg-slate-900" role="tablist" aria-label="La sala">
                    <button type="button" role="tab" :aria-selected="scheda === 'presenze'" :class="linguetta(scheda === 'presenze')" data-testid="scheda-presenze"
                        @click="scheda = 'presenze'">Presenze</button>
                    <button type="button" role="tab" :aria-selected="scheda === 'punto'" :class="linguetta(scheda === 'punto')" data-testid="scheda-punto"
                        @click="scheda = 'punto'">Punto {{ punto?.numero ?? '' }} di {{ vista.punti.length }}</button>
                </div>
                <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <div :class="scheda === 'presenze' ? '' : 'hidden sm:block'">
                        <PresenzeDellaSala :presenze="presenze" :presenti="presenti" />
                    </div>
                    <div class="space-y-3" :class="scheda === 'punto' ? '' : 'hidden sm:block'">
                        <!-- Fino a sei punti i pulsanti; oltre, «‹ Punto 3 di 15 · … ›» su una riga (decisione 664 (3)) -->
                        <SceltaDelPunto :punti="vista.punti" :model-value="puntoId" @update:model-value="vaiAlPunto" />
                        <VotoDelPunto v-if="punto" :key="punto.id" :punto="punto" :presenti="presenti" :costituzione="conto" />
                    </div>
                </div>
            </template>
        </div>

        <DueVersioni v-if="conflitto" v-model:open="conflittoAperto" :conflitto="conflitto" :mio-dispositivo="rete.dispositivo.value" :titolo="fraseDelConflitto(conflitto, vista)"
            :punto="puntoDelConflitto" :dispositivo="dispositivo" @scegli="scelto" />
        <SegnaDeserta v-model:open="finestraDeserta" :seduta="sedutaAperta" :seconda="seconda" dopo-l-appello />
        <SospendiLAssemblea v-model:open="finestraSospendi" @rinvia="finestraRinvia = true" />
        <ChiudiLAssemblea v-model:open="finestraChiudi" @vai="vaiAlPunto" />
        <RinviaLAssemblea v-model:open="finestraRinvia" />
        <SalaGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
