<?php

if (!function_exists('load_env')) {
    function load_env($path)
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // 既存の環境変数を上書きする（.envの値を優先）
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        $value = getenv($key);

        if ($value === false) {
            // getenvで取得できない場合、$_ENVや$_SERVERを確認
            if (isset($_ENV[$key])) {
                return $_ENV[$key];
            }
            if (isset($_SERVER[$key])) {
                return $_SERVER[$key];
            }
            return $default;
        }

        return $value;
    }
}
