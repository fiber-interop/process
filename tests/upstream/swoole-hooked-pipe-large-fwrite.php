<?php
/**
 * Swoole 6.2.3 (PHP 8.4, SWOOLE_HOOK_ALL): fwrite() to a proc_open() stdin pipe under-reports what it sent.
 *
 * With SWOOLE_HOOK_PROC the pipes are coroutine sockets (stream_set_blocking(false) returns true, yet the
 * stream keeps reporting blocked=true). When the buffer is larger than the free socket space, fwrite() puts
 * part of it on the wire but returns 0, so a correct retry loop sends the same bytes again: the child (cat)
 * echoes far more than was written. Eventually fwrite() returns false with "Send of N bytes failed with
 * errno=2 No such file or directory" (a stale errno) as an E_NOTICE. Writes of at most 64 KB after the fd
 * polls writable are reported correctly.
 *
 * Run: php swoole-hooked-pipe-large-fwrite.php [chunk]   (chunk 0 = whole remaining buffer; prints BUG or OK)
 * chunk=0 prints BUG on 6.2.3; chunk=65536 prints OK. Swoole 6.3.0RC1 passes either way.
 */
$chunk = (int) ($argv[1] ?? 0);
Co\run(function () use ($chunk) {
    $cmd = ['sh', '-c', 'head -c 4194304 /dev/zero | tr "\\0" e >&2; cat'];
    $proc = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    foreach ($pipes as $p) {
        stream_set_blocking($p, false);
    }
    $out = '';
    foreach ([1, 2] as $fd) {
        go(function () use ($pipes, $fd, &$out) {
            while (!feof($pipes[$fd])) {
                Swoole\Coroutine\System::waitEvent($pipes[$fd], SWOOLE_EVENT_READ, 5);
                $s = (string) fread($pipes[$fd], 65536);
                if ($fd === 1) {
                    $out .= $s;
                    if (strlen($out) > 8 << 20) {
                        printf("BUG: swoole %s, child echoed %d bytes of a 4194304-byte input (fwrite under-reported)\n", SWOOLE_VERSION, strlen($out));
                        exit(2);
                    }
                }
            }
        });
    }
    $in = str_repeat('0123456789abcdef', 256 * 1024);
    $sent = 0;
    $error = null;
    set_error_handler(function ($no, $msg) use (&$error) { $error = $msg; return true; });
    while ($sent < strlen($in)) {
        Swoole\Coroutine\System::waitEvent($pipes[0], SWOOLE_EVENT_WRITE, 5);
        $n = @fwrite($pipes[0], $chunk ? substr($in, $sent, $chunk) : substr($in, $sent));
        if ($n === false) {
            break;
        }
        $sent += $n;
    }
    fclose($pipes[0]);
    for ($i = 0; $i < 500 && strlen($out) < $sent; $i++) {
        Co::sleep(0.01);
    }
    proc_close($proc);
    $ok = $sent === strlen($in) && $out === $in && $error === null;
    printf("%s: swoole %s, chunk=%d, sent %d/%d, echoed %d, notice: %s\n", $ok ? 'OK' : 'BUG',
        SWOOLE_VERSION, $chunk, $sent, strlen($in), strlen($out), $error ?? 'none');
    exit($ok ? 0 : 1);
});
