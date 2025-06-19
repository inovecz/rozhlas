<script setup>
import {computed, ref} from 'vue'
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot,} from '@headlessui/vue'
import Button from "../forms/Button.vue";
import Input from "../forms/Input.vue";
import Select from "../forms/Select.vue";

const errorBag = ref({});
const isOpen = ref(true)
const props = defineProps(['locationGroups', 'location']);
const emit = defineEmits(['confirm', 'cancel']);

const cantSave = computed(() => {
  errorBag.value = {};
  let retVal = false;
  if (props.location.name.length < 3) {
    errorBag.value.name = 'Název musí mít alespoň 3 znaky';
    retVal = true;
  }
  return retVal;
});

const closeModalWith = (value) => {
  isOpen.value = false;
  setTimeout(() => {
    if (value === 'confirm') {
      emit('confirm', props.location);
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

      <div class="fixed inset-0 overflow-y-auto z-[999]">
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
                {{ props.location.id ? 'Úprava místa' : 'Nové místo' }}
              </DialogTitle>

              <div class="flex flex-col">
                <Input v-model="props.location.name" label="Název místa" placeholder="Zadejte název místa (alespoň 3 znaky" size="sm" :error="errorBag?.name"/>
                <Select v-model="props.location.type" label="Zvolte typ místa" :options="[{value: 'CENTRAL', label: 'Centrála'}, {value: 'NEST', label: 'Hnízdo'}]" size="sm"/>
                <Select v-model="props.location.location_group_id" label="Zvolte lokaci" option-label="name" option-key="id" :options="props.locationGroups" size="sm"/>
              </div>

              <div class="flex items-center justify-end space-x-2">
                <Button data-class="btn-ghost" label="Zrušit" size="sm" @click="closeModalWith('cancel')"/>
                <Button v-if="props.location.id" icon="mdi-content-save" label="Uložit" size="sm" @click="closeModalWith('confirm')" :disabled="cantSave"/>
                <Button v-else icon="mdi-map-marker-plus" label="Nastavit pozici" size="sm" @click="closeModalWith('confirm')" :disabled="cantSave"/>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>