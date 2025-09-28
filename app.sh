#!/bin/bash
# Script per installazione Cold Wallet App Ionic + Capacitor + Android + clonazione repo www
# e correzione compatibilità Java 17

set -e

echo "==== Aggiornamento sistema ===="
sudo apt update && sudo apt upgrade -y

echo "==== Installazione dipendenze di base ===="
sudo apt install -y curl git build-essential unzip openjdk-17-jdk

echo "==== Installazione Node.js LTS ===="
curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
sudo apt install -y nodejs

echo "==== Verifica versioni ===="
node -v
npm -v
java -version

echo "==== Installazione Ionic CLI globale ===="
sudo npm install -g @ionic/cli

echo "==== Creazione progetto Ionic ===="
ionic start coldwallet blank --type=angular --no-interactive
cd coldwallet

echo "==== Installazione Capacitor ===="
npm install @capacitor/core @capacitor/cli

echo "==== Configurazione Capacitor (capacitor.config.ts) ===="
cat > capacitor.config.ts <<EOL
import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.pasquale.mevacoinwallet',
  appName: 'Mevacoin Cold Wallet',
  webDir: 'www',
  bundledWebRuntime: false
};

export default config;
EOL

echo "==== Creazione cartella www e clonazione web app ===="
rm -rf www
git clone https://github.com/pasqualelembo78/cold.git www

echo "==== Installazione Android platform ===="
npm install @capacitor/android

echo "==== Aggiunta piattaforma Android ===="
npx cap add android

echo "==== Correzione compatibilità Java 17 ===="
find android node_modules/@capacitor -type f -name "*.gradle" -exec sed -i 's/JavaVersion\.VERSION_21/JavaVersion.VERSION_17/g' {} +
find node_modules/@capacitor/cli -type f -name "*.js" -exec sed -i 's/JavaVersion\.VERSION_21/JavaVersion.VERSION_17/g' {} +

echo "==== Sincronizzazione progetto ===="
npx cap sync

echo "==== FINE ===="
echo "Ora puoi aprire Android Studio con:"
echo "npx cap open android"
