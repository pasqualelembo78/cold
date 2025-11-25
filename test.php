<?php
$ch = curl_init('https://www.mevacoin.com/wallet-api');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
if(curl_errno($ch)) { echo curl_error($ch); }
else { echo $res; }
curl_close($ch);
?>
