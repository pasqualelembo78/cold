 <?php
// rate_limit.php
// Gestione rate limit con Redis

class RateLimiter
{
    private $redis;

    public function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect(93.40.195.135, 6379);
    }

    /**
     * Funzione generica rate limit
     */
    public function check($key, $limit, $seconds)
    {
        $count = $this->redis->incr($key);

        if ($count === 1) {
            $this->redis->expire($key, $seconds);
        }

        return $count > $limit;
    }

    /**
     * Rate limit per IP
     */
    public function limitIP($ip, $limit = 10, $seconds = 60)
    {
        $key = "rate:ip:$ip";
        return $this->check($key, $limit, $seconds);
    }

    /**
     * Rate limit per API KEY
     */
    public function limitAPIKey($apiKey, $limit = 200, $seconds = 60)
    {
        $key = "rate:apikey:$apiKey";
        return $this->check($key, $limit, $seconds);
    }

    /**
     * Rate limit specifico per creazione wallet
     */
    public function limitWalletCreation($apiKey, $limit = 5, $seconds = 3600)
    {
        $key = "rate:createwallet:$apiKey";
        return $this->check($key, $limit, $seconds);
    }
}