<script setup>
import {onMounted, ref} from "vue";
import ContactService from "../../services/ContactService.js";
import VueMultiselect from "vue-multiselect";

const contacts = ref([]);
const contactGroups = ref([]);

const selectedContacts = ref([]);
const selectedContactGroups = ref([]);

onMounted(() => {
  fetchContacts();
  fetchContactGroups();
});

function fetchContacts() {
  ContactService.getAllContacts('select').then(response => {
    contacts.value = response;
  }).catch(error => {
    console.error(error);
  });
}

function fetchContactGroups() {
  ContactService.getAllContactGroups('select').then(response => {
    contactGroups.value = response;
  }).catch(error => {
    console.error(error);
  });
}

</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">
      <router-link :to="{ name: 'Messages' }">
        <span class="mdi mdi-chevron-left"></span>
      </router-link>
      <span>SMS a E-mail</span>
    </h1>
    <div class="content flex flex-col space-y-4">
      <div class="component-box">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between">
          <div class="text-xl text-primary mb-4 mt-3 px-1">
            Nová zpráva
          </div>
        </div>

        <div class="flex flex-col gap-2">

          <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <div class="w-full">
              <VueMultiselect v-if="contacts" v-model="selectedContacts" :options="contacts" label="fullname" trackBy="id" :multiple="true"
                              :close-on-select="false"
                              placeholder="Vyhledat kontakt" tagPlaceholder="" noOptions="Seznam je prázdný"
                              selectedLabel="Vybráno" selectLabel="Klikněte pro přidání" deselectLabel="Klikněte pro odebrání">
                <template #noResult>
                  <span>Zadaným parametrům neodpovídá žádný kontakt</span>
                </template>
              </VueMultiselect>
            </div>
            <div class="w-full">
              <VueMultiselect v-if="contactGroups" v-model="selectedContactGroups" :options="contactGroups" label="name" trackBy="id" :multiple="true"
                              :close-on-select="false"
                              placeholder="Vyhledat skupinu" tagPlaceholder="" noOptions="Seznam je prázdný"
                              selectedLabel="Vybráno" selectLabel="Klikněte pro přidání" deselectLabel="Klikněte pro odebrání">
                <template #noResult>
                  <span>Zadaným parametrům neodpovídá žádná skupina</span>
                </template>
              </VueMultiselect>
            </div>
          </div>

          <label class="form-control">
            <div class="label">
              <span class="label-text">Text zprávy</span>
            </div>
            <textarea class="textarea textarea-bordered h-24" placeholder="Zde napište zprávu, která bude rozeslána příjemcům ze seznamu"></textarea>
          </label>

          <div class="flex justify-end">
            <button class="btn btn-primary">Odeslat</button>
          </div>

        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>

</style>