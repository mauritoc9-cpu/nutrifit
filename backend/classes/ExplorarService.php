<?php
declare(strict_types=1);

/** Consultas públicas OSM. Caché temporal anónima, nunca perfiles ni GPS preciso. */
final class ExplorarService
{
    private string $cacheDir;
    public function __construct()
    {
        $this->cacheDir = sys_get_temp_dir() . '/nutrifit-explorar-' . substr(hash('sha256', __DIR__), 0, 12);
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException('cache');
        }
    }

    public static function coordenada(mixed $value, float $limite): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || abs((float)$value) > $limite) throw new InvalidArgumentException('coordenada');
        return round((float)$value, 3);
    }

    public function buscarZona(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2 || mb_strlen($q) > 120 || preg_match('/[\x00-\x1f<>]/u', $q)) throw new InvalidArgumentException('busqueda');
        $url = rtrim(env('EXPLORE_PHOTON_URL', 'https://photon.komoot.io/api/'), '/') . '/?' . http_build_query(['q'=>$q, 'limit'=>5]);
        $raw = $this->consultar('photon', $url, null, 86400);
        $zonas = [];
        foreach ($raw['features'] ?? [] as $feature) {
            $c = $feature['geometry']['coordinates'] ?? [];
            if (count($c) < 2 || !is_numeric($c[0]) || !is_numeric($c[1]) || abs((float)$c[0]) > 180 || abs((float)$c[1]) > 90) continue;
            $p = $feature['properties'] ?? [];
            $partes = array_unique(array_filter([$p['name'] ?? null, $p['city'] ?? null, $p['state'] ?? null, $p['country'] ?? null]));
            $zonas[] = ['nombre'=>implode(', ', $partes), 'lat'=>round((float)$c[1],3), 'lon'=>round((float)$c[0],3)];
        }
        return array_slice($zonas, 0, 5);
    }

    public static function categoria(array $t): ?string
    {
        if (($t['leisure'] ?? '') === 'fitness_centre' || ($t['sport'] ?? '') === 'fitness') return 'gimnasios';
        if (in_array($t['shop'] ?? '', ['nutrition_supplements','sports_nutrition'], true)) return 'suplementos';
        if (in_array($t['amenity'] ?? '', ['restaurant','cafe','fast_food','food_court'], true) || in_array($t['shop'] ?? '', ['supermarket','greengrocer','health_food','bakery','convenience'], true)) return 'comida';
        return null;
    }

    public static function normalizar(array $raw, float $lat, float $lon, string $categoria): array
    {
        $lugares = []; $seen = [];
        foreach ($raw['elements'] ?? [] as $e) {
            $t = $e['tags'] ?? []; $cat = self::categoria($t);
            $y = $e['lat'] ?? $e['center']['lat'] ?? null; $x = $e['lon'] ?? $e['center']['lon'] ?? null;
            if (!$cat || ($categoria !== 'todos' && $categoria !== $cat) || !is_numeric($x) || !is_numeric($y) || abs((float)$y)>90 || abs((float)$x)>180) continue;
            $nombre = $t['name'] ?? $t['brand'] ?? null;
            if (!$nombre) continue; // No inventar identidad para puntos sin nombre.
            $a = sin(deg2rad((float)$y-$lat)/2)**2 + cos(deg2rad($lat))*cos(deg2rad((float)$y))*sin(deg2rad((float)$x-$lon)/2)**2;
            $dist = 6371000 * 2 * asin(min(1, sqrt($a)));
            if ($dist > 3200) continue;
            $key = mb_strtolower($nombre) . ':' . round((float)$y,4) . ':' . round((float)$x,4);
            if (isset($seen[$key])) continue; $seen[$key] = true;
            $direccion = trim(implode(' ', array_filter([$t['addr:street'] ?? null, $t['addr:housenumber'] ?? null])));
            if (!empty($t['addr:city'])) $direccion .= ($direccion ? ', ' : '') . $t['addr:city'];
            $lugares[] = ['id'=>($e['type'] ?? 'node').'/'.($e['id'] ?? ''), 'nombre'=>$nombre, 'categoria'=>$cat, 'tipo'=>$t['amenity'] ?? $t['shop'] ?? $t['leisure'] ?? 'fitness', 'lat'=>(float)$y, 'lon'=>(float)$x, 'distancia_m'=>round($dist), 'direccion'=>$direccion ?: null, 'horarios'=>$t['opening_hours'] ?? null];
        }
        usort($lugares, fn($a,$b)=>$a['distancia_m']<=>$b['distancia_m']);
        if ($categoria !== 'todos') return array_slice($lugares, 0, 30);
        $counts = []; $limitados = [];
        foreach ($lugares as $lugar) {
            $cat = $lugar['categoria'];
            if (($counts[$cat] ?? 0) >= 30) continue;
            $counts[$cat] = ($counts[$cat] ?? 0)+1; $limitados[] = $lugar;
        }
        return $limitados;
    }

    public function cercanos(float $lat, float $lon, string $categoria): array
    {
        if (!in_array($categoria, ['todos','gimnasios','comida','suplementos'], true)) throw new InvalidArgumentException('categoria');
        // Una consulta sirve a todos los filtros; no se vuelve a consultar al cambiar categoría.
        $around = sprintf('(around:3000,%.3F,%.3F)', $lat, $lon);
        $query = '[out:json][timeout:20];';
        foreach ([['[leisure=fitness_centre]','[sport=fitness]'],['[amenity~"^(restaurant|cafe|fast_food|food_court)$"]','[shop~"^(supermarket|greengrocer|health_food|bakery|convenience)$"]'],['[shop~"^(nutrition_supplements|sports_nutrition)$"]']] as $filters) {
            $query .= '(';
            foreach ($filters as $filter) $query .= 'nwr'.$around.$filter.';';
            $query .= ');out center tags 80;';
        }
        $raw = $this->consultar('overpass', env('EXPLORE_OVERPASS_URL', 'https://overpass-api.de/api/interpreter'), http_build_query(['data'=>$query]), 600);
        if (isset($raw['remark'])) throw new RuntimeException('provider');
        return self::normalizar($raw, $lat, $lon, $categoria);
    }

    private function consultar(string $provider, string $url, ?string $body, int $ttl): array
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !parse_url($url, PHP_URL_HOST)) throw new RuntimeException('config');
        $file = $this->cacheDir . '/' . hash('sha256', $url . ($body ?? '')) . '.json';
        $lock = fopen($this->cacheDir . '/'.$provider.'.lock', 'c+');
        if (!$lock) throw new RuntimeException('cache');
        try {
            $deadline = microtime(true)+3;
            while (!flock($lock, LOCK_EX|LOCK_NB)) {
                if (microtime(true)>$deadline) throw new RuntimeException('busy');
                usleep(50000);
            }
            // Purga acotada de archivos caducados (incluye zonas públicas).
            foreach (array_slice(glob($this->cacheDir.'/*.json') ?: [], 0, 100) as $old) if (filemtime($old) < time()-86400) @unlink($old);
            if (is_file($file) && filemtime($file) > time()-$ttl) {
                $cached = json_decode(file_get_contents($file), true);
                if (is_array($cached)) return $cached;
            }
            rewind($lock); $last = (float)stream_get_contents($lock);
            $wait = 1.1 - (microtime(true)-$last);
            if ($wait > 0) usleep((int)($wait*1000000));
            ftruncate($lock, 0); rewind($lock); fwrite($lock, (string)microtime(true));
            $response = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>25, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_USERAGENT=>'NutriFit-Explorar/1.0', CURLOPT_HTTPHEADER=>['Accept: application/json'], CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$response) { $response = ($response ?? '').$chunk; return strlen($response)>2000000 ? 0 : strlen($chunk); }]);
            if ($body !== null) curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body]);
            $ok = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            if ($ok === false || $code !== 200) throw new RuntimeException('provider');
            $result = json_decode($response ?? '',true);
            if (!is_array($result)) throw new RuntimeException('provider');
            file_put_contents($file,json_encode($result,JSON_UNESCAPED_UNICODE),LOCK_EX);
            return $result;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
