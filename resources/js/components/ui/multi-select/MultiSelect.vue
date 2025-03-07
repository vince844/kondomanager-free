
<script setup lang="ts">
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import {
  TagsInput,
  TagsInputInput,
  TagsInputItem,
  TagsInputItemDelete,
  TagsInputItemText,
} from "@/components/ui/tags-input";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Check, ChevronsUpDown } from "lucide-vue-next";
import { ref, watch } from "vue";

const props = defineProps<{
  options: Array<{ [key: string]: string }>;
  text?: string;
  value?: string;
  placeholder?: string;
}>();

// Provide default values for text and value props
const textKey = props.text || "text";
const valueKey = props.value || "value";

const open = ref(false);

const emit = defineEmits(["update:modelValue"]);

// Define the model value
const modelValue = defineModel<string[]>({
  required: true,
});

// Function to handle selection
const handleSelect = (selectedValue: string) => {
  const currentValue = [...(modelValue.value || [])];
  if (!currentValue.includes(selectedValue)) {
    currentValue.push(selectedValue);
  } else {
    const index = currentValue.indexOf(selectedValue);
    currentValue.splice(index, 1);
  }
  modelValue.value = currentValue;
  emit("update:modelValue", currentValue);
};

// Function to handle removal
const handleRemove = (keyToRemove: string) => {
  const newValue = (modelValue.value || []).filter(
    (item) => item !== keyToRemove
  );
  modelValue.value = newValue;
  emit("update:modelValue", newValue);
};

// Function to get display text for a value
const getDisplayText = (value: string) => {
  const option = props.options.find(
    (opt) => opt[valueKey as keyof typeof opt] == value
  );
  return option ? option[textKey as keyof typeof option] : value;
};

// Watch for external changes to modelValue
watch(
  () => modelValue.value,
  (newValue) => {
    if (newValue !== modelValue.value) {
      emit("update:modelValue", newValue);
    }
  },
  { deep: true }
);
</script>

<template>
  <div>
    <Popover v-model:open="open">
      <PopoverTrigger as-child>
        <TagsInput>
          <TagsInputItem
            v-for="item in modelValue"
            :key="item"
            :value="item"
            @remove="handleRemove(item)"
          >
            <span class="truncate p-1">
              {{ getDisplayText(item) }}
            </span>
            <TagsInputItemDelete />
          </TagsInputItem>
          <TagsInputInput
            :placeholder="placeholder"
            v-if="!modelValue?.length"
          />
          <!-- <ChevronsUpDown class="ml-2 size-4" /> -->
        </TagsInput>
      </PopoverTrigger>
      <PopoverContent class="p-0" align="start">
        <Command class="p-0">
          <CommandInput class="h-9" placeholder="Search items..." />
          <CommandEmpty>No items found.</CommandEmpty>
          <CommandList>
            <CommandGroup>
              <CommandItem
                v-for="option in options"
                :key="option[valueKey]"
                :value="option[textKey]"
                @select.prevent="handleSelect(option[valueKey].toString())"
              >
                <span>{{ option[textKey as keyof typeof option] }}</span>
                <Check
                  v-if="modelValue?.includes(option[valueKey].toString())"
                  class="ml-auto h-4 w-4"
                />
              </CommandItem>
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  </div>
</template>



<!-- <script setup lang="ts">
import { Combobox, ComboboxAnchor, ComboboxEmpty, ComboboxGroup, ComboboxInput, ComboboxItem, ComboboxList } from '@/components/ui/combobox'
import { TagsInput, TagsInputInput, TagsInputItem, TagsInputItemDelete, TagsInputItemText } from '@/components/ui/tags-input'
import { useFilter } from 'reka-ui'
import { computed, ref } from 'vue'

import { ChevronDownIcon } from 'lucide-vue-next'

interface Option {
  value: string
  label: string
}

const props = defineProps({
    placeholder: {
        type: String,
        required: false,
    },
    options: {
        type: Array as () => Option[],
        required: false,
        default: () => [],
    },
});

const toggleOpen = () => {
  open.value = !open.value
}

const modelValue = ref<string[]>([])
const open = ref(false)
const searchTerm = ref('')

const { contains } = useFilter({ sensitivity: 'base' })
const filteredFrameworks = computed(() => {
  const options = props.options.filter(i => !modelValue.value.includes(i.label))
  return searchTerm.value ? options.filter(option => contains(option.label, searchTerm.value)) : options
})
</script>

<template>
  <Combobox v-model="modelValue" v-model:open="open" :ignore-filter="true">
    <ComboboxAnchor as-child>
      <TagsInput v-model="modelValue" class="px-2 gap-2 w-80 mt-1 block w-full">
        <div class="flex gap-2 flex-wrap items-center">
          <TagsInputItem class="p-1 mb-2" v-for="item in modelValue" :key="item" :value="item">
            <TagsInputItemText />
            <TagsInputItemDelete />
          </TagsInputItem>
        </div>

        <ComboboxInput v-model="searchTerm" as-child>
            
        <div class="flex justify-between">
            <TagsInputInput :placeholder=props.placeholder class="min-w-[200px] w-full p-0 border-hidden shadow-none focus-visible:ring-0" @keydown.enter.prevent />

            <ChevronDownIcon 
                class="mr-0 right-0 mt-3 transform -translate-y-1/2 mr-3 h-4 w-4 " 
                @click="toggleOpen"
            />
        </div>

        </ComboboxInput>
        
      </TagsInput>

      <ComboboxList class="w-[--reka-popper-anchor-width]">
        <ComboboxEmpty />
        
        <ComboboxGroup>
            
          <ComboboxItem
            v-for="framework in filteredFrameworks" :key="framework.value" :value="framework.label"
            @select.prevent="(ev) => {

              if (typeof ev.detail.value === 'string') {
                searchTerm = ''
                modelValue.push(ev.detail.value)
              }

              if (filteredFrameworks.length === 0) {
                open = false
              }
            }"
          >
            {{ framework.label }}
          </ComboboxItem>
        </ComboboxGroup>
      </ComboboxList>
    </ComboboxAnchor>
  </Combobox>
</template> -->