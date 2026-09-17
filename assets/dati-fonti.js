const FONTI = [
  { id: 'CAM', materiale: 'Atti parlamentari', titolo: 'Portale storico della Camera dei deputati', ente: 'Camera dei deputati', sottotitolo: 'Camera dei deputati · atti parlamentari · copertura 1848–oggi',
    natura: 'Primaria', naturaEstesa: 'Primaria · atti parlamentari', ambito: 'Italiana', ambitoEsteso: 'Italiana · Italia · italiano', lingua: 'IT · it',
    accesso: 'Libero', accessoEsteso: 'Libero, senza registrazione', copertura: '1848–oggi', verifica: 'Raggiungibile · 09/2026',
    verificaEstesa: '12/09/2026 · raggiungibile · contenuto riscontrato', schede: 82,
    url: 'https://storia.camera.it/', urlLabel: 'storia.camera.it',
    affidabile: 'Pubblicazione ufficiale dei resoconti d’assemblea e di commissione: il testo degli interventi e degli esiti di voto è quello verbalizzato.',
    limiti: 'Il resoconto registra ciò che è stato detto in aula, non la sua veridicità. La ricerca full-text sulle legislature più antiche è irregolare.',
    uso: 'Partire dalla legislatura e dalla data di seduta, non dal motore di ricerca: il resoconto stenografico è l’unità da citare.',
    esempio: 'Esempio di localizzatore: Camera dei deputati, XI legislatura, seduta del 29 aprile 1993, resoconto stenografico.',
    usi: [['E48','Mani Pulite e Tangentopoli','primaria'],['E53','Referendum del 1993 e nuova legge elettorale','primaria'],['E79','La Bicamerale D’Alema','primaria'],['E38','Le liste della P2 e la commissione d’inchiesta','primaria']] },
  { id: 'SEN', materiale: 'Fondo archivistico', titolo: 'Archivio storico del Senato', ente: 'Senato della Repubblica', sottotitolo: 'Senato della Repubblica · fondi e atti · copertura 1848–oggi',
    natura: 'Primaria', naturaEstesa: 'Primaria · atti e fondi archivistici', ambito: 'Italiana', ambitoEsteso: 'Italiana · Italia · italiano', lingua: 'IT · it',
    accesso: 'Parziale in sede', accessoEsteso: 'Parte dei fondi consultabile solo in sede', copertura: '1848–oggi', verifica: 'Da riverificare',
    verificaEstesa: '12/09/2026 · raggiungibile · inventari da riscontrare', schede: 9,
    url: 'https://www.senato.it/istituzione/la-storia-del-senato/archivio-storico', urlLabel: 'senato.it — archivio storico',
    affidabile: 'Conserva e descrive i fondi dell’istituzione: inventari redatti da archivisti, con segnature stabili.',
    limiti: 'Digitalizzazione parziale: molti fascicoli si consultano in sede, su richiesta. La verifica dei collegamenti è ancora in corso.',
    uso: 'Usare gli inventari per individuare fondo, serie e fascicolo; il link al portale non è un localizzatore.',
    esempio: 'Esempio di localizzatore: Senato della Repubblica, Archivio storico, fondo …, b. …, fasc. … .',
    usi: [['E53','Referendum del 1993 e nuova legge elettorale','contesto'],['E86','Referendum sul Titolo V','contesto']] },
  { id: 'DDI', materiale: 'Documento diplomatico', titolo: 'Documenti diplomatici italiani', ente: 'Ministero degli Affari Esteri', sottotitolo: 'Ministero degli Affari Esteri · documento diplomatico · copertura 1861–1980',
    natura: 'Primaria', naturaEstesa: 'Primaria · documento diplomatico', ambito: 'Italiana', ambitoEsteso: 'Italiana · Italia · italiano', lingua: 'IT · it',
    accesso: 'Libero', accessoEsteso: 'Volumi digitalizzati ad accesso libero', copertura: '1861–1980', verifica: 'Raggiungibile · 09/2026',
    verificaEstesa: '12/09/2026 · raggiungibile · contenuto riscontrato', schede: 11,
    url: 'https://www.farnesina.ipzs.it/', urlLabel: 'farnesina.ipzs.it',
    affidabile: 'Edizione documentaria ufficiale della diplomazia italiana, curata da una commissione di storici con apparato critico.',
    limiti: 'Selezione operata dall’amministrazione e serie ancora aperte: l’assenza di un documento non prova l’assenza del fatto.',
    uso: 'Citare serie, volume e numero di documento; confrontare la posizione italiana con FRUS e con le fonti dell’altro paese.',
    esempio: 'Esempio di localizzatore: DDI, serie XI, vol. …, doc. …, telegramma del … .',
    usi: [['M02','NATO: sicurezza e sovranità','primaria'],['M01','Piano Marshall: aiuti e scelta occidentale','primaria']] },
  { id: 'FRUS', materiale: 'Documento diplomatico', titolo: 'Foreign Relations of the United States', ente: 'Office of the Historian, U.S. Dept. of State', sottotitolo: 'Office of the Historian, U.S. Department of State · documento diplomatico · copertura 1945–1991',
    natura: 'Primaria', naturaEstesa: 'Primaria · documento diplomatico', ambito: 'Internazionale', ambitoEsteso: 'Internazionale · Stati Uniti · inglese', lingua: 'US · en',
    accesso: 'Libero', accessoEsteso: 'Libero, senza registrazione', copertura: '1945–1991', verifica: 'Raggiungibile · 09/2026',
    verificaEstesa: '12/09/2026 · raggiungibile · contenuto riscontrato', schede: 7,
    url: 'https://history.state.gov/historicaldocuments', urlLabel: 'history.state.gov/historicaldocuments',
    affidabile: 'Edizione documentaria ufficiale curata da storici del Dipartimento di Stato, con criteri di selezione dichiarati e apparato di note.',
    limiti: 'Fonte primaria selezionata dal governo statunitense: documenta la posizione americana. Da confrontare con documenti italiani e di altri paesi.',
    uso: 'Cercare “Italy” insieme al nome della crisi internazionale; risalire sempre al singolo documento e citarlo con volume, numero e data, non con il rimando al portale.',
    esempio: 'Esempio di localizzatore: FRUS 1977–1980, vol. XXII, doc. 114, telegramma del 3 aprile 1978.',
    usi: [['M01','Piano Marshall: aiuti e scelta occidentale','primaria'],['M02','NATO: sicurezza e sovranità','primaria'],['M10','Cile 1973 e compromesso storico','primaria'],['M16','Achille Lauro e Sigonella','primaria'],['E46','La rivelazione di Gladio','contesto']] },
  { id: 'EURLEX', materiale: 'Testo normativo', titolo: 'EUR-Lex — testi dei trattati', ente: 'Unione europea', sottotitolo: 'Unione europea · testi normativi · copertura 1951–oggi',
    natura: 'Primaria', naturaEstesa: 'Primaria · testo normativo', ambito: 'Internazionale', ambitoEsteso: 'Internazionale · Unione europea · multilingue', lingua: 'EU · it/en',
    accesso: 'Libero', accessoEsteso: 'Libero, senza registrazione', copertura: '1951–oggi', verifica: 'Raggiungibile · 09/2026',
    verificaEstesa: '12/09/2026 · raggiungibile · contenuto riscontrato', schede: 5,
    url: 'https://eur-lex.europa.eu/legal-content/IT/TXT/?uri=celex%3A11992M%2FTXT', urlLabel: 'eur-lex.europa.eu — trattato di Maastricht',
    affidabile: 'Testo consolidato pubblicato dall’Unione europea, con identificativo stabile CELEX e versioni linguistiche ufficiali.',
    limiti: 'Il testo dice cosa è stato deciso, non come è stato applicato: per gli effetti sull’Italia servono fonti nazionali.',
    uso: 'Citare per identificativo CELEX e articolo, indicando la versione linguistica consultata.',
    esempio: 'Esempio di localizzatore: trattato sull’Unione europea, CELEX 11992M/TXT, protocollo sui criteri di convergenza.',
    usi: [['M24','Trattato di Maastricht e parametri','primaria'],['M30','L’Italia ammessa nell’euro','primaria'],['E80','Eurotassa e manovra per l’ammissione','contesto']] },
  { id: 'COL', materiale: 'Libro', titolo: 'Storia politica della Repubblica. 1943–2006', ente: 'Simona Colarizi · Laterza, 2007', sottotitolo: 'Simona Colarizi · Laterza, 2007 · ISBN 9788842082590',
    natura: 'Storiografica', naturaEstesa: 'Storiografica · monografia', ambito: 'Italiana', ambitoEsteso: 'Italiana · Italia · italiano', lingua: 'IT · it',
    accesso: 'Biblioteca', accessoEsteso: 'In biblioteca · ISBN 9788842082590', copertura: '1943–2006', verifica: 'ISBN riscontrato',
    verificaEstesa: '12/09/2026 · ISBN riscontrato in OPAC SBN', schede: 34,
    url: 'https://opac.sbn.it/', urlLabel: 'opac.sbn.it — ricerca per ISBN',
    affidabile: 'Sintesi storiografica di riferimento, con bibliografia e apparato di note, scritta da una studiosa della storia repubblicana.',
    limiti: 'È un’interpretazione, non una prova: serve per inquadrare, non per accertare un fatto puntuale.',
    uso: 'Citare capitolo e pagine dell’edizione consultata; per i fatti risalire alle fonti primarie richiamate in nota.',
    esempio: 'Esempio di localizzatore: S. Colarizi, Storia politica della Repubblica, Laterza 2007, cap. …, pp. … .',
    usi: [['E48','Mani Pulite e Tangentopoli','storiografia'],['P10','La crisi della Prima Repubblica','storiografia'],['B11','Bettino Craxi','storiografia']] },
  { id: 'NYT', materiale: 'Archivio di giornale', titolo: 'TimesMachine', ente: 'The New York Times', sottotitolo: 'The New York Times · archivio di giornale · copertura 1851–oggi',
    natura: 'Strumento', naturaEstesa: 'Strumento · archivio di giornale', ambito: 'Internazionale', ambitoEsteso: 'Internazionale · Stati Uniti · inglese', lingua: 'US · en',
    accesso: 'A pagamento', accessoEsteso: 'A pagamento, con abbonamento', copertura: '1851–oggi', verifica: 'A pagamento · 09/2026',
    verificaEstesa: '12/09/2026 · raggiungibile · accesso a pagamento', schede: 3,
    url: 'https://timesmachine.nytimes.com/browser', urlLabel: 'timesmachine.nytimes.com',
    affidabile: 'Riproduzione in facsimile delle pagine pubblicate: consente di datare con esattezza la notizia e il rilievo che ricevette.',
    limiti: 'È stampa quotidiana straniera: racconta come il fatto fu percepito negli Stati Uniti, non che cosa accadde. Accesso a pagamento.',
    uso: 'Citare data, pagina e titolo dell’articolo; usarlo per la percezione internazionale, non come prova del fatto.',
    esempio: 'Esempio di localizzatore: The New York Times, 24 maggio 1992, p. A1.',
    usi: [['E49','La strage di Capaci','contesto'],['M16','Achille Lauro e Sigonella','contesto']] },
  { id: 'RAD', materiale: 'Registrazione audio', titolo: 'Archivio di Radio Radicale', ente: 'Radio Radicale', sottotitolo: 'Radio Radicale · registrazioni audio · copertura 1976–oggi',
    natura: 'Primaria', naturaEstesa: 'Primaria · registrazione audio', ambito: 'Italiana', ambitoEsteso: 'Italiana · Italia · italiano', lingua: 'IT · it',
    accesso: 'Libero', accessoEsteso: 'Libero, senza registrazione', copertura: '1976–oggi', verifica: 'Raggiungibile · 09/2026',
    verificaEstesa: '12/09/2026 · raggiungibile · contenuto riscontrato', schede: 14,
    url: 'https://www.radioradicale.it/', urlLabel: 'radioradicale.it',
    affidabile: 'Registrazioni integrali di congressi, processi e sedute: si ascolta la fonte per intero, senza montaggio.',
    limiti: 'La copertura dipende da ciò che fu registrato; la descrizione dei file è disomogenea e la ricerca richiede pazienza.',
    uso: 'Citare titolo della registrazione, data e minutaggio del passaggio utilizzato.',
    esempio: 'Esempio di localizzatore: Radio Radicale, registrazione del …, min. 1:12:40.',
    usi: [['E48','Mani Pulite e Tangentopoli','primaria'],['B11','Bettino Craxi','primaria'],['E41','Referendum sulla scala mobile','primaria']] },
  { id: 'WIKI', materiale: 'Programma radiofonico', titolo: 'Wikiradio. Le voci della storia', ente: 'Rai Radio 3', sottotitolo: 'Rai Radio 3 · divulgazione radiofonica · copertura 2011–oggi',
    natura: 'Divulgazione', naturaEstesa: 'Divulgazione · programma radiofonico', ambito: 'Italiana', ambitoEsteso: 'Italiana · Italia · italiano', lingua: 'IT · it',
    accesso: 'Libero', accessoEsteso: 'Libero, senza registrazione', copertura: '2011–oggi', verifica: 'Raggiungibile · 09/2026',
    verificaEstesa: '12/09/2026 · raggiungibile · contenuto riscontrato', schede: 21,
    url: 'https://www.raiplaysound.it/programmi/wikiradio', urlLabel: 'raiplaysound.it/programmi/wikiradio',
    affidabile: 'Puntate affidate a studiosi identificati: utile per un primo inquadramento, con autore dichiarato.',
    limiti: 'È divulgazione: non conta per i minimi di verifica di una scheda e non sostituisce la fonte primaria.',
    uso: 'Usarla per orientarsi e per i suggerimenti di ascolto; citare titolo della puntata, autore e data di trasmissione.',
    esempio: 'Esempio di localizzatore: Wikiradio, puntata del …, a cura di … .',
    usi: [['E48','Mani Pulite e Tangentopoli','divulgazione'],['M24','Trattato di Maastricht e parametri','divulgazione']] }
];

