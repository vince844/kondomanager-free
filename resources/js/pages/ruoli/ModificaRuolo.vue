<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import { watch, onMounted, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { LoaderCircle, Info } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import vSelect from 'vue-select';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { Separator } from '@/components/ui/separator';
import type { Permission } from '@/types/permissions';
import type { Role } from '@/types/roles';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
  role: Role;
  permissions: Permission[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'utenti', href: '/utenti' },
  { title: 'ruoli', href: '/ruoli' },
  { title: 'modifica ruolo', href: '#' },
];

const form = useForm({
  name: props.role.name,
  description: props.role.description,
  permissions: [] as Permission[],
  accessAdmin:
    props.role.permissions.some(
      permission => permission.name === 'Accesso pannello amministratore'
    ) || false,
});

const assignedPermissions = computed(() => props.role.permissions);

const selectablePermissions = computed(() => {
  const assignedIds = props.role.permissions.map(p => p.id);
  const selectedIds = form.permissions.map(p => p.id);

  return props.permissions.filter(
    permission =>
      !assignedIds.includes(permission.id) &&
      !selectedIds.includes(permission.id)
  );
});

onMounted(() => {
  // Preselect already assigned permissions (full objects)
  form.permissions = [...assignedPermissions.value];
});

watch(
  () => props.role,
  () => {
    form.permissions = [...props.role.permissions];
  }
);

const submit = () => {
    form.transform((data) => ({
        ...data,
        permissions: data.permissions.map(p => p.id)
    })).put(route("ruoli.update", {id: props.role.id}), {
        preserveScroll: true
    });
};
</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head title="Modifica ruolo" />

    <UtentiLayout>
      <form @submit.prevent="submit">
        <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
          <div class="sm:col-span-3">
            <Label for="name">Nome</Label>
            <Input
              id="name"
              class="mt-1 block w-full"
              v-model="form.name"
              @focus="form.clearErrors('name')"
              placeholder="Inserisci un nome per il ruolo"
            />
            <InputError :message="form.errors.name" />
          </div>

          <div class="sm:col-span-3">
            <Label for="description">Descrizione</Label>
            <Input
              id="description"
              class="mt-1 block w-full"
              v-model="form.description"
              @focus="form.clearErrors('description')"
              placeholder="Inserisci una descrizione per il ruolo"
            />
            <InputError :message="form.errors.description" />
          </div>
        </div>

        <div class="mt-6">
          <Label>Permessi</Label>
          <v-select
            multiple
            :options="selectablePermissions"
            label="name"
            :close-on-select="false"

            v-model="form.permissions"
            placeholder="Seleziona permessi ruolo"
          >
            <template #option="{ name, description }">
                <div class="flex flex-col">
                <span class="font-medium">{{ name }}</span>
                <span class="text-sm text-gray-500">{{ description }}</span>
                </div>
            </template>
           </v-select>
        </div>

        <div class="mb-4">
          <label class="flex items-center space-x-2 mt-6">
            <Checkbox v-model="form.accessAdmin" />
            <span class="font-medium">Dai accesso al layout amministratore</span>
            <Tooltip>
              <TooltipTrigger as-child>
                <Info class="w-4 h-4 text-muted-foreground cursor-pointer" />
              </TooltipTrigger>
              <TooltipContent side="right">
                Se selezionata questa opzione permette di mostrare il layout amministratore per il ruolo.
              </TooltipContent>
            </Tooltip>
          </label>
        </div>

        <div class="pt-5">
          <Button :disabled="form.processing">
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            Modifica ruolo
          </Button>
        </div>
      </form>

      <Separator class="my-6" />

      <div class="sm:flex sm:items-center">
        <div class="sm:flex-auto">
          <h1 class="text-xl font-semibold text-gray-900">Permessi ruolo</h1>
          <p class="mt-2 text-sm text-gray-700">
            Lista dei permessi già associati al ruolo
          </p>
        </div>
      </div>

      <div class="flex flex-col pt-4">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-300">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-3 py-3.5 text-left text-sm font-semibold">Nome</th>
                <th class="px-3 py-3.5 text-left text-sm font-semibold">
                  Descrizione
                </th>
                <th class="px-3 py-3.5 text-left text-sm font-semibold">
                  Azioni
                </th>
              </tr>
            </thead>

            <tbody v-if="!role.permissions.length">
              <tr>
                <td colspan="3" class="px-6 py-4 text-center">
                  Nessun permesso associato a questo ruolo
                </td>
              </tr>
            </tbody>

            <tbody class="divide-y divide-gray-200 bg-white">
              <tr v-for="permission in role.permissions" :key="permission.id">
                <td class="px-3 py-4 font-bold">{{ permission.name }}</td>
                <td class="px-3 py-4">{{ permission.description }}</td>
                <td class="px-3 py-4">
                  <Link
                    :href="route('ruoli.permissions.destroy', [role.id, permission.id])"
                    method="delete"
                    class="text-red-500 hover:text-red-900"
                  >
                    Revoca
                  </Link>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </UtentiLayout>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
