### 功能

读取指定目录下的文件，以数组的形式缓存成文件，需要的时候，可以按键值读取。可以设置排除文件，比如routes.php这种路由配置文件，默认一般不返回数组。
支持多种格式，如php，json，ini等。

### 安装

``` composer require xuey490/config ```

### 快速开始（目录方式）

```
use Framework\Config\Config;
use Framework\Config\Cache\ConfigCache;

$cacheFile = sys_get_temp_dir() . '/myapp_config.cache.php';
$cache = new ConfigCache($cacheFile, 300); // TTL 300s

$config = new Config(__DIR__ . '/config', $cache, null, ['routes.php', 'services.php']);

// 第一次会读取目录并写缓存（不包括 routes.php, services.php）
$all = $config->load();

// 使用点语法读取
$dbHost = $config->get('database.host', '127.0.0.1');

```

### 欢迎star & fork 
