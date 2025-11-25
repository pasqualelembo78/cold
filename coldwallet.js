 
function sendToServer() {
    importViewWallet(pubKey, keys.view.sec)
        .then(() => getBalance(pubKey))
        .then(balance => alert("Saldo: " + balance.balance))
        .catch(err => console.error("Errore:", err));
}