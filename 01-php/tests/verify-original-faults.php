<?php
// Evidence for the fault claims in 01-php/NOTES.md. Each block demonstrates one
// fault from the original snippet in isolation.

echo "1. '[ad]' is a character class, not a literal:\n";
echo "   preg_replace('#[ad]#','X','A dad had a bad day. [ad]') => ";
echo preg_replace('#[ad]#', 'X', 'A dad had a bad day. [ad]') . "\n";
echo "   escaped '#\\[ad\\]#' => ";
echo preg_replace('#\[ad\]#', 'X', 'A dad had a bad day. [ad]') . "\n\n";

echo "2. preg_replace_callback with two arguments:\n";
try {
    preg_replace_callback('#x#', function () {
        return 'y';
    });
} catch (ArgumentCountError $e) {
    echo "   ArgumentCountError: " . $e->getMessage() . "\n\n";
}

echo "3. Callback that echoes instead of returning:\n";
$out = preg_replace_callback('#\[ad\]#', function ($m) {
    echo '[ECHOED OUT OF BAND]';
}, 'before [ad] after');
echo "\n   returned string => " . var_export($out, true) . "\n\n";

echo "4. (int) on a non-numeric page value:\n";
echo "   (int)'abc' => " . var_export((int)'abc', true);
echo " ; max(1,(int)'abc') => " . var_export(max(1, (int)'abc'), true) . "\n\n";

echo "5. Undefined array key under PHP 8:\n";
$get = [];
set_error_handler(function ($no, $str) {
    echo "   Warning: {$str}\n";
    return true;
});
$v = $get['s'];
restore_error_handler();
echo "   with null coalescing => " . var_export($get['s'] ?? '', true) . "\n";
