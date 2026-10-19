<script setup lang="ts">

import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import GestionaleLayout from '@/layouts/GestionaleLayout.vue'
import { usePermission } from '@/composables/permissions'
import { Button } from '@/components/ui/button'
import { Plus, AlertTriangle, Wallet, Receipt, FolderTree, Settings2, Calculator, Lock, Printer, ChevronDown, TrendingUp } from 'lucide-vue-next'
import Alert from "@/components/Alert.vue";
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import RipartoUsufruttoGuide from '@/components/guides/RipartoUsufruttoGuide.vue';
import OperazioniContiGuide from '@/components/guides/OperazioniContiGuide.vue';
import GiaVersatoGuide from '@/components/guides/GiaVersatoGuide.vue';
import ModalNuovoConto from '@/components/gestionale/pianiDeiConti/conti/ModalNuovoConto.vue'
import ModalModificaConto from '@/components/gestionale/pianiDeiConti/conti/ModalModificaConto.vue'
import AlberoDeiConti from '@/components/gestionale/pianiDeiConti/conti/AlberoDeiConti.vue'
import MovimentiVoceDialog from '@/components/gestionale/pianiDeiConti/conti/MovimentiVoceDialog.vue'
import DettaglioConto from '@/components/gestionale/pianiDeiConti/conti/DettaglioConto.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import ModalAssociaTabella from '@/components/gestionale/pianiDeiConti/conti/ModalAssociaTabella.vue'
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter'
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types'
import type { Building } from '@/types/buildings'
import type { Esercizio } from '@/types/gestionale/esercizi'
import type { PianoDeiConti } from '@/types/gestionale/piani-dei-conti'
import type { Conto } from '@/types/gestionale/conti'
import type { Flash } from '@/types/flash';

interface TabellaAssociata {
  id: number
  nome: string
  coefficiente: number
  ripartizioni: Array<{ soggetto: string; percentuale: number }>
}

const props = defineProps<{
  condominio: Building
  esercizio: Esercizio
  esercizi?: Esercizio[]
  condomini?: Building[]
  pianoConti: PianoDeiConti
  conti: Conto[]
  fornitori: Array<{ id: number, ragione_sociale: string }>
  tabelle: Array<{ id: number, nome: string }>
  totalePreventivo: number
  // Le voci di entrata hanno il loro totale (valore assoluto): il preventivo conta le sole voci di spesa.
  totaleEntrate?: number
  totaleDaRipartire?: number
  totaleSopravvenienze: number
  totaleConsuntivo: number
  /**
   * Le voci di spesa scritte con il meno da una versione vecchia: il preventivo le conta in positivo, come il calcolo delle quote, mentre la
   * riga le mostra col meno. `importo` è quello scritto, in centesimi; `piani` sono i piani della gestione che la addebitano, e `avviso` dice se
   * l'avviso della pagina del piano nomina la voce (lo decide il server, `VoceDiSpesaNegativa::pianiPerVoce`); `bloccano` sono i piani approvati che la
   * tengono, e finché la tengono la voce non cambia importo, padre né tipo: con `capitolo` se il piano tiene un capitolo sopra la voce e non lei, e con `movimenti` («incasso», «credito», «passaggio») se sulle sue
   * quote c'è un incasso o un credito usato o rimborsato, o se un passaggio di titolarità di prima della 1.11.0-beta.42, registrato dopo l'emissione,
   * ha preso il piano nel conguaglio, «passaggio_senza_registro» se quel passaggio è di prima della 1.11.0-beta.37 (`Conto::pianiCheBloccanoLaVoce`). `terzo_livello` dice una voce al terzo livello o più in basso, che si salva solo
   * con un capitolo padre di primo livello (`VoceDiSpesaNegativa::alTerzoLivello`).
   */
  speseNegative?: Array<{ id: number, nome: string, importo: number, piani: Array<{ id: number, nome: string, avviso: boolean }>, bloccano?: Array<{ id: number, nome: string, capitolo: string | null, movimenti?: Array<'incasso' | 'credito' | 'passaggio' | 'passaggio_senza_registro'> }>, terzo_livello?: boolean }>
}>()

const { generatePath } = usePermission()
const showModalNew              = ref(false)
const showModalEdit             = ref(false)
const showModalDelete           = ref(false)
const contoSelezionato          = ref<Conto | null>(null)
const contoDaEliminare          = ref<Conto | null>(null)
const showModalMovimenti        = ref(false)
const contoMovimenti            = ref<Conto | null>(null)

