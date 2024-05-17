<script setup>
import {onMounted, ref} from "vue";
import ContactService from "../../services/ContactService.js";
import VueMultiselect from "vue-multiselect";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import CustomFormControl from "../../components/forms/CustomFormControl.vue";
import Textarea from "../../components/forms/Textarea.vue";
import Button from "../../components/forms/Button.vue";

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
  <PageContent label="SMS a E-mail" back-route="Messages">
    <Box label="Nová zpráva">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
        <CustomFormControl>
          <VueMultiselect v-if="contacts" v-model="selectedContacts" :options="contacts" label="fullname" trackBy="id" :multiple="true"
                          :close-on-select="false"
                          placeholder="Vyhledat kontakt" tagPlaceholder="" noOptions="Seznam je prázdný"
                          selectedLabel="Vybráno" selectLabel="Klikněte pro přidání" deselectLabel="Klikněte pro odebrání">
            <template #noResult>
              <span>Zadaným parametrům neodpovídá žádný kontakt</span>
            </template>
          </VueMultiselect>
        </CustomFormControl>
        <CustomFormControl>
          <VueMultiselect v-if="contactGroups" v-model="selectedContactGroups" :options="contactGroups" label="name" trackBy="id" :multiple="true"
                          :close-on-select="false"
                          placeholder="Vyhledat skupinu" tagPlaceholder="" noOptions="Seznam je prázdný"
                          selectedLabel="Vybráno" selectLabel="Klikněte pro přidání" deselectLabel="Klikněte pro odebrání">
            <template #noResult>
              <span>Zadaným parametrům neodpovídá žádná skupina</span>
            </template>
          </VueMultiselect>
        </CustomFormControl>
      </div>

      <Textarea label="Text zprávy" placeholder="Zde napište zprávu, která bude rozeslána příjemcům ze seznamu"/>

      <div class="flex justify-end">
        <Button icon="mdi-send" label="Odeslat" size="sm"/>
      </div>

    </Box>
  </PageContent>
</template>

<style scoped>
</style>