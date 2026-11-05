<?php

namespace hexa_package_wptoolkit\Services;

use hexa_package_whm\Models\WhmServer;
use hexa_package_whm\Services\WhmService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class WordPressDatabaseTargetProbe
{
    public function __construct(private WhmService $whm)
    {
    }

    /**
     * Run a fresh connection on the target server using its real DB_HOST route.
     * The credential is sent through protected SSH stdin and never argv.
     */
    public function database(WhmServer $server, array $config, string $password): array
    {
        $payload = json_encode([
            'host' => (string) $config['host'],
            'database' => (string) $config['database'],
            'username' => (string) $config['username'],
            'password' => $password,
            'table_prefix' => (string) $config['table_prefix'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $result = $this->whm->executeCommandWithInput(
            $server,
            'php -r '.escapeshellarg($this->remoteProbeProgram()),
            $payload,
            [$password],
            30,
        );
        if (!($result['success'] ?? false)) {
            return [
                'success' => false,
                'error_code' => null,
                'error' => 'Protected target-server database probe failed: '.($result['error'] ?? 'unknown transport error'),
            ];
        }

        try {
            $decoded = json_decode((string) ($result['output'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['success' => false, 'error_code' => null, 'error' => 'Target-server probe returned invalid JSON.'];
        }
        if (!is_array($decoded)) {
            return ['success' => false, 'error_code' => null, 'error' => 'Target-server probe returned no result.'];
        }

        return [
            'success' => (bool) ($decoded['success'] ?? false),
            'error_code' => isset($decoded['error_code']) ? (int) $decoded['error_code'] : null,
            'select_1' => (bool) ($decoded['select_1'] ?? false),
            'options' => is_array($decoded['options'] ?? null) ? $decoded['options'] : [],
            'error' => ($decoded['success'] ?? false) ? null : (string) ($decoded['error'] ?? 'Database probe failed.'),
        ];
    }

    public function routes(array $options): array
    {
        $home = trim((string) ($options['home'] ?? $options['siteurl'] ?? ''));
        if ($home === '') {
            throw new RuntimeException('WordPress home/siteurl is missing from the options table.');
        }

        return [
            'home' => $this->http($home),
            'login' => $this->http(rtrim($home, '/').'/wp-login.php'),
        ];
    }

    private function http(string $url): array
    {
        try {
            $response = Http::timeout(20)->connectTimeout(10)->withOptions([
                'allow_redirects' => false,
                'verify' => true,
            ])->get($url);
            $status = $response->status();

            return [
                'url' => $url,
                'status' => $status,
                'reachable' => $status >= 200 && $status < 400,
            ];
        } catch (Throwable $error) {
            return [
                'url' => $url,
                'status' => null,
                'reachable' => false,
                'error' => mb_substr($error->getMessage(), 0, 300),
            ];
        }
    }

    private function remoteProbeProgram(): string
    {
        return <<<'PHP'
$p=json_decode(trim((string)fgets(STDIN)),true,16,JSON_THROW_ON_ERROR);
$host=(string)$p['host'];$port=3306;$socket=null;
if(str_contains($host,':')&&!str_starts_with($host,'[')){
    [$hostPart,$suffix]=array_pad(explode(':',$host,2),2,null);
    $host=$hostPart;
    if(is_string($suffix)&&ctype_digit($suffix)){$port=(int)$suffix;}
    elseif(is_string($suffix)&&str_starts_with($suffix,'/')){$socket=$suffix;}
}
try{
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=mysqli_init();
    if(!$db){throw new RuntimeException('mysqli initialization failed');}
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT,10);
    $db->real_connect($host,(string)$p['username'],(string)$p['password'],(string)$p['database'],$port,$socket);
    $one=$db->query('SELECT 1 AS ok')->fetch_assoc();
    $prefix=(string)$p['table_prefix'];
    if(preg_match('/\A[A-Za-z0-9_$-]+\z/D',$prefix)!==1){throw new RuntimeException('unsafe table prefix');}
    $rows=$db->query("SELECT option_name,option_value FROM `{$prefix}options` WHERE option_name IN ('home','siteurl','blogname')");
    $options=[];
    while($row=$rows->fetch_assoc()){$options[(string)$row['option_name']]=(string)$row['option_value'];}
    ksort($options);$db->close();
    echo json_encode(['success'=>($one['ok']??null)==1,'select_1'=>true,'options'=>$options],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(mysqli_sql_exception $e){
    echo json_encode(['success'=>false,'error_code'=>$e->getCode(),'error'=>'MySQL authentication or query failed.'],JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    echo json_encode(['success'=>false,'error_code'=>null,'error'=>'Target database probe failed.'],JSON_THROW_ON_ERROR);
}
PHP;
    }
}