const apriMovimenti = (conto: Conto) => {
  contoMovimenti.value = conto
  showModalMovimenti.value = true
}
const tabellaDaRimuovere        = ref<number | null>(null)
const showModalAssociaTabella   = ref(false)
const showModalRimuoviTabella   = ref(false)
const tabellaDaModificare       = ref<TabellaAssociata | null>(null)
const showGuideUsufrutto        = ref(false)
const showGuideOperazioni       = ref(false)
/**
 * ⚠️ Aggiunta nella beta.70, su domanda di Vincenzo: «abbiamo spiegato nella guida a cosa serve
 * questa opzione?». No — l'interruttore «già versato» apre una funzione intera (un elenco, una
 * pagina, un modale) e cambia il risultato del riparto, e l'unica spiegazione era la riga sotto
 * l'interruttore stesso.
 */
const showGuideGiaVersato      = ref(false)

const textGuidesList = [
  { id: 'operazioni', title: 'Guida: operazioni e struttura' },
  { id: 'usufrutto', title: 'Guida: ruoli e usufrutto' },
  { id: 'giaversato', title: 'Guida: Già versato' },
]

const openGuide = (id: string) => {
  if (id === 'operazioni') showGuideOperazioni.value = true;
  if (id === 'usufrutto') showGuideUsufrutto.value = true;
  if (id === 'giaversato') showGuideGiaVersato.value = true;
}

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);
const { euro } = useCurrencyFormatter()

/**
 * La frase di una voce di spesa scritta con il meno, a pezzi: i piani sono collegamenti alla loro pagina. Fuori da ogni piano la frase dice come
 * correggerla; in un piano rimanda solo alle pagine dei piani il cui avviso nomina la voce, che dicono le strade secondo lo stato del piano (con il
 * piano approvato l'importo della voce non si cambia, e cambiarne il tipo lascia le rate addebitate). In un piano la frase non dice la cifra che il
 * calcolo addebita: è quella della copia del piano, che può essere un'altra, e la pagina del piano la dice. Dove nessuna pagina ha l'avviso (il piano
 * ne tiene una copia positiva) nomina i piani e dice che la addebitano in positivo.
 *
 * Fuori da ogni piano, se un piano approvato tiene la voce o un capitolo sopra di lei senza addebitarla, la voce non cambia importo, padre né tipo
 * («Modifica inibita», `Conto::pianiCheBloccanoLaVoce`): la frase nomina quel piano e dice la strada che passa, il piano riportato in bozza prima
 * della correzione, con quello che costa e con che cosa annullare prima delle emissioni; se un passaggio di prima della 1.11.0-beta.37 ferma
 * l'annullamento delle emissioni, dice che la voce resta com'è. Una voce sotto un sottoconto si salva solo con un capitolo padre di primo livello: la
 * frase lo dice prima della correzione e della bozza. Sono le parole di `VoceDiSpesaNegativa` e di `BloccoDellaVoce` per la nota del selettore, senza
 * «nel piano dei conti», perché la pagina è quella.
 */
