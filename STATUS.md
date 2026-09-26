# Stato del progetto Admin Suite

Documento di stato scritto a mano il 26 settembre 2026, a commit `bf0500f`.

**Dove sta questo file.** L'ho messo nella root del *plugin* (`wp-content/plugins/admin-suite/`), che è
la radice del repository Git, non nella root del document root di WordPress: lì un file sarebbe
fuori dal versionamento. Se intendevi l'altra, si sposta.

**Una nota che vale per tutta la terza tabella.** In questa sessione non è collegato nessun browser:
il tool esiste ma risponde `No desktop browser is connected to this session`. Quindi ogni riga che
riguarda l'aspetto è **non verificata visivamente**: è verificata sul codice, sul markup servito e
sui CSS, non su uno schermo. Le verifiche fatte sono state `composer run lint` (phpcs + PHPStan
livello 8), la costruzione Vite con `vue-tsc`, ESLint, Prettier, il test di boot con linkedom, e il
confronto del DOM servito via `curl`.

## Cosa è stato fatto

| # | Commit | Cosa cambia |
| --- | --- | --- |
| 1 | `99b8f96` | Impianto del plugin, versione 0.1.0. |
| 2 | `a8eea62` | La suite **prende il posto** di `index.php` invece di annidarsi dentro: monta su `#dashboard-widgets-wrap`, spariscono header e sidebar della SPA, lo slug `?page=admin-suite` fa 302 da `init` (non da `admin_init`, che core raggiunge dopo il `wp_die()`). |
| 3 | `042d7e2` | `linkedom` aggiunto a `package-lock.json`: serve al test di avvio. |
| 4 | `f9fc898` | Sistema di colori proprio in `oklch`, canvas bianco `#ffffff`, cache buster vero. |
| 5 | `bdb34f9` | `[hash]` nei nomi dei file Vite. Il cache buster non poteva funzionare: il file enqueued è una facciata di 78 byte costante. |
| 6 | `4d73d97` | Help contestuale rimosso, welcome panel spostato dentro la suite. |
| 7 | `6a5df25` | Sparita l'intestazione di default "Dashboard". |
| 8 | `c02d671` | Collegato `ScreenOptionsPanel.vue` perché i pulsanti show/hide funzionassero. **Oggi non è più collegato**, vedi `4a6b63b` e `b136c2f`. |
| 9 | `4a6b63b` | Rimosse intestazione, riga "Layout salvato" a riposo e pulsante Screen Options di core. |
| 10 | `9761d64` | I widget nativi non vengono più stampati nel punto di montaggio. |
| 11 | `b136c2f` | Rimosso per intero il righello della dashboard: riga di stato e i tre pulsanti. |
| 12 | `3a45661` | Il righello si piega con la dashboard, non con il viewport. |
| 13 | `5628d35` | Rimosso lo schema scuro. |
| 14 | `1c149dd` | Scorciatoia per comprimere il menu, delegando al bottone di core. |
| 15 | `a1e637e` | Menu "Nuovo" di core nel righello, con i comandi spostati a sinistra. |
| 16 | `e04cecc` | La ricerca torna a destra nel righello. |
| 17 | `3fffe8f` | Link al sito e menu documentazione nel righello. |
| 18 | `60985db` | Link al sito e voci documentazione aperti in una nuova scheda. |
| 19 | `6451547` | Get Involved non si apre più in nuova scheda: è una schermata di amministrazione. |
| 20 | `8c81f30` | Il menu account esce dalla barra in alto e va in cima alla sidebar; il nodo `my-account` della barra sparisce. |
| 21 | `c689bd0` | L'entry dell'account diventa un blocco profilo: via "Howdy", avatar centrato e tondo, nome a capo sotto. |
| 22 | `f5eacb2` | Rimossa la topbar originale di WordPress. `remove_action( 'in_admin_header', 'wp_admin_bar_render', 0 )` da `admin_init`; i 32px della barra compensati in CSS. |
| 23 | `079ca24` | I widget con form tornano a funzionare: struttura del postbox di core (`#<id> .inside`) e riaggancio di `window.quickPressLoad` e `wp.communityEvents.init()`. Sparito il banner che segnalava i form come non inviabili. |
| 24 | `97dace5` | Il welcome panel prende la larghezza di Site overview (`span` da 2 a 3). |
| 25 | `bf0500f` | Separatore tra il blocco account e Dashboard. `handOverFirstClass()` eliminato: con il separatore, core calcola da solo le classi `menu-top-first`/`menu-top-last`. |

## Punti aperti

