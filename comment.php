<?php

$sourceDirs = ['app', 'bootstrap', 'database', 'public', 'resources', 'storage', 'tests', 'routes'];
$directory = __DIR__;

$excludeDirs = ['vendor', 'node_modules', 'storage', 'bootstrap/cache'];
$dryRun = false;
$tagsToKeep = ['!', '?', 'todo'];

$directoryIterator = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);

$filterIterator = new RecursiveCallbackFilterIterator($directoryIterator, function ($current, $key, $iterator) use ($excludeDirs) {
    if ($current->isDir() && in_array($current->getFilename(), $excludeDirs)) {
        return false;
    }
    return true;
});

$iterator = new RecursiveIteratorIterator($filterIterator);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $code = file_get_contents($path);
    $tokens = token_get_all($code);
    $newCode = '';

    foreach ($tokens as $token) {
        if (is_array($token)) {

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $commentText = $token[1];
                $keep = false;

                foreach ($tagsToKeep as $tag) {
                    if ($tag === 'todo') {
                        if (stripos($commentText, $tag) !== false) {
                            $keep = true;
                            break;
                        }
                    }
                    else {
                        if (strpos($commentText, $tag) !== false) {
                            $keep = true;
                            break;
                        }
                    }
                }

                if ($keep) {
                    $newCode .= $commentText;
                }
                continue;
            }
            $newCode .= $token[1];
        }
        else {
            $newCode .= $token;
        }
    }

    file_put_contents($path, $newCode);
    echo "Cleaned: $path\n";
}