type PianoDellaVoce = { id: number, nome: string, avviso: boolean }
type PianoCheBlocca = { id: number, nome: string, capitolo: string | null, movimenti?: Array<'incasso' | 'credito' | 'passaggio' | 'passaggio_senza_registro'> }
type PezzoDellaFrase = { testo: string } | { piano: { id: number, nome: string } }
const elencoDeiNomi = (nomi: string[]) => nomi.length <= 1 ? nomi.join('') : `${nomi.slice(0, -1).join(', ')} e ${nomi[nomi.length - 1]}`
/** I nomi dei piani, ciascuno fra virgolette e con il collegamento, uniti con la virgola e l'ultimo con «e». */
const pezziDeiPiani = (piani: Array<{ id: number, nome: string }>): PezzoDellaFrase[] => piani.flatMap((piano, i) => [
  ...(i > 0 ? [{ testo: i === piani.length - 1 ? ' e ' : ', ' }] : []),
  { testo: '«' }, { piano }, { testo: '»' },
])
/** Chi il piano tiene: «la voce è», «il suo capitolo «X» è», «la voce e il suo capitolo «X» sono», «i suoi capitoli «X» e «Y» sono». */
const chiTiene = (bloccano: PianoCheBlocca[]): string => {
  const capitoli = bloccano.map((piano) => piano.capitolo).filter((c, i, tutti): c is string => c !== null && tutti.indexOf(c) === i)
  const dellaVoce = bloccano.some((piano) => piano.capitolo === null)
  const suoi = capitoli.length === 0 ? null : (capitoli.length === 1 ? `il suo capitolo «${capitoli[0]}»` : `i suoi capitoli ${elencoDeiNomi(capitoli.map((c) => `«${c}»`))}`)
  if (suoi === null) return 'la voce è'
  return dellaVoce ? `la voce e ${suoi} sono` : `${suoi} ${capitoli.length === 1 ? 'è' : 'sono'}`
}
/** La strada di una voce che un piano approvato tiene: la bozza, poi `poi`; con un passaggio di prima della 1.11.0-beta.37, che cosa resta. */
const stradaBloccata = (bloccano: PianoCheBlocca[], poi: string): PezzoDellaFrase[] => {
  const piani = bloccano.filter((piano, i) => bloccano.findIndex((altro) => altro.id === piano.id) === i)
  const uno = piani.length === 1
  const premessa: PezzoDellaFrase[] = [{ testo: ` L'importo, il padre e il tipo non si cambiano finché ${chiTiene(bloccano)} ${uno ? 'nel piano ' : 'nei piani '}` }, ...pezziDeiPiani(piani)]
  // Un passaggio di prima della 1.11.0-beta.37 ferma l'annullamento delle emissioni e dal programma non si annulla: la bozza non è una strada.
  const fermi = piani.filter((piano) => (piano.movimenti ?? []).includes('passaggio_senza_registro'))
  if (fermi.length > 0) {
    return [
      ...premessa,
      { testo: `, ${uno ? 'approvato' : 'approvati'}, e un passaggio di titolarità registrato prima della 1.11.0-beta.37, che dal programma non si annulla, ferma l'annullamento delle emissioni ` },
      ...(fermi.length < piani.length ? [{ testo: 'di ' }, ...pezziDeiPiani(fermi)] : [{ testo: uno ? 'del piano' : 'dei piani' }]),
      { testo: ': la voce resta com\'è.' },
    ]
  }
  return [
    ...premessa,
    { testo: `, ${uno ? 'approvato' : 'approvati'}: ${uno ? 'riporta prima il piano in bozza dalla sua pagina' : 'riporta prima i piani in bozza dalle loro pagine'}, poi ${poi}; `
      + `se è un provento, scegli «Entrata» e scrivi l'importo senza il segno. `
      + `${uno ? 'Riportandolo' : 'Riportandoli'} in bozza si azzerano il numero del verbale e la nota dell'approvazione: annotali prima; con delle rate emesse, ` },
    ...primaDelleEmissioni(piani),
  ]
}
/**
 * Che cosa annullare prima delle emissioni: l'annullamento di un'emissione rifiuta una rata con un incasso o un credito usato o rimborsato, e la rata
 * emessa prima di un passaggio di titolarità di prima della 1.11.0-beta.42 che ha preso il piano nel conguaglio. Quei movimenti e quei passaggi si
 * annullano prima, e si registrano di nuovo dopo la correzione. Si nominano i piani che li hanno, se non li hanno tutti. Un passaggio seguito da altre
 * rate emesse chiede di annullare prima quelle: la frase lo dice.
 */
