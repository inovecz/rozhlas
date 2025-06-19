<script setup>
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";

const centralData = {
  version: '1.4.5',
  hardwareVersion: 2,
  webServerVersion: '1.10.0',
  webAppVersion: '1.10.2',
  serialNumber: 'Devel2',
  uNumber: 'UAADQ 17701'
}
</script>

<template>
  <PageContent label="O aplikaci">
    <p>
      Aplikace slouží k ovládání a nastavení rozhlasové ústředny Sarah IV.
    </p>

    <Box label="Informace o ústředně">
      <table class="table">
        <tr>
          <td>Verze programu:</td>
          <td>{{ centralData.version }}</td>
        </tr>
        <tr>
          <td>Verze hardware:</td>
          <td>{{ centralData.hardwareVersion }}</td>
        </tr>
        <tr>
          <td>Verze webserveru:</td>
          <td>{{ centralData.webServerVersion }}</td>
        </tr>
        <tr>
          <td>Verze webaplikace:</td>
          <td>{{ centralData.webAppVersion }}</td>
        </tr>
        <tr>
          <td>Sériové číslo:</td>
          <td>{{ centralData.serialNumber }}</td>
        </tr>
        <tr>
          <td>U-číslo:</td>
          <td>{{ centralData.uNumber }}</td>
        </tr>
      </table>
    </Box>

    <Box label="Kontaktní údaje">
      <a href="https://www.rozhlasybartek.cz" target="_blank" class="block text-primary cursor-pointer hover:underline">Bártek Rozhlasy s.r.o.</a>
      <p class="mt-2">
        <span class="font-bold">Provozovna č.1, korespondenční adresa</span><br/>
        Podlesí 12<br/>
        757 01 Valašské Meziříčí Česká republika
      </p>
      <p class="mt-2">
        <span class="font-bold">Sídlo společnosti, fakturační adresa</span><br/>
        Vyšehradská, 1349/2<br/>
        Nové město<br/>
        128 00 Praha 2 Česká republika
      </p>
    </Box>
  </PageContent>
</template>