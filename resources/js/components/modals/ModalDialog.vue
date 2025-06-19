<script setup>
import {ref} from 'vue'
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot} from '@headlessui/vue'
import Button from "../forms/Button.vue";
import Input from "../forms/Input.vue";

const isOpen = ref(true)
const props = defineProps({
  title: {type: String, required: true},
  message: {type: String, required: true},
  useInput: {type: String, required: false, default: null},
});
const emit = defineEmits(['confirm', 'cancel']);
const inputValue = ref(props.useInput);

function closeModal() {
  isOpen.value = false
}

const closeModalWith = (value) => {
  isOpen.value = false;
  setTimeout(() => {
    if (value === 'confirm') {
      if (inputValue.value !== '') {
        emit('confirm', inputValue.value);
      } else {
        emit('confirm');
      }
    } else {
      emit('cancel');
    }
  }, 300);
}

function openModal() {
  isOpen.value = true
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
                {{ title }}
              </DialogTitle>

              <div class="flex flex-col">
                <Input v-if="props.useInput" :label="message" v-model="inputValue" size="sm"/>
              </div>

              <div class="flex items-center justify-end space-x-2">
                <Button data-class="btn-ghost" label="Zrušit" size="sm" @click="closeModalWith('cancel')"/>
                <Button icon="mdi-check-bold" label="Potvrdit" size="sm" @click="closeModalWith('confirm')"/>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>