const primaDelleEmissioni = (piani: PianoCheBlocca[]): PezzoDellaFrase[] => {
  const con = (tipi: string[]) => piani.filter((piano) => (piano.movimenti ?? []).some((m) => tipi.includes(m)))
  const conMovimenti = con(['incasso', 'credito'])
  const conPassaggi = con(['passaggio'])
  if (conMovimenti.length === 0 && conPassaggi.length === 0) return [{ testo: 'prima vanno annullate le emissioni.' }]
  const pezzi: PezzoDellaFrase[] = [{ testo: 'prima vanno annullati' }]
  if (conMovimenti.length > 0) {
    const tipi = conMovimenti.flatMap((piano) => piano.movimenti ?? [])
    const cosa = [tipi.includes('incasso') ? 'gli incassi registrati' : null, tipi.includes('credito') ? 'i crediti usati o rimborsati' : null].filter(Boolean).join(' e ')
    pezzi.push(...(conMovimenti.length === piani.length ? [{ testo: ` ${cosa} sulle rate` }] : [{ testo: ` ${cosa} sulle rate di ` }, ...pezziDeiPiani(conMovimenti)]))
  }
  if (conPassaggi.length > 0) {
    pezzi.push({ testo: `${conMovimenti.length > 0 ? ' e' : ''}, ciascuno dallo storico della sua unità, i passaggi di titolarità registrati dopo l'emissione` })
    if (conPassaggi.length < piani.length) pezzi.push({ testo: ' delle rate di ' }, ...pezziDeiPiani(conPassaggi))
  }
  pezzi.push({ testo: conPassaggi.length > 0
    ? ' (da registrare di nuovo dopo), poi le emissioni; se dopo un passaggio sono state emesse altre rate, il suo annullamento chiede di annullare prima quelle.'
    : ' (da registrare di nuovo dopo), poi le emissioni.' })
  return pezzi
}
const fraseDellaSpesaNegativa = (voce: { nome: string, importo: number, piani: PianoDellaVoce[], bloccano?: PianoCheBlocca[], terzo_livello?: boolean }): PezzoDellaFrase[] => {
  const cifra = euro(Math.abs(voce.importo))
  const testa = `La voce «${voce.nome}» ha un importo negativo (meno ${cifra}): `
  if (voce.piani.length === 0) {
    const bloccano = voce.bloccano ?? []
    // Un piano che blocca l'importo tiene la voce, o il suo capitolo, senza addebitarla: la cifra del calcolo non si dice accanto al suo nome.
    const pezzi: PezzoDellaFrase[] = [{ testo: bloccano.length > 0
      ? `${testa}il preventivo la conta in positivo, per ${cifra}.`
      : `${testa}il calcolo delle quote la addebiterebbe in positivo, per ${cifra}, e il preventivo la conta così.` }]
    if (bloccano.length > 0) {
      const ferma = bloccano.some((piano) => (piano.movimenti ?? []).includes('passaggio_senza_registro'))
      if (voce.terzo_livello === true && !ferma) pezzi.push({ testo: ' È sotto un sottoconto, e il piano dei conti non lo prevede: ogni sua modifica chiede come capitolo padre una voce di primo livello.' })
      pezzi.push(...stradaBloccata(bloccano, voce.terzo_livello === true ? 'scegli il capitolo padre e correggi l\'importo' : 'correggi l\'importo'))
    } else {
      pezzi.push({ testo: voce.terzo_livello === true
        ? ' È sotto un sottoconto, e il piano dei conti non lo prevede: scegli prima come suo capitolo padre una voce di primo livello, poi correggi l\'importo; se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.'
        : ' Se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.' })
    }
    return pezzi
  }
  const pezzi: PezzoDellaFrase[] = [{ testo: `${testa}il preventivo la conta in positivo, per ${cifra}.` }]
  pezzi.push({ testo: voce.piani.length === 1 ? ' È nel piano ' : ' È nei piani ' }, ...pezziDeiPiani(voce.piani))
  const conAvviso = voce.piani.filter((piano) => piano.avviso === true)
  if (conAvviso.length === 0) {
    pezzi.push({ testo: voce.piani.length === 1 ? ', che la addebita in positivo.' : ', che la addebitano in positivo.' })
  } else if (conAvviso.length === voce.piani.length) {
    pezzi.push({ testo: voce.piani.length === 1 ? ': la pagina del piano dice che cosa fare.' : ': le pagine dei piani dicono che cosa fare.' })
  } else {
    const nomi = elencoDeiNomi(conAvviso.map((piano) => `«${piano.nome}»`))
    pezzi.push({ testo: conAvviso.length === 1 ? `: la pagina di ${nomi} dice che cosa fare.` : `: le pagine di ${nomi} dicono che cosa fare.` })
  }
  return pezzi
}
const paginaDelPiano = (id: number) => route('admin.gestionale.esercizi.piani-rate.show', { condominio: props.condominio.id, esercizio: props.esercizio.id, pianoRate: id })

const headerBreadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Piani dei conti', href: generatePath('gestionale/:condominio/esercizi/:esercizio/piani-conti', { condominio: props.condominio.id, esercizio: props.esercizio.id }) },
  { title: props.pianoConti.nome, href: '#' }
])

const pageGuides = [
  {
    title: 'Struttura ad albero',
    // Prometteva «Mastro, Conto e Sottoconto», cioè tre livelli, nella stessa pagina in cui
    // la validazione ne consente due dalla beta.16. È il testo che ha legittimato le voci di
    // terzo livello che ora vanno smaltite.
    description: 'Organizza le spese su due livelli: il capitolo raggruppa, il sottoconto porta l\'importo e la tabella millesimale.',
    icon: FolderTree,
    colorVariant: 'blue' as const
  },
  {
    title: 'Associazione Tabelle',
    description: 'Collega direttamente le tabelle millesimali ai conti per automatizzare i futuri riparti di spesa.',
    icon: Settings2,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Preventivo e Consuntivo',
    description: 'Per ogni voce vedi quanto avevi previsto e quanto hai speso davvero. Clicca il consuntivo per scoprire da quali movimenti nasce. Il preventivo conta le sole voci di spesa: le entrate hanno il loro totale.',
    icon: Calculator,
    colorVariant: 'amber' as const
  }
];

function trovaContoInArray(conti: Conto[], id: number): Conto | null {
  for (const conto of conti) {
    if (conto.id === id) return conto;
    if (conto.sottoconti && conto.sottoconti.length) {
      const sottoconto = trovaContoInArray(conto.sottoconti, id);
      if (sottoconto) return sottoconto;
    }
  }
  return null;
}

