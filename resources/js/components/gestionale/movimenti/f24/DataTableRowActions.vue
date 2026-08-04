<script setup lang="ts">
/**
 * Le azioni di riga dello scadenzario F24.
 *
 * Stessa forma delle altre tabelle del gestionale — i tre puntini, il menu a tendina — così
 * il gesto è quello che l'amministratore già conosce. Le voci disponibili dipendono dallo
 * stato: una delega versata non si annulla, una in bozza non si storna.
 *
 * Il **motivo** dei divieti non è nascosto: le voci impossibili semplicemente non compaiono,
 * ma quelle bloccate da una condizione restano visibili e disabilitate col perché nel
 * tooltip. È la regola imparata nella beta.34 — il divieto muto.
 */
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Ban, Eye, FileText, MoreHorizontal, Printer, RotateCcw } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';

const props = defineProps<{ delega: any; condominioId: number }>();

const { generateRoute } = usePermission();

const isVersata = computed(() => props.delega.stato === 'versata');
const isAperta = computed(() => ['bozza', 'confermata'].includes(props.delega.stato));

const vaiAlDettaglio = () => {
    router.visit(route(generateRoute('gestionale.f24.show'), {
        condominio: props.condominioId, delega: props.delega.id,
    }));
};

const stampa = () => {
    router.visit(
        route(generateRoute('gestionale.f24.show'), { condominio: props.condominioId, delega: props.delega.id }),
        { data: { stampa: 1 } },
    );
};

/**
 * Il modello ministeriale non passa dalla scheda: è un PDF che il server compone e apre in
 * una scheda nuova. Dall'elenco è il gesto più diretto — «questa la vado a pagare» — e
 * costringere a un passaggio sulla scheda per premere di nuovo un pulsante sarebbe attrito
 * senza motivo, come già si era deciso per il prospetto.
 */
const modelloF24 = () => {
    window.open(
        route(generateRoute('gestionale.f24.modello'), {
            condominio: props.condominioId,
            delega: props.delega.id,
        }),
        '_blank',
    );
};

// ── Storno ───────────────────────────────────────────────────────────────────

const modaleStorno = ref(false);
const formStorno = useForm({ motivo: '' });

const confermaStorno = () => {
    formStorno.post(
        route(generateRoute('gestionale.f24.storna'), { condominio: props.condominioId, delega: props.delega.id }),
        {
            preserveScroll: true,
            onSuccess: () => { modaleStorno.value = false; formStorno.reset(); },
        },
    );
};

// ── Annullamento ─────────────────────────────────────────────────────────────

const modaleAnnulla = ref(false);
const formAnnulla = useForm({ motivo: '' });

const confermaAnnulla = () => {
    formAnnulla.post(
        route(generateRoute('gestionale.f24.annulla'), { condominio: props.condominioId, delega: props.delega.id }),
        {
            preserveScroll: true,
            onSuccess: () => { modaleAnnulla.value = false; formAnnulla.reset(); },
        },
    );
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" class="h-8 w-8 p-0" @click.stop>
                <span class="sr-only">Apri il menu</span>
                <MoreHorizontal class="h-4 w-4" />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-52" @click.stop>
            <DropdownMenuItem @click="vaiAlDettaglio">
                <Eye class="mr-2 h-4 w-4" /> Apri il dettaglio
            </DropdownMenuItem>

            <DropdownMenuItem @click="modelloF24">
                <FileText class="mr-2 h-4 w-4" /> Modello F24 da pagare
            </DropdownMenuItem>

            <DropdownMenuItem @click="stampa">
                <Printer class="mr-2 h-4 w-4" /> Stampa il prospetto
            </DropdownMenuItem>

            <template v-if="isVersata || isAperta">
                <DropdownMenuSeparator />

                <DropdownMenuItem v-if="isVersata" class="text-rose-600" @click="modaleStorno = true">
                    <RotateCcw class="mr-2 h-4 w-4" /> Storna il versamento
                </DropdownMenuItem>

                <DropdownMenuItem v-if="isAperta" class="text-rose-600" @click="modaleAnnulla = true">
                    <Ban class="mr-2 h-4 w-4" /> Annulla la delega
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>

    <!-- Storno: il denaro rientra e il debito verso l'Erario torna aperto -->
    <Dialog v-model:open="modaleStorno">
        <DialogContent @click.stop>
            <DialogHeader>
                <DialogTitle>Stornare il versamento?</DialogTitle>
                <DialogDescription>
                    Verrà scritto il movimento uguale e contrario: il denaro rientra e il debito verso
                    l'Erario torna aperto. Le ritenute rientreranno nel prossimo calcolo delle scadenze.
                    Se l'Erario ha davvero incassato, il recupero va gestito a parte.
                </DialogDescription>
            </DialogHeader>

            <div>
                <Label class="text-xs font-bold uppercase tracking-wider text-slate-500">Motivo dello storno</Label>
                <Input v-model="formStorno.motivo" placeholder="Es. conto di addebito sbagliato" class="mt-1" />
                <p v-if="formStorno.errors.motivo || formStorno.errors.storno" class="mt-1 text-xs text-rose-600">
                    {{ formStorno.errors.motivo || formStorno.errors.storno }}
                </p>
            </div>

            <DialogFooter>
                <Button variant="ghost" @click="modaleStorno = false">Annulla</Button>
                <Button variant="destructive" :disabled="formStorno.processing" @click="confermaStorno">
                    Conferma storno
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Annullamento: nessun effetto contabile, la delega resta col suo motivo -->
    <Dialog v-model:open="modaleAnnulla">
        <DialogContent @click.stop>
            <DialogHeader>
                <DialogTitle>Annullare la delega?</DialogTitle>
                <DialogDescription>
                    La delega non viene cancellata — va conservata dieci anni — ma passa ad «annullata»
                    con il motivo che scrivi qui. Nessun movimento contabile: non è ancora stata versata.
                </DialogDescription>
            </DialogHeader>

            <div>
                <Label class="text-xs font-bold uppercase tracking-wider text-slate-500">Motivo</Label>
                <Input v-model="formAnnulla.motivo" placeholder="Es. ricalcolata dopo una correzione" class="mt-1" />
                <p v-if="formAnnulla.errors.motivo || formAnnulla.errors.annullamento" class="mt-1 text-xs text-rose-600">
                    {{ formAnnulla.errors.motivo || formAnnulla.errors.annullamento }}
                </p>
            </div>

            <DialogFooter>
                <Button variant="ghost" @click="modaleAnnulla = false">Chiudi</Button>
                <Button variant="destructive" :disabled="formAnnulla.processing" @click="confermaAnnulla">
                    Conferma
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
