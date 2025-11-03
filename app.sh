#!/bin/bash
# Script per installazione Cold Wallet App Ionic + Capacitor + Android + clonazione repo www
# Migliorato: backup se esiste la cartella, controlli, output informativi.
set -euo pipefail

PROJECT_DIR="$HOME/coldwallet"
REPO_URL="https://github.com/pasqualelembo78/cold.git"
TIMESTAMP="$(date +%Y%m%d%H%M%S)"
JAVA_PACKAGE="openjdk-17-jdk"

echo "==== Avvio script di installazione Cold Wallet ===="

echo "==== 1) Aggiornamento sistema ===="
sudo apt update && sudo apt upgrade -y

echo "==== 2) Installazione dipendenze di base ===="
sudo apt install -y curl git build-essential unzip ${JAVA_PACKAGE} ca-certificates

echo "==== 3) Installazione Node.js LTS (NodeSource) ===="
curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
sudo apt install -y nodejs

echo "==== 4) Verifica versioni ===="
echo -n "node: " && node -v
echo -n "npm:  " && npm -v
echo -n "java: " && java -version 2>&1 | head -n 1

echo "==== 5) Installazione Ionic CLI globale ===="
# Possibile che sia richiesto sudo per global install; usiamo sudo per sicurezza
sudo npm install -g @ionic/cli

# Backup se la cartella esiste
if [ -d "${PROJECT_DIR}" ]; then
  BACKUP_DIR="${PROJECT_DIR}_backup_${TIMESTAMP}"
  echo "===> La cartella ${PROJECT_DIR} già esiste. Effettuo backup in ${BACKUP_DIR}"
  mv "${PROJECT_DIR}" "${BACKUP_DIR}"
fi

echo "==== 6) Creazione progetto Ionic ===="
# Creiamo il progetto in PROJECT_DIR
mkdir -p "$(dirname "${PROJECT_DIR}")"
cd "$(dirname "${PROJECT_DIR}")"
# Usare --no-interactive per non far richiedere scelte
ionic start "$(basename "${PROJECT_DIR}")" blank --type=angular --no-interactive

cd "${PROJECT_DIR}"

echo "==== 7) Installazione Capacitor ===="
npm install @capacitor/core @capacitor/cli --save

echo "==== 8) Configurazione Capacitor (capacitor.config.ts) ===="
cat > capacitor.config.ts <<'EOL'
import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.pasquale.mevacoinwallet',
  appName: 'Mevacoin Cold Wallet',
  webDir: 'www',
  bundledWebRuntime: false
};

export default config;
EOL

echo "==== 9) Creazione cartella www e clonazione web app ===="
# Rimuoviamo la www solo se è una directory vuota o preesistente generata da ionic start
if [ -d "www" ]; then
  echo "=> Rimuovo la cartella www generata e la sostituisco con il repo indicato."
  rm -rf www
fi

# Cloniamo con --depth 1 per velocizzare; se il repo richiede credenziali fallirà
git clone --depth 1 "${REPO_URL}" www || {
  echo "ATTENZIONE: clonazione fallita. Verifica l'URL del repo o le credenziali."
  exit 1
}

echo "==== 10) Installazione Android platform ===="
npm install @capacitor/android --save

echo "==== 11) Aggiunta piattaforma Android ===="
npx cap add android

echo "==== 12) Correzione compatibilità Java 17 (tentativo mirato) ===="
# Cerchiamo riferimenti a JavaVersion.VERSION_21 e li sostituiamo con VERSION_17 solo nei file trovati.
echo "=> Cerco riferimenti a JavaVersion.VERSION_21..."
FILES_TO_FIX=$(grep -RIl "JavaVersion\.VERSION_21" android node_modules 2>/dev/null || true)
if [ -n "${FILES_TO_FIX}" ]; then
  echo "=> Modifico i file trovati per usare VERSION_17 (backup .bak creato)..."
  while IFS= read -r f; do
    cp "$f" "$f.bak_${TIMESTAMP}"
    sed -i 's/JavaVersion\.VERSION_21/JavaVersion.VERSION_17/g' "$f"
    echo "  patched: $f"
  done <<< "${FILES_TO_FIX}"
else
  echo "=> Nessun file con JavaVersion.VERSION_21 trovato. Nessuna modifica effettuata."
fi

echo "==== 13) Sincronizzazione progetto ===="
npx cap sync

echo "==== 14) Istruzioni finali e avvisi ===="
cat <<EOF

Installazione completata (parzialmente automatica).

Passi successivi consigliati:
1) Apri Android Studio per completare la configurazione SDK:
   npx cap open android
   - In Android Studio: assicurati di avere installato l'Android SDK, il build tools e accetta le licenze.
   - Se necessario, configura JAVA_HOME/Android SDK nelle impostazioni di Android Studio.

2) Se incontri problemi con la versione di Gradle o il Java toolchain:
   - Puoi provare a impostare org.gradle.java.home nel file android/gradle.properties verso la cartella JDK 17.
   - Controlla i file *.bak_${TIMESTAMP} se qualcosa è stato modificato e vuoi ripristinare.

3) Costruisci l'app (dopo aver configurato Android Studio/SDK):
   - dalla root del progetto:
     npx cap open android
     poi Build > Make Project in Android Studio

Note importanti:
- Questo script fa un backup automatico della cartella ${PROJECT_DIR} se esisteva: ${PROJECT_DIR}_backup_${TIMESTAMP}
- La clonazione del repo github fallirà se il repo è privato e non hai le credenziali/configurazione SSH.
- Non installa Android SDK (Android Studio), che è necessario per buildare l'app. Apri Android Studio e segui le sue richieste.
EOF

echo "==== FINE ===="