watch(
  () => props.conti,
  (newConti) => {
    if (contoSelezionato.value) {
      const contoAggiornato = trovaContoInArray(newConti, contoSelezionato.value.id);
      if (contoAggiornato) {
        contoSelezionato.value = contoAggiornato;
      } else {
        contoSelezionato.value = null;
      }
    }
  },
  { deep: true }
);

const selezionaConto       = (conto: Conto) => { contoSelezionato.value = conto }
const modificaConto        = (conto: Conto) => { contoSelezionato.value = conto; showModalEdit.value = true }
const confermaEliminazione = (conto: Conto) => { contoDaEliminare.value = conto; showModalDelete.value = true }

const confermaRimozioneTabella = (payload: { conto: Conto, tabellaId: number }) => {
  contoSelezionato.value        = payload.conto
  tabellaDaRimuovere.value      = payload.tabellaId
  showModalRimuoviTabella.value = true
}

const onModificaTabella = (payload: { conto: Conto, tabella: TabellaAssociata }) => {
  contoSelezionato.value        = payload.conto
  tabellaDaModificare.value     = payload.tabella
  showModalAssociaTabella.value = true
}

const onAggiungiTabella = (conto: Conto) => {
  contoSelezionato.value        = conto
  tabellaDaModificare.value     = null
  showModalAssociaTabella.value = true
}

const onChiudiModalAssociaTabella = (val: boolean) => {
  showModalAssociaTabella.value = val
  if (!val) tabellaDaModificare.value = null
}

// Ordinamento dell'elenco. Il default resta "nome", cioè il comportamento storico:
// il codice è un campo nullable e senza formato imposto, quindi renderlo criterio
// unico romperebbe l'elenco a chi non lo compila. La scelta è una preferenza di
// visualizzazione e vive nel browser, per condominio — non serve un campo in
// database né una voce nelle impostazioni.
type CriterioOrdinamento = 'nome' | 'codice'

const chiaveOrdinamento = `km.ordinamento-conti.${props.condominio.id}`;

const ordinamento = ref<CriterioOrdinamento>(
  (typeof window !== 'undefined' && window.localStorage.getItem(chiaveOrdinamento) === 'codice')
    ? 'codice'
    : 'nome'
)

const impostaOrdinamento = (criterio: CriterioOrdinamento) => {
  ordinamento.value = criterio
  if (typeof window !== 'undefined') {
    window.localStorage.setItem(chiaveOrdinamento, criterio)
  }
}

// Quante voci hanno davvero un codice: senza nessuna, offrire l'ordinamento per
// codice sarebbe un pulsante che non fa nulla di visibile.
const vociConCodice = computed(() => {
  const conta = (elenco: Conto[]): number => elenco.reduce((tot, c) => {
    const suo = (c.codice ?? '').trim() !== '' ? 1 : 0
    return tot + suo + (c.sottoconti?.length ? conta(c.sottoconti) : 0)
  }, 0)

  return conta(props.conti)
})

const contiPreventivo = computed(() => props.conti.filter(c => !c.is_tecnico))
const contiTecnici    = computed(() => props.conti.filter(c => c.is_tecnico))

// Residuo disponibile per il conto selezionato
const residuoDisponibile = computed(() => {
  if (!contoSelezionato.value?.tabelle_millesimali) return 100
  const somma = contoSelezionato.value.tabelle_millesimali.reduce(
    (acc, tm) => acc + (tm.coefficiente ?? 0), 0
  )
  return Math.max(0, 100 - somma)
})

// ID delle tabelle già associate al conto selezionato (per filtrare il dropdown)
const tabelleGiaAssociateIds = computed(() =>
  contoSelezionato.value?.tabelle_millesimali?.map(tm => tm.tabella_id) ?? []
)

const eliminaConto = () => {
  if (!contoDaEliminare.value) return
  router.delete(route('admin.gestionale.esercizi.piani-conti.conti.destroy', {
    condominio: props.condominio.id,
    esercizio:  props.esercizio.id,
    pianoConto: props.pianoConti.id,
    conto:      contoDaEliminare.value.id
  }), {
    preserveScroll: true,
    onSuccess: () => { contoSelezionato.value = null; contoDaEliminare.value = null; showModalDelete.value = false },
    onError:   () => { showModalDelete.value = false }
  })
}

const annullaEliminazione     = () => { contoDaEliminare.value = null; showModalDelete.value = false }
const annullaRimozioneTabella = () => { tabellaDaRimuovere.value = null; showModalRimuoviTabella.value = false }
const onModificaSuccess       = () => { showModalEdit.value = false }

