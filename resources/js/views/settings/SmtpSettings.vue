<script setup>

import {computed, onMounted, ref} from "vue";
import SettingsService from "../../services/SettingsService.js";
import {useToast} from "vue-toastification";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";
import Select from "../../components/forms/Select.vue";
import Button from "../../components/forms/Button.vue";
import {disableAutoComplete} from "../../utils/diableAutocompleteTrait.js";

const toast = useToast();

const smtpSettings = ref({
  host: '',
  port: '',
  encryption: '',
  username: '',
  password: '',
  from_address: '',
  from_name: '',
});

onMounted(() => {
  fetchSmtpSettings();
  disableAutoComplete();
});

function fetchSmtpSettings() {
  SettingsService.fetchSmtpSettings().then(response => {
    smtpSettings.value = response;
  }).catch(error => {
    console.error(error);
  });
}

function saveSmtpSettings() {
  SettingsService.saveSmtpSettings(smtpSettings.value).then(() => {
    toast.success('Nastavení SMTP serveru bylo úspěšně uloženo');
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se uložit nastavení SMTP serveru');
  });
}

const cantSave = computed(() => {
  let retVal = false;
  if (smtpSettings.value.host === '') {
    retVal = true;
  }
  if (smtpSettings.value.port === '') {
    retVal = true;
  }
  if (!['TCP', 'SSL', 'TLS'].includes(smtpSettings.value.encryption)) {
    retVal = true;
  }
  if (smtpSettings.value.username === '') {
    retVal = true;
  }
  if (smtpSettings.value.password === '') {
    retVal = true;
  }
  if (smtpSettings.value.from_address === '') {
    retVal = true;
  }
  if (smtpSettings.value.from_name === '') {
    retVal = true;
  }
  return retVal;
});

</script>

<template>
  <PageContent label="Nastavení SMTP komunikace">
    <Box label="Nastavení poštovního serveru">
      <Input v-model="smtpSettings.host" label="Adresa SMTP serveru:" placeholder="Např.: smtp.seznam.cz"/>
      <Input v-model="smtpSettings.port" type="number" label="Port SMTP serveru:" placeholder="Např.: 465"/>
      <Select v-model="smtpSettings.encryption" label="Typ připojení:" :options="['TCP', 'SSL', 'TLS']"/>
      <Input v-model="smtpSettings.username" label="Uživatelské jméno:" placeholder="Např.: jan.novak" autocomplete-off/>
      <Input v-model="smtpSettings.password" type="password" label="Heslo:" placeholder="Např.: 1234567890" autocomplete-off/>
      <Input v-model="smtpSettings.from_address" label="E-mailová adresa odesílatele:" placeholder="Např.: jan.novak@seznam.cz"/>
      <Input v-model="smtpSettings.from_name" label="Jméno odesílatele:" placeholder="Např.: Jan Novák"/>

      <div class="flex justify-end">
        <Button icon="mdi-content-save" label="Uložit" size="sm" @click="saveSmtpSettings" :disabled="cantSave"/>
      </div>
    </Box>
  </PageContent>
</template>

<style scoped>
</style>