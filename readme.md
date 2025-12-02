### 安装

``` composer require xuey490/config ```

### 快速开始（目录方式）

```
use Framework\Config\Config;
use Framework\Config\Cache\ConfigCache;

$cacheFile = sys_get_temp_dir() . '/myapp_config.cache.php';
$cache = new ConfigCache($cacheFile, 300); // TTL 300s

$config = new Config(__DIR__ . '/config', $cache, ['routes.php']);

// 第一次会读取目录并写缓存（不包括 routes.php）
$all = $config->load();

// 使用点语法读取
$dbHost = $config->get('database.host', '127.0.0.1');

```

### 欢迎star & fork 
