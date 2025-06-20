<script setup>
import {computed, ref} from 'vue'
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot,} from '@headlessui/vue'
import Button from "../forms/Button.vue";
import Input from "../forms/Input.vue";

const errorBag = ref({});
const isOpen = ref(true)
const props = defineProps(['user']);
const emit = defineEmits(['confirm', 'cancel']);

const cantSave = computed(() => {
  errorBag.value = {};
  let retVal = false;
  if (props.user.username.length < 3) {
    errorBag.value.username = 'Jméno musí mít alespoň 3 znaky';
    retVal = true;
  }
  if (props.user.id === null && (!props.user.password || props.user.password?.length < 5)) {
    errorBag.value.password = 'Heslo musí mít alespoň 5 znaků';
    retVal = true;
  }
  return retVal;
});

const closeModalWith = (value) => {
  isOpen.value = false;
  setTimeout(() => {
    if (value === 'confirm') {
      emit('confirm', props.user);
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
                {{ props.user.id ? 'Úprava uživatele' : 'Nový uživatel' }}
              </DialogTitle>

              <div class="flex flex-col">
                <Input v-model="props.user.username" label="Uživatelské jméno" placeholder="Zadejte jméno uživatele" :error="errorBag?.name" size="sm"/>
                <Input v-model="props.user.password" :label="'Heslo' + (props.user.id ? ' (Vyplnit pouze při změně hesla)' : '')" :placeholder="props.user.id ? 'Změňte heslo uživatele' : 'Zadejte heslo uživatele (min. 5 znaků)'" :error="errorBag?.password" size="sm"/>
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