const gestisciTabella = (dati: any) => {
  if (!contoSelezionato.value) return

  if (dati._isEdit && dati._tabellaId) {
    router.put(
      route('admin.gestionale.esercizi.piani-conti.conti.aggiorna-tabella', {
        condominio: props.condominio.id,
        esercizio:  props.esercizio.id,
        pianoConto: props.pianoConti.id,
        conto:      contoSelezionato.value.id,
        tabella:    dati._tabellaId,
      }),
      {
        coefficiente:              dati.coefficiente,
        percentuale_proprietario:  dati.percentuale_proprietario,
        percentuale_inquilino:     dati.percentuale_inquilino,
        percentuale_usufruttuario: dati.percentuale_usufruttuario,
      },
      {
        preserveScroll: true,
        onSuccess: () => { showModalAssociaTabella.value = false; tabellaDaModificare.value = null },
      }
    )
  } else {
    router.post(
      route('admin.gestionale.esercizi.piani-conti.conti.associa-tabella', {
        condominio: props.condominio.id,
        esercizio:  props.esercizio.id,
        pianoConto: props.pianoConti.id,
        conto:      contoSelezionato.value.id,
      }),
      {
        tabella_millesimale_id:    dati.tabella_millesimale_id,
        coefficiente:              dati.coefficiente,
        percentuale_proprietario:  dati.percentuale_proprietario,
        percentuale_inquilino:     dati.percentuale_inquilino,
        percentuale_usufruttuario: dati.percentuale_usufruttuario,
      },
      {
        preserveScroll: true,
        onSuccess: () => { showModalAssociaTabella.value = false },
      }
    )
  }
}

const rimuoviTabella = () => {
  if (!contoSelezionato.value || !tabellaDaRimuovere.value) return
  router.delete(route('admin.gestionale.esercizi.piani-conti.conti.dissocia-tabella', {
    condominio: props.condominio.id,
    esercizio:  props.esercizio.id,
    pianoConto: props.pianoConti.id,
    conto:      contoSelezionato.value.id,
    tabella:    tabellaDaRimuovere.value
  }), {
    preserveScroll: true,
    onSuccess: () => { showModalRimuoviTabella.value = false; tabellaDaRimuovere.value = null }
  })
}
const printDistinta = () => {
  window.open(route('admin.gestionale.esercizi.piani-conti.print-distinta', {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id,
    pianoConto: props.pianoConti.id,
    ordina: ordinamento.value
  }), '_blank');
}

const printRiparto = () => {
  window.open(route('admin.gestionale.esercizi.piani-conti.print-riparto', {
    condominio: props.condominio.id,
    esercizio: props.esercizio.id,
    pianoConto: props.pianoConti.id
  }), '_blank');
}
</script>

