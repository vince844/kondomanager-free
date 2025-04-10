<script setup lang="ts">

import { watch, onMounted } from "vue";
import { Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, List, Pencil } from 'lucide-vue-next';
import vSelect from "vue-select";
import { Separator } from '@/components/ui/separator';
import type { Building } from '@/types/buildings';
import type { Segnalazione } from '@/types/segnalazioni';
import type { Anagrafica } from '@/types/anagrafiche';
import type { PriorityType, StatoType, PublishedType } from '@/types/segnalazioni';
import { priorityConstants, statoConstants, publishedConstants } from '@/lib/segnalazioni/constants';
import '@vuepic/vue-datepicker/dist/main.css';

const props = defineProps<{
  segnalazione: Segnalazione;
}>();  

const form = useForm({
    subject: props.segnalazione?.subject,
    description: props.segnalazione?.description,
    priority: props.segnalazione?.priority,
    stato: props.segnalazione?.stato,
    condominio_id: props.segnalazione?.condominio?.id,
    can_comment: !!props.segnalazione?.can_comment,
    is_featured: !!props.segnalazione?.is_featured,
    is_published: !!props.segnalazione?.is_published,
    anagrafiche: props.segnalazione?.anagrafiche.map(anagrafica => anagrafica.id) || [],
});

</script>


<template>

    <Head title="Visualizza segnalazione guasto" />
  
    <AppLayout >
  
      <div class="px-4 py-6">
        
        <Heading title="Modifica segnalazione guasto" description="Compila il seguente modulo per modificare segnalazione guasto" />

            <form class="space-y-2" @submit.prevent="submit">

                <!-- Container for buttons (wraps buttons for alignment) -->
                <div class="flex flex-col lg:flex-row lg:justify-end space-y-2 lg:space-y-0 lg:space-x-2 items-start lg:items-center">

                    <!-- Button for "Update Segnalazione" -->
                    <Button :disabled="form.processing" class="lg:flex h-8 w-full lg:w-auto">
                        <Pencil class="w-4 h-4" v-if="!form.processing" />
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        Modifica
                    </Button>

                    <!-- Button for "Elenco Segnalazioni" -->
                    <Button class="lg:flex h-8 w-full lg:w-auto">
                        <List class="w-4 h-4" />
                        <Link :href="route('admin.segnalazioni.index')" class="block lg:inline">
                        Elenco 
                        </Link>
                    </Button>

                </div>

                <!-- Two-column layout (3:1 ratio) -->
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-3 ">

                    <!-- Main Card (3/4 width) -->
                    <div class="col-span-1 lg:col-span-3 mt-3">
                        <div class="bg-white dark:bg-muted rounded shadow-sm p-6 space-y-4 border">
                            
                           dettagli ticket

                        </div>
                    </div>

                    <!-- Side Card (1/4 width) -->
                    <div class="col-span-1 mt-3">
                        <div class="bg-white dark:bg-muted rounded shadow-sm p-3 border">

                            altri dettagli
                            
                        </div>
                    </div>
                
                </div>

            </form>
      </div>
    </AppLayout> 
  
  </template>

<style src="vue-select/dist/vue-select.css"></style>