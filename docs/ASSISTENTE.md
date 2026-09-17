# Assistente AI — il kit

Modulo generico di VelociBuilder LITE: uno o più **agenti conversazionali** che intervistano
il visitatore, propongono solo ciò che sta nel loro know-how e chiudono con una proposta +
un lead. Stesso motore per qualunque dominio: cambia solo il contenuto degli agenti.

> La prova che l'impianto regge: **Scienzoo è nato duplicando BUCK e cambiando i `.md`** —
> dominio diverso, regole diverse (niente prezzi, niente calcolatore), **zero righe di PHP nuove**.

---

## 1. La catena (fonte di verità unica)

```
agenti-ai/<agente>/*.md ──php tools/ai-seed-build.php──▶ inc/ai-seed.json
        ──migration / "Ripristina dal seed"──▶ cms_ai_config ──▶ system prompt
```

I contenuti si scrivono nei `.md` (versionabili, diffabili). Il seed è **solo trasporto**.
Il DB è la copia editabile dal Backoffice.

```bash
php tools/ai-seed-build.php --src=../MIOSITO/agenti-ai          # compila
php tools/ai-seed-build.php --src=… --check                      # cosa cambierebbe (exit 1 se diverso)
php tools/ai-seed-build.php --src=… --init                       # crea gli agente.json dal seed esistente
```

Il compilatore mostra un report per sezione (NUOVO / MODIFICATO / invariato) — è anche il modo
di accorgersi che i `.md` e il seed hanno preso strade diverse.

### Il verso inverso: DB → `.md`