const WB_DATA = '12/09/2026';
const WB = u => 'https://web.archive.org/web/20260912000000/' + u;

// Localizzatore accertato per coppia fonte|scheda. url presente = documento raggiungibile.
const DOC = {
  'CAM|E53': { url: 'https://storia.camera.it/lavori/sedute-assemblea', etichettaUrl: 'Resoconti d\u2019assemblea', loc: 'XI legislatura, seduta del 29 aprile 1993, resoconto stenografico' },
  'CAM|E48': { loc: 'XI legislatura, sedute sul finanziamento dei partiti \u2014 numero di seduta da accertare' },
  'CAM|E79': { loc: 'XIII legislatura, Commissione bicamerale per le riforme \u2014 seduta da accertare' },
  'CAM|E38': { loc: 'VIII legislatura, Commissione d\u2019inchiesta sulla loggia P2 \u2014 volume da accertare' },
  'FRUS|M01': { url: 'https://history.state.gov/historicaldocuments/frus1947v03', etichettaUrl: 'FRUS 1947, vol. III', loc: 'FRUS 1947, vol. III (The British Commonwealth; Europe), sezione sull\u2019European Recovery Program' },
  'FRUS|M02': { url: 'https://history.state.gov/historicaldocuments/frus1949v04', etichettaUrl: 'FRUS 1949, vol. IV', loc: 'FRUS 1949, vol. IV (Western Europe), documenti sul Patto atlantico' },
  'FRUS|M10': { loc: 'FRUS 1969\u20131976, volume sul Cile \u2014 numero di documento da accertare' },
  'FRUS|M16': { loc: 'FRUS 1981\u20131988: il volume che copre Sigonella non \u00e8 ancora pubblicato' },
  'FRUS|E46': { loc: 'Volume e documento da indicare in revisione' },
  'EURLEX|M24': { url: 'https://eur-lex.europa.eu/legal-content/IT/TXT/?uri=celex%3A11992M%2FTXT', etichettaUrl: 'Testo del trattato (CELEX)', loc: 'Trattato sull\u2019Unione europea, CELEX 11992M/TXT, protocollo sui criteri di convergenza' },
  'EURLEX|M30': { url: 'https://eur-lex.europa.eu/legal-content/IT/TXT/?uri=celex%3A31998D0317', etichettaUrl: 'Decisione 98/317/CE', loc: 'Decisione del Consiglio 98/317/CE, CELEX 31998D0317, ammissione alla terza fase' },
  'DDI|M01': { loc: 'DDI, serie decima \u2014 volume e numero di documento da accertare' },
  'DDI|M02': { loc: 'DDI, serie undicesima \u2014 volume e numero di documento da accertare' },
  'NYT|E49': { url: 'https://timesmachine.nytimes.com/browser/1992/05/24', etichettaUrl: 'Edizione del 24/05/1992', loc: 'The New York Times, 24 maggio 1992, pagina da accertare' },
  'NYT|M16': { loc: 'The New York Times, ottobre 1985 \u2014 data e pagina da accertare' }
};