<template>
  <Head title="Gestione conto" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        page-title="Gestione spese"
        :page-subtitle="props.pianoConti.nome"
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :back-url="generatePath('gestionale/:condominio/esercizi/:esercizio/piani-conti', { condominio: props.condominio.id, esercizio: props.esercizio.id })"
        back-text="Torna ai piani dei conti"
        :text-guides="textGuidesList"
        @open-text-guide="openGuide"
      >
        <template #actions>
          <div class="flex items-center gap-2">
            <DropdownMenu>
              <DropdownMenuTrigger as-child>
                <button class="inline-flex h-8 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-3 shadow-sm hover:bg-slate-50 text-[10px] font-bold uppercase tracking-widest text-slate-700 transition-colors">
                  <Printer class="w-3.5 h-3.5" />
                  <span class="hidden sm:inline">Stampe</span>
                  <ChevronDown class="w-3 h-3 opacity-60" />
                </button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" class="w-48 shadow-xl rounded-xl border-slate-100 p-1.5">
                <DropdownMenuLabel class="text-[10px] text-slate-400 uppercase tracking-widest px-2 py-1.5 font-bold">
                  Documenti
                </DropdownMenuLabel>
                <DropdownMenuSeparator class="bg-slate-100" />
                <DropdownMenuItem
                  @click="printDistinta"
                  class="cursor-pointer flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-indigo-50 focus:bg-indigo-50 text-slate-700"
                >
                  <Printer class="w-3.5 h-3.5 text-indigo-500" />
                  <div>
                    <div class="text-xs font-medium">Distinta base</div>
                  </div>
                </DropdownMenuItem>
                <DropdownMenuItem
                  @click="printRiparto"
                  class="cursor-pointer flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-indigo-50 focus:bg-indigo-50 text-slate-700"
                >
                  <Printer class="w-3.5 h-3.5 text-indigo-500" />
                  <div>
                    <div class="text-xs font-medium">Ripartizione spese</div>
                  </div>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>

            <button
              @click="showModalNew = true"
              class="inline-flex h-8 items-center justify-center gap-2 rounded-md shadow px-4 bg-primary text-[10px] font-bold uppercase tracking-widest text-primary-foreground hover:bg-primary/90 transition-colors"
            >
              <Plus class="w-3.5 h-3.5" />
              <span>Aggiungi Voce</span>
            </button>
          </div>
        </template>
      </PageHeaderGuide>

      <div class="w-full">
        <section class="w-full">
          <div v-if="flashMessage" class="py-3">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">

            <!-- ELENCO CONTI -->
            <div class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm overflow-hidden">
              <!-- Titolo e totali su righe separate: affiancati, con tre badge, il
                   titolo andava a capo e i numeri si accalcavano nel pannello stretto. -->
              <div class="p-4 border-b border-gray-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Elenco conti e sottoconti</h3>
                <div class="flex flex-wrap items-center gap-2 mt-3">
                  <span v-if="contiTecnici.length > 0"
                        class="bg-amber-50 text-amber-700 border border-amber-200 text-[10px] font-bold px-2 py-1 rounded-md flex items-center gap-1 shadow-sm">
                    <AlertTriangle class="w-3 h-3" />
                    Sopravvenienze: {{ euro(props.totaleSopravvenienze) }}
                  </span>
                  <span class="bg-indigo-50 text-indigo-700 border border-indigo-200 text-[10px] font-bold px-2 py-1 rounded-md flex items-center gap-1 shadow-sm">
                    <Wallet class="w-3 h-3" />
                    Preventivo: {{ euro(props.totalePreventivo) }}
                  </span>
                  <span v-if="(props.totaleEntrate ?? 0) > 0"
                        class="bg-sky-50 text-sky-700 border border-sky-200 text-[10px] font-bold px-2 py-1 rounded-md flex items-center gap-1 shadow-sm"
                        :title="`Le entrate si sottraggono alle spese: da ripartire ${euro(props.totaleDaRipartire ?? 0)}.`">
                    <TrendingUp class="w-3 h-3" />
                    Entrate: {{ euro(props.totaleEntrate ?? 0) }}
                  </span>
                  <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold px-2 py-1 rounded-md flex items-center gap-1 shadow-sm">
                    <Receipt class="w-3 h-3" />
                    Consuntivo: {{ euro(props.totaleConsuntivo) }}
                  </span>
                </div>
                <!-- Una voce di spesa scritta con il meno: la riga dice il meno e il preventivo la conta in positivo. La frase la nomina. -->
                <div v-if="(props.speseNegative ?? []).length > 0" class="mt-3 space-y-1.5">
                  <p v-for="voce in props.speseNegative" :key="voce.id"
                     class="flex gap-2 text-xs leading-relaxed text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 dark:text-amber-300 dark:bg-amber-950/40 dark:border-amber-900/60">
                    <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5" />
                    <span><template v-for="(pezzo, i) in fraseDellaSpesaNegativa(voce)" :key="i"><Link v-if="'piano' in pezzo" :href="paginaDelPiano(pezzo.piano.id)" class="font-semibold underline underline-offset-2 hover:text-amber-950 dark:hover:text-amber-100">{{ pezzo.piano.nome }}</Link><template v-else>{{ pezzo.testo }}</template></template></span>
                  </p>
                </div>
              </div>

              <div class="flex items-center px-5 py-1.5 bg-slate-50/70 dark:bg-slate-900/40 border-b border-slate-100 dark:border-slate-800">
                <span class="flex-1 text-[9px] font-semibold uppercase tracking-wider text-slate-400 flex items-center gap-2">
                  Voce di spesa
                  <!-- Il selettore compare solo se almeno una voce ha un codice:
                       altrove sarebbe un pulsante che non cambia nulla di visibile. -->
                  <span v-if="vociConCodice > 0" class="flex items-center rounded-md border border-slate-200 dark:border-slate-700 overflow-hidden normal-case tracking-normal">
                    <button
                      type="button"
                      class="px-1.5 py-0.5 text-[9px] font-semibold transition-colors"
                      :class="ordinamento === 'nome' ? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-100' : 'text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'"
                      title="Ordina per nome"
                      @click="impostaOrdinamento('nome')"
                    >Nome</button>
                    <button
                      type="button"
                      class="px-1.5 py-0.5 text-[9px] font-semibold transition-colors"
                      :class="ordinamento === 'codice' ? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-100' : 'text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800'"
                      title="Ordina per codice — le voci senza codice vanno in fondo"
                      @click="impostaOrdinamento('codice')"
                    >Codice</button>
                  </span>
                </span>
                <span class="w-24 text-right text-[9px] font-semibold uppercase tracking-wider text-slate-400">Preventivo</span>
                <span class="w-24 text-right text-[9px] font-semibold uppercase tracking-wider text-slate-400 ml-1.5">Consuntivo</span>
              </div>

              <div class="pl-2 pt-4 pr-2 max-h-[600px] overflow-y-auto">
                <div class="mb-2">
                  <div class="flex items-center gap-2 px-3 py-2">
                    <Lock class="w-3.5 h-3.5 text-indigo-500" />
                    <span class="text-[10px] font-bold uppercase tracking-widest text-indigo-600">Preventivo deliberato</span>
                  </div>
                  <div class="bg-indigo-50/30 rounded-lg mx-1 mb-2">
                    <AlberoDeiConti
                      :conti="contiPreventivo"
                      :selected-id="contoSelezionato?.id"
                      :ordinamento="ordinamento"
                      @seleziona="selezionaConto"
                      @apri-movimenti="apriMovimenti"
                    />
                  </div>
                </div>

                <div v-if="contiTecnici.length > 0" class="mt-4 pt-3 border-t border-dashed border-amber-200">
                  <div class="flex items-center gap-2 px-3 py-2 mb-1">
                    <AlertTriangle class="w-3.5 h-3.5 text-amber-500" />
                    <span class="text-[10px] font-bold uppercase tracking-widest text-amber-600">Sopravvenienze e imprevisti</span>
                    <span class="text-[9px] text-amber-500 font-medium">(fuori preventivo)</span>
                  </div>
                  <div class="bg-amber-50/30 rounded-lg mx-1 mb-2">
                    <AlberoDeiConti
                      :conti="contiTecnici"
                      :selected-id="contoSelezionato?.id"
                      :ordinamento="ordinamento"
                      @seleziona="selezionaConto"
                      @apri-movimenti="apriMovimenti"
                    />
                  </div>
                </div>
              </div>
            </div>

            <!-- DETTAGLIO CONTO -->
            <div class="bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-2xl shadow-sm overflow-hidden">
              <div class="p-4 border-b border-gray-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Dettagli voce selezionata</h3>
              </div>
              <div class="p-2">
                <DettaglioConto
                  :conto="contoSelezionato"
                  :condominio-id="props.condominio.id"
                  :esercizio-id="props.esercizio.id"
                  :gestione-id="props.pianoConti.gestione?.id ?? null"
                  @elimina="confermaEliminazione"
                  @modifica="modificaConto"
                  @aggiungi-tabella="onAggiungiTabella"
                  @modifica-tabella="onModificaTabella"
                  @rimuovi-tabella="confermaRimozioneTabella"
                />
              </div>
            </div>

          </div>
        </section>
      </div>
    </div>

    <ModalNuovoConto
      :show="showModalNew"
      :condominio-id="props.condominio.id"
      :esercizio-id="props.esercizio.id"
      :piano-conto-id="props.pianoConti.id"
      :fornitori="props.fornitori"
      @update:show="showModalNew = $event"
    />

    <ModalAssociaTabella
      :show="showModalAssociaTabella"
      :conto="contoSelezionato"
      :condominio-id="props.condominio.id"
      :tabella-esistente="tabellaDaModificare"
      :residuo-disponibile="residuoDisponibile"
      :tabelle-gia-associate-ids="tabelleGiaAssociateIds"
      @update:show="onChiudiModalAssociaTabella"
      @success="gestisciTabella"
    />

    <ModalModificaConto
      :show="showModalEdit"
      :conto="contoSelezionato"
      :condominio-id="props.condominio.id"
      :esercizio-id="props.esercizio.id"
      :piano-conto-id="props.pianoConti.id"
      :tabelle="props.tabelle"
      :fornitori="props.fornitori"
      @update:show="showModalEdit = $event"
      @success="onModificaSuccess"
    />

    <ConfirmDialog
      v-model:modelValue="showModalRimuoviTabella"
      title="Rimuovi tabella associata"
      description="Sei sicuro di voler rimuovere questa tabella millesimale dal conto?"
      confirm-text="Rimuovi"
      cancel-text="Annulla"
      variant="destructive"
      @confirm="rimuoviTabella"
      @cancel="annullaRimozioneTabella"
    />

    <ConfirmDialog
      v-model:modelValue="showModalDelete"
      title="Sei sicuro di voler eliminare"
      description="Questa azione non è reversibile. Il conto verrà eliminato permanentemente."
      confirm-text="Elimina"
      cancel-text="Annulla"
      variant="destructive"
      @confirm="eliminaConto"
      @cancel="annullaEliminazione"
    />

    <MovimentiVoceDialog
      v-model:open="showModalMovimenti"
      :conto="contoMovimenti"
      :condominio-id="props.condominio.id"
      :esercizio-id="props.esercizio.id"
    />

  </GestionaleLayout>

  <RipartoUsufruttoGuide v-model:open="showGuideUsufrutto" />
  <OperazioniContiGuide v-model:open="showGuideOperazioni" />
  <GiaVersatoGuide v-model:open="showGuideGiaVersato" />

</template>