| Punto | Stato | Note |
| --- | --- | --- |
| **Screen Options** | Non montato | `ScreenOptionsPanel.vue` è su disco e compilato, ma non è cablato in `DashboardView.vue`: il componente non è mai renderizzato. Era cablato in `c02d671` e i pulsanti sono stati rimossi in `4a6b63b`/`b136c2f`. Va ripreso al giro degli screen options. |
| **Click "Dismiss" del welcome panel** | Decisione rimandata | `window.updateWelcomePanel` è una variabile locale dentro la closure `jQuery(function($){…})` di `dashboard.js:29`, quindi non è richiamabile da fuori. Due strade: (a) intercettare il click, fare `addClass('hidden')` e replicare il `$.post` di `dashboard.js:30-40` — nessun reload, ma il codice di core viene copiato e può divergere; (b) intercettare il click, fare `addClass('hidden')` e lasciare che il page load `?welcome=0` di oggi salvi — nulla da mantenere, ma il click ricarica comunque la pagina. |
| **Help** | Non ricostruire | `HelpPanel.vue` non è mai stato scritto e la metà server è stata rimossa su richiesta. Non va rifatta senza chiedere. |
| **`UserPreferences.lastRoute`** | Dichiarato e inutilizzato | Il campo esiste ma nessuno lo scrive, e la dashboard non ripristina l'ultima rotta visitata. |
| **Sandbox** | Funziona, non usato | `OutputBuffer` cattura davvero (verificati 0 byte con `?admin-suite-sandbox=1`), ma nessuna schermata della SPA chiede uno schermo sandboxato: nessuna pagina di terze parti è ancora resa da Vue. |
| **Schermate Media / Plugin / User manager** | Non esistono | Nessuna delle tre è stata iniziata. |
| **Test di copertura** | Deliberatamente minimo | Nessun framework di test installato (niente vitest, niente phpunit). Ci sono `npm test` (helper di layout), `npm run test:smoke` (avvio reale del bundle sotto Node con linkedom), più phpcs e PHPStan. **Il test di avvio non esercita `NativeWidgetBody`**: il fixture contiene solo pannelli `suite-*`, nessun widget nativo. |
| **Preflight di Tailwind** | Noto, non bloccante | `@import "tailwindcss"` mette il preflight nel layer base globale e il foglio è caricato su tutta la pagina di amministrazione. `isolation: isolate` su `#dashboard-widgets-wrap` ferma le nostre utility a uscire, non il preflight a entrare. Oggi non dà problemi perché sotto il punto di montaggio c'è solo la dashboard, che la SPA sostituisce per intero. Va isolato se e quando una schermata di terze parti verrà resa attraverso la suite. |

## Problemi

| Problema | Gravità | Dettaglio |
| --- | --- | --- |
| **Il JS di core del welcome panel non si aggancia alla nostra copia** | Alto | `AdminShell::detachWelcomePanel()` toglie dal documento la copia server di `#welcome-panel`, ma `dashboard.js:16` la cerca una volta sola al DOM ready, quando la nostra copia non esiste ancora. `welcomePanel` resta quindi un set jQuery **vuoto** per tutto quel blocco, e sulla nostra copia restano morti: la rimozione di `hidden` quando `#wp_welcome_panel-hide` è spuntata (`:43-46`), l'handler del Dismiss (`:49-54`) e il toggle della casella (`:57-60`). Il Dismiss segue quindi il `href` grezzo `?welcome=0`, fa un page load e core scrive `show_welcome_panel = 0`. **È la causa più probabile di come questo utente sia finito con il pannello chiuso.** |
| **`id="welcome-panel"` duplicato** | Medio | Il wrapper di `NativeWidgetBody.vue` porta `:id="id"`, e l'elemento iniettato porta a sua volta `id="welcome-panel"`: nel documento ci sono due elementi con lo stesso id. Non è questo che nasconde qualcosa, ma non è corretto. **Non verificato** se `quickPressLoad` abbia bisogno che sia il wrapper a portare quell'id: se lo togliamo va ricontrollato che i form continoino a salvare. |
| **Sotto i 782px non si può più aprire il menu** | Medio | La topbar conteneva `#wp-admin-bar-menu-toggle`, il bottone che su schermi stretti apre la sidebar. Tolta la topbar in `f5eacb2`, quel bottone non esiste più. Il bottone di compressione della toolbar serve a piegare, non ad aprire. |
| **Osservazioni sul welcome panel non spiegate** | Informativo | L'utente ha riferito che il pannello appariva su un tablet e che appariva con i temi di default a plugin disattivato. Con `show_welcome_panel = 0` anche WordPress puro lo mostrerebbe chiuso. Ricostruzione più probabile: quel valore è stato scritto **dopo** quelle osservazioni, dal click su Dismiss di cui sopra. Non verificato. |
| **Stato "collassata" della sidebar non verificato** | Informativo | Lo scopo di `bf0500f` era proprio il caso collassato, che non si può controllare senza browser. Core ha regole proprie in `admin-menu.css:319` e la variante `.auto-fold` a `:611`. |
| **Gli `<script>` inline dei widget nativi non girano** | Informativo | `v-html` passa per `innerHTML`, quindi nessuno `<script>` viene eseguito: un widget di terze parti che dipende dal proprio JS resta incompleto. Il caso è segnalato in `NativeWidgetBody.vue` con `hasScripts`. **Nota: il widget Events and News non è in questa categoria** — non ha script inline, è pilotato da `dashboard.js` e funziona una volta riagganciato. |
| **Wordmark di `AccountMenu` fragile con menu modificati da plugin** | Basso | `firstFreeKey()` prende la prima chiave intera libera saltando la `0`, e `separatorKey()` costruisce la chiave `<chiave>.5` perché fra due interi adiacenti non c'è nulla da infilare (`uksort` a `includes/menu.php:280` ordina le chiavi **come testo**). Se un altro plugin occupa la `1`, il separatore finisce a `1.5` e `dropHardcodedFirst()` toglie `menu-top-first` dal primo elemento che ordina sopra. Il comportamento con menu pesantemente modificati non è stato provato. |
| **Il `hidden` del welcome panel è una scelta dell'utente, non un difetto** | Da non toccare | `wp_usermeta.show_welcome_panel = 0` significa "chiuso". Il pannello è stato riabilitato portando il valore a `1`, una modifica di dati e non di codice. **Non va "corretto" togliendo la classe `hidden`**: rischierebbe di far risorgere un pannello che l'utente ha chiuso. La via prevista da WordPress è la casella in Screen Options. |