const CERCA = {
  FRUS: { base: 'https://history.state.gov/search?q=', label: 'Cerca nel repertorio FRUS' },
  CAM: { base: 'https://storia.camera.it/', label: 'Portale della Camera' },
  SEN: { base: 'https://www.senato.it/istituzione/la-storia-del-senato/archivio-storico', label: 'Inventari del Senato' },
  DDI: { base: 'https://www.farnesina.ipzs.it/', label: 'Volumi DDI' },
  EURLEX: { base: 'https://eur-lex.europa.eu/homepage.html?locale=it', label: 'Cerca in EUR-Lex' },
  COL: { base: 'https://opac.sbn.it/', label: 'Cerca in OPAC SBN' },
  NYT: { base: 'https://timesmachine.nytimes.com/browser', label: 'Sfoglia TimesMachine' },
  RAD: { base: 'https://www.radioradicale.it/', label: 'Archivio Radio Radicale' },
  WIKI: { base: 'https://www.raiplaysound.it/programmi/wikiradio', label: 'Puntate di Wikiradio' }
};

const GRUPPI = [
  { natura: 'Primaria', nome: 'Primarie' },
  { natura: 'Storiografica', nome: 'Storiografia' },
  { natura: 'Strumento', nome: 'Strumenti e stampa' },
  { natura: 'Divulgazione', nome: 'Per ascoltare e vedere', nota: 'Divulgazione: utile per orientarsi, non conta per i minimi di verifica.' }
];