Ciò che si modifica **dal Backoffice** vive solo nel DB: senza un export, la copia sul disco
invecchia a ogni modifica dal pannello (l'altra faccia dello stesso sdoppiamento).

```
Backoffice → Assistente AI → "Esporta" (admin/ai-export.php)  →  seed.json
php tools/ai-seed-explode.php --seed=seed.json --dst=…/agenti-ai --check   # cosa cambierebbe
php tools/ai-seed-explode.php --seed=… --dst=… --only=meta,config          # solo agente.json
php tools/ai-seed-explode.php --seed=… --dst=… --skip=knowhow              # tutto tranne quelle
```

⚠️ **Attenzione al verso.** Se una sezione sul **disco** è più aggiornata del DB (l'hai corretta
nei `.md` e non l'hai ancora portata live), esploderla la **sovrascrive**. Perciò:
- lancia SEMPRE prima con `--check` (i char negativi = il disco è più lungo, quindi più recente);
- usa `--only` / `--skip` per esplodere solo le sezioni giuste. Caso tipico: **`--only=meta,config`**
  per riportare a disco le sole impostazioni fatte dal pannello (voce, apertura, dati obbligatori,
  email notifiche) senza toccare i contenuti che sul disco sono avanti.
- il know-how incollato a mano dal pannello **non ha i marker** `<!-- file: … -->`: esploderlo
  crea un unico `00-knowhow.md` invece delle schede granulari (lo script avvisa).

Regola pratica dei due versi: **contenuti** (persona/metodo/intervista/know-how/proposta) → si
scrivono nei `.md` e si spingono al DB con "Ripristina dal seed"; **impostazioni** (meta/config)
→ si toccano dal pannello e si riportano al disco con l'export + explode `--only=meta,config`.

### Struttura della cartella sorgente

```
agenti-ai/
  config.json                (facoltativo) default globali → seed["config"]
  <chiave-agente>/
    agente.json              meta (name, branch, role, desc, azienda, accent, avatar, tone) + config
    persona.md               chi è, come parla, regole d'oro
    metodo.md                come conduce la conversazione
    intervista.md            la checklist NASCOSTA da spuntare conversando
    proposta.md              struttura della proposta finale + mapping dei campi
    knowhow/*.md             il catalogo: l'unica fonte di ciò che può proporre
    immagini.json            {"Prodotto": "uploads/x.jpg"} + chiavi speciali _campionario*
    video.json               {"chiave": {"youtube":"…","titolo":"…"}}
    email.html               (facoltativo) template email dell'agente
```

Il nome della cartella è la chiave dell'agente. Le `knowhow/*.md` vengono concatenate in
ordine alfabetico con un marker `<!-- file: nome.md -->`.

---

## 2. Strumenti deterministici

> Una funzione server **registrata** che il modello può **invocare popolando un campo JSON**,
> ma di cui **non produce mai il risultato**.

Il calcolatore del canone è *un'istanza*: un preventivatore, un configuratore, una verifica
di disponibilità funzionano identici.

```
il modello:  { "reply": "…", "calc": {"n4":3,"n3":1,"vbn":3000,"vcol":1000} }
il server:   ai_stima_canone(…) → €170
il server:   "DATO UFFICIALE: €170/mese. Riscrivi la risposta includendo la cifra."
```

Registrare uno strumento del sito in `inc/ai-tools-site.php` (file per-sito, non del motore):

```php
<?php
ai_tool_register('preventivo', [
  'campo'       => 'preventivo',                       // chiave JSON che il modello popola
  'label'       => 'Preventivatore',
  'descrizione' => 'Calcola il totale dal listino a partire da quantità e opzioni.',
  'schema'      => '{"articolo":"string","qta":int}',
  'gate'        => true,                                // niente cifre senza i dati di contatto
  'istruzioni'  => 'quando hai articolo e quantità popola "preventivo": riceverai il totale ufficiale.',
  'run'         => function ($args, $agent) { return ['totale' => mio_calcolo($args)]; },
  'fact'        => function ($r, $agent) { return "DATO UFFICIALE: totale €{$r['totale']}. Riscrivi la risposta con questa cifra."; },
]);
```

Poi si abilita per agente dal Backoffice (Configurazione → Strumenti deterministici).
Il calcolatore incluso ha il **listino a scaglioni configurabile** in `cms_settings`
(`ai_tool_canone_listino`, JSON con `A4`/`A3`/`max_per_tipo`): nessun numero di un cliente
specifico dentro il motore.

---

## 3. Protezione degli endpoint (`inc/ai-guard.php`)

`api/assistente/chat.php` è **pubblico e anonimo**, e ogni chiamata spende token sulla chiave
del sito. Senza tetti, uno script che lo martella produce una bolletta. Il guard applica:

| tetto | default | chiave |
|---|---|---|
| per IP · minuto / ora / giorno | 10 / 90 / 250 | `ai_rl_ip_min` `ai_rl_ip_hour` `ai_rl_ip_day` |
| per conversazione (cookie) · giorno | 120 | `ai_rl_sid_day` |
| per agente · giorno | 600 | `ai_rl_agent_day` |
| per sito · giorno | 1200 | `ai_rl_site_day` |
| intervallo minimo fra messaggi | 2 s | `ai_rl_min_gap` |
| caratteri massimi di cronologia | 60.000 | `ai_rl_max_chars` |

Più **honeypot** (campo `website` invisibile nel widget) e conteggio a **costo**: un messaggio
di chat pesa 1, una proposta 4. Tutto si regola da Backoffice → Assistente AI → **Limiti & consenso**,
che mostra anche il consumo di oggi per agente e gli IP più attivi. `0` = nessun limite.

La contabilità sta in `cms_ai_usage` (creata dalla migration, o al volo alla prima chiamata).

---

## 4. Consenso privacy

L'assistente raccoglie azienda/referente/email/telefono e archivia **l'intera conversazione**
in `cms_ai_leads`: serve un consenso esplicito, come per ogni altro modulo del sito.

- Il widget mostra una spunta obbligatoria prima di "Genera la proposta" (testo e link
  informativa configurabili; di default quelli del sito, `inc/consent.php`).
- `lead.php` **rifiuta** il salvataggio senza consenso e registra quello prestato nel registro
  `gdpr_consent` (Backoffice → Consensi), con data, ora, IP e testo.
- Si disattiva da Backoffice → Limiti & consenso (`ai_consent_required`).

---

## 5. Niente dominio nel motore

Il modulo non sa cosa vendi. Ciò che era cablato oggi è configurazione:

| era | ora |
|---|---|
| scaglioni del calcolatore | `cms_settings.ai_tool_canone_listino` |
| "di M.C. System" nel prompt | `meta.azienda` dell'agente, o `SITE_NAME` |
| linea di prodotto e "blocco Xerox" in `proposta.php` | regole nella sezione **proposta** dell'agente; campo generico `alternativa` (`xerox` resta accettato) |
| titolo del blocco alternativa | `config.titolo_alternativa` |
| instradamento rivenditori in `lead.php` | `config.instradamenti` per tipo di interlocutore (oggetto, destinatario interno, chi ricontatta, invito) |
| intestazione/footer dell'email | `inc/ai-email-template.html` per-sito (assente = template generico) |
| titoli del campionario | chiavi `_campionario_titolo` / `_campionario_testo` in **immagini** |

I campi di contatto obbligatori erano già configurabili (`config.dati_obbligatori`).

---

## 6. Installare il modulo su un sito nuovo

1. `php tools/ai-seed-build.php --src=…/agenti-ai` → genera `inc/ai-seed.json`.
2. Deploy dei file, poi `/api/migrate_assistente.php` (crea `cms_ai_config`, `cms_ai_leads`,
   `cms_ai_usage`, `gdpr_consent` e semina gli agenti **solo se la config è vuota**).
3. Backoffice → Assistente AI → **API**: provider + chiave (resta lato-server).
4. Backoffice → **Limiti & consenso**: verifica tetti e testo del consenso.
5. Voce di menu verso `assistente.html` + "Pubblica".
