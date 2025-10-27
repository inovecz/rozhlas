<script setup>
import {computed, ref, watch} from 'vue'
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot,} from '@headlessui/vue'
import Button from "../forms/Button.vue";
import Input from "../forms/Input.vue";
import Select from "../forms/Select.vue";
import {FILE_SUBTYPE_OPTIONS} from "../../constants/fileSubtypeOptions.js";

const isOpen = ref(true)
const props = defineProps({
  title: {
    type: String,
    default: '',
  },
  message: {
    type: String,
    default: '',
  },
  uploadedFile: {
    type: Object,
    default: null,
  },
  defaultName: {
    type: String,
    default: null,
  },
  defaultSubtype: {
    type: String,
    default: 'COMMON',
  },
  allowSubtypeChange: {
    type: Boolean,
    default: true,
  },
});
const emit = defineEmits(['confirm', 'cancel']);

const subtypeOptions = FILE_SUBTYPE_OPTIONS;

const defaultName = props.defaultName ?? props.uploadedFile?.name ?? `Nahrávka ${new Date().toLocaleString('cs-CZ')}`;
const recordName = ref(defaultName);
const defaultSubtypeExists = subtypeOptions.some((option) => option.value === props.defaultSubtype);
const recordSubtype = ref(defaultSubtypeExists ? props.defaultSubtype : 'COMMON');

watch(
  () => props.defaultSubtype,
  (value) => {
    if (value && subtypeOptions.some((option) => option.value === value)) {
      recordSubtype.value = value;
    }
  },
);

const cantSave = computed(() => recordName.value.length < 3);

const closeModalWith = (value) => {
  isOpen.value = false;
  setTimeout(() => {
    if (value === 'confirm') {
      emit('confirm', {name: recordName.value, subtype: recordSubtype.value});
    } else {
      emit('cancel');
    }
  }, 300);
}
</script>

<template>
  <TransitionRoot appear :show="isOpen" as="template">
    <Dialog as="div" @close="closeModalWith('cancel')" class="relative z-10">
      <TransitionChild
          as="template"
          enter="duration-300 ease-out"
          enter-from="opacity-0"
          enter-to="opacity-100"
          leave="duration-200 ease-in"
          leave-from="opacity-100"
          leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-black/25 backdrop-blur-sm"/>
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 text-center">
          <TransitionChild
              as="template"
              enter="duration-300 ease-out"
              enter-from="opacity-0 scale-95"
              enter-to="opacity-100 scale-100"
              leave="duration-200 ease-in"
              leave-from="opacity-100 scale-100"
              leave-to="opacity-0 scale-95">
            <DialogPanel class="w-full max-w-md flex flex-col gap-6 transform overflow-hidden rounded-2xl glass p-6 text-left align-middle shadow-xl transition-all">
              <DialogTitle as="h3" class="text-lg font-medium leading-6 text-primary">
                Uložit nahrávku
              </DialogTitle>

              <div class="flex flex-col">
                <Input v-model="recordName" type="text" placeholder="Zadejte název nahrávky" label="Název nahrávky" size="sm"/>
                <Select
                    v-if="props.allowSubtypeChange"
                    v-model="recordSubtype"
                    :options="subtypeOptions"
                    label="Typ nahrávky"
                    size="sm"
                />
              </div>

              <div class="flex items-center justify-end space-x-2">
                <Button data-class="btn-ghost" label="Zrušit" size="sm" @click="closeModalWith('cancel')"/>
                <Button icon="mdi-content-save" label="Uložit" size="sm" @click="closeModalWith('confirm')" :disabled="cantSave"/>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
