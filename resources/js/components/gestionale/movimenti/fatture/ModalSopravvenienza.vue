<script setup lang="ts">
import { ref, watch } from 'vue';
import { Label } from '@/components/ui/label';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Scale } from 'lucide-vue-next';
import vSelect from 'vue-select';
import { useTabelle } from '@/composables/useTabelle';

const props = defineProps<{
    show: boolean;
    fornitoreNome: string;
    condominioId: number;
}>();

const emit = defineEmits<{
    (e: 'update:show', value: boolean): void;
    (e: 'confirm', payload: any): void;
}>();

const { tabelle, isLoading: isLoadingTabelle, fetchTabelle } = useTabelle();

const datiSopravvenienza = ref({
    nome_voce: '',
    autorizzazione: 'urgenza',
    data_assemblea: '',
    note: '',
    tabella_millesimale_id: null as number | null,
    percentuale_proprietario: 100,
    percentuale_inquilino: 0,
    percentuale_usufruttuario: 0,
});

const onDropdownTabelleOpen = () => {
    if (tabelle.value.length === 0) {
        fetchTabelle(props.condominioId);
    }
};

watch(() => props.show, (isOpen) => {
    if (isOpen) {
        datiSopravvenienza.value = {
            nome_voce: `Integrazione debito pregresso - ${props.fornitoreNome}`,
            autorizzazione: 'urgenza',
            data_assemblea: '',
            note: '',
            tabella_millesimale_id: null,
            percentuale_proprietario: 100,
            percentuale_inquilino: 0,
            percentuale_usufruttuario: 0,
        };
    }
});

const handleCancel = () => {
    emit('update:show', false);
};

const handleConfirm = () => {
    if (datiSopravvenienza.value.nome_voce.length < 5 || !datiSopravvenienza.value.tabella_millesimale_id) return;
    emit('confirm', { ...datiSopravvenienza.value });
    emit('update:show', false);
};
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-slate-200 dark:border-slate-800">
                <div class="bg-amber-50 dark:bg-amber-950/30 p-7 border-b border-amber-100 dark:border-amber-900/30 flex items-start gap-4">
                    <div class="bg-amber-100 dark:bg-amber-900/50 p-2.5 rounded-xl shrink-0">
                        <Scale class="w-6 h-6 text-amber-600" />
                    </div>
                    <div>
                        <h3 class="font-black text-amber-900 dark:text-amber-100 text-lg">Integrazione Spesa Rilevata</h3>
                        <p class="text-xs text-amber-700/70 mt-1">Stai per creare una nuova voce di spesa fuori preventivo.</p>
                    </div>
                </div>
                <div class="p-7 space-y-5">
                    <div class="space-y-1.5">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Nome Nuova Voce di Spesa *</Label>
                        <Input v-model="datiSopravvenienza.nome_voce" class="h-10 text-sm font-semibold" />
                    </div>

                    <div class="space-y-3">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Autorizzazione Legale *</Label>
                        <label class="flex items-start gap-3 p-3 border rounded-xl cursor-pointer transition-colors" :class="datiSopravvenienza.autorizzazione === 'urgenza' ? 'border-amber-400 bg-amber-50/50 dark:bg-amber-900/10' : 'border-slate-200 hover:bg-slate-50'">
                            <input type="radio" v-model="datiSopravvenienza.autorizzazione" value="urgenza" class="mt-1 w-4 h-4 text-amber-600 focus:ring-amber-500" />
                            <div>
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-200">Intervento d'Urgenza (Art. 1135 c.c.)</p>
                                <p class="text-[10px] text-slate-500 mt-0.5">Rischio decreto. Da ratificare alla prossima assemblea.</p>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 p-3 border rounded-xl cursor-pointer transition-colors" :class="datiSopravvenienza.autorizzazione === 'assemblea' ? 'border-emerald-400 bg-emerald-50/50 dark:bg-emerald-900/10' : 'border-slate-200 hover:bg-slate-50'">
                            <input type="radio" v-model="datiSopravvenienza.autorizzazione" value="assemblea" class="mt-1 w-4 h-4 text-emerald-600 focus:ring-emerald-500" />
                            <div class="w-full">
                                <p class="text-sm font-bold text-slate-800 dark:text-slate-200">Delibera Assembleare</p>
                                <Input v-if="datiSopravvenienza.autorizzazione === 'assemblea'" type="date" v-model="datiSopravvenienza.data_assemblea" class="h-8 text-xs w-full mt-2" />
                            </div>
                        </label>
                    </div>

                    <div class="space-y-1.5 border-t border-slate-100 dark:border-slate-800 pt-4">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Tabella di Ripartizione *</Label>
                        <v-select
                            :options="tabelle"
                            label="nome"
                            v-model="datiSopravvenienza.tabella_millesimale_id"
                            placeholder="Seleziona tabella millesimale..."
                            :reduce="(t: any) => t.id"
                            @open="onDropdownTabelleOpen"
                            :loading="isLoadingTabelle"
                            class="style-chooser text-sm"
                        />
                    </div>
                    <div class="grid grid-cols-3 gap-3 bg-slate-50 dark:bg-slate-800/50 p-3 rounded-xl border border-slate-100 dark:border-slate-700">
                        <div>
                            <Label class="text-[10px] text-slate-500 font-bold uppercase block mb-1">Proprietario %</Label>
                            <Input type="number" v-model="datiSopravvenienza.percentuale_proprietario" class="h-8 text-sm" />
                        </div>
                        <div>
                            <Label class="text-[10px] text-slate-500 font-bold uppercase block mb-1">Inquilino %</Label>
                            <Input type="number" v-model="datiSopravvenienza.percentuale_inquilino" class="h-8 text-sm" />
                        </div>
                        <div>
                            <Label class="text-[10px] text-slate-500 font-bold uppercase block mb-1">Usufruttuario %</Label>
                            <Input type="number" v-model="datiSopravvenienza.percentuale_usufruttuario" class="h-8 text-sm" />
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <Label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Note Log (Audit Trail)</Label>
                        <textarea v-model="datiSopravvenienza.note" rows="2" class="w-full border border-slate-200 dark:border-slate-700 rounded-xl p-3 text-sm bg-slate-50 dark:bg-slate-800 outline-none resize-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-400 transition-all" />
                    </div>

                    <div class="flex gap-3 pt-2">
                        <Button variant="outline" class="flex-1 h-11 rounded-xl font-bold" @click="handleCancel">Annulla</Button>
                        <Button class="flex-1 h-11 rounded-xl bg-amber-600 hover:bg-amber-700 text-white font-black" :disabled="datiSopravvenienza.nome_voce.length < 5 || !datiSopravvenienza.tabella_millesimale_id" @click="handleConfirm">Crea Voce e Registra</Button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>