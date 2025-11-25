 
const API_KEY = "desy2011";
const WALLET_API_URL = "http://127.0.0.1:8070";

async function importViewWallet(address, viewKey, filename = "wallet_view.dat", password = "desy2011") {
    const payload = {
        filename,
        password,
        address,
        viewKey
    };

    const res = await fetch(`${WALLET_API_URL}/wallet/import/view`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-API-KEY": API_KEY
        },
        body: JSON.stringify(payload)
    });

    return res.json();
}

async function getBalance(address) {
    const res = await fetch(`${WALLET_API_URL}/balance/${address}`, {
        method: "GET",
        headers: {
            "X-API-KEY": API_KEY
        }
    });

    return res.json();
}