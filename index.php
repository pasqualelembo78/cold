<!DOCTYPE html>  
<html lang="it">  
<head>  
    <meta charset="UTF-8">  
    <title>Monero / Mevacoin Cold Wallet Generator</title>  
    <meta name="description" content="Lightweight client-side cold wallet generator">  
    <meta name="viewport" content="width=device-width, initial-scale=1">  
     
    <style>  
    body {  
        font-family: Arial, Helvetica, sans-serif;  
        background: #0d0d0f;  
        color: #e8e8e8;  
        padding: 20px;  
    }  
    .container {  
        max-width: 900px;  
        margin: 0 auto;  
        background: #1a1a1d;  
        padding: 20px;  
        border-radius: 10px;  
        box-shadow: 0 0 25px rgba(0,0,0,0.45);  
    }  
    h1 { margin-top: 0; color: #ffffff; text-shadow: 0 0 8px rgba(255,255,255,0.15); }  
    .button-group { margin-bottom: 15px; }  
    .button-group button {  
        margin-right: 8px; padding: 10px 14px; border-radius: 6px; border: none;  
        background: #2c2c30; color: #e8e8e8; cursor: pointer; transition: 0.2s;  
    }  
    .button-group button:hover { background: #3d3d42; }  
    .info-box { margin: 14px 0; padding: 12px; border: 1px solid #333; border-radius: 8px; background: #141416; }  
    .info-box label { display: block; font-weight: 700; color: #cfcfcf; margin-bottom: 8px; }  
    .info-box .row { display: flex; gap: 12px; align-items: center; }  
    .info-box span { word-break: break-all; background: #1d1d20; padding: 8px 10px; border-radius: 6px; border: 1px dashed #444; flex: 1; color: #e0e0e0; }  
    .qr-code { width: 120px; height: 120px; background: #0d0d0f; border-radius: 6px; padding: 6px; }  
    .controls { display: flex; gap: 8px; margin-top: 10px; }  
    .small-btn { padding: 6px 10px; font-size: 0.9rem; border-radius: 6px; border: none; background: #27272b; color: #e0e0e0; cursor: pointer; transition: 0.2s; }  
    .small-btn:hover { background: #3a3a3f; }  
    .warn { color: #ff6161; font-size: 0.95rem; margin-top: 12px; font-weight: bold; }  
    </style>  
</head>  
<body>  
    <div class="container">  
        <h1>Monero / Mevacoin Cold Wallet</h1>  
        <div class="button-group">  
            <button onclick="window.location.href='index.html'">Home</button>  
            <button onclick="generate()">Generate</button>  
            <button onclick="window.print()">Print</button>  
<button onclick="window.location.href='bilancio.php'">Bilancio</button>  
  
        </div>  
  
        <div class="info-box">  
            <label>Public Address (SHARE)</label>  
            <div class="row">  
                <span id="public">Click Generate to create wallet</span>  
                <div id="public_qr" class="qr-code"></div>  
            </div>  
            <div class="controls">  
                <button class="small-btn" onclick="copyToClipboard('public')">Copy address</button>  
            </div>  
        </div>  
  
        <div class="info-box">  
            <label>Mnemonic Seed (SECRET) — non condividerlo</label>  
            <div class="row">  
                <span id="secret">Click Generate to create wallet</span>  
                <div id="secret_qr" class="qr-code"></div>  
            </div>  
            <div class="controls">  
                <button class="small-btn" onclick="copyToClipboard('secret')">Copy seed</button>  
            </div>  
        </div>  
  
        <div class="info-box">  
            <label>Private Spend Key (SECRET)</label>  
            <div class="row">  
                <span id="spend_key">Click Generate to create wallet</span>  
                <div id="spend_qr" class="qr-code"></div>  
            </div>  
            <div class="controls">  
                <button class="small-btn" onclick="copyToClipboard('spend_key')">Copy spend key</button>  
            </div>  
        </div>  
  
        <div class="info-box">  
            <label>Private View Key (SECRET)</label>  
            <div class="row">  
                <span id="view_key">Click Generate to create wallet</span>  
                <div id="view_qr" class="qr-code"></div>  
            </div>  
            <div class="controls">  
                <button class="small-btn" onclick="copyToClipboard('view_key')">Copy view key</button>  
            </div>  
        </div>  
  
        <div class="info-box">  
            <label>Public Spend Key</label>  
            <div class="row">  
                <span id="pub_spend">Click Generate to create wallet</span>  
                <div id="pub_spend_qr" class="qr-code"></div>  
            </div>  
            <div class="controls">  
                <button class="small-btn" onclick="copyToClipboard('pub_spend')">Copy pub spend</button>  
            </div>  
        </div>  
  
        <div class="info-box">  
            <label>Public View Key</label>  
            <div class="row">  
                <span id="pub_view">Click Generate to create wallet</span>  
                <div id="pub_view_qr" class="qr-code"></div>  
            </div>  
            <div class="controls">  
                <button class="small-btn" onclick="copyToClipboard('pub_view')">Copy pub view</button>  
            </div>  
        </div>  
  
        <p class="warn">  
            ATTENZIONE: Mantieni questi dati offline e al sicuro. Chi possiede il seed o le chiavi private può controllare i fondi.  
        </p>  
    </div>  
  
    <!-- libsodium (CDN - puoi sostituire con versione locale se preferisci) -->  
    <script src="https://unpkg.com/libsodium-wrappers/dist/modules-sumo/sodium.js"></script>  
  
    <!-- Dipendenze esterne: mevacoin (cnUtil), mn_encode, qrcode. Mantienile o sostituisci con locali -->  
    <script src="js/mevacoin.js"></script>  <!-- dovrebbe definire cnUtil, mn_encode, ecc. -->  
    <script src="js/qrcode.js"></script>    <!-- libreria QRCode -->  
  
    <script type="text/javascript">  
        // --- helper: hex <-> bytes, toHex  
        function toHex(buf) {  
            if (!buf) return "";  
            if (typeof buf === 'string') return buf.replace(/^0x/, '');  
            return Array.from(buf).map(b => ('0' + (b & 0xFF).toString(16)).slice(-2)).join('');  
        }  
        function hexToBytes(hex) {  
            if (!hex) return new Uint8Array(0);  
            hex = hex.replace(/^0x/, '');  
            if (hex.length % 2) hex = '0' + hex;  
            var bytes = new Uint8Array(hex.length / 2);  
            for (var i = 0; i < hex.length; i += 2) bytes[i/2] = parseInt(hex.substr(i,2),16);  
            return bytes;  
        }  
        function ensureUint8Array(input) {  
            if (!input && input !== 0) return new Uint8Array(0);  
            if (input instanceof Uint8Array) return input;  
            if (Array.isArray(input)) return new Uint8Array(input);  
            if (typeof input === 'string') return hexToBytes(input);  
            if (input && typeof input === 'object' && input.length) return new Uint8Array(Array.prototype.slice.call(input,0));  
            return new Uint8Array(0);  
        }  
  
        function copyToClipboard(elementId) {  
            try {  
                const el = document.getElementById(elementId);  
                const text = el.textContent || el.innerText || "";  
                navigator.clipboard.writeText(text).then(function() {  
                    alert("Copiato negli appunti.");  
                }, function(err){  
                    alert("Impossibile copiare: " + err);  
                });  
            } catch (e) {  
                alert("Errore copia: " + e);  
            }  
        }  
  
        var account, keyPair, pubKey, privKey;  
        function clearQR(id) {  
            var node = document.getElementById(id);  
            if (!node) return;  
            node.innerHTML = "";  
        }  
  
        // costante l (order of group) come BigInt  
        const L = (1n << 252n) + 27742317777372353535851937790883648493n;  
  
        // converte Uint8Array(<=32) little-endian in BigInt  
        function leBytesToBigInt(buf) {  
            let n = 0n;  
            for (let i = 0; i < buf.length; i++) n += BigInt(buf[i]) << (8n * BigInt(i));  
            return n;  
        }  
        // converte BigInt in 32-byte Uint8Array little-endian  
        function intTo32LE(n) {  
            const out = new Uint8Array(32);  
            let v = n;  
            for (let i = 0; i < 32; i++) {  
                out[i] = Number(v & 0xffn);  
                v = v >> 8n;  
            }  
            return out;  
        }  
  
        // Keccak256: prova varie implementazioni disponibili  
        function keccak256_bytes(inputUint8) {  
            // 1) cnUtil.keccak (es. presente in mevacoin.js)  
            try {  
                if (typeof cnUtil !== 'undefined' && typeof cnUtil.keccak === 'function') {  
                    var h = cnUtil.keccak(inputUint8);  
                    if (typeof h === 'string') return hexToBytes(h);  
                    return ensureUint8Array(h);  
                }  
            } catch(e) {}  
            // 2) cnUtil.keccak_256  
            try {  
                if (typeof cnUtil !== 'undefined' && typeof cnUtil.keccak_256 === 'function') {  
                    var hh = cnUtil.keccak_256(inputUint8);  
                    if (typeof hh === 'string') return hexToBytes(hh);  
                    return ensureUint8Array(hh);  
                }  
            } catch(e) {}  
            // 3) window.keccak256 (es. js-sha3)  
            try {  
                if (typeof keccak256 === 'function') {  
                    var hx = keccak256(Array.from(inputUint8));  
                    return hexToBytes(hx);  
                }  
            } catch(e) {}  
            // 4) window.sha3_256  
            try {  
                if (typeof sha3_256 === 'function') {  
                    var hx2 = sha3_256(Array.from(inputUint8));  
                    return hexToBytes(hx2);  
                }  
            } catch(e) {}  
            // se non trovata  
            throw new Error("Keccak256 non trovata: aggiungi cnUtil.keccak o una libreria js-sha3.");  
        }  
  
        // Trova funzione scalar base (no-clamp) - ora async per libsodium.ready  
        async function getScalarBaseFunction() {  
            // Prefer cnUtil (se presente)  
            if (typeof cnUtil !== 'undefined') {  
                if (typeof cnUtil.ge_scalarmult_base === 'function') return function(sec){ return cnUtil.ge_scalarmult_base(sec); };  
                if (typeof cnUtil.scalarMultBase === 'function') return function(sec){ return cnUtil.scalarMultBase(sec); };  
                if (typeof cnUtil.scalarmultBase === 'function') return function(sec){ return cnUtil.scalarmultBase(sec); };  
                if (typeof cnUtil.scalarmult_base === 'function') return function(sec){ return cnUtil.scalarmult_base(sec); };  
            }  
  
            // libsodium-wrappers (CDN) — attendi sodium.ready e poi usa la funzione no-clamp  
            if (typeof sodium !== 'undefined') {  
                try {  
                    await sodium.ready;  
                    if (typeof sodium.crypto_scalarmult_ed25519_base_noclamp === 'function') {  
                        return function(sec){  
                            // accetta Uint8Array o hex string  
                            var arr = ensureUint8Array(sec);  
                            // sodium ritorna Uint8Array  
                            return sodium.crypto_scalarmult_ed25519_base_noclamp(arr);  
                        };  
                    }  
                } catch(e) {  
                    console.warn("libsodium disponibile ma ready() fallita:", e);  
                }  
            }  
  
            // Fallback avvertimento: non usare tweetnacl.scalarMult (X25519) per ed25519  
            if (typeof nacl !== 'undefined' && nacl.scalarMult && typeof nacl.scalarMult.base === 'function') {  
                console.warn("ATTENZIONE: usando fallback X25519 (nacl.scalarMult.base) — risultati potrebbero NON corrispondere a Ed25519 no-clamp.");  
                return function(sec){  
                    var arr = ensureUint8Array(sec);  
                    try {  
                        return nacl.scalarMult.base(arr);  
                    } catch(e) {  
                        return null;  
                    }  
                };  
            }  
  
            // Nessuna funzione trovata  
            return null;  
        }  
  
        // generate: rende async per poter await getScalarBaseFunction()  
        async function generate() {  
            // Controlli minimi  
            if (typeof mn_encode === "undefined" || typeof QRCode === "undefined") {  
                alert("Errore: mancano librerie obbligatorie (mn_encode / QRCode). Verifica che js/mevacoin.js e js/qrcode.js siano corretti.");  
                return;  
            }  
  
            // avvisa se libsodium non è caricato (ma continuiamo e proveremo fallback)  
            if (typeof sodium === 'undefined') {  
                console.warn("libsodium non trovato: prova a includere libsodium-wrappers (consigliato). Verranno usati fallback se disponibili.");  
            }  
  
            var current_lang = "english";  
  
            // genera un seed random ridotto a 32 byte (sc_reduce32 già riduce)  
            var seed;  
            try {  
                seed = cnUtil.sc_reduce32(cnUtil.rand_32()); // Uint8Array o array di byte  
            } catch(e) {  
                // fallback: usa crypto random  
                try {  
                    var tmp = new Uint8Array(64);  
                    window.crypto.getRandomValues(tmp);  
                    seed = tmp.slice(0,32);  
                } catch(e2) {  
                    alert("Impossibile generare seed random: " + e2);  
                    return;  
                }  
            }  
  
            // crea address secondo cnUtil (se disponibile)  
            var keys = (typeof cnUtil !== 'undefined' && typeof cnUtil.create_address === 'function') ? cnUtil.create_address(seed) : null;  
  
            // mnemonic seed (parole)  
            try {  
                privKey = mn_encode(seed, current_lang);  
            } catch(e) {  
                privKey = "ERROR: mn_encode assente o fallita";  
            }  
  
            // address pubblico (stringa) tramite cnUtil se possibile  
            if (keys && typeof cnUtil.pubkeys_to_string === 'function') {  
                pubKey = cnUtil.pubkeys_to_string(keys.spend.pub, keys.view.pub);  
            } else {  
                pubKey = "N/A (cnUtil mancante)";  
            }  
  
            // private keys dai keys se forniti (possono essere hex o array)  
            var spendKey = (keys && keys.spend && keys.spend.sec) ? keys.spend.sec : (keys && keys.spend ? keys.spend : null);  
            var viewKey  = (keys && keys.view  && keys.view.sec)  ? keys.view.sec  : (keys && keys.view ? keys.view : null);  
  
            // Normalizza a Uint8Array  
            var spendSecArr = ensureUint8Array(spendKey);  
            var viewSecArr  = ensureUint8Array(viewKey);  
  
            // --- Qui calcoliamo le public key seguendo l'algoritmo Python (no-clamp) ---  
            var private_spend_input = (spendSecArr.length === 32) ? spendSecArr : ensureUint8Array(seed);  
            var spend_int = leBytesToBigInt(private_spend_input) % L;  
            var private_spend_key = intTo32LE(spend_int); // 32 bytes LE  
  
            // Trova funzione scalar-base compatibile (attende libsodium se necessario)  
            var scalarBaseFn = await getScalarBaseFunction();  
  
            if (!scalarBaseFn) {  
                console.error("Funzione per scalarMultBase non trovata. Inserisci ge_scalarmult_base in cnUtil o libsodium.");  
                if (keys && keys.spend && keys.spend.pub) {  
                    document.getElementById("pub_spend").textContent = toHex(ensureUint8Array(keys.spend.pub));  
                } else {  
                    document.getElementById("pub_spend").textContent = "ERROR: scalarBase missing";  
                }  
                if (keys && keys.view && keys.view.pub) {  
                    document.getElementById("pub_view").textContent = toHex(ensureUint8Array(keys.view.pub));  
                } else {  
                    document.getElementById("pub_view").textContent = "ERROR: scalarBase missing";  
                }  
            } else {  
                // public spend = scalarBase(private_spend_key)  
                var pubSpendRaw = null;  
                try {  
                    pubSpendRaw = scalarBaseFn(private_spend_key);  
                } catch(e) {  
                    try { pubSpendRaw = scalarBaseFn(toHex(private_spend_key)); } catch(e2) { pubSpendRaw = null; }  
                }  
                if (!pubSpendRaw) {  
                    try {  
                        var alt = ensureUint8Array(private_spend_key).slice().reverse();  
                        pubSpendRaw = scalarBaseFn(alt);  
                    } catch(e) { pubSpendRaw = null; }  
                }  
                if (typeof pubSpendRaw === 'string') pubSpendRaw = hexToBytes(pubSpendRaw);  
                if (pubSpendRaw && pubSpendRaw.length) {  
                    var pubSpendHex = toHex(ensureUint8Array(pubSpendRaw));  
                    document.getElementById("pub_spend").textContent = pubSpendHex;  
                    try { clearQR("pub_spend_qr"); new QRCode(document.getElementById("pub_spend_qr"), { text: pubSpendHex, width: 100, height: 100, correctLevel: QRCode.CorrectLevel.H }); } catch(e){}  
                } else {  
                    document.getElementById("pub_spend").textContent = "ERROR: calcolo fallito";  
                }  
  
                // Deriva private view: keccak(private_spend_key) mod L  
                var keccakBytes;  
                try {  
                    keccakBytes = keccak256_bytes(private_spend_key); // 32 byte  
                } catch(err) {  
                    console.error("Keccak error:", err);  
                    document.getElementById("pub_view").textContent = "ERROR: keccak missing";  
                    keccakBytes = null;  
                }  
  
                if (keccakBytes) {  
                    var private_view_int = leBytesToBigInt(keccakBytes) % L;  
                    var private_view_key = intTo32LE(private_view_int);  
  
                    // public view = scalarBase(private_view_key)  
                    var pubViewRaw = null;  
                    try {  
                        pubViewRaw = scalarBaseFn(private_view_key);  
                    } catch(e) {  
                        try { pubViewRaw = scalarBaseFn(toHex(private_view_key)); } catch(e2) { pubViewRaw = null; }  
                    }  
                    if (!pubViewRaw) {  
                        try {  
                            var alt2 = private_view_key.slice().reverse();  
                            pubViewRaw = scalarBaseFn(alt2);  
                        } catch(e) { pubViewRaw = null; }  
                    }  
                    if (typeof pubViewRaw === 'string') pubViewRaw = hexToBytes(pubViewRaw);  
                    if (pubViewRaw && pubViewRaw.length) {  
                        var pubViewHex = toHex(ensureUint8Array(pubViewRaw));  
                        document.getElementById("pub_view").textContent = pubViewHex;  
                        try { clearQR("pub_view_qr"); new QRCode(document.getElementById("pub_view_qr"), { text: pubViewHex, width: 100, height: 100, correctLevel: QRCode.CorrectLevel.H }); } catch(e){}  
                    } else {  
                        document.getElementById("pub_view").textContent = "ERROR: calcolo fallito";  
                    }  
  
                    // Mostra anche la private view derivata per verifica  
                    document.getElementById("view_key").textContent = toHex(private_view_key);  
                    try { clearQR("view_qr"); new QRCode(document.getElementById("view_qr"), { text: toHex(private_view_key), width: 100, height: 100, correctLevel: QRCode.CorrectLevel.H }); } catch(e){}  
                }  
            }  
  
            // mostra tutto il resto nella pagina  
            document.getElementById("public").textContent = pubKey;  
            document.getElementById("secret").textContent = privKey;  
            document.getElementById("spend_key").textContent = toHex(private_spend_key); // mostra la private spend normalized  
            // view_key già impostata se derivata sopra; altrimenti se viewSecArr esiste la mostriamo  
            if ((!document.getElementById("view_key").textContent || document.getElementById("view_key").textContent.startsWith("Click")) && viewSecArr.length===32) {  
                document.getElementById("view_key").textContent = toHex(viewSecArr);  
                try { clearQR("view_qr"); new QRCode(document.getElementById("view_qr"), { text: toHex(viewSecArr), width: 100, height: 100, correctLevel: QRCode.CorrectLevel.H }); } catch(e){}  
            }  
  
            // pulisci QR precedenti (address/seed/spend/view già gestiti)  
            clearQR("public_qr");  
            clearQR("secret_qr");  
            // crea QR (dimensione 100x100)  
            try { new QRCode(document.getElementById("public_qr"), { text: pubKey, width: 100, height: 100, correctLevel: QRCode.CorrectLevel.H }); } catch(e){}  
            try { new QRCode(document.getElementById("secret_qr"), { text: privKey, width: 100, height: 100, correctLevel: QRCode.CorrectLevel.H }); } catch(e){}  
            try { new QRCode(document.getElementById("spend_qr"), { text: toHex(private_spend_key), width: 100, height: 100, correctLevel: QRCode.CorrectLevel.H }); } catch(e){}  
        }  
  
        // avviare dopo il caricamento della pagina  
        window.addEventListener('load', function(){ generate(); });  
    </script>  
</body>  
</html>