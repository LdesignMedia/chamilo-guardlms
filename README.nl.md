# GuardLMS voor Chamilo

Ontwikkelversie 0.1.0 (alpha), lokaal getest met Chamilo 3.0.2 en 1.11.42. Raadpleeg het [verificatierapport](https://github.com/LdesignMedia/chamilo-guardlms/blob/111bd99/verification/VERIFICATION.md) voor de precieze resultaten en beperkingen. De [Engelse handleiding](README.md) bevat de volledige technische instructies.

## Installeren en verbinden

1. Installeer eerst de bijbehorende GuardLMS-ontvanger. Pak de ZIP uit: plaats `guardlms/` onder `public/plugin/` bij Chamilo 3 of onder `plugin/` bij Chamilo 1.11.
2. Maak `/var/lib/chamilo-guardlms` buiten de publieke webmap aan, met rechten 0700 en de PHP-servicegebruiker als eigenaar. Bewaar de installatiesleutel en versleutelde instellingen samen in een beveiligde back-up.
3. Installeer en activeer de plugin via het pluginbeheer van Chamilo. Kies `pre_footer` bij Chamilo 3, of `header_main` en `pre_footer` bij Chamilo 1.11. Werk bij Chamilo 3 de Composer-autoloader bij en leeg de applicatiecache.
4. Open `/plugin/guardlms/settings.php` als platformbeheerder. Bekijk de inventaris, kies **Verbinden met GuardLMS** en keur de verbinding in GuardLMS goed. Controleer daarna het tijdstip van de laatst geaccepteerde inventaris.
5. Stel de dagelijkse rapportage in via de gedocumenteerde cronopdracht. Voer deze uit als dezelfde servicegebruiker die de privéopslag beheert. `--due` rapporteert pas wanneer de laatste geaccepteerde inventaris minstens een dag oud is.

Elke canonieke toegangs-URL heeft eigen instellingen en sleutels. Verbind iedere URL afzonderlijk. Plan per URL een cronopdracht met `--site=https://geregistreerd-portaal.example`; niet-geregistreerde URLs worden geweigerd. Pas bij het klonen van een installatie de native Chamilo-URL aan; een afwijkende URL mag geen gegevens voor de oorspronkelijke website aanleveren.

## Optionele gegevens

Configuratiecontroles, browserfouten, pagina-/scrollstatistieken en serverfouten staan standaard uit. Ze zijn onafhankelijk in te schakelen. Monitoring vereist daarnaast een geschikte, actieve GuardLMS-configuratie en een toegestaan domein. De bestaande SDK-regels voor privacy, toestemming en sampling blijven gelden. Er worden geen cursist- of cursusgegevens toegevoegd.

Voor PHP-/SQL-fouten zijn de `auto_prepend_file`-instelling en de vertrouwde, per-vhost ingestelde `CHAMILO_GUARDLMS_SITE` uit de Engelse handleiding nodig. De plugin behoudt bestaande handlers en foutreacties. Alleen algemene foutmeldingen, exceptionklasse, bestandsnaam, regelnummer en stackframes zonder argumenten worden doorgestuurd. SQL, parameters en oorspronkelijke meldingen blijven achterwege. Privélogbestanden kunnen lokaal wel gevoelige diagnoses bevatten; roteer en beveilig deze.

De wachtrij bevat maximaal 100 meldingen, met batches van 20 en herpogingen via cron. Harde procesbeëindiging, fouten vóór de PHP-bootstrap en handlers die alle diagnoses onderdrukken kunnen vastlegging verhinderen. Een wachtwoordsterktemeter bewijst geen serverbeleid: die controle blijft onbekend zonder betrouwbaar bewijs. Onbekende plugin-updates worden niet als actueel gepresenteerd. Zonder betrouwbare Chamilo-koppeling blijft ook de kwetsbaarheidsstatus van plugins onbekend; ondersteunde kernsoftware, runtime en afhankelijkheden worden wel gecontroleerd. CLI-rapporten slaan webspecifieke debug- en sessiecookie-instellingen over; gebruik daarvoor een handmatig webrapport.

**Verbinding verbreken** probeert beide typen sleutels op afstand in te trekken en verwijdert daarna lokale sleutels, wachtrijen en monitoringinstellingen. Bij een netwerkfout moet je de sleutels ook in GuardLMS intrekken. Verwijder vóór het wissen van pluginbestanden ook de `auto_prepend_file`-instelling en herstart PHP, zodat PHP niet naar een verwijderd bestand verwijst. Alleen pluginbestanden verwijderen trekt geen sleutels in.

Namen van dependencies langer dan de databaselimiet van de ontvanger (120 tekens) worden overgeslagen in de optionele dependency-inventaris. Verschillende geïnstalleerde versies blijven afzonderlijke waarnemingen.