function etichettaFonte(f) {
  const p = (f.naturaEstesa || '').split('\u00b7');
  const e = (p[1] || f.natura || '').trim();
  return e ? e.charAt(0).toUpperCase() + e.slice(1) : f.natura;
}

function loc(idFonte, idScheda) {
  const d = DOC[idFonte + '|' + idScheda];
  return (d && d.loc) || 'Localizzatore da indicare in revisione';
}

function voce(f, idScheda, titoloScheda) {
  const d = DOC[f.id + '|' + idScheda] || {};
  const c = CERCA[f.id] || { base: f.url, label: 'Apri il portale' };
  const cercaUrl = d.url ? d.url : (c.base.slice(-1) === '=' ? c.base + encodeURIComponent(titoloScheda || '') : c.base);
  const target = d.url || cercaUrl;
  return {
    idFonte: f.id, titolo: f.titolo, ente: f.ente, lingua: f.lingua, ambito: f.ambito,
    etichetta: etichettaFonte(f),
    linkFonte: 'Fonte.dc.html?id=' + f.id,
    localizzatore: (d.loc || 'Localizzatore da indicare in revisione'),
    haDoc: !!d.url, senzaDoc: !d.url,
    docUrl: d.url || '', docLabel: d.etichettaUrl || 'Documento',
    cercaUrl: cercaUrl, cercaLabel: c.label,
    archivio: WB(target), dataArchivio: WB_DATA,
    avviso: 'Il pulsante Documento si attiva quando il localizzatore \u00e8 accertato: per ora si apre la ricerca sul repertorio.'
  };
}

function gruppiFonti(idScheda, titoloScheda) {
  const usate = FONTI.filter(f => (f.usi || []).some(u => u[0] === idScheda));
  return GRUPPI.map(g => ({
    nome: g.nome, nota: g.nota || '',
    voci: usate.filter(f => f.natura === g.natura).map(f => voce(f, idScheda, titoloScheda))
  })).filter(g => g.voci.length);
}

window.ATLANTE = { FONTI: FONTI, gruppiFonti: gruppiFonti, loc: loc, WB: WB, WB_DATA: WB_DATA };
