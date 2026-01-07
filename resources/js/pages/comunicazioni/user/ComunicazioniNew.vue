<script setup lang="ts">

import {Head, useForm, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { LoaderCircle, Plus, List, Info } from 'lucide-vue-next';
import vSelect from "vue-select";
import { trans } from 'laravel-vue-i18n';
import { Separator } from '@/components/ui/separator';
import { priorityConstants } from '@/lib/comunicazioni/constants';
import { usePermission } from "@/composables/permissions";
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import type { Building } from '@/types/buildings';
import type { PriorityType } from '@/types/comunicazioni';
import '@vuepic/vue-datepicker/dist/main.css';

const { generateRoute } = usePermission();

const props = defineProps<{
  condomini: Building[];
}>();  

const form = useForm({
    subject: '',
    description: '',
    priority: '',
    condomini_ids: [],
    is_featured: false as boolean,
    is_private: false as boolean,
});

const submit = () => {
    form.post(route(generateRoute('comunicazioni.store')), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>

    <Head :title="trans('comunicazioni.header.new_communication_head')" />
  
    <AppLayout >
  
      <div class="px-4 py-6">
        
        <Heading 
            :title="trans('comunicazioni.header.new_communication_title')" 
            :description="trans('comunicazioni.header.new_communication_description')" 
        />

        <form class="space-y-2" @submit.prevent="submit">

            <!-- Container for buttons (wraps buttons for alignment) -->
            <div class="flex flex-col lg:flex-row lg:justify-end space-y-2 lg:space-y-0 lg:space-x-2 items-start lg:items-center">

                <Button :disabled="form.processing" class="lg:flex h-8 w-full lg:w-auto">
                    <Plus class="w-4 h-4" v-if="!form.processing" />
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{trans('comunicazioni.actions.save_communication')}}
                </Button>

                <Button type="button" class="lg:flex h-8 w-full lg:w-auto">
                    <List class="w-4 h-4" />
                    <Link :href="route(generateRoute('comunicazioni.index'))" class="block lg:inline">
                     {{ trans('comunicazioni.actions.list_communications') }}
                    </Link>
                </Button>

            </div>
        
            <!-- Two-column layout (3:1 ratio) -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-3">

                <!-- Main Card (3/4 width) -->
                <div class="col-span-1 lg:col-span-3 mt-3">
                    <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border">
                        
                        <!--  subject field -->
                        <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                            <div class="sm:col-span-3">
                                <Label for="subject">{{ trans('comunicazioni.label.subject') }}</Label>
                                <Input 
                                    id="subject" 
                                    class="mt-1 block w-full"
                                    v-model="form.subject" 
                                    v-on:focus="form.clearErrors('subject')"
                                    :placeholder="trans('comunicazioni.placeholder.subject')" 
                                />
                                
                                <InputError :message="form.errors.subject" />
                    
                            </div>                           
                        </div> 

                        <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="description">{{ trans('comunicazioni.label.description') }}</Label>
                                <Textarea 
                                    id="description" 
                                    class="mt-1 block w-full min-h-[320px]"
                                    v-model="form.description" 
                                    v-on:focus="form.clearErrors('description')"
                                    :placeholder="trans('comunicazioni.placeholder.description')" 
                                />
                                
                                <InputError :message="form.errors.description" />
                    
                            </div>  
                        </div> 

                    </div>
                </div>

                <!-- Side Card (1/4 width) -->
                <div class="col-span-1 mt-3">
                    <div class="bg-white dark:bg-muted rounded shadow-sm p-3 border">

                        <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                                    <Label for="priority">{{ trans('comunicazioni.label.priority') }}</Label>

                                    <HoverCard>
                                        <HoverCardTrigger as-child>
                                        <button type="button" class="cursor-pointer">
                                            <Info class="w-4 h-4 text-muted-foreground" />
                                        </button>
                                        </HoverCardTrigger>
                                        <HoverCardContent class="w-80">
                                        <div class="flex justify-between space-x-4">
                                            <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">{{ trans('comunicazioni.label.priority') }}</h4>
                                            <p class="text-sm">
                                                {{ trans('comunicazioni.tooltip.priority') }}
                                            </p>
                                            </div>
                                        </div>
                                        </HoverCardContent>
                                    </HoverCard>
                                </div>

                                <v-select 
                                    id="priority" 
                                    :options="priorityConstants" 
                                    label="label" 
                                    v-model="form.priority"
                                    :placeholder="trans('comunicazioni.placeholder.priority')" 
                                    @update:modelValue="form.clearErrors('priority')" 
                                    :reduce="(priority: PriorityType) => priority.value"
                                >
                                    <!-- Dropdown list items -->
                                    <template #option="{ label, icon }">
                                        <div class="flex items-center gap-2">
                                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                        <span>{{ trans(label) }}</span>
                                        </div>
                                    </template>

                                    <!-- Selected option display -->
                                    <template #selected-option="{ label, icon }">
                                        <div class="flex items-center gap-2">
                                        <component :is="icon" class="w-4 h-4 text-muted-foreground" />
                                        <span>{{ trans(label) }}</span>
                                        </div>
                                    </template>
                                </v-select>

                                <InputError :message="form.errors.priority" />
                    
                            </div>
                        </div>

                        <div class="pt-3 grid grid-cols-1 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="condomini">{{ trans('comunicazioni.label.buildings') }}</Label>

                                <v-select 
                                    multiple
                                    :options="condomini" 
                                    label="label" 
                                    v-model="form.condomini_ids"
                                    :placeholder="trans('comunicazioni.placeholder.buildings')"
                                    @update:modelValue="form.clearErrors('condomini_ids')" 
                                    :reduce="(condomini: Building) => condomini.value"
                                />

                                <InputError :message="form.errors.condomini_ids" />
                    
                            </div>
                        </div>

                        <Separator class="my-4" />

                        <div class="grid grid-cols-1 sm:grid-cols-6">
                            <div class="flex items-center space-x-2 sm:col-span-6">
                                <Checkbox 
                                    class="size-4" 
                                    :checked="form.is_private"
                                    v-model="form.is_private" 
                                    id="is_private" 
                                    @update:checked="(val: boolean) => form.is_private = val" 
                                    />
                                <label
                                    for="is_private"
                                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-7 flex items-center"
                                    >
                                     {{trans('comunicazioni.label.private')}}

                                    <HoverCard>
                                        <HoverCardTrigger as-child>
                                            <Info class="w-4 h-4 text-muted-foreground cursor-pointer ml-2" />
                                        </HoverCardTrigger>
                                        <HoverCardContent class="w-80">
                                        <div class="flex justify-between space-x-4">
                                            <div class="space-y-1">
                                                <h4 class="text-sm font-semibold">
                                                    {{trans('comunicazioni.label.private')}}
                                                </h4>
                                                <p class="text-sm">
                                                    {{trans('comunicazioni.tooltip.private')}}
                                                </p>
                                            </div>
                                        </div>
                                        </HoverCardContent>
                                    </HoverCard>

                                </label>
                            </div>
                        </div>

                        <div class="pt-4 grid grid-cols-1 sm:grid-cols-6">
                            <div class="flex items-center space-x-2 sm:col-span-6">
                                <Checkbox 
                                    class="size-4" 
                                    :checked="form.is_featured"
                                    v-model="form.is_featured" 
                                    id="is_private" 
                                    @update:checked="(val: boolean) => form.is_featured = val" 
                                    />
                                <label
                                    for="is_featured"
                                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-7 flex items-center"
                                    >
                                     {{trans('comunicazioni.label.featured')}}

                                    <HoverCard>
                                        <HoverCardTrigger as-child>
                                            <Info class="w-4 h-4 text-muted-foreground cursor-pointer ml-2" />
                                        </HoverCardTrigger>
                                        <HoverCardContent class="w-80">
                                        <div class="flex justify-between space-x-4">
                                            <div class="space-y-1">
                                                <h4 class="text-sm font-semibold">
                                                    {{trans('comunicazioni.label.featured')}}
                                                </h4>
                                                <p class="text-sm">
                                                    {{trans('comunicazioni.tooltip.featured')}}
                                                </p>
                                            </div>
                                        </div>
                                        </HoverCardContent>
                                    </HoverCard>

                                </label>
                            </div>
                        </div>

                    </div>
                </div>
            
            </div>

        </form>

      </div>
      
    </AppLayout> 
  
</template>

<style src="vue-select/dist/vue-select.css"></style>